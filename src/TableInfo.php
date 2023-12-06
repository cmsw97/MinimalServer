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
	 * @return array<string>
	 */
	public static function clientTableNames(): array
	{
		return array_keys(self::$clientTables);
	}
}
