<?php

/**
 * WithholdRevealValuesFromRelations: takes explicit-reveal values out of stored relations indexes.
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/authorization-rbac/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\Command\MagicTableSelection;
use OCA\OpenRegister\Command\MagicTableTarget;
use OCA\OpenRegister\Db\MagicMapper\MagicColumnNameTrait;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\SaveObject\RevealRelations;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Repair step: rebuild the `_relations` index of stored rows of schemas with
 * explicit-reveal properties, without the values of those properties.
 *
 * A save no longer puts an explicit-reveal value in the index. Rows stored
 * before that still carry it. For an index keyed by property path, the paths
 * under an explicit-reveal property are dropped; for an index stored as a plain
 * list, the values the row holds in its explicit-reveal columns are dropped.
 * A row whose index holds none of them is not written, so a second run
 * changes nothing.
 *
 * Never throws: one unreadable table must not abort an upgrade.
 *
 * @spec openspec/specs/authorization-rbac/spec.md
 */
class WithholdRevealValuesFromRelations implements IRepairStep {
	use MagicColumnNameTrait;

	/**
	 * Rows read per page.
	 */
	private const PAGE = 500;

	/**
	 * Constructor.
	 *
	 * @param MagicTableSelection $selection The magic tables.
	 * @param IDBConnection       $db        The connection.
	 * @param LoggerInterface     $logger    Logger.
	 */
	public function __construct(
		private readonly MagicTableSelection $selection,
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Human-readable step name surfaced in occ and the admin UI.
	 *
	 * @return string The step name.
	 *
	 * @spec openspec/specs/authorization-rbac/spec.md
	 */
	public function getName(): string {
		return 'Take the values of explicit-reveal properties out of the stored relations index';
	}//end getName()

	/**
	 * Rebuild the relations index of every table of a schema with explicit-reveal properties.
	 *
	 * @param IOutput $output Upgrade output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/authorization-rbac/spec.md
	 */
	public function run(IOutput $output): void {
		try {
			$schemas = array_filter(
				$this->selection->schemas(schemaRef: null),
				static fn (Schema $schema): bool => RevealRelations::explicitProperties(schema: $schema) !== []
			);
			$tables = $this->selection->tables(registers: $this->selection->registers(registerRef: null), schemas: $schemas);
		} catch (Throwable $e) {
			$output->warning('Could not list the object tables: ' . $e->getMessage());
			return;
		}

		$rewritten = 0;
		foreach ($tables as $target) {
			try {
				$rewritten += $this->rebuildTable(target: $target);
			} catch (Throwable $e) {
				$this->logger->warning(
					message: '[WithholdRevealValuesFromRelations] Skipped a table',
					context: ['table' => $target->tableName, 'exception' => $e->getMessage()]
				);
				$output->warning($target->label() . ': skipped, ' . $e->getMessage());
			}
		}

		$output->info(sprintf('Rebuilt the relations index of %d stored object(s).', $rewritten));
	}//end run()

	/**
	 * Rebuild the relations index of one table, page by page.
	 *
	 * @param MagicTableTarget $target The table.
	 *
	 * @return int The rows written.
	 *
	 * @spec openspec/specs/authorization-rbac/spec.md
	 */
	private function rebuildTable(MagicTableTarget $target): int {
		$properties = RevealRelations::explicitProperties(schema: $target->schema);
		$columns = array_map(fn (string $name): string => $this->sanitizeColumnName(name: $name), $properties);

		$rewritten = 0;
		$lastId = 0;
		do {
			$rows = $this->page(tableName: $target->tableName, afterId: $lastId);
			foreach ($rows as $row) {
				$lastId = (int)$row['_id'];
				$relations = RevealRelations::withoutRevealValues(
					relations: self::decode(value: $row['_relations'] ?? null),
					properties: $properties,
					values: self::revealValues(row: $row, columns: $columns)
				);
				if ($relations !== null) {
					$this->write(tableName: $target->tableName, rowId: $lastId, relations: $relations);
					$rewritten++;
				}
			}
		} while (count($rows) === self::PAGE);

		return $rewritten;
	}//end rebuildTable()

	/**
	 * The string values a row holds in its explicit-reveal columns, nested values included.
	 *
	 * @param array        $row     The row.
	 * @param list<string> $columns The explicit-reveal column names.
	 *
	 * @return list<string> The values.
	 *
	 * @spec openspec/specs/authorization-rbac/spec.md
	 */
	private static function revealValues(array $row, array $columns): array {
		$values = [];
		foreach ($columns as $column) {
			$value = $row[$column] ?? null;
			if (is_string($value) === true) {
				$decoded = json_decode($value, true);
				if (is_array($decoded) === true) {
					$value = $decoded;
				}
			}

			if (is_array($value) === true) {
				array_walk_recursive($value, static function (mixed $leaf) use (&$values): void {
					if (is_string($leaf) === true && $leaf !== '') {
						$values[] = $leaf;
					}
				});
				continue;
			}

			if (is_string($value) === true && $value !== '') {
				$values[] = $value;
			}
		}//end foreach

		return $values;
	}//end revealValues()

	/**
	 * A stored relations index as an array.
	 *
	 * @param mixed $value The column value.
	 *
	 * @return array|null The index, or null when it is empty or not JSON.
	 *
	 * @spec openspec/specs/authorization-rbac/spec.md
	 */
	private static function decode(mixed $value): ?array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_string($value) === false || $value === '') {
			return null;
		}

		$decoded = json_decode($value, true);
		if (is_array($decoded) === true) {
			return $decoded;
		}

		return null;
	}//end decode()

	/**
	 * One page of whole rows that carry a relations index.
	 *
	 * @param string $tableName The magic table.
	 * @param int    $afterId   The last id of the previous page.
	 *
	 * @return list<array> The rows.
	 *
	 * @spec openspec/specs/authorization-rbac/spec.md
	 */
	private function page(string $tableName, int $afterId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($tableName)
			->where($qb->expr()->gt('_id', $qb->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNotNull('_relations'))
			->orderBy('_id', 'ASC')
			->setMaxResults(self::PAGE);

		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		return $rows;
	}//end page()

	/**
	 * Store a row's rebuilt relations index.
	 *
	 * @param string $tableName The magic table.
	 * @param int    $rowId     The row id.
	 * @param array  $relations The index.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/authorization-rbac/spec.md
	 */
	private function write(string $tableName, int $rowId, array $relations): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($tableName)
			->set('_relations', $qb->createNamedParameter(json_encode($relations)))
			->where($qb->expr()->eq('_id', $qb->createNamedParameter($rowId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}//end write()
}//end class
