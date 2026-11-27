<?php

/**
 * The live object count per register and schema, read from the magic tables.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Observability
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/production-observability/specs/production-observability/spec.md#requirement-register-schema-and-object-count-metrics
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Observability;

use OCA\OpenRegister\AppHost\IMetricsProvider;
use OCA\OpenRegister\AppHost\Observability\MetricSample;
use OCP\IConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Publishes `objects_total{register,schema}`.
 *
 * Objects live in one table per register and schema (`openregister_table_<register>_<schema>`),
 * so a count of the legacy `openregister_objects` blob table misses every object stored since
 * the move. This counts the rows of every magic table whose `_deleted` marker is unset, in
 * `UNION ALL` batches, adds the legacy rows that are not deleted, and drops the tables whose
 * register or schema no longer exists. Label values are the numeric register and schema ids.
 * A count that cannot be made publishes no samples, with a warning, never a false zero.
 *
 * @spec openspec/changes/production-observability/specs/production-observability/spec.md#requirement-register-schema-and-object-count-metrics
 */
class ObjectCountMetricsProvider implements IMetricsProvider {

	/**
	 * Magic tables counted per query.
	 *
	 * @var integer
	 */
	public const BATCH_SIZE = 100;

	/**
	 * A bare magic table name, with its register and schema id.
	 *
	 * @var string
	 */
	private const MAGIC_TABLE = '/^openregister_table_(\d+)_(\d+)$/';

	/**
	 * The legacy blob table.
	 *
	 * @var string
	 */
	private const LEGACY_TABLE = 'openregister_objects';

	/**
	 * Constructor.
	 *
	 * @param IDBConnection   $db     Reads the tables.
	 * @param IConfig         $config Holds the table prefix.
	 * @param LoggerInterface $logger Logs a count that failed.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly IConfig $config,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The object count gauge.
	 *
	 * @return array<int, MetricSample>
	 *
	 * @spec openspec/changes/production-observability/specs/production-observability/spec.md#requirement-register-schema-and-object-count-metrics
	 */
	public function metrics(): array {
		return [
			new MetricSample(
				name: 'objects_total',
				type: 'gauge',
				help: 'Total objects by register and schema',
				samples: $this->samples()
			),
		];
	}//end metrics()

	/**
	 * One sample per register and schema that holds objects, ordered by register then schema.
	 *
	 * @return array<int, array{labels: array{register: string, schema: string}, value: int}>
	 */
	private function samples(): array {
		try {
			$registers = $this->liveIds(table: 'openregister_registers');
			$schemas = $this->liveIds(table: 'openregister_schemas');
			$counts = $this->magicCounts();
			foreach ($this->legacyCounts() as $key => $count) {
				$counts[$key] = ($counts[$key] ?? 0) + $count;
			}
		} catch (Throwable $e) {
			$this->logger->warning('[Observability] object counts could not be read: '.$e->getMessage());
			return [];
		}

		ksort($counts, SORT_NATURAL);
		$samples = [];
		foreach ($counts as $key => $count) {
			[$register, $schema] = explode('|', (string) $key);
			if (isset($registers[$register], $schemas[$schema]) === false || $count === 0) {
				continue;
			}

			$samples[] = ['labels' => ['register' => $register, 'schema' => $schema], 'value' => $count];
		}

		return $samples;
	}//end samples()

	/**
	 * The ids of a table's rows, as keys.
	 *
	 * @param string $table The registers or schemas table.
	 *
	 * @return array<string, true>
	 */
	private function liveIds(string $table): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from($table);
		$result = $qb->executeQuery();
		$ids = [];
		while (($row = $result->fetch()) !== false) {
			$ids[(string) $row['id']] = true;
		}

		$result->closeCursor();
		return $ids;
	}//end liveIds()

	/**
	 * The live rows of every magic table, keyed `<register>|<schema>`.
	 *
	 * @return array<string, int>
	 */
	private function magicCounts(): array {
		$counts = [];
		foreach (array_chunk($this->magicTables(), self::BATCH_SIZE) as $batch) {
			$arms = array_map(
				static fn (array $table): string => sprintf(
					'SELECT %d AS register_id, %d AS schema_id, COUNT(*) AS cnt FROM %s WHERE _deleted IS NULL',
					$table['register'],
					$table['schema'],
					$table['name']
				),
				$batch
			);
			$stmt = $this->db->prepare(implode(' UNION ALL ', $arms));
			$result = $stmt->execute();
			foreach ($result->fetchAll() as $row) {
				$key = (string) $row['register_id'].'|'.(string) $row['schema_id'];
				$counts[$key] = ($counts[$key] ?? 0) + (int) $row['cnt'];
			}

			$result->closeCursor();
		}

		return $counts;
	}//end magicCounts()

	/**
	 * The magic tables that exist, by their prefixed name, register and schema id.
	 *
	 * Only tables of the instance's own database are listed (CatalogueScope), and only a
	 * name matching `openregister_table_<digits>_<digits>` after the prefix is
	 * returned, so nothing but those characters and the configured prefix reaches the SQL.
	 *
	 * @return array<int, array{name: string, register: int, schema: int}>
	 *
	 * @throws \RuntimeException When the configured prefix is not a plain identifier.
	 */
	private function magicTables(): array {
		$prefix = (string) $this->config->getSystemValue('dbtableprefix', 'oc_');
		if (preg_match('/^[A-Za-z0-9_]*$/', $prefix) !== 1) {
			throw new \RuntimeException('the table prefix is not a plain identifier');
		}

		$stmt = $this->db->prepare(
			'SELECT table_name FROM information_schema.tables WHERE table_name LIKE ? AND '.$this->catalogueScope()
		);
		$result = $stmt->execute([$prefix.'openregister_table_%']);
		$tables = [];
		foreach ($result->fetchAll() as $row) {
			$name = (string) ($row['table_name'] ?? $row['TABLE_NAME'] ?? '');
			if (str_starts_with($name, $prefix) === false
				|| preg_match(self::MAGIC_TABLE, substr($name, strlen($prefix)), $matches) !== 1
			) {
				continue;
			}

			$tables[$name] = ['name' => $name, 'register' => (int) $matches[1], 'schema' => (int) $matches[2]];
		}

		$result->closeCursor();
		return array_values($tables);
	}//end magicTables()

	/**
	 * The rows of the legacy blob table that are not deleted, keyed `<register>|<schema>`.
	 *
	 * @return array<string, int>
	 */
	private function legacyCounts(): array {
		if ($this->db->tableExists(self::LEGACY_TABLE) === false) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('register', 'schema')
			->selectAlias($qb->func()->count('*'), 'cnt')
			->from(self::LEGACY_TABLE)
			->where($qb->expr()->isNull('deleted'))
			->groupBy('register', 'schema');
		$result = $qb->executeQuery();
		$counts = [];
		while (($row = $result->fetch()) !== false) {
			$key = (string) $row['register'].'|'.(string) $row['schema'];
			$counts[$key] = ($counts[$key] ?? 0) + (int) $row['cnt'];
		}

		$result->closeCursor();
		return $counts;
	}//end legacyCounts()

	/**
	 * Limit an information_schema query to this instance's own database, as Db\CatalogueScope does.
	 *
	 * Restated here so the Integrations area does not name the Core class.
	 *
	 * @return string The table_schema condition for the connection's platform.
	 */
	private function catalogueScope(): string {
		try {
			$postgres = ($this->db->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform);
		} catch (Throwable) {
			// A connection that cannot name its platform fails on the query itself; the callers handle that.
			$postgres = false;
		}

		if ($postgres === true) {
			return 'table_schema = current_schema()';
		}

		return 'table_schema = DATABASE()';
	}//end catalogueScope()
}//end class
