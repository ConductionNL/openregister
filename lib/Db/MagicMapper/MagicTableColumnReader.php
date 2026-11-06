<?php

/**
 * MagicTableColumnReader: read the live columns of a magic table.
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

use Exception;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Read the live columns of a magic table from information_schema, a system
 * table that takes no prefix, so this is raw SQL.
 *
 * @spec openspec/specs/magic-table-columns/spec.md
 */
final class MagicTableColumnReader {
	/**
	 * The columns read on every platform.
	 *
	 * @var string
	 */
	private const SELECT = 'SELECT column_name, data_type, character_maximum_length, numeric_precision, numeric_scale, is_nullable, column_default';

	/**
	 * The table filter, without the schema.
	 *
	 * @var string
	 */
	private const FROM = '
                    FROM information_schema.columns
                    WHERE table_name = ? AND table_schema = ';

	/**
	 * Constructor.
	 *
	 * @param IDBConnection   $db     The connection.
	 * @param LoggerInterface $logger Receives a failure.
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The live columns of a table.
	 *
	 * @param string $tableName     The table name, for the log.
	 * @param string $fullTableName The prefixed table name.
	 *
	 * @throws Exception If the columns cannot be read.
	 *
	 * @return (bool|mixed)[][] Column definitions, keyed by column name.
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md
	 */
	public function read(string $tableName, string $fullTableName): array {
		try {
			$stmt = $this->db->prepare($this->selectSql());
			$stmt->execute([$fullTableName]);

			return $this->definitions(rows: $stmt->fetchAll());
		} catch (Exception $e) {
			$this->logger->error(
				message: '[MagicMapper] Failed to get existing table columns',
				context: ['tableName' => $tableName, 'error' => $e->getMessage()]
			);

			throw $e;
		}
	}//end read()

	/**
	 * The query for this platform's current schema. MySQL also reads `column_type`,
	 * the full type with its length, because MODIFY has to restate it; PostgreSQL has no such column.
	 *
	 * @return string The SQL.
	 */
	private function selectSql(): string {
		$platform = $this->db->getDatabasePlatform();
		if (stripos($platform::class, 'PostgreSQL') !== false) {
			return self::SELECT . self::FROM . 'current_schema()';
		}

		return self::SELECT . ', column_type' . self::FROM . 'DATABASE()';
	}//end selectSql()

	/**
	 * Column definitions from information_schema rows.
	 *
	 * @param array $rows The rows.
	 *
	 * @return (bool|mixed)[][] Column definitions, keyed by column name.
	 */
	private function definitions(array $rows): array {
		$definitions = [];
		foreach ($rows as $column) {
			$definitions[$column['column_name']] = $this->definition(column: $column);
		}

		return $definitions;
	}//end definitions()

	/**
	 * The column definition of one information_schema row.
	 *
	 * @param array $column The row.
	 *
	 * @return (bool|mixed)[] Column definition.
	 */
	private function definition(array $column): array {
		return [
			'name' => $column['column_name'],
			'type' => $column['data_type'],
			'sqlType' => $column['column_type'] ?? $column['data_type'],
			'length' => $column['character_maximum_length'],
			'precision' => $column['numeric_precision'] ?? null,
			'scale' => $column['numeric_scale'] ?? null,
			'nullable' => $column['is_nullable'] === 'YES',
			'default' => $column['column_default'],
		];
	}//end definition()
}//end class
