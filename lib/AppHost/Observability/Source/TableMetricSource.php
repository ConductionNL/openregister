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
class TableMetricSource implements MetricSourceInterface
{
    /**
     * Constructor.
     *
     * @param IDBConnection   $db     Database connection.
     * @param LoggerInterface $logger PSR logger.
     */
    public function __construct(
        private readonly IDBConnection $db,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * {@inheritDoc}
     *
     * @return string
     *
     * @spec openspec/changes/apphost-observability-engine/tasks.md#task-3.3
     */
    public function kind(): string
    {
        return 'tableCount';
    }//end kind()

    /**
     * {@inheritDoc}
     *
     * @param string           $appId      Calling app id.
     * @param MetricDescriptor $descriptor The metric descriptor.
     *
     * @return MetricSample[]
     *
     * @spec openspec/changes/apphost-observability-engine/tasks.md#task-3.3
     */
    public function collect(string $appId, MetricDescriptor $descriptor): array
    {
        $source = $descriptor->source;
        $table  = (string) ($source['table'] ?? '');
        $help   = $descriptor->help ?? sprintf('Row count of %s', $table);

        // SECURITY BOUNDARY (re-enforced): only [a-z0-9_] table names reach the
        // QueryBuilder. The descriptor validator already rejects others; this is
        // defence in depth so the source can never be misused in isolation.
        if (preg_match(MetricDescriptor::TABLE_REGEX, $table) !== 1) {
            $errorMessage = sprintf('tableCount table "%s" is not allowlisted.', $table);
            throw new ObservabilityValidationException(message: $errorMessage);
        }

        // Graceful zero-emission when the table does not exist (drained-table parity).
        if ($this->db->tableExists($table) === false) {
            return [new MetricSample(name: $descriptor->name, type: $descriptor->type, help: $help, samples: [])];
        }

        $groupBy       = array_values($source['groupBy'] ?? []);
        $labelMap      = $source['labelMap'] ?? [];
        $labelDefaults = $source['labelDefaults'] ?? [];

        try {
            if ($groupBy === []) {
                $value   = $this->countAll(table: $table, filter: $source['filter'] ?? []);
                $samples = [['labels' => [], 'value' => $value]];
                return [new MetricSample(name: $descriptor->name, type: $descriptor->type, help: $help, samples: $samples)];
            }

            $samples = $this->countGrouped(
                table: $table,
                groupBy: $groupBy,
                filter: $source['filter'] ?? [],
                labelMap: $labelMap,
                labelDefaults: $labelDefaults
            );

            return [new MetricSample(name: $descriptor->name, type: $descriptor->type, help: $help, samples: $samples)];
        } catch (Throwable $e) {
            $this->logger->warning(
                message: sprintf('[AppHost\\Metrics] tableCount "%s" (app %s) failed: %s', $descriptor->name, $appId, $e->getMessage()),
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            return [new MetricSample(name: $descriptor->name, type: $descriptor->type, help: $help, samples: [])];
        }//end try
    }//end collect()

    /**
     * COUNT(*) of the table with optional filters.
     *
     * @param string               $table  Allowlisted table name.
     * @param array<string, mixed> $filter Column filters.
     *
     * @return int
     *
     * @throws \OCP\DB\Exception On DB error.
     */
    private function countAll(string $table, array $filter): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'cnt'))->from($table);
        $this->applyFilters(qb: $qb, filter: $filter);

        $result = $qb->executeQuery();
        $count  = (int) $result->fetchOne();
        $result->closeCursor();
        return $count;
    }//end countAll()

    /**
     * COUNT(*) GROUP BY the given columns, with optional filters.
     *
     * @param string                $table         Allowlisted table name.
     * @param string[]              $groupBy       Columns to group by.
     * @param array<string, mixed>  $filter        Column filters.
     * @param array<string, string> $labelMap      Column → label rename.
     * @param array<string, scalar> $labelDefaults Default value for NULL/empty columns.
     *
     * @return array<int, array{labels: array<string,string>, value: int}>
     *
     * @throws \OCP\DB\Exception On DB error.
     */
    private function countGrouped(string $table, array $groupBy, array $filter, array $labelMap, array $labelDefaults): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'cnt'));

        foreach ($groupBy as $column) {
            $this->assertColumn(column: (string) $column);
            $qb->addSelect((string) $column)->addGroupBy((string) $column);
        }

        $qb->from($table);
        $this->applyFilters(qb: $qb, filter: $filter);

        $result  = $qb->executeQuery();
        $samples = [];
        foreach ($result->fetchAll() as $row) {
            $labels = [];
            foreach ($groupBy as $column) {
                $column = (string) $column;
                $raw    = $row[$column] ?? null;

                $value = $raw;
                if ($raw === null || $raw === '') {
                    $value = ($labelDefaults[$column] ?? '');
                }

                $labelKey = $column;
                if (isset($labelMap[$column]) === true) {
                    $labelKey = (string) $labelMap[$column];
                }

                $labels[$labelKey] = (string) $value;
            }

            $samples[] = ['labels' => $labels, 'value' => (int) ($row['cnt'] ?? 0)];
        }//end foreach

        $result->closeCursor();
        return $samples;
    }//end countGrouped()

    /**
     * Apply column filters (eq/neq/lt/lte/gt/gte/like) to the query builder.
     *
     * @param IQueryBuilder        $qb     Query builder (by reference via object).
     * @param array<string, mixed> $filter Column filters.
     *
     * @return void
     */
    private function applyFilters(IQueryBuilder $qb, array $filter): void
    {
        foreach ($filter as $column => $ops) {
            $this->assertColumn(column: (string) $column);
            foreach ($ops as $operator => $value) {
                $param = $qb->createNamedParameter($value);
                switch ($operator) {
                    case 'eq':
                        $qb->andWhere($qb->expr()->eq((string) $column, $param));
                        break;
                    case 'neq':
                        $qb->andWhere($qb->expr()->neq((string) $column, $param));
                        break;
                    case 'lt':
                        $qb->andWhere($qb->expr()->lt((string) $column, $param));
                        break;
                    case 'lte':
                        $qb->andWhere($qb->expr()->lte((string) $column, $param));
                        break;
                    case 'gt':
                        $qb->andWhere($qb->expr()->gt((string) $column, $param));
                        break;
                    case 'gte':
                        $qb->andWhere($qb->expr()->gte((string) $column, $param));
                        break;
                    case 'like':
                        $qb->andWhere($qb->expr()->like((string) $column, $param));
                        break;
                }//end switch
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
    private function assertColumn(string $column): void
    {
        if (preg_match('/^[a-zA-Z0-9_]+$/', $column) !== 1) {
            $errorMessage = sprintf('Unsafe column identifier "%s".', $column);
            throw new ObservabilityValidationException(message: $errorMessage);
        }
    }//end assertColumn()
}//end class
