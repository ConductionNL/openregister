<?php

/**
 * MagicColumnNullability: keep the NOT NULL constraints of a magic table in step with its schema.
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
 * Bring the NOT NULL constraints of a table in line with its schema.
 *
 * @spec openspec/specs/magic-table-columns/spec.md
 */
final class MagicColumnNullability {
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
	 * Apply one direction to every required column it concerns.
	 *
	 * @param MagicNullabilityChange $change The change.
	 *
	 * @return array<string> The column names changed.
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md
	 */
	public function apply(MagicNullabilityChange $change): array {
		$changed = [];
		foreach ($change->sync->columns->shared() as [$columnName, $columnDef]) {
			if ($change->applies(columnName: $columnName, columnDef: $columnDef) === true
				&& $this->changeColumn(change: $change, column: [$columnName, $columnDef]) === true
			) {
				$changed[] = $columnName;
			}
		}

		return $changed;
	}//end apply()

	/**
	 * Change one column.
	 *
	 * @param MagicNullabilityChange $change The change.
	 * @param array                  $column Pair of column name and required definition.
	 *
	 * @return bool Whether the statement succeeded.
	 */
	private function changeColumn(MagicNullabilityChange $change, array $column): bool {
		[$columnName, $columnDef] = $column;
		$this->logger->debug(
			message: $change->announcement(),
			context: ['tableName' => $change->sync->tableName, 'columnName' => $columnName]
		);
		$sql = new MagicColumnSql(isPostgres: $change->sync->isPostgres);

		return $this->ddl->attempt(
			sql: $change->statement(columnName: $columnName, sqlType: $sql->type(type: $columnDef['type'], column: $columnDef)),
			failure: new MagicDdlFailure(message: $change->failureMessage(), context: ['columnName' => $columnName])
		);
	}//end changeColumn()
}//end class
