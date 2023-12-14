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

	private mixed $requestBody;

	// Máximo de rows que se permite enviar al cliente en una petición.
	// Nunca debería ser mayor a 10,000 porque se usa en query IN.
	const MAX_RECORDS_PER_TABLE = 2;

	public function __construct()
	{
		$this->db = new Db();
	}

	/**
	 * Devuelve una cadena en la forma "id0,id1,id2,...,0" con los ids editados que deben enviarse al cliente.
	 */
	private function getEditionIds(string $tableName, int $maxClientEditionId, int $limit): string
	{
		if ($tableName === 'deletion')
		{
			// La tabla deletion nunca tiene ediciones.
			return "0";
		}

		$statement = $this->db->execute(
			"SELECT idRow FROM edition " .
				"WHERE idAccount = :idAccount AND tableId = :tableId AND id > :maxClientEditionId " .
				"ORDER BY id LIMIT {$limit};",
			[
				":idAccount" => $this->idAccount,
				":tableId" => TableInfo::getTableId($tableName),
				":maxClientEditionId" => $maxClientEditionId,
			]
		);

		$result = "";
		foreach ($statement->fetchAll(PDO::FETCH_NUM) as $row)
		{
			$result .= strval($row[0]) . ",";
		}
		$result .= "0";	// Agrega un cero para que el resultado nunca se vaya vacío y la consulta no falle.

		return $result;
	}

	/**
	 * Devuelve los records que se deben enviar al cliente.
	 * @return array<array<string, mixed>>
	 */
	private function getResponseTables(?bool &$outEOF): array
	{
		$clientTables = &$this->requestBody["tables"];

		$result = [];
		$outEOF = true;

		foreach (TableInfo::getPublicTableNames() as $tableName)
		{
			$clientMaxId = $clientTables[$tableName];

			$table = [];
			$table["Name"] = $tableName;
			$table["Columns"] = [];
			$table["Rows"] = [];

			// Obtiene los ids que deben tuvieron ediciones y se deben enviar al cliente.
			$editionIds = $this->getEditionIds($tableName, $clientTables['edition'], self::MAX_RECORDS_PER_TABLE);

			// Determina el máximo de registros que pueden obtenerse en esta petición.
			// Este límite ayuda a mantener bajo el tamaño de lo que se envia al cliente y no saturar la RAM del servidor.
			$limit = self::MAX_RECORDS_PER_TABLE + 1; // + 1 para poder determinar si ya no hay más y asignar $outEOF.

			// Ejecuta la query.
			$statement = $this->db->execute(
				"SELECT * FROM {$tableName} " .
					"WHERE idAccount = :idAccount AND (id > :clientMaxId OR id IN ({$editionIds})) " .
					"ORDER BY id LIMIT {$limit};",
				[":idAccount" => $this->idAccount, ":clientMaxId" => $clientMaxId]
			);

			$columnCount = $statement->columnCount();

			// Pasa los nombres de las columnas.
			/** @var int[] */
			$columnIndexes = [];	// Índices de las columnas que se pueden enviar al cliente.
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

		return $result;
	}

	/**
	 * Si assertion es false entonces ejecuta callable o lanza una exepción.
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

	/**
	 * Crea un nuevo row en la base de datos.
	 * @param array<string, mixed> $dbObject
	 */
	private function post(string $tableName, array &$dbObject): void
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
				$parameters[":" . $name] = $value;
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
	 * Actualiza un row en la base de datos.
	 * @param array<string,mixed> $dbObject
	 */
	private function put(string $tableName, array &$dbObject): void
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
			$id = intval($dbObject["id"]);
			$tableId = TableInfo::getTableId($tableName);

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
					$parameters[":" . $name] = $value;
				}
			}
			$query = rtrim($query, ", ");	// Quita el trailing ", ".
			$query .= " WHERE id = :id AND idAccount = :idAccount;";
			$parameters[":id"] = $id;
			$parameters[":idAccount"] = $this->idAccount;
			$statement = $this->db->execute($query, $parameters);
			if ($statement->rowCount() !== 1)
			{
				// Nada se actualizó a lo mejor el registro no tuvo cambios.
				$this->db->rollBack();
				return;
			}

			// Borra registros anteriores en la tabla 'edition' que hagan referencia a esta edición porque ya no se usan.
			$query = "DELETE FROM edition WHERE idRow = :idRow AND idAccount = :idAccount AND tableId = :tableId;";
			$parameters = [":idRow" => $id, ":idAccount" => $this->idAccount, ":tableId" => $tableId];
			$statement = $this->db->execute($query, $parameters);

			// Inserta un registro en la tabla 'edition' indicando que sufrió cambios.
			$query = "INSERT INTO edition (idAccount, tableId, idRow) VALUES (:idAccount, :tableId, :idRow);";
			$parameters = [
				":idAccount" => $this->idAccount,
				":tableId" => $tableId,
				":idRow" => $id,
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

	private function delete(string $tableName, int $id): void
	{
		// TODO: Al borrar un registro, debe borrar todo lo que haya de él en edition y deletion,
		// p.ej. al borrar un empleado, hay que ver qué registros están relacionados con él y borrarlos también
		// a lo mejor hay que agregar un campo idEmployee en edition y deletion para cuando se vorra a un empleado,
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
			$statement = $this->db->execute(
				"DELETE FROM {$tableName} WHERE idAccount = :idAccount AND id = :id;",
				[":idAccount" => $this->idAccount, ":id" => $id]
			);
			self::assertEx($statement->rowCount() === 1, "Nothing deleted, perhaps it not existed");

			// Inserta un registro en la tabla 'deletion' indicando que ya se borró.
			$statement = $this->db->execute(
				"INSERT INTO deletion (idAccount, tableId, idRow) VALUES (:idAccount, :tableId, :idRow);",
				[
					":idAccount" => $this->idAccount,
					":tableId" => TableInfo::getTableId($tableName),
					":idRow" => $id
				]
			);
			self::assertEx($statement->rowCount() === 1, "Couldn't create deletion record");

			// Borra las ediciones de ese registro.
			$this->db->execute("DELETE FROM edition WHERE idRow = :idRow;", [":idRow" => $id]);

			$this->db->commit();
		}
		catch (Throwable $throwable)
		{
			$this->db->rollBack();

			throw $throwable;
		}
	}

	private function performAction(): ?string
	{
		/** @var ?array<string, mixed> */
		$action = $this->requestBody["action"];

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

	public function __invoke(ServerRequestInterface $request): Response
	{
		$this->requestBody = json_decode($request->getBody(), true);

		if ($this->requestBody["version"] !== 1)
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
		$actionResult = $this->performAction();

		// Obtiene el contenido de las tablas que se devolverá al cliente.
		$tables = $this->getResponseTables($eof);

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
