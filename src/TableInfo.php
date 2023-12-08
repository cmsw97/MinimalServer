<?php

namespace Ts;

class TableInfo
{
	// Tablas que se pueden enviar al cliente.
	// Los identificadores tienen que replicarse en TableInfo.cs TableIdentifier.
	// Nunca puede haber cambios.
	// Siempre deben estar en minúsculas.
	/** @var array<string, int> */
	private static array $clientTables = [
		"branch" => 1,
		"del" => 2,
	];

	/**
	 * @return string[]
	 */
	public static function getPublicTableNames(): array
	{
		return array_keys(self::$clientTables);
	}

	public static function isPublicTable(string $tableName): bool
	{
		return array_key_exists($tableName, self::$clientTables);
	}

	/**
	 * Se usa como medida de seguridad para que el cliente no intente hacer SQL inyection.
	 */
	public static function isValidColumnName(string $columnName): bool
	{
		return ctype_alnum($columnName);
	}

	/**
	 * Devuelve true si la columna tiene un nombre que se considera privado,
	 * es decir, que el cliente no tiene permitido alterar.
	 */
	public static function isPrivateColumn(string $columnName): bool
	{
		$columnName = strtolower(trim($columnName));
		return $columnName === "idaccount";
	}

	/**
	 * Devuelve true si la columna tiene un nombre que el cliente tiene permitido leer,
	 * pero no escribir.
	 */
	public static function isReadOnlyColumn(string $columnName): bool
	{
		$columnName = strtolower(trim($columnName));
		return $columnName === "id";
	}
}
