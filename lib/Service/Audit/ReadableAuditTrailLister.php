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
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
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
	 * @param AuditTrailMapperInterface        $auditTrailMapper Reads the candidate rows.
	 * @param MagicMapperInterface             $objectMapper     Locates an entry's object across the magic tables.
	 * @param ObjectHistoryForReaders $history          Reads each object and redacts its entries as its history does,
	 *                                                  and holds the rule on who may see read entries.
	 * @param ReadableAuditCursor     $cursors          Seals the raw-trail offset a client is handed.
	 */
	public function __construct(
		private readonly AuditTrailMapperInterface $auditTrailMapper,
		private readonly MagicMapperInterface $objectMapper,
		private readonly ObjectHistoryForReaders $history,
		private readonly ReadableAuditCursor $cursors,
	) {
	}//end __construct()

	/**
	 * One page of the audit trail, as far as this caller may read.
	 *
	 * The cursor is an offset into the RAW trail, not into the filtered
	 * result, because the filter is decided per row and not in SQL. That
	 * offset counts rows the caller may not read, so it is handed out sealed
	 * to the caller, filters and search it was walked under, no client-chosen
	 * offset is accepted, and no count of the walked rows is returned: a
	 * client hands back the `nextCursor` it was given and is never told how
	 * many rows were skipped to fill its page.
	 *
	 * @param string|null     $userId  The caller, or null when anonymous.
	 * @param int             $limit   How many readable rows the caller asked for.
	 * @param int|string|null $cursor  A sealed cursor this list handed out under the same caller, filters and search.
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
			return ['results' => [], 'limit' => $limit, 'cursor' => $cursor, 'nextCursor' => null];
		}

		return $this->readablePage(userId: $userId, limit: $limit, cursor: $cursor, filters: $filters, search: $search);
	}//end page()

	/**
	 * One page for a signed-in caller: walk the trail from the sealed cursor and seal where it stopped.
	 *
	 * @param string          $userId  The caller.
	 * @param int             $limit   How many readable rows the page holds, already bounded.
	 * @param int|string|null $cursor  The sealed cursor handed back, if any.
	 * @param array           $filters Column filters from the request.
	 * @param string|null     $search  Optional free-text term.
	 *
	 * @return array{results: array<int, array>, limit: int, cursor: int|string|null, nextCursor: string|null} The page.
	 */
	private function readablePage(string $userId, int $limit, int|string|null $cursor, array $filters, ?string $search): array {
		// Who read an object is shown only to the groups named for it. A search
		// is matched against what the caller is given, after redaction: matched
		// in SQL on the stored `changed`, it would tell the caller whether a
		// hidden property ever held the term.
		$this->withReads = $this->history->readEvents()->maySeeReadEvents(userId: $userId);
		$this->search = trim((string)$search);
		$filters = $this->appliedFilters(filters: $filters);

		$scope = $this->cursors->scope(userId: $userId, query: ['filters' => $filters, 'search' => $this->search]);
		$walk = $this->walk(filters: $filters, offset: $this->cursors->open(cursor: $cursor, scope: $scope), limit: $limit);

		$nextCursor = null;
		if ($walk['exhausted'] === false) {
			$nextCursor = $this->sealedCursor(offset: $walk['offset'], scope: $scope);
		}

		return ['results' => $walk['results'], 'limit' => $limit, 'cursor' => $cursor, 'nextCursor' => $nextCursor];
	}//end readablePage()

	/**
	 * Walk the raw trail in batches until the page is full, the trail ends, or the scan budget runs out.
	 *
	 * @param array $filters The applied column filters.
	 * @param int   $offset  The raw-trail offset to start at.
	 * @param int   $limit   How many readable rows the page holds.
	 *
	 * @return array{results: array<int, array>, offset: int, exhausted: bool} The rows, where the walk stopped, and whether the trail ended.
	 */
	private function walk(array $filters, int $offset, int $limit): array {
		$results = [];
		$start = $offset;
		while (count($results) < $limit && ($offset - $start) < self::SCAN_BUDGET) {
			$batch = $this->auditTrailMapper->findAll(limit: self::BATCH_SIZE, offset: $offset, filters: $filters, sort: ['created' => 'DESC']);
			$room = ($limit - count($results));
			$taken = $this->takeReadable(batch: $batch, readable: $this->readerRows(batch: $batch), room: $room, results: $results);
			$offset += $taken['consumed'];

			if ($this->endsTheTrail(batchSize: count($batch), consumed: $taken['consumed']) === true) {
				return ['results' => $results, 'offset' => $offset, 'exhausted' => true];
			}
		}

		return ['results' => $results, 'offset' => $offset, 'exhausted' => false];
	}//end walk()

	/**
	 * Whether a batch ends the trail.
	 *
	 * An empty batch does. A batch shorter than the page size is the end of the
	 * trail, but only once it has been walked to the end: breaking out
	 * mid-batch to fill a page leaves rows behind, and calling that exhausted
	 * would lose them.
	 *
	 * @param int $batchSize How many rows the batch held.
	 * @param int $consumed  How many of them were walked.
	 *
	 * @return bool
	 */
	private function endsTheTrail(int $batchSize, int $consumed): bool {
		return $batchSize === 0 || ($batchSize < self::BATCH_SIZE && $consumed === $batchSize);
	}//end endsTheTrail()

	/**
	 * The filters the trail query applies and a reader may use.
	 *
	 * A key the query drops would make two walks over the same rows look like
	 * different scopes, and a cursor parameter echoed into the filters would
	 * make every page a new one.
	 *
	 * @param array $filters Column filters from the request.
	 *
	 * @return array The filters that reach the query and the cursor's scope.
	 */
	private function appliedFilters(array $filters): array {
		return array_intersect_key(
			$this->history->readerColumns(columns: $filters),
			array_flip(AuditTrailMapper::QUERY_COLUMNS)
		);
	}//end appliedFilters()

	/**
	 * The cursor to resume at an offset, or none when the walk has no scope to seal it to.
	 *
	 * @param int         $offset The raw-trail offset to resume at.
	 * @param string|null $scope  The scope of the walk.
	 *
	 * @return string|null The sealed cursor, or null.
	 */
	private function sealedCursor(int $offset, ?string $scope): ?string {
		if ($scope === null) {
			return null;
		}

		return $this->cursors->seal(offset: $offset, scope: $scope);
	}//end sealedCursor()

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
			if ($this->shows(entry: $entry, row: ($readable[$index] ?? null)) === true) {
				$results[] = $readable[$index];
				$kept++;
			}

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

		if ($this->withReads === false && $this->history->readEvents()->isReadAction(action: $entry->getAction()) === true) {
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

		$rows = [];
		foreach ($this->locate(uuids: array_keys($groups)) as $object) {
			$indices = ($groups[(string)$object->getUuid()] ?? []);
			unset($groups[(string)$object->getUuid()]);
			$rows += $this->objectRows(object: $object, indices: $indices, batch: $batch);
		}

		return $rows;
	}//end readerRows()

	/**
	 * The live objects the batch points at, in one cross-table lookup.
	 *
	 * @param array<int, string> $uuids The object uuids.
	 *
	 * @return array The located objects; empty when the lookup fails.
	 */
	private function locate(array $uuids): array {
		try {
			return $this->objectMapper->findMultipleAcrossAllMagicTables(uuids: $uuids, includeDeleted: false);
		} catch (Throwable $e) {
			// Fail closed: an unresolvable batch means nothing is readable,
			// which hides rows rather than showing them.
			return [];
		}
	}//end locate()

	/**
	 * One object's rows of the batch, redacted as its history is, by batch index.
	 *
	 * @param ObjectEntity      $object  The located object; anything else is read as unreadable.
	 * @param list<int>         $indices The batch indices of its rows.
	 * @param array<AuditTrail> $batch   The candidate rows.
	 *
	 * @return array<int, array> The redacted rows; empty when the object's rows cannot all be read.
	 */
	private function objectRows(mixed $object, array $indices, array $batch): array {
		if ($indices === []) {
			return [];
		}

		try {
			$rows = array_map(static fn (int $index): AuditTrail => $batch[$index], $indices);
			$entries = $this->history->readerEntries(located: $object, rows: $rows);
		} catch (Throwable $e) {
			return [];
		}

		if ($entries === null || count($entries) !== count($indices)) {
			return [];
		}

		return array_combine($indices, $entries);
	}//end objectRows()

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
