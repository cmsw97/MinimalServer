<?php

namespace Ts;

require_once __DIR__ . '/../vendor/autoload.php';

use Exception;
use LengthException;
use MessagePack\Packer;
use PDO;
use PDOException;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

class DataController
{
	private Db $db;

	private int $idAccount;

	// Máximo de rows que se permite enviar al cliente en una petición.
	const MAX_RECORDS_PER_TABLE = 2;

	public function __construct()
	{
		$this->db = new Db();
	}

	/**
	 * @param array<string, int> $clientTables
	 * @return array<array<string, mixed>>
	 */
	private function getResponseTables(array $clientTables, ?bool &$outEOF): array
	{
		$result = [];
		$outEOF = true;

		foreach (TableInfo::getPublicTableNames() as $tableName)
		{
			$clientMaxId = $clientTables[$tableName];
			$serverMaxId = $this->db->executeScalar("SELECT MAX(id) FROM {$tableName};") ?? 0;

			if ($clientMaxId < $serverMaxId)
			{
				// El servidor tiene más registros que el cliente, hay que mandarle los que le faltan.

				$table = [];
				$table["Name"] = $tableName;
				$table["Columns"] = [];
				$table["Rows"] = [];

				// Ejecuta la query.
				$statement = $this->db->execute(
					"SELECT * FROM {$tableName} " .
						"WHERE idAccount = :idAccount AND id > :id " .
						"ORDER BY id LIMIT " . (self::MAX_RECORDS_PER_TABLE + 1),
					[":idAccount" => $this->idAccount, ":id" => $clientMaxId]
				);

				$columnCount = $statement->columnCount();

				// Pasa los nombres de las columnas.
				/** @var int[] */
				$columnIndexes = [];
				for ($i = 0; $i < $columnCount; $i++)
				{
					$name = $statement->getColumnMeta($i)["name"];
					if (!TableInfo::isPrivateColumn($name))
					{
						$columnIndexes[] = $i;
						$table["Columns"][] = $name;
					}
				}

				// Pasa los valores de las columnas.
				$rowsAdded = 0;
				while ($row = $statement->fetch(PDO::FETCH_NUM))
				{
					if (++$rowsAdded > self::MAX_RECORDS_PER_TABLE)
					{
						// Este record ya excede el máximo permitido, lo que quiere decir que
						// no se puede enviar toda la información al cliente en esta solicitud,
						// así que se cambia el indicador EOF para que el cliente sepa que debe pedir más.
						$outEOF = false;
						break;
					}

					$tableRow = [];
					foreach ($columnIndexes as $i)
					{
						$tableRow[] = $row[$i];
					}
					$table["Rows"][] = $tableRow;
				}

				$result[] = $table;
			}
		}

		return $result;
	}

	/** @param ?array<string, mixed> $action */
	private function performAction(?array $action): ?string
	{
		if ($action === null)
		{
			return null;
		}

		/** @var string */
		$verb = $action["verb"];
		/** @var array<mixed> */
		$arguments = $action["arguments"];

		try
		{
			if ($verb === "post")
			{
				// Hay que crear un objeto. En el 1er argumento está el nombre de la tabla, y en el 2o el objeto.
				$this->post($arguments[0], $arguments[1]);
			}
			elseif ($verb === "delete")
			{
				// Hay que crear un objeto. En el 1er argumento está el nombre de la tabla, y en el 2o el id.
				$this->delete($arguments[0], $arguments[1]);
			}
			return null;
		}
		catch (PDOException $pdoException)
		{
			return $pdoException->getMessage();
		}
	}

	private function delete(string $tableName, int $id): bool
	{
		if (!TableInfo::isPublicTable($tableName) || TableInfo::isReadOnlyTable($tableName))
		{
			// ¡El cliente está queriendo alterar una tabla para la que no tiene permiso,
			// o hasta queriendo hacer SQL injection!
			return false;
		}

		if (!$this->db->beginTransaction())
		{
			// Quién sabe por qué podría pasar esto, better safe than sorry.
			return false;
		}

		try
		{
			$query = "DELETE FROM {$tableName} WHERE idAccount = :idAccount AND id = :id;";
			$parameters = [":idAccount" => $this->idAccount, ":id" => $id];
			$statement = $this->db->execute($query, $parameters);
			if ($statement->rowCount() !== 1)
			{
				// A lo mejor ya no existe.
				throw new LengthException();
			}

			$query = "INSERT INTO erase (idAccount, tableId, idRow) VALUES (:idAccount, :tableId, :idRow);";
			$parameters = [
				":idAccount" => $this->idAccount,
				":tableId" => TableInfo::getTableId($tableName),
				":idRow" => $id
			];
			$statement = $this->db->execute($query, $parameters);
			if ($statement->rowCount() !== 1)
			{
				throw new LengthException();
			}

			$this->db->commit();

			$succeded = true;
		}
		catch (Exception $ex)
		{
			$this->db->rollBack();

			$succeded = false;
		}

		return $succeded;
	}

	/**
	 * Crea un nuevo row en la base de datos.
	 * @param array<string,mixed> $dbObject
	 */
	private function post(string $tableName, array $dbObject): bool
	{
		if (!TableInfo::isPublicTable($tableName) || TableInfo::isReadOnlyTable($tableName))
		{
			// ¡El cliente está queriendo alterar una tabla para la que no tiene permiso,
			// o hasta queriendo hacer SQL injection!
			return false;
		}

		$columns = ["idAccount"];
		$parameters = [":idAccount" => $this->idAccount];

		foreach ($dbObject as $name => $value)
		{
			if (!TableInfo::isValidColumnName($name) || TableInfo::isPrivateColumn($name))
			{
				// ¡El cliente está queriendo alterar una columna para la que no tiene permiso
				// o hasta queriendo hacer SQL inyection!.
				return false;
			}

			if (!TableInfo::isReadOnlyColumn($name))
			{
				$columns[] = $name;
				$parameters[":{$name}"] = $value;
			}
		}

		$query = "INSERT INTO {$tableName} " .
			"(" . implode(",", $columns) . ") VALUES (" . implode(",", array_keys($parameters)) . ");";

		$statement = $this->db->execute($query, $parameters);
		return $statement->rowCount() === 1;
	}

	public function __invoke(ServerRequestInterface $request): Response
	{
		$requestBody = json_decode($request->getBody(), true);

		if ($requestBody["version"] !== 1)
		{
			// Versión incorrecta.
			return new Response(
				status: Response::STATUS_BAD_REQUEST,
				body: "Presione CTRL+F5 para actualizar la página e intente nuevamente."
			);
		}

		if (!$this->authenticateUser($request))
		{
			return new Response(
				status: Response::STATUS_UNAUTHORIZED,
				body: "Datos de acceso incorrectos."
			);
		}

		// Si recibió alguna acción, entonces hay que crear/editar/borrar algo.
		$actionResult = $this->performAction($requestBody["action"]);

		// Obtiene el contenido de las tablas que se devolverá al cliente.
		$tables = $this->getResponseTables($requestBody["tables"], $eof);

		$response = [];
		$response["ActionResult"] = $actionResult;
		$response["EOF"] = $eof;
		$response["Message"] = null;
		$response["Tables"] = $tables;
		$response["Version"] = 1;

		$packer = new Packer();
		$packedResponse = $packer->pack($response);

		return new Response(status: Response::STATUS_OK, body: $packedResponse);
	}

	/**
	 * Recibe la request, y si el usuario y contraseña son correctos
	 * entonces inicializa la propiedad $idAccount y devuelve true.
	 */
	private function authenticateUser(ServerRequestInterface $request): bool
	{
		$authHeader = explode(" ", $request->getHeader("authorization")[0]);

		// El primer elemento del header debe ser el esquema "Basic".
		if ($authHeader[0] !== "Basic")
		{
			return false;
		}

		// El segundo elemento debe una cadena base64 con usuario:contraseña,
		// ambos codificados en URL para escapar los caracteres ':'.
		$credentials = explode(":", base64_decode($authHeader[1]));
		$credentials = array_map(fn ($o) => urldecode($o), $credentials);

		$user = $this->db->fetchSingle(
			"SELECT id, idAccount, password FROM user WHERE name = :name;",
			[":name" => $credentials[0]],
			PDO::FETCH_OBJ
		);

		if ($user && password_verify($credentials[1], $user->password))
		{
			$this->idAccount = $user->idAccount;
			return true;
		}

		return false;
	}
}
