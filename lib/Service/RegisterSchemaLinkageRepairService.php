<?php

/**
 * OpenRegister RegisterSchemaLinkageRepairService
 *
 * Reconstructs a register's `schemas` list from the physical object tables when
 * the stored list has been lost.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCP\DB\IPreparedStatement;
use OCP\IDBConnection;
use PDO;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Rebuilds lost register→schema linkage from physical storage.
 *
 * A schema row carries NO register column: the relation is stored only as a JSON
 * id list on the register. When that list is lost, nothing on the schema side can
 * rebuild it.
 *
 * What can is the storage layout itself. Objects live in per-pair tables named
 * `oc_openregister_table_<registerId>_<schemaId>`, so the table NAME is a durable
 * record of a pairing that was actually used. Measured on the development instance
 * 2026-08-16: 3220 such tables, 45 registers with an empty list, 17 of them
 * recoverable this way, and exactly one — DocuDesk's Document Register (id 6) —
 * holding live rows it could no longer reach by slug.
 *
 * Deliberately rejected as evidence sources:
 *  - slug similarity: nine schemas shared slug `anonymizationLink`;
 *  - `application` ownership: all nine were owned by `docudesk`;
 *  - the app's own register manifest: it describes what the app intends NOW, not
 *    what the register holds, so it would orphan a schema whose objects exist but
 *    which the app has since dropped.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/register-scoped-slug-resolution/spec.md
 */
class RegisterSchemaLinkageRepairService implements RegisterSchemaLinkageRepairServiceInterface {

	/**
	 * Marker the shard tables are matched on.
	 *
	 * Anchored on the marker rather than a computed prefix: OCP\IDBConnection
	 * exposes neither getSchema() nor getPrefix(), and getTableName('') yields the
	 * literal `*PREFIX*` placeholder that a raw information_schema string never
	 * resolves. Same reasoning as {@see \OCA\OpenRegister\Repair\RenameDutchColumns}.
	 *
	 * @var string
	 */
	private const TABLE_MARKER = 'openregister_table_';

	/**
	 * Lists the shard tables; `information_schema` is honoured by PostgreSQL and MySQL.
	 *
	 * @var string
	 */
	private const SHARD_TABLES_QUERY = 'SELECT table_name FROM information_schema.tables WHERE table_name LIKE :pattern';

	/**
	 * Constructor.
	 *
	 * @param IDBConnection   $db             The database connection.
	 * @param RegisterMapperInterface  $registerMapper The register mapper.
	 * @param LoggerInterface $logger         The logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly RegisterMapperInterface $registerMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Inspect registers for recoverable schema linkage.
	 *
	 * Reports only — never mutates. Row counts are included because they separate
	 * strong evidence (a table holding rows) from weak (an empty table left by a
	 * schema attached and never used). Both are recoverable; the operator should be
	 * able to see which is which before deciding.
	 *
	 * @param int|null $registerId Inspect only this register, or all when null.
	 *
	 * @return array<int, array{registerId:int, registerSlug:string|null, currentIds:int[], recoverable:array<int,int>}>
	 *         One entry per register that would gain at least one id.
	 *
	 * @spec openspec/specs/register-scoped-slug-resolution/spec.md
	 */
	public function inspect(?int $registerId=null): array {
		$pairs = $this->physicalPairs();
		if ($pairs === []) {
			return [];
		}

		$report = [];
		foreach ($this->registersInScope(registerId: $registerId) as $register) {
			$entry = $this->reportEntry(register: $register, candidates: ($pairs[$register->getId()] ?? []));
			if ($entry !== null) {
				$report[] = $entry;
			}
		}

		return $report;
	}//end inspect()

	/**
	 * Every register, or only the one asked for.
	 *
	 * No null check on the id: Entity declares `@method int getId()`, and
	 * findAll() only ever yields persisted rows, so phpstan is right that
	 * `$id === null` can never be true here. The scope filter is the part
	 * that does work.
	 *
	 * @param int|null $registerId Only this register, or all when null.
	 *
	 * @return Register[] The registers to inspect, in mapper order.
	 */
	private function registersInScope(?int $registerId): array {
		return array_filter(
			$this->registerMapper->findAll(),
			static fn (Register $register): bool => $registerId === null || $register->getId() === $registerId
		);
	}//end registersInScope()

	/**
	 * Build the report entry for one register, or null when nothing is recoverable.
	 *
	 * @param Register         $register   The register under inspection.
	 * @param array<int, int>  $candidates Schema id => live row count from its tables.
	 *
	 * @return array{registerId:int, registerSlug:string|null, currentIds:int[], recoverable:array<int,int>}|null
	 */
	private function reportEntry(Register $register, array $candidates): ?array {
		$currentIds = $this->normaliseIds(candidates: ($register->getSchemas() ?? []));

		$recoverable = $this->unlinked(candidates: $candidates, currentIds: $currentIds);
		if ($recoverable === []) {
			return null;
		}

		ksort($recoverable);
		return [
			'registerId'   => $register->getId(),
			'registerSlug' => $register->getSlug(),
			'currentIds'   => $currentIds,
			'recoverable'  => $recoverable,
		];
	}//end reportEntry()

	/**
	 * The physically evidenced schemas the stored list does not carry yet.
	 *
	 * Additive only: a schema may legitimately be linked before its first
	 * object is written, so a missing table is NOT evidence of "not linked".
	 * Only ids absent from the stored list are proposed.
	 *
	 * @param array<int, int> $candidates Schema id => live row count.
	 * @param int[]           $currentIds The stored schema ids.
	 *
	 * @return array<int, int> Schema id => live row count, for unlinked ids only.
	 */
	private function unlinked(array $candidates, array $currentIds): array {
		return array_filter(
			$candidates,
			static fn (int $schemaId): bool => in_array($schemaId, $currentIds, true) === false,
			ARRAY_FILTER_USE_KEY
		);
	}//end unlinked()

	/**
	 * Apply a repair to one register.
	 *
	 * Strictly additive: the stored ids are preserved and the recovered ids merged
	 * in. The method never removes an id, so a register cannot lose correct
	 * configuration because one of its schemas has no objects yet.
	 *
	 * @param int   $registerId The register to repair.
	 * @param int[] $schemaIds  The schema ids to add.
	 *
	 * @return int[] The register's schema ids after the repair.
	 *
	 * @spec openspec/specs/register-scoped-slug-resolution/spec.md
	 */
	public function apply(int $registerId, array $schemaIds): array {
		$register = $this->registerMapper->find($registerId);
		$current  = $this->normaliseIds(candidates: ($register->getSchemas() ?? []));

		$merged = $this->mergeIds(current: $current, additions: $this->normaliseIds(candidates: $schemaIds));
		$register->setSchemas($merged);
		$this->registerMapper->update($register);
		$this->logRepair(registerId: $registerId, current: $current, merged: $merged);

		return $merged;
	}//end apply()

	/**
	 * Record a repaired register's before and after schema lists.
	 *
	 * @param int   $registerId The repaired register.
	 * @param int[] $current    The ids before the repair.
	 * @param int[] $merged     The ids after the repair.
	 *
	 * @return void
	 */
	private function logRepair(int $registerId, array $current, array $merged): void {
		$this->logger->warning(
			message: sprintf(
				'[RegisterSchemaLinkageRepair] Register %d schemas list repaired: %s -> %s.',
				$registerId,
				json_encode($current),
				json_encode($merged)
			),
			context: ['file' => __FILE__, 'line' => __LINE__, 'register' => $registerId]
		);
	}//end logRepair()

	/**
	 * Merge additional ids into the current ones, keeping every current id, sorted.
	 *
	 * @param int[] $current   The stored ids.
	 * @param int[] $additions The ids to add.
	 *
	 * @return int[] The sorted union.
	 */
	private function mergeIds(array $current, array $additions): array {
		$merged = $current;
		foreach ($additions as $candidate) {
			if (in_array($candidate, $merged, true) === false) {
				$merged[] = $candidate;
			}
		}

		sort($merged);
		return $merged;
	}//end mergeIds()

	/**
	 * Map every register id to the schema ids it has physical tables for.
	 *
	 * @return array<int, array<int, int>> registerId => [schemaId => live row count].
	 */
	private function physicalPairs(): array {
		$pairs = [];
		foreach ($this->shardTableNames() as $name) {
			$pair = $this->parsePair(name: $name);
			if ($pair !== null) {
				$pairs[$pair[0]][$pair[1]] = $this->countRows(table: $name);
			}
		}

		return $pairs;
	}//end physicalPairs()

	/**
	 * Read the register and schema id out of a shard table name.
	 *
	 * @param string $name The table name.
	 *
	 * @return array{0:int, 1:int}|null [registerId, schemaId], or null when the name is not a shard table.
	 */
	private function parsePair(string $name): ?array {
		$offset = strpos($name, self::TABLE_MARKER);
		if ($offset === false) {
			return null;
		}

		$parts = explode('_', substr($name, ($offset + strlen(self::TABLE_MARKER))));
		if ($this->isIdPair(parts: $parts) === false) {
			return null;
		}

		return [(int)$parts[0], (int)$parts[1]];
	}//end parsePair()

	/**
	 * Whether a split table suffix is exactly two digit-only segments.
	 *
	 * @param string[] $parts The suffix split on underscores.
	 *
	 * @return bool True for `<registerId>_<schemaId>`.
	 */
	private function isIdPair(array $parts): bool {
		return count($parts) === 2 && ctype_digit($parts[0]) === true && ctype_digit($parts[1]) === true;
	}//end isIdPair()

	/**
	 * List every shard table name.
	 *
	 * `information_schema.tables` is honoured by both PostgreSQL and MySQL, so no
	 * per-platform branch is needed here.
	 *
	 * @return array<int, string> The table names.
	 */
	private function shardTableNames(): array {
		try {
			$stmt = $this->db->prepare(self::SHARD_TABLES_QUERY);
			$stmt->bindValue('pattern', '%openregister\_table\_%');
			$stmt->execute();
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[RegisterSchemaLinkageRepair] Could not list tables: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return [];
		}

		return $this->tableNamesFrom(stmt: $stmt);
	}//end shardTableNames()

	/**
	 * Collect the non-empty table names from an executed information_schema statement.
	 *
	 * @param IPreparedStatement $stmt The executed statement.
	 *
	 * @return array<int, string> The table names.
	 */
	private function tableNamesFrom(IPreparedStatement $stmt): array {
		$names = [];
		while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
			$name = (string)($row['table_name'] ?? ($row['TABLE_NAME'] ?? ''));
			if ($name !== '') {
				$names[] = $name;
			}
		}

		return $names;
	}//end tableNamesFrom()

	/**
	 * Count the live rows in a shard table.
	 *
	 * The name is re-validated against the strict shard pattern before it reaches
	 * the statement. It arrives from `information_schema`, not from a caller, but
	 * an identifier cannot be bound as a parameter, so the guard is what keeps this
	 * from being an interpolation hazard if the source ever changes.
	 *
	 * @param string $table The shard table name.
	 *
	 * @return int The row count, or -1 when it could not be read.
	 */
	private function countRows(string $table): int {
		if (preg_match('/^[A-Za-z0-9]+_openregister_table_[0-9]+_[0-9]+$/', $table) !== 1) {
			return -1;
		}

		try {
			$stmt   = $this->db->prepare('SELECT COUNT(*) AS c FROM ' . $table);
			$stmt->execute();
			$row = $stmt->fetch(PDO::FETCH_ASSOC);
			return (int)($row['c'] ?? ($row['C'] ?? 0));
		} catch (Throwable $e) {
			return -1;
		}
	}//end countRows()

	/**
	 * Normalise a stored schemas value into a list of positive ints.
	 *
	 * @param mixed $candidates The stored value.
	 *
	 * @return int[] The normalised ids.
	 */
	private function normaliseIds(mixed $candidates): array {
		$ids = [];
		foreach ((array)$candidates as $candidate) {
			if (is_numeric($candidate) === true && (int)$candidate > 0) {
				$ids[] = (int)$candidate;
			}
		}

		return array_values(array_unique($ids));
	}//end normaliseIds()
}//end class
