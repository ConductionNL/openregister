<?php

/**
 * OpenRegister AppHost — Table Metric Source
 *
 * Executes `tableCount` metric descriptors via the Nextcloud QueryBuilder:
 * COUNT (optionally GROUP BY columns, filtered by column operators) over an
 * own-table, with `labelMap` column→label renames and `labelDefaults` for
 * NULL/empty columns. The `tableCount.table` allowlist regex is RE-ENFORCED
 * here (defence in depth) and the name is passed through the QueryBuilder
 * table API — never string-concatenated. Missing tables emit zero samples.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\AppHost\Observability\Source
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Observability\Source;

use OCA\OpenRegister\AppHost\Observability\MetricDescriptor;
use OCA\OpenRegister\AppHost\Observability\MetricSample;
use OCA\OpenRegister\AppHost\Observability\MetricSourceInterface;
use OCA\OpenRegister\AppHost\Observability\ObservabilityValidationException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Table-count source via QueryBuilder (aggregate-only, allowlisted table).
 *
 * @spec openspec/changes/apphost-observability-engine/tasks.md#task-3.3
 */
class TableMetricSource implements MetricSourceInterface {
	/**
	 * The filter operators, each the name of the expression builder method it becomes.
	 *
	 * @var string[]
	 */
	private const COMPARISONS = ['eq', 'neq', 'lt', 'lte', 'gt', 'gte', 'like'];

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 *
	 * @spec openspec/changes/apphost-observability-engine/tasks.md#task-3.3
	 */
	public function kind(): string {
		return 'tableCount';
	}//end kind()

	/**
	 * {@inheritDoc}
	 *
	 * @param string $appId Calling app id.
	 * @param MetricDescriptor $descriptor The metric descriptor.
	 *
	 * @return MetricSample[]
	 *
	 * @spec openspec/changes/apphost-observability-engine/tasks.md#task-3.3
	 */
	public function collect(string $appId, MetricDescriptor $descriptor): array {
		$table = (string)($descriptor->source['table'] ?? '');
		$help  = $descriptor->help ?? sprintf('Row count of %s', $table);

		self::assertTableAllowed(table: $table);

		// Graceful zero-emission when the table does not exist (drained-table parity).
		$samples = [];
		if ($this->db->tableExists($table) === true) {
			$samples = $this->countedSamples(appId: $appId, descriptor: $descriptor);
		}

		return [new MetricSample(name: $descriptor->name, type: $descriptor->type, help: $help, samples: $samples)];
	}//end collect()

	/**
	 * Refuse a table name outside [a-z0-9_].
	 *
	 * SECURITY BOUNDARY (re-enforced): only [a-z0-9_] table names reach the
	 * QueryBuilder. The descriptor validator already rejects others; this is
	 * defence in depth so the source can never be misused in isolation.
	 *
	 * @param string $table The descriptor's table.
	 *
	 * @return void
	 *
	 * @throws ObservabilityValidationException When the table is not allowlisted.
	 */
	private static function assertTableAllowed(string $table): void {
		if (preg_match(MetricDescriptor::TABLE_REGEX, $table) !== 1) {
			$errorMessage = sprintf('tableCount table "%s" is not allowlisted.', $table);
			throw new ObservabilityValidationException(message: $errorMessage);
		}
	}//end assertTableAllowed()

	/**
	 * The samples of an existing table; none, logged, when counting fails.
	 *
	 * @param string           $appId      Calling app id.
	 * @param MetricDescriptor $descriptor The metric descriptor.
	 *
	 * @return array<int, array{labels: array<string, string>, value: int}>
	 */
	private function countedSamples(string $appId, MetricDescriptor $descriptor): array {
		$groupBy = array_values($descriptor->source['groupBy'] ?? []);

		try {
			return $this->samplesOf(descriptor: $descriptor, groupBy: $groupBy);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: sprintf('[AppHost\\Metrics] tableCount "%s" (app %s) failed: %s', $descriptor->name, $appId, $e->getMessage()),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return [];
		}
	}//end countedSamples()

	/**
	 * Count the table, as one sample or one per group.
	 *
	 * @param MetricDescriptor $descriptor The metric descriptor.
	 * @param string[]         $groupBy    Columns to group by; none for one total.
	 *
	 * @return array<int, array{labels: array<string, string>, value: int}>
	 *
	 * @throws \OCP\DB\Exception On DB error.
	 */
	private function samplesOf(MetricDescriptor $descriptor, array $groupBy): array {
		$source = $descriptor->source;
		$count  = new TableCount(table: (string)($source['table'] ?? ''), filter: ($source['filter'] ?? []));
		if ($groupBy === []) {
			return [['labels' => [], 'value' => $this->countAll(count: $count)]];
		}

		$labels = new TableLabels(columns: $groupBy, map: ($source['labelMap'] ?? []), defaults: ($source['labelDefaults'] ?? []));
		return $this->groupedSamples(count: $count, labels: $labels);
	}//end samplesOf()

	/**
	 * One sample per group of the table.
	 *
	 * @param TableCount  $count  The allowlisted table and its column filters.
	 * @param TableLabels $labels The columns grouped by and how they become labels.
	 *
	 * @return array<int, array{labels: array<string, string>, value: int}>
	 *
	 * @throws \OCP\DB\Exception On DB error.
	 */
	private function groupedSamples(TableCount $count, TableLabels $labels): array {
		$samples = [];
		foreach ($this->countGrouped(count: $count, columns: $labels->columns) as $row) {
			$samples[] = [
				'labels' => $this->labelsOf(row: $row, labels: $labels),
				'value' => (int)($row['cnt'] ?? 0),
			];
		}

		return $samples;
	}//end groupedSamples()

	/**
	 * COUNT(*) of the table with optional filters.
	 *
	 * @param TableCount $count The allowlisted table and its column filters.
	 *
	 * @return int
	 *
	 * @throws \OCP\DB\Exception On DB error.
	 */
	private function countAll(TableCount $count): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))->from($count->table);
		$this->applyFilters(qb: $qb, filter: $count->filter);

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}//end countAll()

	/**
	 * COUNT(*) GROUP BY the given columns, with optional filters.
	 *
	 * @param TableCount $count   The allowlisted table and its column filters.
	 * @param string[]   $columns Columns to group by.
	 *
	 * @return array<int, array<string, mixed>> The grouped rows, each with its `cnt`.
	 *
	 * @throws \OCP\DB\Exception On DB error.
	 */
	private function countGrouped(TableCount $count, array $columns): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'));

		foreach ($columns as $column) {
			$this->assertColumn(column: (string)$column);
			$qb->addSelect((string)$column)->addGroupBy((string)$column);
		}

		$qb->from($count->table);
		$this->applyFilters(qb: $qb, filter: $count->filter);

		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();
		return $rows;
	}//end countGrouped()

	/**
	 * The sample labels of one grouped row.
	 *
	 * @param array<string, mixed> $row One grouped row.
	 * @param TableLabels $labels The columns grouped by, the column → label rename and the defaults for NULL/empty columns.
	 *
	 * @return array<string, string>
	 */
	private function labelsOf(array $row, TableLabels $labels): array {
		$out = [];
		foreach ($labels->columns as $column) {
			$column = (string)$column;
			$raw = $row[$column] ?? null;

			$value = $raw;
			if ($raw === null || $raw === '') {
				$value = ($labels->defaults[$column] ?? '');
			}

			$labelKey = $column;
			if (isset($labels->map[$column]) === true) {
				$labelKey = (string)$labels->map[$column];
			}

			$out[$labelKey] = (string)$value;
		}

		return $out;
	}//end labelsOf()

	/**
	 * Apply column filters (eq/neq/lt/lte/gt/gte/like) to the query builder.
	 *
	 * @param IQueryBuilder $qb Query builder (by reference via object).
	 * @param array<string, mixed> $filter Column filters.
	 *
	 * @return void
	 */
	private function applyFilters(IQueryBuilder $qb, array $filter): void {
		foreach ($filter as $column => $ops) {
			$this->assertColumn(column: (string)$column);
			foreach ($ops as $operator => $value) {
				$param = $qb->createNamedParameter($value);
				if (in_array($operator, self::COMPARISONS, true) === true) {
					$qb->andWhere($qb->expr()->{$operator}((string)$column, $param));
				}
			}//end foreach
		}//end foreach
	}//end applyFilters()

	/**
	 * Assert a column name is a safe identifier before it touches the builder.
	 *
	 * @param string $column Column name.
	 *
	 * @return void
	 *
	 * @throws ObservabilityValidationException When the column name is unsafe.
	 */
	private function assertColumn(string $column): void {
		if (preg_match('/^[a-zA-Z0-9_]+$/', $column) !== 1) {
			$errorMessage = sprintf('Unsafe column identifier "%s".', $column);
			throw new ObservabilityValidationException(message: $errorMessage);
		}
	}//end assertColumn()
}//end class
