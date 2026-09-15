<?php

/**
 * OpenRegister SearchIndexMaintenance
 *
 * Rebuild, snapshot and restore the indexes that make search fast, without
 * making search wrong while the work runs.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Search
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Search;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The search index under administration.
 *
 * A rebuild that empties the index first makes search wrong for the length of
 * the rebuild, and a municipality notices. PostgreSQL has the primitive that
 * avoids it: `REINDEX INDEX CONCURRENTLY` builds the replacement beside the
 * index in use and swaps them when it is complete, so the worst case is a stale
 * answer rather than no answer.
 *
 * MySQL and MariaDB have no equivalent. Rather than drop and recreate, which is
 * exactly the window this exists to close, the rebuild refuses on those
 * platforms and says why. A refusal an administrator can read beats a rebuild
 * that quietly costs them their search for twenty minutes.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The work spans registers, schemas, the magic
 *                                                mapper and the connection; naming them is the
 *                                                job rather than a sign of one too many.
 */
class SearchIndexMaintenance {
	/**
	 * The app config key holding the report of the last rebuild.
	 */
	public const LAST_RUN_KEY = 'search_index_last_run';

	/**
	 * Constructor.
	 *
	 * @param IDBConnection   $db            The database connection.
	 * @param RegisterMapper  $registerMapper Resolves the registers to walk.
	 * @param MagicMapper     $magicMapper   Resolves a register and schema to its table.
	 * @param IAppConfig      $appConfig     Stores the report of the last run.
	 * @param IConfig         $config        Reads the configured table prefix.
	 * @param LoggerInterface $logger        Reports progress and failure.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly RegisterMapper $registerMapper,
		private readonly MagicMapper $magicMapper,
		private readonly IAppConfig $appConfig,
		private readonly IConfig $config,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether this platform can rebuild an index without taking it away first.
	 *
	 * @return bool True on PostgreSQL.
	 *
	 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
	 */
	public function supportsConcurrentRebuild(): bool {
		return stripos($this->db->getDatabasePlatform()::class, 'PostgreSQL') !== false;
	}//end supportsConcurrentRebuild()

	/**
	 * Every magic table in scope, as bare table names.
	 *
	 * @param int|null $registerId Limit to one register, or null for all of them.
	 *
	 * @return array<int, string> The bare table names.
	 *
	 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
	 */
	public function tablesInScope(?int $registerId = null): array {
		$tables = [];
		foreach ($this->registerMapper->findAll(_rbac: false, _multitenancy: false) as $register) {
			if (($register instanceof Register) === false) {
				continue;
			}

			if ($registerId !== null && (int)$register->getId() !== $registerId) {
				continue;
			}

			$schemas = $this->registerMapper->getSchemasByRegisterId(
				registerId: (int)$register->getId(),
				_rbac: false,
				_multitenancy: false
			);

			foreach ($schemas as $schema) {
				if (($schema instanceof Schema) === false) {
					continue;
				}

				try {
					$tables[] = $this->magicMapper->getTableNameForRegisterSchema(
						register: $register,
						schema: $schema
					);
				} catch (Throwable $exception) {
					$this->logger->warning(
						message: '[SearchIndexMaintenance] Could not resolve a magic table',
						context: [
							'file' => __FILE__,
							'line' => __LINE__,
							'register' => $register->getId(),
							'schema' => $schema->getId(),
							'error' => $exception->getMessage(),
						]
					);
				}
			}//end foreach
		}//end foreach

		return array_values(array_unique($tables));
	}//end tablesInScope()

	/**
	 * The indexes that exist on one magic table, with their definitions.
	 *
	 * Read from the catalogue rather than from the list the code would create,
	 * so a hand-made index an administrator added is snapshotted and rebuilt
	 * too, and so an index the code expects but the database lost shows up as
	 * absent rather than as present.
	 *
	 * @param string $tableName The bare table name.
	 *
	 * @return array<string, string> Index name to its CREATE statement.
	 *
	 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
	 */
	public function indexesFor(string $tableName): array {
		if ($this->supportsConcurrentRebuild() === false) {
			return [];
		}

		$fullTableName = $this->prefix() . $tableName;
		$statement = $this->db->prepare(
			'SELECT indexname, indexdef FROM pg_indexes WHERE tablename = ? ORDER BY indexname'
		);
		$statement->execute([$fullTableName]);

		$indexes = [];
		while (($row = $statement->fetch()) !== false) {
			$indexes[(string)$row['indexname']] = (string)$row['indexdef'];
		}

		return $indexes;
	}//end indexesFor()

	/**
	 * Rebuild the indexes of every table in scope, beside the ones in use.
	 *
	 * A failure on one index leaves that index in place: `REINDEX INDEX
	 * CONCURRENTLY` either completes and swaps, or leaves the original
	 * answering and an invalid leftover beside it. The leftover is dropped
	 * here so a second run does not trip over it, and the failure is named.
	 *
	 * @param int|null      $registerId Limit to one register, or null for all.
	 * @param bool          $apply      False reports what it would rebuild and touches nothing.
	 * @param callable|null $progress   Called with (table, index, position, total) per index.
	 *
	 * @phpstan-param callable(string, string, int, int): void|null $progress
	 *
	 * @psalm-param callable(string, string, int, int): void|null $progress
	 *
	 * @return array<string, mixed> The report: counts, failures and the platform verdict.
	 *
	 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) The walk is tables, then indexes, then the
	 *                                              dry-run and failure branches.
	 */
	public function rebuild(?int $registerId = null, bool $apply = false, ?callable $progress = null): array {
		$startedAt = date('c');

		if ($this->supportsConcurrentRebuild() === false) {
			return $this->finish(
				report: [
					'startedAt' => $startedAt,
					'state' => 'refused',
					'reason' => 'This platform has no concurrent reindex. Rebuilding here would take the '
						. 'index away while it runs, and search would answer nothing rather than '
						. 'something stale.',
					'tables' => 0,
					'indexes' => 0,
					'rebuilt' => 0,
					'failed' => 0,
					'failures' => [],
				],
				apply: $apply
			);
		}

		$tables = $this->tablesInScope(registerId: $registerId);
		$planned = [];
		foreach ($tables as $tableName) {
			foreach (array_keys($this->indexesFor(tableName: $tableName)) as $indexName) {
				$planned[] = [$tableName, $indexName];
			}
		}

		$total = count($planned);
		$rebuilt = 0;
		$failures = [];
		$position = 0;

		foreach ($planned as [$tableName, $indexName]) {
			$position++;
			if ($progress !== null) {
				$progress($tableName, $indexName, $position, $total);
			}

			if ($apply === false) {
				continue;
			}

			try {
				$this->db->executeStatement('REINDEX INDEX CONCURRENTLY ' . $this->quoteIdentifier(name: $indexName));
				$rebuilt++;
			} catch (Throwable $exception) {
				$failures[] = [
					'table' => $tableName,
					'index' => $indexName,
					'error' => $exception->getMessage(),
				];
				$this->dropInvalidLeftover(indexName: $indexName);
				$this->logger->error(
					message: '[SearchIndexMaintenance] Rebuild failed, the current index still answers',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'table' => $tableName,
						'index' => $indexName,
						'error' => $exception->getMessage(),
					]
				);
			}//end try
		}//end foreach

		$state = 'completed';
		if ($apply === false) {
			$state = 'planned';
		}

		if ($failures !== []) {
			$state = 'failed';
		}

		return $this->finish(
			report: [
				'startedAt' => $startedAt,
				'finishedAt' => date('c'),
				'state' => $state,
				'tables' => count($tables),
				'indexes' => $total,
				'rebuilt' => $rebuilt,
				'failed' => count($failures),
				'failures' => $failures,
			],
			apply: $apply
		);
	}//end rebuild()

	/**
	 * Write the index definitions of every table in scope to a file.
	 *
	 * A snapshot is a file an administrator can move to another node, which is
	 * the whole point of writing it out rather than keeping it in a table.
	 *
	 * @param string   $path       Where to write the snapshot.
	 * @param int|null $registerId Limit to one register, or null for all.
	 *
	 * @return array<string, mixed> The report: the path, and what it holds.
	 *
	 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
	 */
	public function snapshot(string $path, ?int $registerId = null): array {
		$tables = [];
		$indexCount = 0;
		foreach ($this->tablesInScope(registerId: $registerId) as $tableName) {
			$indexes = $this->indexesFor(tableName: $tableName);
			$tables[$tableName] = $indexes;
			$indexCount += count($indexes);
		}

		$snapshot = [
			'version' => 1,
			'takenAt' => date('c'),
			'prefix' => $this->prefix(),
			'tables' => $tables,
		];

		$written = file_put_contents($path, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		if ($written === false) {
			throw new \RuntimeException("Could not write the index snapshot to '{$path}'.");
		}

		return [
			'path' => $path,
			'tables' => count($tables),
			'indexes' => $indexCount,
			'bytes' => $written,
		];
	}//end snapshot()

	/**
	 * Recreate from a snapshot every index the database is missing.
	 *
	 * An index that is already there is left alone rather than dropped and
	 * remade: restoring is about closing a gap, not about rebuilding what
	 * already answers.
	 *
	 * @param string $path  The snapshot file to read.
	 * @param bool   $apply False reports what it would create and touches nothing.
	 *
	 * @return array<string, mixed> The report: what was missing, created and refused.
	 *
	 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
	 */
	public function restore(string $path, bool $apply = false): array {
		$raw = file_get_contents($path);
		if ($raw === false) {
			throw new \RuntimeException("Could not read the index snapshot at '{$path}'.");
		}

		$snapshot = json_decode($raw, true);
		if (is_array($snapshot) === false || is_array($snapshot['tables'] ?? null) === false) {
			throw new \RuntimeException("'{$path}' is not an index snapshot this version can read.");
		}

		$missing = [];
		$created = 0;
		$failures = [];

		foreach ($snapshot['tables'] as $tableName => $indexes) {
			$present = $this->indexesFor(tableName: (string)$tableName);
			foreach ((array)$indexes as $indexName => $definition) {
				if (isset($present[$indexName]) === true) {
					continue;
				}

				$missing[] = ['table' => (string)$tableName, 'index' => (string)$indexName];
				if ($apply === false) {
					continue;
				}

				try {
					$this->db->executeStatement((string)$definition);
					$created++;
				} catch (Throwable $exception) {
					$failures[] = [
						'table' => (string)$tableName,
						'index' => (string)$indexName,
						'error' => $exception->getMessage(),
					];
				}
			}//end foreach
		}//end foreach

		return [
			'path' => $path,
			'missing' => count($missing),
			'created' => $created,
			'failed' => count($failures),
			'failures' => $failures,
			'indexes' => $missing,
		];
	}//end restore()

	/**
	 * The report of the last rebuild, for the surface an administrator reads.
	 *
	 * @return array<string, mixed> The stored report, or an empty array before the first run.
	 *
	 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
	 */
	public function lastRun(): array {
		$stored = $this->appConfig->getValueString('openregister', self::LAST_RUN_KEY, '');
		if ($stored === '') {
			return [];
		}

		$decoded = json_decode($stored, true);
		if (is_array($decoded) === false) {
			return [];
		}

		return $decoded;
	}//end lastRun()

	/**
	 * Store the report of a run that actually ran, and hand it back.
	 *
	 * A dry run is not stored: it did nothing, and a console showing it as the
	 * last run would be reporting work that never happened.
	 *
	 * @param array $report The report to store.
	 * @param bool  $apply  Whether this run changed anything.
	 *
	 * @phpstan-param array<string, mixed> $report
	 *
	 * @psalm-param array<string, mixed> $report
	 *
	 * @return array<string, mixed> The report, unchanged.
	 */
	private function finish(array $report, bool $apply): array {
		if ($apply === true) {
			$this->appConfig->setValueString('openregister', self::LAST_RUN_KEY, (string)json_encode($report));
		}

		return $report;
	}//end finish()

	/**
	 * Drop the invalid index a failed concurrent reindex leaves behind.
	 *
	 * PostgreSQL names it `<index>_ccnew`, and it is never used for answering.
	 * Leaving it means the next rebuild fails on a name collision, which reads
	 * as the same failure twice for two different reasons.
	 *
	 * @param string $indexName The index whose rebuild failed.
	 *
	 * @return void
	 */
	private function dropInvalidLeftover(string $indexName): void {
		try {
			$this->db->executeStatement(
				'DROP INDEX IF EXISTS ' . $this->quoteIdentifier(name: $indexName . '_ccnew')
			);
		} catch (Throwable $exception) {
			$this->logger->warning(
				message: '[SearchIndexMaintenance] Could not drop the leftover of a failed rebuild',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'index' => $indexName,
					'error' => $exception->getMessage(),
				]
			);
		}
	}//end dropInvalidLeftover()

	/**
	 * The configured table prefix.
	 *
	 * @return string The prefix, `oc_` unless the install changed it.
	 */
	private function prefix(): string {
		return (string)$this->config->getSystemValue('dbtableprefix', 'oc_');
	}//end prefix()

	/**
	 * Quote an identifier for PostgreSQL, refusing anything unexpected.
	 *
	 * Index names come from the catalogue rather than from a request, so this
	 * is a belt on top of braces. It is here because the statement cannot be
	 * parameterised: an identifier is not a value.
	 *
	 * @param string $name The identifier.
	 *
	 * @throws \InvalidArgumentException When the name is not a plain identifier.
	 *
	 * @return string The quoted identifier.
	 */
	private function quoteIdentifier(string $name): string {
		if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
			throw new \InvalidArgumentException("Refusing to act on the index name '{$name}'.");
		}

		return '"' . $name . '"';
	}//end quoteIdentifier()
}//end class
