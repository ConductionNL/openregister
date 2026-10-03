<?php

/**
 * ReadableAuditTrailLister - the audit trail as far as one caller may read
 *
 * `AuditTrailController::index()` is admin-only and stays admin-only: the
 * cross-tenant index leaks per-row diffs of every object change in every
 * register and schema. This class does not widen it. It answers a smaller
 * question on its own path, so an error here cannot make the admin path wider
 * than it was.
 *
 * The question is "which of these entries belong to objects this caller may
 * read", and it is answered by the funnel the object read path already uses,
 * `PermissionHandler::hasPermission()` with action `read` and the resolved
 * entity. That funnel consults `ObjectGrantResolver`, so an inherited grant
 * means here exactly what it means on the object itself. A second reachability
 * rule written for this page would be a second answer to the question the
 * whole RBAC layer exists for, and the two would drift.
 *
 * Every unknown resolves to absent. No caller, no object uuid, an object that
 * no longer resolves, a schema that no longer resolves and any throwable all
 * drop the row. An audit list that fails open looks exactly like one that
 * works.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Audit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-is-readable-within-a-callers-own-scope
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Audit;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use Throwable;

/**
 * Lists the audit trail within the reach of one caller.
 */
class ReadableAuditTrailLister {

	/**
	 * How many raw rows one request may inspect before it gives up.
	 *
	 * The scan reads candidates and drops the ones the caller may not read, so
	 * a caller with a narrow scope on a busy instance can walk a long way for
	 * one page. The budget bounds that walk: past it the response comes back
	 * short with a cursor, and the client asks again. Unbounded, a single
	 * request on an empty scope would read the whole table.
	 *
	 * @var int
	 */
	public const SCAN_BUDGET = 2000;

	/**
	 * How many raw rows one candidate query asks for.
	 *
	 * @var int
	 */
	public const BATCH_SIZE = 200;

	/**
	 * The largest page a caller may ask for.
	 *
	 * @var int
	 */
	public const MAX_LIMIT = 100;

	/**
	 * The fields a scoped row does not carry.
	 *
	 * They describe the instance rather than the object: which session, which
	 * request and which address. That is the recon signal the admin gate holds
	 * back, and a reader of their own case has no use for it.
	 *
	 * @var string[]
	 */
	private const WITHHELD_FIELDS = ['session', 'request', 'ipAddress'];

	/**
	 * Schemas already resolved in this run, by id.
	 *
	 * @var array<int, Schema|null>
	 */
	private array $schemaCache = [];

	/**
	 * Constructor.
	 *
	 * @param AuditTrailMapper  $auditTrailMapper  Reads the candidate rows.
	 * @param MagicMapper       $objectMapper      Resolves an entry's object across the magic tables.
	 * @param SchemaMapper      $schemaMapper      Resolves the object's schema.
	 * @param PermissionHandler $permissionHandler Decides whether the caller may read the object.
	 */
	public function __construct(
		private readonly AuditTrailMapper $auditTrailMapper,
		private readonly MagicMapper $objectMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly PermissionHandler $permissionHandler,
	) {
	}//end __construct()

	/**
	 * One page of the audit trail, as far as this caller may read.
	 *
	 * The cursor is an offset into the RAW trail, not into the filtered
	 * result, because the filter is decided per row and not in SQL. A client
	 * hands back the `nextCursor` it was given and never has to know how many
	 * rows were skipped to fill its page.
	 *
	 * @param string|null $userId  The caller, or null when anonymous.
	 * @param int         $limit   How many readable rows the caller asked for.
	 * @param int         $cursor  Where in the raw trail to resume.
	 * @param array       $filters Column filters, as `AuditTrailMapper::findAll()` takes them.
	 * @param string|null $search  Optional free-text term.
	 *
	 * @return array{results: array<int, array>, limit: int, cursor: int, nextCursor: int|null, scanned: int}
	 *     The page. `nextCursor` is null when the trail was exhausted, and an
	 *     offset when there may be more — including when the scan budget ran
	 *     out before the page filled.
	 *
	 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-is-readable-within-a-callers-own-scope
	 */
	public function page(
		?string $userId,
		int $limit = 20,
		int $cursor = 0,
		array $filters = [],
		?string $search = null,
	): array {
		$limit = max(1, min($limit, self::MAX_LIMIT));
		$cursor = max(0, $cursor);

		// An anonymous caller holds no readable scope, and asking the mapper
		// for candidates it would then drop is work done to reach the same
		// answer. Refuse before the query, not after it.
		if ($userId === null || $userId === '') {
			return [
				'results' => [],
				'limit' => $limit,
				'cursor' => $cursor,
				'nextCursor' => null,
				'scanned' => 0,
			];
		}

		$this->schemaCache = [];

		$results = [];
		$found = 0;
		$scanned = 0;
		$rawOffset = $cursor;
		$exhausted = false;

		while ($found < $limit && $scanned < self::SCAN_BUDGET) {
			$batch = $this->auditTrailMapper->findAll(
				limit: self::BATCH_SIZE,
				offset: $rawOffset,
				filters: $filters,
				sort: ['created' => 'DESC'],
				search: $search
			);

			$batchSize = count($batch);
			if ($batchSize === 0) {
				$exhausted = true;
				break;
			}

			$taken = $this->takeReadable(
				batch: $batch,
				readable: $this->readableUuids(userId: $userId, batch: $batch),
				room: ($limit - $found),
				results: $results
			);

			$found += $taken['kept'];
			$scanned += $taken['consumed'];
			$rawOffset += $taken['consumed'];

			// A batch shorter than the page size is the end of the trail, but
			// only once it has been walked to the end: breaking out mid-batch
			// to fill a page leaves rows behind, and calling that exhausted
			// would lose them.
			if ($batchSize < self::BATCH_SIZE && $taken['consumed'] === $batchSize) {
				$exhausted = true;
				break;
			}
		}//end while

		$nextCursor = $rawOffset;
		if ($exhausted === true) {
			$nextCursor = null;
		}

		return [
			'results' => $results,
			'limit' => $limit,
			'cursor' => $cursor,
			'nextCursor' => $nextCursor,
			'scanned' => $scanned,
		];
	}//end page()

	/**
	 * Append the readable rows of one batch, up to the room left on the page.
	 *
	 * Reports how many rows it walked as well as how many it kept, because the
	 * cursor advances over the rows it SKIPPED too. A cursor that only counted
	 * the kept rows would hand the next page the same unreadable rows again,
	 * for ever.
	 *
	 * @param array<AuditTrail>   $batch    The candidate rows, in order.
	 * @param array<string, true> $readable The readable object uuids.
	 * @param int                 $room     How many more rows the page may hold.
	 * @param array               $results  The page so far, appended to in place.
	 *
	 * @return array{consumed: int, kept: int} How many rows were walked and kept.
	 */
	private function takeReadable(array $batch, array $readable, int $room, array &$results): array {
		$consumed = 0;
		$kept = 0;

		foreach ($batch as $entry) {
			$consumed++;

			$objectUuid = $entry->getObjectUuid();
			if ($objectUuid === null || isset($readable[$objectUuid]) === false) {
				continue;
			}

			$results[] = $this->scopedRow(entry: $entry);
			$kept++;

			if ($kept >= $room) {
				break;
			}
		}

		return ['consumed' => $consumed, 'kept' => $kept];
	}//end takeReadable()

	/**
	 * The object uuids in this batch that the caller may read.
	 *
	 * Resolved in one cross-table lookup rather than one per row: a page of
	 * twenty entries on one case is twenty rows pointing at one object.
	 *
	 * Soft-deleted objects are NOT included. An entry whose object is gone
	 * stays on the admin surface, which is where the deletion itself is read.
	 *
	 * @param string           $userId The caller.
	 * @param array<AuditTrail> $batch  The candidate rows.
	 *
	 * @return array<string, true> The readable object uuids, as a set.
	 */
	private function readableUuids(string $userId, array $batch): array {
		$uuids = $this->candidateUuids(batch: $batch);
		if ($uuids === []) {
			return [];
		}

		try {
			$objects = $this->objectMapper->findMultipleAcrossAllMagicTables(
				uuids: $uuids,
				includeDeleted: false
			);
		} catch (Throwable $e) {
			// Fail closed: an unresolvable batch means nothing is readable,
			// which hides rows rather than showing them.
			return [];
		}

		$readable = [];
		foreach ($objects as $object) {
			$objectUuid = $object->getUuid();
			if ($objectUuid !== null && $objectUuid !== '' && $this->mayRead(userId: $userId, object: $object) === true) {
				$readable[$objectUuid] = true;
			}
		}

		return $readable;
	}//end readableUuids()

	/**
	 * The distinct object uuids one batch of entries points at.
	 *
	 * @param array<AuditTrail> $batch The candidate rows.
	 *
	 * @return string[] The uuids, without repeats.
	 *
	 * @psalm-return list<string>
	 */
	private function candidateUuids(array $batch): array {
		$uuids = [];
		foreach ($batch as $entry) {
			$objectUuid = $entry->getObjectUuid();
			if ($objectUuid !== null && $objectUuid !== '') {
				$uuids[$objectUuid] = true;
			}
		}

		return array_keys($uuids);
	}//end candidateUuids()

	/**
	 * Whether this caller may read this object.
	 *
	 * THE ONE FUNNEL. `PermissionHandler::hasPermission()` with action `read`
	 * and the resolved entity is what the object read path itself asks, and it
	 * consults `ObjectGrantResolver`, so an inherited grant means here exactly
	 * what it means on the object. A second reachability rule written for this
	 * page would be a second answer to the question the whole RBAC layer
	 * exists for, and the two would drift.
	 *
	 * Every unknown answers no: a schema that will not resolve and a check
	 * that throws both hide the row.
	 *
	 * @param string       $userId The caller.
	 * @param ObjectEntity $object The object an entry belongs to.
	 *
	 * @return bool True when the caller may read it.
	 */
	private function mayRead(string $userId, ObjectEntity $object): bool {
		$schemaId = $object->getSchema();
		if ($schemaId === null) {
			return false;
		}

		$schema = $this->schema(schemaId: (int)$schemaId);
		if ($schema === null) {
			return false;
		}

		try {
			return $this->permissionHandler->hasPermission(
				schema: $schema,
				action: 'read',
				userId: $userId,
				objectOwner: $object->getOwner(),
				_rbac: true,
				object: $object
			);
		} catch (Throwable $e) {
			return false;
		}
	}//end mayRead()

	/**
	 * A schema by id, resolved once per run.
	 *
	 * @param int $schemaId The schema id.
	 *
	 * @return Schema|null The schema, or null when it cannot be resolved.
	 */
	private function schema(int $schemaId): ?Schema {
		if (array_key_exists($schemaId, $this->schemaCache) === true) {
			return $this->schemaCache[$schemaId];
		}

		try {
			$schema = $this->schemaMapper->find($schemaId);
		} catch (Throwable $e) {
			$schema = null;
		}

		if (($schema instanceof Schema) === false) {
			$schema = null;
		}

		$this->schemaCache[$schemaId] = $schema;

		return $schema;
	}//end schema()

	/**
	 * One entry as the scoped surface renders it.
	 *
	 * @param AuditTrail $entry The entry.
	 *
	 * @return array The row, without the withheld fields.
	 */
	private function scopedRow(AuditTrail $entry): array {
		$row = $entry->jsonSerialize();

		foreach (self::WITHHELD_FIELDS as $field) {
			unset($row[$field]);
		}

		return $row;
	}//end scopedRow()
}//end class
