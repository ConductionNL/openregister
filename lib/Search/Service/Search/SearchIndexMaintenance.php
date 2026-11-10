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

namespace OCA\OpenRegister\Search\Service\Search;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use RuntimeException;
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
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Inventory, concurrent rebuild, snapshot and
 *                                                   restore of the search indexes are one job;
 *                                                   the rebuild runs on PostgreSQL only and
 *                                                   refuses elsewhere with its reason.
 */
class SearchIndexMaintenance implements SearchIndexMaintenanceInterface {
	/**
	 * The app config key holding the report of the last rebuild.
	 */
	public const LAST_RUN_KEY = 'search_index_last_run';

	/**
	 * Reads the indexes of one table, with their definitions, from the catalogue.
	 */
	private const INDEX_DEFINITIONS_SQL = 'SELECT indexname, indexdef FROM pg_indexes WHERE tablename = ? ORDER BY indexname';

	/**
	 * The report of a rebuild refused on a platform without a concurrent reindex, after its start time.
	 */
	private const REFUSED_REPORT = [
		'state' => 'refused',
		'reason' => 'This platform has no concurrent reindex. Rebuilding here would take the '
			. 'index away while it runs, and search would answer nothing rather than '
			. 'something stale.',
		'tables' => 0,
		'indexes' => 0,
		'rebuilt' => 0,
		'failed' => 0,
		'failures' => [],
	];

	/**
	 * Constructor.
	 *
	 * @param SearchIndexStores $stores    The database connection, register mapper and magic table mapper.
	 * @param IAppConfig        $appConfig Stores the report of the last run.
	 * @param IConfig           $config    Reads the configured table prefix.
	 * @param LoggerInterface   $logger    Reports progress and failure.
	 */
	public function __construct(
		private readonly SearchIndexStores $stores,
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
		return stripos($this->stores->db->getDatabasePlatform()::class, 'PostgreSQL') !== false;
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
		return array_keys($this->schemaSlugsByTable(registerId: $registerId));
	}//end tablesInScope()

	/**
	 * How many rows the tables in scope hold, in all and per schema slug.
	 *
	 * A slug of which one table cannot be counted is left out, and so is the total, so a caller never shows a
	 * number that is too low.
	 *
	 * @return array{rowsInTables?: int, indexed: array<string, int>} The counts that could be made.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-the-search-index-status-names-how-many-rows-it-covers
	 */
	public function rowCounts(): array {
		$slugs  = $this->schemaSlugsByTable(registerId: null);
		$counts = new SearchIndexRowCounts();
		foreach (array_chunk(array_keys($slugs), 200) as $chunk) {
			$rows = $this->countRows(tableNames: $chunk);
			foreach ($chunk as $table) {
				$counts->add(slug: $slugs[$table], rows: ($rows[$table] ?? null));
			}
		}

		return $counts->toArray();
	}//end rowCounts()

	/**
	 * The rows of each table, in one query for the lot, or one per table when the lot cannot be read.
	 *
	 * @param array<int, string> $tableNames The bare table names.
	 *
	 * @return array<string, int> Rows by table; a table that cannot be counted is absent.
	 */
	private function countRows(array $tableNames): array {
		$selects = $this->countSelects(tableNames: $tableNames);
		try {
			return $this->rowsByTable(sql: implode(' UNION ALL ', $selects), tableNames: $tableNames);
		} catch (Throwable) {
			$rows = [];
			foreach ($selects as $table => $sql) {
				$rows += $this->countOneTable(sql: $sql, tableName: [array_search($table, $tableNames, true) => $table]);
			}

			return $rows;
		}
	}//end countRows()

	/**
	 * One row count query per table, keyed by table.
	 *
	 * @param array<int, string> $tableNames The bare table names.
	 *
	 * @return array<string, string> The query by table.
	 */
	private function countSelects(array $tableNames): array {
		$platform = $this->stores->db->getDatabasePlatform();
		$selects  = [];
		foreach ($tableNames as $index => $table) {
			$selects[$table] = 'SELECT '.$index.' AS t, COUNT(*) AS n FROM '.$platform->quoteIdentifier($this->prefix().$table);
		}

		return $selects;
	}//end countSelects()

	/**
	 * Count one table on its own, logging rather than failing when it cannot be read.
	 *
	 * @param string             $sql       The table's row count query.
	 * @param array<int, string> $tableName The table, keyed by its position.
	 *
	 * @return array<string, int> Its rows, or nothing when it cannot be counted.
	 */
	private function countOneTable(string $sql, array $tableName): array {
		try {
			return $this->rowsByTable(sql: $sql, tableNames: $tableName);
		} catch (Throwable $exception) {
			$this->logger->warning(
				message: '[SearchIndexMaintenance] Could not count the rows of a magic table',
				context: ['file' => __FILE__, 'line' => __LINE__, 'table' => current($tableName), 'error' => $exception->getMessage()]
			);
			return [];
		}
	}//end countOneTable()

	/**
	 * Run a row count query and key its answers by table.
	 *
	 * @param string             $sql        Selects `t` (the table's position in $tableNames) and `n` (its rows).
	 * @param array<int, string> $tableNames The tables, by position.
	 *
	 * @return array<string, int> Rows by table.
	 */
	private function rowsByTable(string $sql, array $tableNames): array {
		$rows = [];
		foreach ($this->stores->db->executeQuery($sql)->fetchAll() as $row) {
			$rows[$tableNames[(int) $row['t']]] = (int) $row['n'];
		}

		return $rows;
	}//end rowsByTable()

	/**
	 * Every magic table in scope, with the slug of the schema it holds.
	 *
	 * @param int|null $registerId Limit to one register, or null for all of them.
	 *
	 * @return array<string, string> Schema slug by bare table name.
	 */
	private function schemaSlugsByTable(?int $registerId): array {
		$tables = [];
		foreach ($this->stores->registerMapper->findAll(_rbac: false, _multitenancy: false) as $register) {
			if (($register instanceof Register) === true && ($registerId === null || (int)$register->getId() === $registerId)) {
				$tables += $this->tablesOfRegister(register: $register);
			}
		}

		return $tables;
	}//end schemaSlugsByTable()

	/**
	 * The magic tables of one register, with the slug of the schema each holds.
	 *
	 * @param Register $register The register.
	 *
	 * @return array<string, string> Schema slug by bare table name.
	 */
	private function tablesOfRegister(Register $register): array {
		$tables  = [];
		$schemas = $this->stores->registerMapper->getSchemasByRegisterId(
			registerId: (int)$register->getId(),
			_rbac: false,
			_multitenancy: false
		);
		foreach ($schemas as $schema) {
			if ($schema instanceof Schema) {
				$tables += $this->tableOf(register: $register, schema: $schema);
			}
		}

		return $tables;
	}//end tablesOfRegister()

	/**
	 * The magic table of one register and schema, with the schema's slug, or nothing when it cannot be resolved.
	 *
	 * @param Register $register The register.
	 * @param Schema   $schema   The schema.
	 *
	 * @return array<string, string> At most one entry: schema slug by bare table name.
	 */
	private function tableOf(Register $register, Schema $schema): array {
		try {
			$table = $this->stores->magicMapper->getTableNameForRegisterSchema(register: $register, schema: $schema);
		} catch (Throwable $exception) {
			$this->logger->warning(
				message: '[SearchIndexMaintenance] Could not resolve a magic table',
				context: ['file' => __FILE__, 'line' => __LINE__, 'register' => $register->getId(), 'schema' => $schema->getId()]
					+ ['error' => $exception->getMessage()]
			);
			return [];
		}

		return [$table => (string) ($schema->getSlug() ?? $schema->getId())];
	}//end tableOf()

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

		$statement = $this->stores->db->prepare(self::INDEX_DEFINITIONS_SQL);
		$statement->execute([$this->prefix() . $tableName]);
		$indexes = [];
		while (($row = $statement->fetch()) !== false) {
			$indexes[(string)$row['indexname']] = (string)$row['indexdef'];
		}

		return $indexes;
	}//end indexesFor()

	/**
	 * The index names of many tables, in one read per 500 tables.
	 *
	 * The status page lists every table in scope; one pg_indexes query per
	 * table took 6.5 s for 1,688 tables on a demo instance, long enough that a
	 * settings card still showed its spinner when the page was read.
	 *
	 * @param array<int, string> $tableNames The tables, without the prefix.
	 *
	 * @return array<string, array<int, string>> Each table's index names, sorted, keyed as given.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function indexNamesFor(array $tableNames): array {
		$names = array_fill_keys(array_map(static fn (mixed $name): string => (string)$name, $tableNames), []);

		if ($names === [] || $this->supportsConcurrentRebuild() === false) {
			return $names;
		}

		$prefix = $this->prefix();
		foreach (array_chunk(array_keys($names), 500) as $chunk) {
			$prefixed = array_combine($chunk, array_map(static fn (string $name): string => $prefix . $name, $chunk));
			$names    = $this->withIndexNames(names: $names, prefixed: $prefixed);
		}

		return $names;
	}//end indexNamesFor()

	/**
	 * Add the index names of one chunk of tables, read in one query.
	 *
	 * @param array<string, array<int, string>> $names    Each table's index names so far.
	 * @param array<string, string>             $prefixed The chunk: prefixed table name by bare table name.
	 *
	 * @return array<string, array<int, string>> The names, with this chunk's added.
	 */
	private function withIndexNames(array $names, array $prefixed): array {
		$placeholders = implode(', ', array_fill(0, count($prefixed), '?'));
		$statement    = $this->stores->db->prepare(
			'SELECT tablename, indexname FROM pg_indexes WHERE tablename IN ('.$placeholders.') ORDER BY tablename, indexname'
		);
		$statement->execute(array_values($prefixed));
		while (($row = $statement->fetch()) !== false) {
			$table = array_search((string)$row['tablename'], $prefixed, true);
			if ($table !== false) {
				$names[$table][] = (string)$row['indexname'];
			}
		}

		return $names;
	}//end withIndexNames()

	/**
	 * Rebuild the indexes of every table in scope, beside the ones in use.
	 *
	 * A failure on one index leaves that index in place: `REINDEX INDEX
	 * CONCURRENTLY` either completes and swaps, or leaves the original
	 * answering and an invalid leftover beside it. The leftover is dropped
	 * here so a second run does not trip over it, and the failure is named.
	 *
	 * @param int|null             $registerId Limit to one register, or null for all.
	 * @param SearchIndexPass|null $pass       Whether to apply (false, the default, reports what it would rebuild
	 *                                         and touches nothing) and the per-index progress callback.
	 *
	 * @return array<string, mixed> The report: counts, failures and the platform verdict.
	 *
	 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
	 */
	public function rebuild(?int $registerId = null, ?SearchIndexPass $pass = null): array {
		$pass ??= new SearchIndexPass(apply: false, progress: null);
		if ($this->supportsConcurrentRebuild() === false) {
			return $this->finish(report: ['startedAt' => date('c')] + self::REFUSED_REPORT, apply: $pass->apply);
		}

		return $this->finish(report: $this->rebuildPass(registerId: $registerId, pass: $pass), apply: $pass->apply);
	}//end rebuild()

	/**
	 * Rebuild every index of the tables in scope in one pass and report on it.
	 *
	 * @param int|null        $registerId Limit to one register, or null for all.
	 * @param SearchIndexPass $pass       Whether to write, and who hears of progress.
	 *
	 * @return array<string, mixed> The report: counts, failures and the state.
	 */
	private function rebuildPass(?int $registerId, SearchIndexPass $pass): array {
		$startedAt = date('c');
		$tables   = $this->tablesInScope(registerId: $registerId);
		$planned  = $this->planFor(tables: $tables);
		$failures = $this->walkPlan(planned: $planned, pass: $pass);
		$report   = ['startedAt' => $startedAt, 'finishedAt' => date('c'), 'state' => $pass->state(failures: $failures)];
		$counts   = ['tables' => count($tables), 'indexes' => count($planned), 'rebuilt' => $pass->changed(succeeded: count($planned) - count($failures))];

		return $report + $counts + ['failed' => count($failures), 'failures' => $failures];
	}//end rebuild()

	/**
	 * Every index of the given tables, as (table, index) pairs in rebuild order.
	 *
	 * @param array<int, string> $tables The bare table names.
	 *
	 * @return array<int, array{0: string, 1: string}> The plan.
	 */
	private function planFor(array $tables): array {
		$planned = [];
		foreach ($tables as $tableName) {
			foreach (array_keys($this->indexesFor(tableName: $tableName)) as $indexName) {
				$planned[] = [$tableName, $indexName];
			}
		}

		return $planned;
	}//end planFor()

	/**
	 * Walk the plan, announcing each index and rebuilding it unless this is a dry run.
	 *
	 * @param array<int, array{0: string, 1: string}> $planned The plan.
	 * @param SearchIndexPass                  $pass    The dry-run switch and progress callback.
	 *
	 * @return array<int, array<string, string>> The failures.
	 */
	private function walkPlan(array $planned, SearchIndexPass $pass): array {
		$failures = [];
		foreach ($planned as $position => [$tableName, $indexName]) {
			$pass->announce(step: [$tableName, $indexName, $position + 1, count($planned)]);
			if ($pass->apply === true) {
				$failures = array_merge($failures, $this->reindex(tableName: $tableName, indexName: $indexName));
			}
		}

		return $failures;
	}//end walkPlan()

	/**
	 * Rebuild one index beside the one in use.
	 *
	 * @param string $tableName The bare table name, for the report.
	 * @param string $indexName The index.
	 *
	 * @return array<int, array<string, string>> The failure, or nothing when it was rebuilt.
	 */
	private function reindex(string $tableName, string $indexName): array {
		try {
			$this->stores->db->executeStatement('REINDEX INDEX CONCURRENTLY ' . $this->quoteIdentifier(name: $indexName));
			return [];
		} catch (Throwable $exception) {
			$this->dropInvalidLeftover(indexName: $indexName);
			$this->logger->error(
				message: '[SearchIndexMaintenance] Rebuild failed, the current index still answers',
				context: ['file' => __FILE__, 'line' => __LINE__, 'table' => $tableName, 'index' => $indexName, 'error' => $exception->getMessage()]
			);
			return [['table' => $tableName, 'index' => $indexName, 'error' => $exception->getMessage()]];
		}
	}//end reindex()

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
		foreach ($this->tablesInScope(registerId: $registerId) as $tableName) {
			$tables[$tableName] = $this->indexesFor(tableName: $tableName);
		}

		$snapshot = ['version' => 1, 'takenAt' => date('c'), 'prefix' => $this->prefix(), 'tables' => $tables];

		$written = file_put_contents($path, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		if ($written === false) {
			throw new RuntimeException("Could not write the index snapshot to '{$path}'.");
		}

		return ['path' => $path, 'tables' => count($tables), 'indexes' => array_sum(array_map('count', $tables)), 'bytes' => $written];
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
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The same dry-run switch as rebuild(), for the
	 *                                             same reason.
	 */
	public function restore(string $path, bool $apply = false): array {
		return (new SearchIndexSnapshotRestore(maintenance: $this, db: $this->stores->db))->restore(
			path: $path,
			pass: new SearchIndexPass(apply: $apply, progress: null)
		);
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
			$this->stores->db->executeStatement('DROP INDEX IF EXISTS ' . $this->quoteIdentifier(name: $indexName . '_ccnew'));
		} catch (Throwable $exception) {
			$this->logger->warning(
				message: '[SearchIndexMaintenance] Could not drop the leftover of a failed rebuild',
				context: ['file' => __FILE__, 'line' => __LINE__, 'index' => $indexName, 'error' => $exception->getMessage()]
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
			throw new InvalidArgumentException("Refusing to act on the index name '{$name}'.");
		}

		return '"' . $name . '"';
	}//end quoteIdentifier()
}//end class
