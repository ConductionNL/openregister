<?php

/**
 * MagicJsonColumnNarrower: move the JSON columns of existing magic tables from LONGTEXT to checked MEDIUMTEXT.
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
 * @spec openspec/specs/magic-table-columns/spec.md#requirement-a-json-column-does-not-slow-every-schema-read-of-the-instance-req-mtc-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use Doctrine\DBAL\Platforms\MariaDBPlatform;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCP\IConfig;
use OCP\IDBConnection;

/**
 * Narrow the JSON columns a magic table was created with on MariaDB.
 *
 * A table created before JSON was declared as checked MEDIUMTEXT holds it in
 * LONGTEXT, and each such column costs every schema read of the instance one
 * scan of every table's constraints (see {@see MagicColumnSql}). One ALTER per
 * table restates all of its JSON columns. It rebuilds the table, so the caller
 * decides how large a table it accepts and for how long it runs.
 *
 * @spec openspec/specs/magic-table-columns/spec.md#requirement-a-json-column-does-not-slow-every-schema-read-of-the-instance-req-mtc-003
 */
class MagicJsonColumnNarrower {
	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db     The database connection.
	 * @param IConfig       $config The system configuration, for the table prefix.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly IConfig $config,
	) {
	}//end __construct()

	/**
	 * Whether the platform stores JSON in a column this class narrows: MariaDB only.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md#requirement-a-json-column-does-not-slow-every-schema-read-of-the-instance-req-mtc-003
	 */
	public function applies(): bool {
		return $this->db->getDatabasePlatform() instanceof MariaDBPlatform;
	}//end applies()

	/**
	 * The magic tables that still hold JSON in LONGTEXT, smallest first.
	 *
	 * Three catalogue queries for the whole instance, and only the first when
	 * nothing is left to narrow.
	 *
	 * @return array<string, array{bytes: int, columns: list<array{name: string, nullable: bool, default: ?string}>}>
	 *         The JSON columns and data size per full table name.
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md#requirement-a-json-column-does-not-slow-every-schema-read-of-the-instance-req-mtc-003
	 */
	public function pending(): array {
		$longText = $this->rows(
			sql: 'SELECT table_name AS t, column_name AS c, is_nullable AS n, column_default AS d FROM information_schema.columns'
				. " WHERE table_schema = DATABASE() AND table_name LIKE ? AND column_type = 'longtext'"
		);
		if ($longText === []) {
			return [];
		}

		$checked = $this->jsonCheckedColumns();
		$pending = [];
		foreach ($longText as $row) {
			$table = (string)$row['t'];
			// LONGTEXT without the check was not declared as JSON; its size is not ours to cap.
			if (isset($checked[$table][(string)$row['c']]) === false) {
				continue;
			}

			$pending[$table]['columns'][] = [
				'name' => (string)$row['c'],
				'nullable' => ($row['n'] !== 'NO'),
				'default' => $this->defaultOf(value: $row['d']),
			];
		}

		return $this->smallestFirst(pending: $pending);
	}//end pending()

	/**
	 * Narrow the pending tables, within the given limits.
	 *
	 * @param int|null   $maxTableBytes Tables holding more data than this are left; null narrows any size.
	 * @param float|null $budgetSeconds Stop starting new tables after this long; null never stops.
	 *
	 * @return array{narrowed: list<string>, failed: array<string, string>, left: list<string>}
	 *         Full table names: narrowed, failed with the reason, and left for a later run.
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md#requirement-a-json-column-does-not-slow-every-schema-read-of-the-instance-req-mtc-003
	 */
	public function narrowPending(?int $maxTableBytes=null, ?float $budgetSeconds=null): array {
		$result = ['narrowed' => [], 'failed' => [], 'left' => []];
		$pending = $this->pending();
		if ($pending === []) {
			return $result;
		}

		$started = microtime(true);
		$this->db->executeStatement('SET @openregister_sql_mode = @@SESSION.sql_mode');
		// Without strict mode MariaDB truncates a value that does not fit, with only a warning.
		$this->db->executeStatement("SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'STRICT_ALL_TABLES')");
		try {
			foreach ($pending as $table => $entry) {
				$overBudget = ($budgetSeconds !== null && (microtime(true) - $started) >= $budgetSeconds);
				if ($overBudget === true || ($maxTableBytes !== null && $entry['bytes'] > $maxTableBytes)) {
					$result['left'][] = $table;
					continue;
				}

				try {
					$this->narrow(table: $table, columns: $entry['columns']);
					$result['narrowed'][] = $table;
				} catch (\Throwable $e) {
					$result['failed'][$table] = $e->getMessage();
				}
			}
		} finally {
			$this->db->executeStatement('SET SESSION sql_mode = @openregister_sql_mode');
		}//end try

		return $result;
	}//end narrowPending()

	/**
	 * Restate the JSON columns of one table as checked MEDIUMTEXT, in one statement.
	 *
	 * @param string                                                      $table   The full table name.
	 * @param list<array{name: string, nullable: bool, default: ?string}> $columns The JSON columns.
	 *
	 * @return void
	 *
	 * @throws \Throwable When the database refuses, for one on a value larger than MEDIUMTEXT holds.
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md#requirement-a-json-column-does-not-slow-every-schema-read-of-the-instance-req-mtc-003
	 */
	public function narrow(string $table, array $columns): void {
		$sql = new MagicColumnSql(isPostgres: false, jsonAsText: true);
		$changes = [];
		foreach ($columns as $column) {
			$change = 'MODIFY ' . $sql->quote(name: $column['name']) . ' ' . $sql->type(type: 'json', column: $column);
			if ($column['nullable'] === false) {
				$change .= ' NOT NULL';
			}

			if ($column['default'] !== null) {
				$change .= ' DEFAULT ' . $column['default'];
			}

			$changes[] = $change . $sql->check(type: 'json', name: $column['name']);
		}

		$this->db->executeStatement('ALTER TABLE ' . $sql->quote(name: $table) . ' ' . implode(', ', $changes));
	}//end narrow()

	/**
	 * The columns of magic tables that carry the json_valid check of a JSON declaration.
	 *
	 * @return array<string, array<string, true>> Column names per full table name.
	 */
	private function jsonCheckedColumns(): array {
		$rows = $this->rows(
			sql: 'SELECT table_name AS t, constraint_name AS c, check_clause AS k FROM information_schema.check_constraints'
				. ' WHERE constraint_schema = DATABASE() AND table_name LIKE ?'
		);

		$checked = [];
		foreach ($rows as $row) {
			// A column check is named after its column.
			if ((string)$row['k'] === 'json_valid(`' . $row['c'] . '`)') {
				$checked[(string)$row['t']][(string)$row['c']] = true;
			}
		}

		return $checked;
	}//end jsonCheckedColumns()

	/**
	 * Add each table's data size and order the tables smallest first.
	 *
	 * @param array<string, array{columns: list<array>}> $pending The JSON columns per full table name.
	 *
	 * @return array<string, array{bytes: int, columns: list<array>}>
	 */
	private function smallestFirst(array $pending): array {
		if ($pending === []) {
			return [];
		}

		$rows = $this->rows(
			sql: 'SELECT table_name AS t, data_length AS b FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE ?'
		);
		foreach ($rows as $row) {
			if (isset($pending[(string)$row['t']]) === true) {
				$pending[(string)$row['t']]['bytes'] = (int)$row['b'];
			}
		}

		foreach ($pending as $table => $entry) {
			$pending[$table]['bytes'] = ($entry['bytes'] ?? 0);
		}

		uasort($pending, static fn (array $a, array $b): int => ($a['bytes'] <=> $b['bytes']));

		return $pending;
	}//end smallestFirst()

	/**
	 * A column default as the catalogue writes it, ready to restate; null when there is none.
	 *
	 * @param mixed $value The catalogue's `column_default`.
	 *
	 * @return string|null
	 */
	private function defaultOf(mixed $value): ?string {
		if ($value === null || strtoupper((string)$value) === 'NULL') {
			return null;
		}

		return (string)$value;
	}//end defaultOf()

	/**
	 * Run a catalogue query restricted to the magic tables.
	 *
	 * @param string $sql The query, with one placeholder for the table name pattern.
	 *
	 * @return list<array<string, mixed>> The rows.
	 */
	private function rows(string $sql): array {
		$prefix = (string)$this->config->getSystemValue('dbtableprefix', 'oc_');
		$stmt = $this->db->prepare($sql);
		// Escaped: `_` is a wildcard in LIKE, and the prefix and the table name are full of them.
		$stmt->execute([addcslashes($prefix . MagicMapperInterface::TABLE_PREFIX, '\\_%') . '%']);

		return $stmt->fetchAll();
	}//end rows()
}//end class
