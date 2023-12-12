<?php

namespace Ts;

class TableInfo
{
	// Nunca puede haber cambios.
	const ID_ERASE = 0;
	const ID_MODIFY = 1;
	// -
	const ID_BRANCH = 2;

	/**
	 * Tablas que el cliente puede ver.
	 * Los ids tienen que ser iguales en C# en el IdTableAttribute de cada clase que represente una tabla.
	 * Siempre deben estar en minúsculas.
	 * @var array<string, int>
	 */
	private static array $publicTables = [
		"erase" => self::ID_ERASE,
		"modify" => self::ID_MODIFY,

		"branch" => self::ID_BRANCH,
	];

	/**
	 * Tablas que el cliente no puede editar / borrar.
	 * Siempre deben estar en minúsculas.
	 * @var string[]
	 */
	private static array $readOnlyTables = [
		"erase",
		"modify"
	];

	/**
	 * @return string[]
	 */
	public static function getPublicTableNames(): array
	{
		return array_keys(self::$publicTables);
	}

	public static function isPublicTable(string $tableName): bool
	{
		return array_key_exists(trim(strtolower($tableName)), self::$publicTables);
	}

	public static function isReadOnlyTable(string $tableName): bool
	{
		return in_array(trim(strtolower($tableName)), self::$readOnlyTables);
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
	 * es decir, que el cliente no tiene permitido ver / editar.
	 */
	public static function isPrivateColumn(string $columnName): bool
	{
		$columnName = trim(strtolower($columnName));
		return $columnName === "idaccount";
	}

	/**
	 * Devuelve true si la columna tiene un nombre que el cliente tiene permitido leer,
	 * pero no escribir.
	 */
	public static function isReadOnlyColumn(string $columnName): bool
	{
		$columnName = trim(strtolower($columnName));
		return $columnName === "id";
	}

	public static function getTableId(string $tableName): int
	{
		return self::$publicTables[trim(strtolower($tableName))];
	}
}
