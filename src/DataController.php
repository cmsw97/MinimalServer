<?php

namespace Ts;

require_once __DIR__ . '/../vendor/autoload.php';

use AssertionError;
use MessagePack\Packer;
use PDO;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;
use Throwable;

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
			if ($verb === "POST")
			{
				// Hay que crear un objeto. En el 1er argumento está el nombre de la tabla, y en el 2o el objeto.
				$this->post($arguments[0], $arguments[1]);
			}
			elseif ($verb === "DELETE")
			{
				// Hay que crear un objeto. En el 1er argumento está el nombre de la tabla, y en el 2o el id.
				$this->delete($arguments[0], $arguments[1]);
			}
			elseif ($verb === "PUT")
			{
				// Hay que modificar un objeto. En el 1er argumento está el nombre de la tabla, y en el 2o el id.
				$this->put($arguments[0], $arguments[1]);
			}
			return null;
		}
		catch (Throwable $throwable)
		{
			return $throwable->getMessage();
		}
	}

	/**
	 * Si assertion es false entonces lanza la excepción.
	 */
	private static function assertEx(bool $assertion, callable|string $else): void
	{
		if (!$assertion)
		{
			if (is_string($else))
			{
				throw new AssertionError($else);
			}
			else
			{
				$else();
			}
		}
	}

	private function delete(string $tableName, int $id): void
	{
		self::assertEx(
			TableInfo::isPublicTable($tableName) && !TableInfo::isReadOnlyTable($tableName),
			fn () => throw new SecurityException()	// ¡Está intentando acceder a una tabla a la que no tiene permiso!
		);

		if (!$this->db->beginTransaction())
		{
			// Quién sabe por qué podría pasar esto, better safe than sorry.
			return;
		}

		try
		{
			// Borra el registro.
			$query = "DELETE FROM {$tableName} WHERE idAccount = :idAccount AND id = :id;";
			$parameters = [":idAccount" => $this->idAccount, ":id" => $id];
			$statement = $this->db->execute($query, $parameters);
			self::assertEx($statement->rowCount() === 1, "Nothing deleted, perhaps it not existed");

			// Inserta un registro en la tabla 'deletion' indicando que ya se borró.
			$query = "INSERT INTO deletion (idAccount, tableId, idRow) VALUES (:idAccount, :tableId, :idRow);";
			$parameters = [
				":idAccount" => $this->idAccount,
				":tableId" => TableInfo::getTableId($tableName),
				":idRow" => $id
			];
			$statement = $this->db->execute($query, $parameters);
			self::assertEx($statement->rowCount() === 1, "Couldn't create deletion record");

			$this->db->commit();
		}
		catch (Throwable $throwable)
		{
			$this->db->rollBack();

			throw $throwable;
		}
	}

	/**
	 * Crea un nuevo row en la base de datos.
	 * @param array<string,mixed> $dbObject
	 */
	private function post(string $tableName, array $dbObject): void
	{
		self::assertEx(
			TableInfo::isPublicTable($tableName) && !TableInfo::isReadOnlyTable($tableName),
			fn () => throw new SecurityException()	// ¡Está intentando acceder a una tabla a la que no tiene permiso!
		);

		foreach ($dbObject as $name => $value)
		{
			self::assertEx(
				TableInfo::isValidColumnName($name) && !TableInfo::isPrivateColumn($name),
				fn () => throw new SecurityException() // ¡Está intentando acceder a una columna a la que no tiene permiso!
			);

			if (!TableInfo::isReadOnlyColumn($name))
			{
				$columns[] = $name;
				$parameters[":{$name}"] = $value;
			}
		}

		// Crea el registro.
		$columns[] = "idAccount";
		$parameters[":idAccount"] = $this->idAccount;
		$query = "INSERT INTO {$tableName} " .
			"(" . implode(",", $columns) . ") VALUES (" . implode(",", array_keys($parameters)) . ");";
		$statement = $this->db->execute($query, $parameters);
		self::assertEx($statement->rowCount() === 1, "Couldn't create record");
	}

	/**
	 * Crea un nuevo row en la base de datos.
	 * @param array<string,mixed> $dbObject
	 */
	private function put(string $tableName, array $dbObject): void
	{
		self::assertEx(
			TableInfo::isPublicTable($tableName) && !TableInfo::isReadOnlyTable($tableName),
			fn () => throw new SecurityException()	// ¡Está intentando acceder a una tabla a la que no tiene permiso!
		);

		if (!$this->db->beginTransaction())
		{
			// Quién sabe por qué podría pasar esto, better safe than sorry.
			return;
		}

		try
		{
			// Actualiza el registro.
			$parameters = [];
			$query = "UPDATE {$tableName} SET ";
			foreach ($dbObject as $name => $value)
			{
				self::assertEx(
					TableInfo::isValidColumnName($name) && !TableInfo::isPrivateColumn($name),
					fn () => throw new SecurityException() // ¡Está intentando acceder a una columna a la que no tiene permiso!
				);

				if (!TableInfo::isReadOnlyColumn($name))
				{
					$query .= $name . " = :{$name}, ";
					$parameters[":{$name}"] = $value;
				}
			}
			$query = rtrim($query, ", ");
			$query .= " WHERE id = :id AND idAccount = :idAccount;";
			$parameters[":id"] = $dbObject["id"];
			$parameters[":idAccount"] = $this->idAccount;
			$statement = $this->db->execute($query, $parameters);
			self::assertEx($statement->rowCount() === 1, "Nothing updated, perhaps it not existed");

			// Inserta un registro en la tabla 'edition' indicando que sufrió cambios.
			$query = "INSERT INTO edition (idAccount, tableId, idRow) VALUES (:idAccount, :tableId, :idRow);";
			$parameters = [
				":idAccount" => $this->idAccount,
				":tableId" => TableInfo::getTableId($tableName),
				":idRow" => $dbObject["id"],
			];
			$statement = $this->db->execute($query, $parameters);
			self::assertEx($statement->rowCount() === 1, "Couldn't create edition record");

			$this->db->commit();
		}
		catch (Throwable $throwable)
		{
			$this->db->rollBack();

			throw $throwable;
		}
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
