<?php

/**
 * TimelineEntryMapper: reads and writes the timeline entry record.
 *
 * Two reads matter here and they are not the same query. The timeline read is
 * one object, pinned first, newest next. The search read spans objects and is
 * narrowed by a list of object uuids the caller may read and by the visibility
 * the caller is entitled to, INSIDE the statement (D-1), because a filter
 * applied to a page that was already built silently shortens every page and
 * still leaks the count.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Db;

use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Class TimelineEntryMapper.
 *
 * @method TimelineEntry insert(Entity $entity)
 * @method TimelineEntry update(Entity $entity)
 * @method TimelineEntry delete(Entity $entity)
 *
 * @template-extends QBMapper<TimelineEntry>
 *
 * @psalm-suppress PossiblyUnusedMethod
 *
 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
 */
class TimelineEntryMapper extends QBMapper {
	use NotificationQueryTrait;


	/**
	 * The most entries one search statement will return.
	 *
	 * @var integer
	 */
	public const SEARCH_LIMIT = 200;

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(
			db: $db,
			tableName: 'openregister_timeline_entries',
			entityClass: TimelineEntry::class
		);

	}//end __construct()

	/**
	 * One entry by its stable uuid.
	 *
	 * @param string $uuid The entry uuid.
	 *
	 * @return TimelineEntry|null The row, or null when there is none.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function findByUuid(string $uuid): ?TimelineEntry {
		$found = $this->findFirstOrNull(qb: $this->selectWhereEquals(columns: ['uuid' => $uuid]));
		if ($found instanceof TimelineEntry) {
			return $found;
		}

		return null;
	}//end findByUuid()

	/**
	 * The record behind one Nextcloud comment.
	 *
	 * @param integer $commentId The comment id.
	 *
	 * @return TimelineEntry|null The row, or null when the comment has none.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function findByComment(int $commentId): ?TimelineEntry {
		$found = $this->findFirstOrNull(qb: $this->selectWhereEquals(columns: ['comment_id' => $commentId]));
		if ($found instanceof TimelineEntry) {
			return $found;
		}

		return null;
	}//end findByComment()

	/**
	 * One object's timeline: pinned first, then newest.
	 *
	 * Four entries out of three hundred are the ones a colleague needs, and
	 * the sort is where that is delivered (D-4).
	 *
	 * @param string      $objectUuid The object the entries hang on.
	 * @param string|null $visibility Keep only entries carrying this flag; null returns every entry.
	 * @param integer     $limit      Page size.
	 * @param integer     $offset     Page offset.
	 *
	 * @return array<int, TimelineEntry> The rows.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function findForObject(
		string $objectUuid,
		?string $visibility = null,
		int $limit = 50,
		int $offset = 0,
	): array {
		$qb = $this->selectWhereEquals(columns: ['object_uuid' => $objectUuid]);
		$qb->orderBy('pinned', 'DESC')
			->addOrderBy('created', 'DESC')
			->setMaxResults($limit)
			->setFirstResult($offset);

		$this->whereEqualsWhenGiven(qb: $qb, column: 'visibility', value: $visibility);

		return $this->findEntities(query: $qb);
	}//end findForObject()

	/**
	 * Entries matching a term, across objects.
	 *
	 * The visibility predicate is part of the STATEMENT, never a filter on a
	 * page that was already built (D-1): filtering afterwards shortens every
	 * page by an unknown amount and still reports the unfiltered count.
	 *
	 * `$objectUuids` has three states and they are deliberately not two.
	 * `null` means "do not narrow by object": the caller resolves access per
	 * row, which is what the cross-object search does, because the set of
	 * objects a handler may read is unbounded. An EMPTY ARRAY means "narrow to
	 * nothing" and returns nothing, which is what a subject-scoped reader with
	 * an empty scope must get. Collapsing those two onto one empty value is
	 * how an access failure turns into a full read.
	 *
	 * @param string                 $term        The search term.
	 * @param array<int,string>|null $objectUuids The objects to narrow to, or null for no narrowing.
	 * @param string|null            $visibility  The visibility the caller is entitled to, or null for both.
	 * @param string|null            $kind        Narrow to one kind.
	 * @param integer                $limit       Page size, capped at SEARCH_LIMIT.
	 *
	 * @return array<int, TimelineEntry> The matching rows, newest first.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/unified-search-provider/spec.md
	 */
	public function search(string $term, ?array $objectUuids = null, ?string $visibility = null, ?string $kind = null, int $limit = 50): array {
		if (is_array($objectUuids) === true && $objectUuids === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->iLike('message', $qb->createNamedParameter('%'.$this->db->escapeLikeParameter($term).'%')))
			->orderBy('created', 'DESC')
			->setMaxResults(min(max($limit, 1), self::SEARCH_LIMIT));

		$this->whereObjectIn(qb: $qb, objectUuids: $objectUuids);
		$this->whereEqualsWhenGiven(qb: $qb, column: 'visibility', value: $visibility);

		return $this->findOfKind(qb: $qb, kind: $kind);
	}//end search()

	/**
	 * Every open follow-up of a kind, across objects the caller may read.
	 *
	 * A callback nobody made is a property of the request, not of the case
	 * (D-3), so the list that answers "what is still open" spans cases.
	 *
	 * @param array<int,string>|null $objectUuids The objects to narrow to; null does not narrow, [] narrows to nothing.
	 * @param string|null            $kind        Narrow to one kind.
	 * @param integer                $limit       Page size.
	 *
	 * @return array<int, TimelineEntry> The open rows, oldest first.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function findOpenFollowUps(?array $objectUuids = null, ?string $kind = null, int $limit = 50): array {
		if (is_array($objectUuids) === true && $objectUuids === []) {
			return [];
		}

		$qb = $this->selectWhereEquals(columns: ['follow_up' => TimelineEntry::FOLLOW_UP_OPEN]);
		$qb->orderBy('created', 'ASC')
			->setMaxResults($limit);

		$this->whereObjectIn(qb: $qb, objectUuids: $objectUuids);

		return $this->findOfKind(qb: $qb, kind: $kind);
	}//end findOpenFollowUps()

	/**
	 * Run the query, narrowed to one kind when one is given.
	 *
	 * @param IQueryBuilder $qb   The query.
	 * @param string|null   $kind The kind, or null for every kind.
	 *
	 * @return array<int, TimelineEntry> The entries.
	 */
	private function findOfKind(IQueryBuilder $qb, ?string $kind): array {
		$this->whereEqualsWhenGiven(qb: $qb, column: 'kind', value: $kind);

		return $this->findEntities(query: $qb);
	}//end findOfKind()

	/**
	 * Drop every record for one object.
	 *
	 * @param string $objectUuid The object being emptied.
	 *
	 * @return integer The number of rows removed.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function deleteForObject(string $objectUuid): int {
		return (int)$this->deleteWhereEquals(columns: ['object_uuid' => $objectUuid])->executeStatement();
	}//end deleteForObject()

	/**
	 * Drop the record behind one comment.
	 *
	 * @param integer $commentId The comment being deleted.
	 *
	 * @return integer The number of rows removed.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function deleteForComment(int $commentId): int {
		return (int)$this->deleteWhereEquals(columns: ['comment_id' => $commentId])->executeStatement();
	}//end deleteForComment()
}//end class
