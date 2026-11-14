<?php

/**
 * The composite index over a magic table's identity columns.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\MagicMapper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/modelling-composite-identity/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use Exception;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Schemas\SchemaDeclarationValidators;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Backs a by-key lookup with one index over the identity's columns, in declared order.
 *
 * The index is not unique: data from before the identity was declared may
 * break it, and the uniqueness listener, not the table, refuses a new
 * duplicate. Its name carries a short hash of its columns, so an identity
 * over other columns gets a new index and the old one is recognised as
 * stale.
 *
 * @spec openspec/specs/magic-table-columns/spec.md
 * @spec openspec/changes/modelling-composite-identity/tasks.md
 */
final class MagicTableIdentityIndex {

	use MagicColumnNameTrait;

	/**
	 * The part of the name between the table and the hash.
	 *
	 * @var string
	 */
	private const INFIX = '_identity_';

	/**
	 * Build the index helper.
	 *
	 * @param IDBConnection   $db     The connection.
	 * @param LoggerInterface $logger Receives a failed statement.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The identity's columns in declared order, or null when the schema declares
	 * no identity or one of its properties has no column.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return array<int, string>|null
	 *
	 * @spec openspec/changes/modelling-composite-identity/tasks.md
	 */
	public function columns(Schema $schema): ?array {
		$identity = SchemaDeclarationValidators::uniqueConstraints()->identity(configuration: $schema->getConfiguration());
		if ($identity === null) {
			return null;
		}

		$properties = $schema->getProperties();
		$columns = [];
		foreach ($identity['properties'] as $property) {
			if (is_array($properties[$property] ?? null) === false) {
				return null;
			}

			$columns[] = $this->sanitizeColumnName(name: $property);
		}

		return $columns;
	}//end columns()

	/**
	 * The index name for these columns on this table.
	 *
	 * @param string             $tableName The table name, without prefix.
	 * @param array<int, string> $columns   The identity columns in declared order.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/modelling-composite-identity/tasks.md
	 */
	public function name(string $tableName, array $columns): string {
		return $tableName . self::INFIX . substr(md5(implode(',', $columns)), 0, 8) . '_idx';
	}//end name()

	/**
	 * Create the identity index of a table; a failure is logged, never thrown.
	 *
	 * @param MagicTableIndexRun $run    The pass, on the table.
	 * @param Schema             $schema The schema.
	 *
	 * @return string|null The index name, or null when there is none to make or it failed.
	 *
	 * @spec openspec/changes/modelling-composite-identity/tasks.md
	 */
	public function create(MagicTableIndexRun $run, Schema $schema): ?string {
		$columns = $this->columns(schema: $schema);
		if ($columns === null) {
			return null;
		}

		$sql = new MagicColumnSql(isPostgres: $run->isPostgres);
		$quoted = implode(', ', array_map(static fn (string $column): string => $sql->quote(name: $column), $columns));
		$name = $this->name(tableName: $run->tableName, columns: $columns);

		try {
			$this->db->executeStatement("CREATE INDEX IF NOT EXISTS {$name} ON {$run->fullTableName} ({$quoted})");
		} catch (Exception $e) {
			$this->logger->warning(
				message: '[MagicMapper] Could not create the identity index',
				context: ['tableName' => $run->tableName, 'index' => $name, 'error' => $e->getMessage()]
			);
			return null;
		}

		return $name;
	}//end create()

	/**
	 * The existing identity indexes of a table the schema's identity no longer asks for.
	 *
	 * @param string             $tableName The table name, without prefix.
	 * @param Schema             $schema    The schema.
	 * @param array<int, string> $existing  The index names on the table.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/modelling-composite-identity/tasks.md
	 */
	public function stale(string $tableName, Schema $schema, array $existing): array {
		$columns = $this->columns(schema: $schema);
		$wanted = null;
		if ($columns !== null) {
			$wanted = $this->name(tableName: $tableName, columns: $columns);
		}

		$pattern = '/^' . preg_quote($tableName . self::INFIX, '/') . '[0-9a-f]{8}_idx$/';

		return array_values(
			array_filter(
				$existing,
				static fn (string $name): bool => preg_match($pattern, $name) === 1 && $name !== $wanted
			)
		);
	}//end stale()
}//end class
