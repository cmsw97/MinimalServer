<?php

namespace Ts;

require_once __DIR__ . '/../vendor/autoload.php';

use Exception;
use MessagePack\Packer;
use PDO;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;
use stdClass;

class DataController
{
	private Db $db;

	// Máximo de rows que se permite enviar al cliente en una petición.
	const MAX_RECORDS_PER_TABLE = 2;

	private static function shouldExcludeColumn(string $columnName): bool
	{
		return $columnName === "idAccount";
	}

	/**
	 * @return array<array<string, mixed>>
	 */
	private function getServerTables(int $idAccount, stdClass $clientTables, ?bool &$outEOF): array
	{
		$result = [];
		$outEOF = true;

		foreach (TableInfo::clientTableNames() as $tableName)
		{
			$clientMaxId = $clientTables->{$tableName};
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
					[":idAccount" => $idAccount, ":id" => $clientMaxId]
				);

				$columnCount = $statement->columnCount();

				// Pasa los nombres de las columnas.
				/** @var int[] */
				$columnIndexes = [];
				for ($i = 0; $i < $columnCount; $i++)
				{
					$name = $statement->getColumnMeta($i)["name"];
					if (!self::shouldExcludeColumn($name))
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

	public function __invoke(ServerRequestInterface $request): Response
	{
		$data = json_decode($request->getBody());

		if ($data->version !== 1)
		{
			// Versión incorrecta.
			return new Response(
				status: Response::STATUS_BAD_REQUEST,
				body: "Presione CTRL+F5 para actualizar la página e intente nuevamente."
			);
		}

		$this->db = new Db();

		$user = $this->authenticateUser($request);

		if (!$user)
		{
			return new Response(
				status: Response::STATUS_UNAUTHORIZED,
				body: "Datos de acceso incorrectos."
			);
		}

		$response = [];
		$response["Version"] = 1;
		$response["Message"] = null;
		$response["Tables"] = $this->getServerTables($user->idAccount, $data->tables, $eof);
		$response["EOF"] = $eof;

		$packer = new Packer();
		$packedResponse = $packer->pack($response);

		return new Response(status: Response::STATUS_OK, body: $packedResponse);
	}

	/**
	 * Recibe la request, y si el usuario y contraseña son correctos
	 * entonces devuelve el usuario de la base datos, si no, devuelve null.
	 */
	private function authenticateUser(ServerRequestInterface $request): ?stdClass
	{
		$authHeader = explode(" ", $request->getHeader("authorization")[0]);

		// El primer elemento del header debe ser el esquema "Basic".
		if ($authHeader[0] !== "Basic")
		{
			return null;
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
			return $user;
		}

		return null;
	}
}
