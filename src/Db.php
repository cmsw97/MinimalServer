<?php

namespace Ts;

require_once __DIR__ . '/../vendor/autoload.php';

use PDO;
use PDOStatement;

class Db
{
	public PDO $pdo;

	public function __construct()
	{
		$host = "localhost";
		$port = 3306;
		$dbname = "ts";
		$charset = "utf8mb4";
		$username = "root";
		$password = "";

		$dns = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset};";

		$this->pdo = new PDO($dns, $username, $password);
	}

	/**
	 * @param ?array<string,mixed> $parameters
	 */
	public function execute(string $query, ?array $parameters = null): PDOStatement
	{
		$statement = $this->pdo->prepare($query);

		if ($parameters === null)
		{
			$statement->execute();
		}
		else
		{
			$statement->execute($parameters);
		}

		return $statement;
	}

	/**
	 * @param ?array<string,mixed> $parameters
	 */
	public function fetchSingle(string $query, ?array $parameters = null, int $fetchMode = PDO::FETCH_DEFAULT): mixed
	{
		$statement = $this->execute($query, $parameters);

		return $statement->fetch($fetchMode);
	}

	/**
	 * @param ?array<string,mixed> $parameters
	 */
	public function executeScalar(string $query, ?array $parameters = null): mixed
	{
		return $this->execute($query, $parameters)->fetchColumn();
	}

	public function beginTransaction(): bool
	{
		return $this->pdo->beginTransaction();
	}

	public function commit(): bool
	{
		return $this->pdo->commit();
	}

	public function rollBack(): bool
	{
		return $this->pdo->rollBack();
	}
}
