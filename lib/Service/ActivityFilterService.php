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

use OCP\App\IAppManager;
use OCP\DB\IResult;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
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
	 * Constructor.
	 *
	 * @param IDBConnection $db NC DB connection.
	 * @param IAppManager $appManager NC app manager (availability check).
	 * @param LoggerInterface $logger Logger for the degraded (empty result) path.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether the NC Activity app is installed/enabled.
	 *
	 * @return bool
	 */
	public function isActivityAvailable(): bool {
		return $this->appManager->isInstalled(self::REQUIRED_APP);
	}//end isActivityAvailable()

	/**
	 * Fetch a filtered, cursor-paginated page of activity entries for an
	 * OR object.
	 *
	 * @param string $objectUuid OR object UUID (marker target).
	 * @param string|null $type Optional exact activity `type` filter.
	 * @param string|null $actor Optional exact `affecteduser` filter.
	 * @param int|null $after Optional lower-bound Unix timestamp (date range).
	 * @param int $limit Page size (1..MAX_LIMIT, default DEFAULT_LIMIT).
	 * @param int|null $cursor Optional cursor — only rows with `timestamp` strictly
	 *                         less than this value are returned (DESC paging).
	 * @param string|null $visibility Optional `internal` / `public` filter. Every row of this
	 *                                source is internal, so a public-only read returns nothing.
	 *
	 * @return array{results: array<int,array<string,mixed>>, total: int, nextCursor: ?int}
	 *
	 * @spec openspec/changes/timeline-entry-visibility/specs/integration-activity/spec.md
	 */
	public function getActivityEntries(
		string $objectUuid,
		?string $type = null,
		?string $actor = null,
		?int $after = null,
		int $limit = self::DEFAULT_LIMIT,
		?int $cursor = null,
		?string $visibility = null,
	): array {
		if ($this->isActivityAvailable() === false) {
			return [
				'results' => [],
				'total' => 0,
				'nextCursor' => null,
			];
		}

		if (is_string($visibility) === true
			&& strtolower(trim($visibility)) === TimelineVisibilityServiceInterface::PUBLIC_ENTRY
		) {
			return [
				'results' => [],
				'total' => 0,
				'nextCursor' => null,
			];
		}

		$limit = $this->clampLimit(limit: $limit);
		$filter = new ActivityFilter(objectUuid: $objectUuid, type: $type, actor: $actor, after: $after);
		$rows = $this->queryRows(filter: $filter->withCursor(cursor: $cursor), limit: ($limit + 1));

		$hasMore = (count($rows) > $limit);
		if ($hasMore === true) {
			$rows = array_slice($rows, 0, $limit);
		}

		$results = [];
		foreach ($rows as $row) {
			$results[] = $this->normaliseRow(row: $row);
		}

		$nextCursor = null;
		if ($hasMore === true && empty($results) === false) {
			$nextCursor = (int)($results[(count($results) - 1)]['timestamp'] ?? 0);
		}

		return [
			'results' => $results,
			'total' => $this->countRows(filter: $filter),
			'nextCursor' => $nextCursor,
		];
	}//end getActivityEntries()

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

		$this->andWhereEquals(qb: $qb, column: 'type', value: $filter->type);
		$this->andWhereEquals(qb: $qb, column: 'affecteduser', value: $filter->actor);
		$this->andWhereTimestamp(qb: $qb, operator: 'gte', timestamp: $filter->after);
		$this->andWhereTimestamp(qb: $qb, operator: 'lt', timestamp: $filter->cursor());
	}//end applyFilters()

	/**
	 * Restrict a column to a value, unless the value is null or ''.
	 *
	 * @param \OCP\DB\QueryBuilder\IQueryBuilder $qb     Query builder.
	 * @param string                             $column The column.
	 * @param string|null                        $value  The value, or null/'' for no restriction.
	 *
	 * @return void
	 */
	private function andWhereEquals(\OCP\DB\QueryBuilder\IQueryBuilder $qb, string $column, ?string $value): void {
		if (($value ?? '') !== '') {
			$qb->andWhere($qb->expr()->eq($column, $qb->createNamedParameter($value)));
		}
	}//end andWhereEquals()

	/**
	 * Bound the timestamp column with the given comparison, unless the bound is null.
	 *
	 * @param \OCP\DB\QueryBuilder\IQueryBuilder $qb        Query builder.
	 * @param string                             $operator  Expression-builder comparison: 'gte' or 'lt'.
	 * @param int|null                           $timestamp The bound, or null for none.
	 *
	 * @return void
	 */
	private function andWhereTimestamp(\OCP\DB\QueryBuilder\IQueryBuilder $qb, string $operator, ?int $timestamp): void {
		if ($timestamp !== null) {
			$qb->andWhere(
				$qb->expr()->{$operator}('timestamp', $qb->createNamedParameter($timestamp, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
			);
		}
	}//end andWhereTimestamp()

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
		$this->logger->debug(
			'[ActivityFilterService] ' . $context . ' failed: ' . $e->getMessage(),
			['exception' => $e]
		);
	}//end logFailure()
}//end class
