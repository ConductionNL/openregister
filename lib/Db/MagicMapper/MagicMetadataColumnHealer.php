<?php

/**
 * MagicMetadataColumnHealer: keep every magic table's metadata columns present.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Db\MagicMapper
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
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper;
use OCP\ICache;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Finds and adds the metadata columns (`_archived`, `_frozen`, `_status`, ...)
 * a magic table lacks.
 *
 * Why this exists. The object list puts `_archived IS NULL` on every query, so
 * a table without that column turns every list of it into an SQL error. The
 * columns used to arrive only through the `AddObjectStateColumns` repair step,
 * which runs only when the app version changes. Measured on a development
 * instance on 14 October 2026: 38 of 1,712 magic tables lacked `_archived`,
 * `_frozen` and `_status`, and Postgres logged `column t._archived does not
 * exist` on almost every request. `occ openregister:tables:reconcile` said
 * "No drift" because it only walked the schemas a register still lists, and
 * 37 of the 38 tables were not among them.
 *
 * This class is the ONE place that decides which metadata columns a table is
 * missing and adds them. Three callers share it so they cannot drift apart:
 * the request path (MagicTableHandler::existsTableForRegisterSchema()), the
 * `openregister:tables:reconcile` command and the AddObjectStateColumns
 * repair step.
 *
 * Cost on the request path: ensure() answers from a per-process memo after the
 * first call for a table, and from the distributed cache (keyed by a version
 * hash of the metadata column list) across requests. Only a cold cache costs
 * one information_schema query for that table.
 *
 * Concurrency: two requests may try to add the same column. On PostgreSQL the
 * statement carries `IF NOT EXISTS`; elsewhere a failed ALTER is followed by a
 * re-read of the columns, and a column that is now present counts as added.
 *
 * Failure: when a column cannot be added (no ALTER permission, a table locked
 * for too long), the table is remembered as degraded in the distributed cache
 * for FAILURE_TTL seconds. The warning is logged once per degraded period, not
 * per request, and the search handler leaves the missing column out of the
 * query where that is safe (see missingFor()).
 *
 * @spec openspec/specs/object-lifecycle/spec.md#requirement-a-magic-table-missing-metadata-columns-heals-itself-on-first-use-req-mth-001
 */
class MagicMetadataColumnHealer {

	/**
	 * How long a healthy answer is trusted across requests, in seconds.
	 *
	 * A table restored from an old backup after this answer was cached is
	 * healed at most this long after the restore. Short enough to bound that,
	 * long enough that the information_schema query is rare.
	 *
	 * @var int
	 */
	public const HEALTHY_TTL = 3600;

	/**
	 * How long a table that could not be healed is left alone, in seconds.
	 *
	 * @var int
	 */
	public const FAILURE_TTL = 900;

	/**
	 * Per-process memo: bare table name => metadata columns still missing.
	 *
	 * An empty list means the table is complete. Static so a second MagicMapper
	 * built in the same process shares it, as the other MagicMapper memos do.
	 *
	 * @var array<string, list<string>>
	 */
	private static array $verified = [];

	/**
	 * Tables already warned about in this process.
	 *
	 * @var array<string, true>
	 */
	private static array $warned = [];

	/**
	 * The version hash of the metadata column list, computed once.
	 *
	 * @var string|null
	 */
	private ?string $version = null;

	/**
	 * Constructor.
	 *
	 * @param IDBConnection   $db          Connection the ALTER statements run on.
	 * @param LoggerInterface $logger      Logger.
	 * @param MagicMapper     $magicMapper Column definitions, column introspection and DDL rendering.
	 * @param ICache|null     $cache       Distributed cache shared across requests, null for none.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
		private readonly MagicMapper $magicMapper,
		private readonly ?ICache $cache = null,
	) {
	}//end __construct()

	/**
	 * Make sure a table has its metadata columns; cheap after the first call.
	 *
	 * Never throws. Returns the metadata columns the table still lacks, which
	 * is empty in the normal case.
	 *
	 * @param string $tableName Bare magic table name (without the database prefix).
	 *
	 * @return list<string> Metadata column names still missing after the attempt.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-a-magic-table-missing-metadata-columns-heals-itself-on-first-use-req-mth-001
	 */
	public function ensure(string $tableName): array {
		if (isset(self::$verified[$tableName]) === true) {
			return self::$verified[$tableName];
		}

		$cached = $this->readCache(tableName: $tableName);
		if ($cached !== null) {
			self::$verified[$tableName] = $cached;
			return $cached;
		}

		try {
			$missing = $this->inspect(tableName: $tableName);
		} catch (\Throwable $e) {
			// The columns could not be read. Do not guess and do not cache:
			// the query that follows reports the real problem, and the next
			// request tries again.
			$this->logger->debug(
				message: '[MagicMetadataColumnHealer] Could not read columns of '.$tableName.': '.$e->getMessage()
			);
			return [];
		}

		if ($missing === []) {
			$this->remember(tableName: $tableName, missing: []);
			return [];
		}

		if ($this->mayAlterNow() === false) {
			// A DDL statement inside an open transaction commits it on MySQL
			// and MariaDB. Leave this request alone (the search handler skips
			// the absent column) and heal on the next request instead.
			self::$verified[$tableName] = array_keys($missing);
			return self::$verified[$tableName];
		}

		$result = $this->heal(tableName: $tableName, missing: $missing);

		return array_keys($result['failed']);
	}//end ensure()

	/**
	 * The metadata columns a table lacks, read fresh from the database.
	 *
	 * Writes nothing and consults no cache. Columns that cannot be added to an
	 * existing table (the primary key) are included; heal() reports them as
	 * failed.
	 *
	 * @param string $tableName Bare magic table name.
	 *
	 * @return array<string, array> Missing column definitions keyed by column name.
	 *
	 * @throws \Throwable When the table's columns cannot be read.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-reconcile-reports-and-repairs-missing-metadata-columns-req-mth-003
	 */
	public function inspect(string $tableName): array {
		$current = $this->magicMapper->getExistingTableColumns(tableName: $tableName);

		$missing = [];
		foreach ($this->magicMapper->getMetadataColumnDefinitions() as $definition) {
			$name = (string)($definition['name'] ?? '');
			if ($name === '' || isset($current[$name]) === true) {
				continue;
			}

			$missing[$name] = $definition;
		}

		return $missing;
	}//end inspect()

	/**
	 * Add the missing metadata columns to a table.
	 *
	 * Each column is added on its own, so one that fails does not stop the
	 * rest. A column another request added first counts as added. Updates the
	 * per-process memo and the distributed cache with the outcome.
	 *
	 * @param string                    $tableName Bare magic table name.
	 * @param array<string, array>|null $missing   The missing definitions when already known, else read fresh.
	 *
	 * @return array{added: list<string>, failed: array<string, string>} Column names added, and failures with their reason.
	 *
	 * @throws \Throwable When $missing is null and the table's columns cannot be read.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-a-magic-table-missing-metadata-columns-heals-itself-on-first-use-req-mth-001
	 */
	public function heal(string $tableName, ?array $missing = null): array {
		if ($missing === null) {
			$missing = $this->inspect(tableName: $tableName);
		}

		$added = [];
		$failed = [];
		foreach ($missing as $name => $definition) {
			$reason = $this->addColumn(tableName: $tableName, name: (string)$name, definition: $definition);
			if ($reason === null) {
				$added[] = (string)$name;
				continue;
			}

			$failed[(string)$name] = $reason;
		}

		if ($added !== []) {
			$this->logger->info(
				message: '[MagicMetadataColumnHealer] Added missing metadata columns to '.$tableName.': '.implode(', ', $added)
			);
		}

		if ($failed === []) {
			$this->remember(tableName: $tableName, missing: []);
			return ['added' => $added, 'failed' => []];
		}

		$this->rememberFailure(tableName: $tableName, failed: $failed);

		return ['added' => $added, 'failed' => $failed];
	}//end heal()

	/**
	 * The metadata columns this process knows a table lacks, without any I/O.
	 *
	 * The search handler asks this after ensure() has run for the table, to
	 * leave a missing column out of the archive and draft predicates instead
	 * of sending a query that cannot succeed.
	 *
	 * @param string $tableName Bare magic table name.
	 *
	 * @return list<string> Missing metadata column names, empty when complete or unknown.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-a-table-that-cannot-be-healed-degrades-quietly-req-mth-002
	 */
	public function missingFor(string $tableName): array {
		return self::$verified[$tableName] ?? [];
	}//end missingFor()

	/**
	 * Forget what is known about a table, here and across requests.
	 *
	 * Called when a table is dropped, so a table recreated under the same name
	 * is inspected again.
	 *
	 * @param string $tableName Bare magic table name.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-a-magic-table-missing-metadata-columns-heals-itself-on-first-use-req-mth-001
	 */
	public function forget(string $tableName): void {
		unset(self::$verified[$tableName], self::$warned[$tableName]);

		if ($this->cache === null) {
			return;
		}

		try {
			$this->cache->remove($this->healthyKey(tableName: $tableName));
			$this->cache->remove($this->failedKey(tableName: $tableName));
		} catch (\Throwable $e) {
			// A cache that cannot be written only costs a re-inspection later.
			return;
		}
	}//end forget()

	/**
	 * Clear the per-process memos (tests, and MagicMapper::clearAllStaticCaches()).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-a-magic-table-missing-metadata-columns-heals-itself-on-first-use-req-mth-001
	 */
	public static function reset(): void {
		self::$verified = [];
		self::$warned = [];
	}//end reset()

	/**
	 * The version key: changes whenever the metadata column list changes.
	 *
	 * A healthy answer cached before a new metadata column was declared is
	 * therefore never read as healthy for the new list.
	 *
	 * @return string A short hash of the metadata column names and types.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-a-magic-table-missing-metadata-columns-heals-itself-on-first-use-req-mth-001
	 */
	public function version(): string {
		if ($this->version !== null) {
			return $this->version;
		}

		$signature = [];
		foreach ($this->magicMapper->getMetadataColumnDefinitions() as $definition) {
			$signature[] = ($definition['name'] ?? '').':'.($definition['type'] ?? '');
		}

		sort($signature);
		$this->version = substr(md5(implode(',', $signature)), 0, 12);

		return $this->version;
	}//end version()

	/**
	 * Add one column, tolerating a concurrent add of the same column.
	 *
	 * @param string $tableName  Bare magic table name.
	 * @param string $name       Column name.
	 * @param array  $definition Column definition from getMetadataColumns().
	 *
	 * @return string|null Null when the column is now present, else the reason it is not.
	 */
	private function addColumn(string $tableName, string $name, array $definition): ?string {
		if (($definition['primary'] ?? false) === true || ($definition['autoincrement'] ?? false) === true) {
			return 'a primary key column cannot be added to an existing table';
		}

		try {
			$this->db->executeStatement(
				$this->magicMapper->buildAddColumnSql(tableName: $tableName, definition: $definition)
			);
			return null;
		} catch (\Throwable $e) {
			// Another request may have added it between our read and our
			// ALTER. Ask the database rather than parse a driver message.
			try {
				$current = $this->magicMapper->getExistingTableColumns(tableName: $tableName);
				if (isset($current[$name]) === true) {
					return null;
				}
			} catch (\Throwable $_reread) {
				// Fall through to the original reason.
			}

			return $e->getMessage();
		}//end try
	}//end addColumn()

	/**
	 * Whether a DDL statement may run now without committing someone's transaction.
	 *
	 * PostgreSQL runs DDL transactionally, so it is always safe there.
	 *
	 * @return bool True when the ALTER may run in this request.
	 */
	private function mayAlterNow(): bool {
		try {
			if ($this->db->inTransaction() === false) {
				return true;
			}

			$platform = $this->db->getDatabasePlatform();
			return $platform instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform;
		} catch (\Throwable $e) {
			return true;
		}
	}//end mayAlterNow()

	/**
	 * Read the cross-request answer for a table.
	 *
	 * @param string $tableName Bare magic table name.
	 *
	 * @return list<string>|null Missing columns ([] for healthy), or null when nothing is cached.
	 */
	private function readCache(string $tableName): ?array {
		if ($this->cache === null) {
			return null;
		}

		try {
			if ($this->cache->get($this->healthyKey(tableName: $tableName)) === true) {
				return [];
			}

			$failed = $this->cache->get($this->failedKey(tableName: $tableName));
			if (is_array($failed) === true) {
				return array_values(array_map('strval', $failed));
			}
		} catch (\Throwable $e) {
			return null;
		}

		return null;
	}//end readCache()

	/**
	 * Remember a complete table, here and across requests.
	 *
	 * @param string       $tableName Bare magic table name.
	 * @param list<string> $missing   Always empty; kept explicit for the memo shape.
	 *
	 * @return void
	 */
	private function remember(string $tableName, array $missing): void {
		self::$verified[$tableName] = $missing;
		unset(self::$warned[$tableName]);

		if ($this->cache === null) {
			return;
		}

		try {
			$this->cache->remove($this->failedKey(tableName: $tableName));
			$this->cache->set($this->healthyKey(tableName: $tableName), true, self::HEALTHY_TTL);
		} catch (\Throwable $e) {
			return;
		}
	}//end remember()

	/**
	 * Remember a table that could not be healed, and warn about it once.
	 *
	 * @param string                $tableName Bare magic table name.
	 * @param array<string, string> $failed    Column name => reason.
	 *
	 * @return void
	 */
	private function rememberFailure(string $tableName, array $failed): void {
		$names = array_keys($failed);
		self::$verified[$tableName] = $names;

		if (isset(self::$warned[$tableName]) === false) {
			self::$warned[$tableName] = true;
			$this->logger->warning(
				message: '[MagicMetadataColumnHealer] Could not add metadata columns to '.$tableName
					.'; queries leave them out until this is fixed. Run occ openregister:tables:reconcile --apply '
					.'as a user allowed to alter tables.',
				context: ['tableName' => $tableName, 'failed' => $failed]
			);
		}

		if ($this->cache === null) {
			return;
		}

		try {
			$this->cache->set($this->failedKey(tableName: $tableName), $names, self::FAILURE_TTL);
		} catch (\Throwable $e) {
			return;
		}
	}//end rememberFailure()

	/**
	 * Cache key for a healthy answer.
	 *
	 * @param string $tableName Bare magic table name.
	 *
	 * @return string The key.
	 */
	private function healthyKey(string $tableName): string {
		return 'meta_ok_'.$this->version().'_'.$tableName;
	}//end healthyKey()

	/**
	 * Cache key for a degraded table.
	 *
	 * @param string $tableName Bare magic table name.
	 *
	 * @return string The key.
	 */
	private function failedKey(string $tableName): string {
		return 'meta_fail_'.$this->version().'_'.$tableName;
	}//end failedKey()
}//end class
