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
 * read, and what of them may the caller see", and it is answered by the reader
 * path of one object's history, `ObjectHistoryForReaders::readerEntries()`.
 * That path reads the object again through the object GET's read path,
 * `ObjectService::find()` with RBAC and multitenancy on, so the organisation
 * boundary, a grant, a schema rule, a draft and a deleted object mean here
 * what they mean on the object. It runs the doelbinding guard of the object
 * GET and removes from each entry the properties the caller may not see.
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
	public const WITHHELD_FIELDS = ['session', 'request', 'ipAddress'];

	/**
	 * Whether read entries are given to the caller of this run.
	 *
	 * @var bool
	 */
	private bool $withReads = false;

	/**
	 * The free-text term of this run, matched against the redacted entries.
	 *
	 * @var string
	 */
	private string $search = '';

	/**
	 * Constructor.
	 *
	 * @param AuditTrailMapper        $auditTrailMapper Reads the candidate rows.
	 * @param MagicMapper             $objectMapper     Locates an entry's object across the magic tables.
	 * @param ObjectHistoryForReaders $history          Reads each object and redacts its entries as its history does.
	 * @param ReadEventVisibility     $readEvents       Whether the caller may see who read an object.
	 * @param ReadableAuditCursor     $cursors          Seals the raw-trail offset a client is handed.
	 */
	public function __construct(
		private readonly AuditTrailMapper $auditTrailMapper,
		private readonly MagicMapper $objectMapper,
		private readonly ObjectHistoryForReaders $history,
		private readonly ReadEventVisibility $readEvents,
		private readonly ReadableAuditCursor $cursors,
	) {
	}//end __construct()

	/**
	 * One page of the audit trail, as far as this caller may read.
	 *
	 * The cursor is an offset into the RAW trail, not into the filtered
	 * result, because the filter is decided per row and not in SQL. That
	 * offset counts rows the caller may not read, so it is handed out sealed
	 * and no count of the walked rows is returned: a client hands back the
	 * `nextCursor` it was given and is never told how many rows were skipped
	 * to fill its page.
	 *
	 * @param string|null     $userId  The caller, or null when anonymous.
	 * @param int             $limit   How many readable rows the caller asked for.
	 * @param int|string|null $cursor  A sealed cursor this list handed out, or a raw-trail offset.
	 * @param array           $filters Column filters, as `AuditTrailMapper::findAll()` takes them.
	 * @param string|null     $search  Optional free-text term.
	 *
	 * @return array{results: array<int, array>, limit: int, cursor: int|string|null, nextCursor: string|null}
	 *     The page. `nextCursor` is null when the trail was exhausted, and a
	 *     sealed cursor when there may be more, including when the scan budget
	 *     ran out before the page filled.
	 *
	 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-is-readable-within-a-callers-own-scope
	 */
	public function page(
		?string $userId,
		int $limit = 20,
		int|string|null $cursor = null,
		array $filters = [],
		?string $search = null,
	): array {
		$limit = max(1, min($limit, self::MAX_LIMIT));

		// An anonymous caller holds no readable scope, and asking the mapper
		// for candidates it would then drop is work done to reach the same
		// answer. Refuse before the query, not after it.
		if ($userId === null || $userId === '') {
			return [
				'results' => [],
				'limit' => $limit,
				'cursor' => $cursor,
				'nextCursor' => null,
			];
		}

		// Who read an object is shown only to the groups named for it. A search
		// is matched against what the caller is given, after redaction: matched
		// in SQL on the stored `changed`, it would tell the caller whether a
		// hidden property ever held the term.
		$this->withReads = $this->readEvents->maySeeReadEvents(userId: $userId);
		$this->search = trim((string)$search);
		$filters = $this->history->readerColumns(columns: $filters);

		$results = [];
		$found = 0;
		$scanned = 0;
		$rawOffset = $this->cursors->open(cursor: $cursor);
		$exhausted = false;

		while ($found < $limit && $scanned < self::SCAN_BUDGET) {
			$batch = $this->auditTrailMapper->findAll(
				limit: self::BATCH_SIZE,
				offset: $rawOffset,
				filters: $filters,
				sort: ['created' => 'DESC']
			);

			$batchSize = count($batch);
			if ($batchSize === 0) {
				$exhausted = true;
				break;
			}

			$taken = $this->takeReadable(
				batch: $batch,
				readable: $this->readerRows(batch: $batch),
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

		$nextCursor = null;
		if ($exhausted === false) {
			$nextCursor = $this->cursors->seal(offset: $rawOffset);
		}

		return [
			'results' => $results,
			'limit' => $limit,
			'cursor' => $cursor,
			'nextCursor' => $nextCursor,
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
	 * @param array<int, array>   $readable The redacted rows the caller may read, by batch index.
	 * @param int                 $room     How many more rows the page may hold.
	 * @param array               $results  The page so far, appended to in place.
	 *
	 * @return array{consumed: int, kept: int} How many rows were walked and kept.
	 */
	private function takeReadable(array $batch, array $readable, int $room, array &$results): array {
		$consumed = 0;
		$kept = 0;

		foreach ($batch as $index => $entry) {
			$consumed++;

			if ($this->shows(entry: $entry, row: ($readable[$index] ?? null)) === false) {
				continue;
			}

			$results[] = $readable[$index];
			$kept++;

			if ($kept >= $room) {
				break;
			}
		}

		return ['consumed' => $consumed, 'kept' => $kept];
	}//end takeReadable()

	/**
	 * Whether one candidate row goes on the page.
	 *
	 * @param AuditTrail $entry The stored entry.
	 * @param array|null $row   Its redacted form, or null when the caller may not read its object.
	 *
	 * @return bool True when the row is readable, not a hidden read entry, and matches the search.
	 */
	private function shows(AuditTrail $entry, ?array $row): bool {
		if ($row === null) {
			return false;
		}

		if ($this->withReads === false && $this->readEvents->isReadAction(action: $entry->getAction()) === true) {
			return false;
		}

		return $this->search === '' || $this->history->matches(entry: $row, search: $this->search);
	}//end shows()

	/**
	 * The rows of this batch the caller may read, redacted, by batch index.
	 *
	 * The objects are located in one cross-table lookup, and each object is
	 * then read and its rows redacted once, however many rows point at it.
	 * Soft-deleted objects are NOT included. An entry whose object is gone
	 * stays on the admin surface, which is where the deletion itself is read.
	 *
	 * @param array<AuditTrail> $batch The candidate rows.
	 *
	 * @return array<int, array> The redacted readable rows, keyed by their index in the batch.
	 */
	private function readerRows(array $batch): array {
		$groups = $this->indicesByObject(batch: $batch);
		if ($groups === []) {
			return [];
		}

		try {
			$located = $this->objectMapper->findMultipleAcrossAllMagicTables(
				uuids: array_keys($groups),
				includeDeleted: false
			);
		} catch (Throwable $e) {
			// Fail closed: an unresolvable batch means nothing is readable,
			// which hides rows rather than showing them.
			return [];
		}

		$rows = [];
		foreach ($located as $object) {
			$indices = ($groups[(string)$object->getUuid()] ?? []);
			unset($groups[(string)$object->getUuid()]);
			if ($indices === []) {
				continue;
			}

			try {
				$entries = $this->history->readerEntries(
					located: $object,
					rows: array_map(static fn (int $index): AuditTrail => $batch[$index], $indices)
				);
			} catch (Throwable $e) {
				$entries = null;
			}

			if ($entries !== null && count($entries) === count($indices)) {
				$rows += array_combine($indices, $entries);
			}
		}//end foreach

		return $rows;
	}//end readerRows()

	/**
	 * The batch indices of the rows, grouped by the object uuid they point at.
	 *
	 * @param array<AuditTrail> $batch The candidate rows.
	 *
	 * @return array<string, list<int>> The indices, by object uuid.
	 */
	private function indicesByObject(array $batch): array {
		$groups = [];
		foreach ($batch as $index => $entry) {
			$objectUuid = $entry->getObjectUuid();
			if ($objectUuid !== null && $objectUuid !== '') {
				$groups[$objectUuid][] = $index;
			}
		}

		return $groups;
	}//end indicesByObject()
}//end class
