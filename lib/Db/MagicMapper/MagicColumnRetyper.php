<?php

/**
 * MagicColumnRetyper: move magic table columns off a JSON type that no longer fits.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\MagicMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/magic-table-columns/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use Psr\Log\LoggerInterface;

/**
 * Retype PostgreSQL columns whose stored type no longer fits their property.
 *
 * Both retypes leave a column as it is when the statement fails, and neither
 * reorders or truncates stored data.
 *
 * @spec openspec/specs/magic-table-columns/spec.md
 */
final class MagicColumnRetyper {
	private const JSON_RETYPED = '[MagicMapper] Migrated object-typed column from JSONB to JSON for key-order preservation';

	private const RELATION_RETYPED = '[MagicMapper] Retyped relation column from JSON to VARCHAR (it now holds a UUID reference)';

	/**
	 * Constructor.
	 *
	 * @param MagicDdlRunner  $ddl    Runs the statements.
	 * @param LoggerInterface $logger Receives the outcome.
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md
	 */
	public function __construct(
		private readonly MagicDdlRunner $ddl,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Move object-typed columns from JSONB to JSON to keep client key order (#1720).
	 *
	 * JSONB hashes keys; plain JSON stores the document verbatim. Going through
	 * text keeps the order the column already held, so only new writes keep the
	 * client's order. MySQL/MariaDB JSON already keeps document order.
	 *
	 * @param TableStructureSync $sync The table sync.
	 *
	 * @return array<string> The column names retyped.
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md
	 */
	public function orderedJson(TableStructureSync $sync): array {
		if ($sync->isPostgres === false) {
			return [];
		}

		$retyped = [];
		foreach ($sync->columns->storedAs(declaredType: 'json_ordered', liveTypes: ['jsonb']) as $columnName) {
			if ($this->retypeToJson(sync: $sync, columnName: $columnName) === true) {
				$retyped[] = $columnName;
				$this->logger->debug(message: self::JSON_RETYPED, context: ['columnName' => $columnName]);
			}
		}

		return $retyped;
	}//end orderedJson()

	/**
	 * Convert JSON columns that now hold a UUID reference to VARCHAR.
	 *
	 * Deliberately non-destructive: a column that still holds documents cannot
	 * become a 255-character reference without losing data, so the failure is
	 * logged rather than forced. `#>> '{}'` extracts a JSON scalar as text, so a
	 * column holding only UUID strings or nulls converts cleanly.
	 *
	 * @param TableStructureSync $sync The table sync.
	 *
	 * @return array<string> The column names retyped.
	 *
	 * @spec exclude magic-table DDL sync
	 */
	public function relations(TableStructureSync $sync): array {
		if ($sync->isPostgres === false) {
			return [];
		}

		$retyped = [];
		foreach ($sync->columns->storedAs(declaredType: 'string', liveTypes: ['json', 'jsonb']) as $columnName) {
			if ($this->retypeToVarchar(sync: $sync, columnName: $columnName) === true) {
				$retyped[] = $columnName;
				$this->logger->debug(message: self::RELATION_RETYPED, context: ['tableName' => $sync->tableName, 'columnName' => $columnName]);
			}
		}

		return $retyped;
	}//end relations()

	/**
	 * Retype one column to JSON.
	 *
	 * @param TableStructureSync $sync       The table sync.
	 * @param string             $columnName The physical column.
	 *
	 * @return bool Whether the statement succeeded.
	 */
	private function retypeToJson(TableStructureSync $sync, string $columnName): bool {
		$column = (new MagicColumnSql(isPostgres: true))->quote(name: $columnName);

		return $this->ddl->attempt(
			sql: 'ALTER TABLE ' . $sync->tableNameQuoted . ' ALTER COLUMN ' . $column . ' TYPE JSON USING ' . $column . '::text::json',
			failure: new MagicDdlFailure(message: '[MagicMapper] Failed to migrate column from JSONB to JSON', context: ['columnName' => $columnName])
		);
	}//end retypeToJson()

	/**
	 * Retype one column to VARCHAR.
	 *
	 * @param TableStructureSync $sync       The table sync.
	 * @param string             $columnName The physical column.
	 *
	 * @return bool Whether the statement succeeded.
	 */
	private function retypeToVarchar(TableStructureSync $sync, string $columnName): bool {
		$column = (new MagicColumnSql(isPostgres: true))->quote(name: $columnName);

		return $this->ddl->attempt(
			sql: 'ALTER TABLE ' . $sync->tableNameQuoted . ' ALTER COLUMN ' . $column . ' TYPE VARCHAR(255) USING ' . $column . " #>> '{}'",
			failure: new MagicDdlFailure(
				message: '[MagicMapper] Could not retype relation column to VARCHAR; it still holds data '
					. 'that is not a UUID reference. Saves to this property will keep failing until '
					. 'the existing values are migrated.',
				context: ['tableName' => $sync->tableName, 'columnName' => $columnName]
			)
		);
	}//end retypeToVarchar()
}//end class
