<?php

/**
 * ActivityFilterService — Tier-2 read surface for the `activity`
 * integration leaf.
 *
 * NC Activity entries are core-generated and read-only; the Tier-2
 * surface is therefore narrow: filter (by type / actor / date) and
 * paginate (cursor-based) the entries linked to an OpenRegister object
 * via the `[or:{objectUuid}]` marker in the `activity.subject` column.
 *
 * This service deliberately preserves the wave-5.3 MarkerLookupTrait
 * carve-out: NC Activity writes a single string `subject` column and
 * that column is the marker target, so the marker-LIKE lookup remains
 * the canonical link mechanism here (unlike the Tier-2 Flow leaf which
 * moved to a dedicated link table). The filter/paginate logic wraps
 * the same marker query; it never replaces it.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCP\DB\IResult;
use OCP\IDBConnection;
use Throwable;
use OCA\OpenRegister\Notifications\Service\TimelineVisibilityServiceInterface;

/**
 * Filtered + paginated read access to NC Activity entries linked to an
 * OpenRegister object.
 */
class ActivityFilterService implements ActivityFilterServiceInterface {
	/**
	 * NC app id required for this leaf.
	 *
	 * @var string
	 */
	private const REQUIRED_APP = 'activity';

	/**
	 * Marker prefix written into `activity.subject`.
	 *
	 * @var string
	 */
	private const MARKER_PREFIX = '[or:';

	/**
	 * Default page size for entry listing.
	 *
	 * @var int
	 */
	private const DEFAULT_LIMIT = 100;

	/**
	 * Maximum page size to protect the upstream table.
	 *
	 * @var int
	 */
	private const MAX_LIMIT = 200;

	/**
	 * The page of a read that is refused or has nothing to serve.
	 *
	 * @var array{results: array<int,array<string,mixed>>, total: int, nextCursor: null}
	 */
	private const EMPTY_PAGE = [
		'results' => [],
		'total' => 0,
		'nextCursor' => null,
	];

	/**
	 * Constructor.
	 *
	 * @param IDBConnection      $db      NC DB connection.
	 * @param LinkServiceRuntime $runtime App availability check and the logger for the degraded (empty result) path.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LinkServiceRuntime $runtime,
	) {
	}//end __construct()

	/**
	 * Whether the NC Activity app is installed/enabled.
	 *
	 * @return bool
	 */
	public function isActivityAvailable(): bool {
		return $this->runtime->appManager()->isInstalled(self::REQUIRED_APP);
	}//end isActivityAvailable()

	/**
	 * Fetch a filtered, cursor-paginated page of activity entries for an
	 * OR object.
	 *
	 * @param ActivityFilter         $filter  The object, the optional type / actor / lower-bound filters and the
	 *                                        optional cursor (only rows with `timestamp` strictly below it, DESC paging).
	 * @param ActivityEntriesOptions $options The page size and the optional `internal` / `public` visibility filter.
	 *
	 * @return array{results: array<int,array<string,mixed>>, total: int, nextCursor: ?int}
	 *
	 * @spec openspec/changes/timeline-entry-visibility/specs/integration-activity/spec.md
	 */
	public function getActivityEntries(ActivityFilter $filter, ActivityEntriesOptions $options): array {
		if ($this->servesEntries(visibility: $options->visibility) === false) {
			return self::EMPTY_PAGE;
		}

		return $this->entryPage(filter: $filter, limit: $this->clampLimit(limit: $options->limit));
	}//end getActivityEntries()

	/**
	 * Whether a read is served at all: the activity app must be there, and a
	 * public-only read gets nothing because every row of this source is internal.
	 *
	 * @param string|null $visibility The requested `internal` / `public` filter, or null.
	 *
	 * @return bool
	 */
	private function servesEntries(?string $visibility): bool {
		if ($this->isActivityAvailable() === false) {
			return false;
		}

		return (is_string($visibility) === true
			&& strtolower(trim($visibility)) === TimelineVisibilityServiceInterface::PUBLIC_ENTRY) === false;
	}//end servesEntries()

	/**
	 * One page of normalised entries, the total without the cursor, and the next cursor.
	 *
	 * @param ActivityFilter $filter The filters, including the cursor.
	 * @param int            $limit  The clamped page size.
	 *
	 * @return array{results: array<int,array<string,mixed>>, total: int, nextCursor: ?int}
	 */
	private function entryPage(ActivityFilter $filter, int $limit): array {
		$rows = $this->queryRows(filter: $filter, limit: ($limit + 1));

		$hasMore = (count($rows) > $limit);
		$results = [];
		foreach (array_slice($rows, 0, $limit) as $row) {
			$results[] = $this->normaliseRow(row: $row);
		}

		return [
			'results' => $results,
			'total' => $this->countRows(filter: $filter->withCursor(cursor: null)),
			'nextCursor' => $this->nextCursor(results: $results, hasMore: $hasMore),
		];
	}//end entryPage()

	/**
	 * The cursor of the next page: the timestamp of the last entry, when more follow.
	 *
	 * @param array<int,array<string,mixed>> $results The normalised entries of this page.
	 * @param bool                           $hasMore Whether a further row was found.
	 *
	 * @return int|null
	 */
	private function nextCursor(array $results, bool $hasMore): ?int {
		if ($hasMore === false || empty($results) === true) {
			return null;
		}

		return (int)($results[(count($results) - 1)]['timestamp'] ?? 0);
	}//end nextCursor()

	/**
	 * Distinct activity `type` values for the dropdown source.
	 *
	 * @param string $objectUuid OR object UUID.
	 *
	 * @return array<int,string>
	 */
	public function getActivityTypes(string $objectUuid): array {
		return $this->distinctColumn(objectUuid: $objectUuid, column: 'type');
	}//end getActivityTypes()

	/**
	 * Distinct actor (`affecteduser`) values for the dropdown source.
	 *
	 * @param string $objectUuid OR object UUID.
	 *
	 * @return array<int,string>
	 */
	public function getActivityActors(string $objectUuid): array {
		return $this->distinctColumn(objectUuid: $objectUuid, column: 'affecteduser');
	}//end getActivityActors()

	/**
	 * Clamp a requested limit into the allowed range.
	 *
	 * @param int $limit Requested limit.
	 *
	 * @return int
	 */
	private function clampLimit(int $limit): int {
		if ($limit < 1) {
			return self::DEFAULT_LIMIT;
		}

		if ($limit > self::MAX_LIMIT) {
			return self::MAX_LIMIT;
		}

		return $limit;
	}//end clampLimit()

	/**
	 * Apply the object marker and the optional type, actor, after and cursor filters.
	 *
	 * @param \OCP\DB\QueryBuilder\IQueryBuilder $qb     Query builder.
	 * @param ActivityFilter                     $filter The filters to apply.
	 *
	 * @return void
	 */
	private function applyFilters(\OCP\DB\QueryBuilder\IQueryBuilder $qb, ActivityFilter $filter): void {
		$marker = self::MARKER_PREFIX . $filter->objectUuid . ']';
		$qb->where(
			$qb->expr()->iLike('subject', $qb->createNamedParameter('%' . $marker . '%'))
		);

		$this->andWhereEquals(qb: $qb, values: ['type' => $filter->type, 'affecteduser' => $filter->actor]);
		$this->andWhereTimestamps(qb: $qb, bounds: ['gte' => $filter->after, 'lt' => $filter->cursor()]);
	}//end applyFilters()

	/**
	 * Restrict each column to its value, in order, skipping a value that is null or ''.
	 *
	 * @param \OCP\DB\QueryBuilder\IQueryBuilder $qb     Query builder.
	 * @param array<string,string|null>          $values The value per column.
	 *
	 * @return void
	 */
	private function andWhereEquals(\OCP\DB\QueryBuilder\IQueryBuilder $qb, array $values): void {
		foreach ($values as $column => $value) {
			if (($value ?? '') !== '') {
				$qb->andWhere($qb->expr()->eq($column, $qb->createNamedParameter($value)));
			}
		}
	}//end andWhereEquals()

	/**
	 * Bound the timestamp column with each comparison, in order, skipping a null bound.
	 *
	 * @param \OCP\DB\QueryBuilder\IQueryBuilder $qb     Query builder.
	 * @param array<string,int|null>             $bounds The bound per expression-builder comparison ('gte', 'lt').
	 *
	 * @return void
	 */
	private function andWhereTimestamps(\OCP\DB\QueryBuilder\IQueryBuilder $qb, array $bounds): void {
		foreach ($bounds as $operator => $timestamp) {
			if ($timestamp !== null) {
				$qb->andWhere(
					$qb->expr()->{$operator}('timestamp', $qb->createNamedParameter($timestamp, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
				);
			}
		}
	}//end andWhereTimestamps()

	/**
	 * Run the paged SELECT and return raw rows.
	 *
	 * @param ActivityFilter $filter The filters, including the cursor.
	 * @param int            $limit  Page size (+1 for has-more detection).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function queryRows(ActivityFilter $filter, int $limit): array {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('activity_id', 'subject', 'type', 'affecteduser', 'timestamp', 'object_id')
				->from('activity');
			$this->applyFilters(qb: $qb, filter: $filter);
			$qb->orderBy('timestamp', 'DESC')
				->addOrderBy('activity_id', 'DESC')
				->setMaxResults($limit);

			$result = $qb->executeQuery();
			$rows = $this->fetchRows(result: $result);
			$result->closeCursor();

			return $rows;
		} catch (Throwable $e) {
			$this->logFailure(context: 'queryRows', e: $e);
			return [];
		}//end try
	}//end queryRows()

	/**
	 * Every remaining row of a query result.
	 *
	 * @param IResult $result The query result.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function fetchRows(IResult $result): array {
		$rows = [];
		$row = $result->fetch();
		while ($row !== false) {
			$rows[] = $row;
			$row = $result->fetch();
		}

		return $rows;
	}//end fetchRows()

	/**
	 * Count all rows matching the filters, without the cursor.
	 *
	 * @param ActivityFilter $filter The filters.
	 *
	 * @return int
	 */
	private function countRows(ActivityFilter $filter): int {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select($qb->func()->count('*', 'cnt'))->from('activity');
			$this->applyFilters(qb: $qb, filter: $filter);

			$result = $qb->executeQuery();
			$row = $result->fetch();
			$result->closeCursor();

			return (int)($row['cnt'] ?? 0);
		} catch (Throwable $e) {
			$this->logFailure(context: 'countRows', e: $e);
			return 0;
		}//end try
	}//end countRows()

	/**
	 * Fetch distinct non-empty values of a column across the marker set.
	 *
	 * @param string $objectUuid OR object UUID.
	 * @param string $column Column to distinct over.
	 *
	 * @return array<int,string>
	 */
	private function distinctColumn(string $objectUuid, string $column): array {
		if ($this->isActivityAvailable() === false) {
			return [];
		}

		try {
			$qb = $this->db->getQueryBuilder();
			$qb->selectDistinct($column)->from('activity');
			$this->applyFilters(qb: $qb, filter: new ActivityFilter(objectUuid: $objectUuid, type: null, actor: null, after: null));
			$qb->orderBy($column, 'ASC');

			$result = $qb->executeQuery();
			$values = [];
			$row = $result->fetch();
			while ($row !== false) {
				$value = (string)($row[$column] ?? '');
				if ($value !== '') {
					$values[] = $value;
				}

				$row = $result->fetch();
			}

			$result->closeCursor();
			return array_values(array_unique($values));
		} catch (Throwable $e) {
			$this->logFailure(context: 'distinctColumn:' . $column, e: $e);
			return [];
		}//end try
	}//end distinctColumn()

	/**
	 * Normalise an upstream activity row into the registry leaf row shape.
	 *
	 * Mirrors ActivityProvider::list() flattening (Phase B-3) so the
	 * bespoke CnActivityTab reads type / timestamp / actor at the row
	 * root without hand-walking `data.*`.
	 *
	 * @param array<string,mixed> $row Raw upstream row.
	 *
	 * @return array<string,mixed>
	 */
	private function normaliseRow(array $row): array {
		$activityId = (string)($row['activity_id'] ?? '');

		return [
			'id' => $activityId,
			'title' => (string)($row['subject'] ?? ''),
			'subject' => (string)($row['subject'] ?? ''),
			'type' => (string)($row['type'] ?? ''),
			'timestamp' => (int)($row['timestamp'] ?? 0),
			'affecteduser' => (string)($row['affecteduser'] ?? ''),
			'actor_id' => (string)($row['affecteduser'] ?? ''),
			'object_id' => (string)($row['object_id'] ?? ''),
			'url' => '/index.php/apps/activity/' . $activityId,
			// An NC Activity row records what a handler did, so it is always
			// internal; only a note ever carries the public flag.
			'visibility' => TimelineVisibilityServiceInterface::INTERNAL,
			'data' => $row,
		];
	}//end normaliseRow()

	/**
	 * Log a degraded-path failure (AD-23: any DB error → empty result).
	 *
	 * @param string $context Call-site context label.
	 * @param Throwable $e Caught throwable.
	 *
	 * @return void
	 */
	private function logFailure(string $context, Throwable $e): void {
		$this->runtime->logger()->debug(
			'[ActivityFilterService] ' . $context . ' failed: ' . $e->getMessage(),
			['exception' => $e]
		);
	}//end logFailure()
}//end class
