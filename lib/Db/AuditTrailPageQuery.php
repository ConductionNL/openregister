<?php

/**
 * One page of the instance-wide audit trail, by cursor.
 *
 * The audit trail grows without bound, so the list behind the audit page
 * pages by KEYSET on the row id (newest first) rather than by offset, and
 * never counts the table: an offset of 200,000 reads and discards 200,000
 * rows, and a COUNT on page load is the same scan again before the first row
 * is shown. The id is monotonic with `created`, so "newest first" and "id
 * descending" are the same order, and the primary key is the index.
 *
 * The six filters are the ones the audit page offers: actor, period, action,
 * register, schema and object, plus full text on the change summary. A filter
 * value that is empty is no filter; a filter that is set is always applied,
 * never silently dropped, because a dropped filter answers the whole trail
 * with a 200 and reads as a search that matched everything.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/audit-log-page/specs/audit-trail-immutable/spec.md#requirement-an-instance-wide-audit-list-with-filters
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTimeImmutable;
use Exception;
use InvalidArgumentException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Keyset-paged reader of the audit trail.
 *
 * @spec openspec/changes/audit-log-page/specs/audit-trail-immutable/spec.md#requirement-an-instance-wide-audit-list-with-filters
 */
class AuditTrailPageQuery {

	/**
	 * The largest page one call answers.
	 *
	 * @var int
	 */
	public const MAX_LIMIT = 500;

	/**
	 * The filter keys this query understands, and the column each reads.
	 *
	 * @var array<string, string>
	 */
	public const FILTER_COLUMNS = [
		'actor' => 'user',
		'action' => 'action',
		'register' => 'register',
		'schema' => 'schema',
		'object' => 'object_uuid',
	];

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The database.
	 */
	public function __construct(
		private readonly IDBConnection $db,
	) {
	}//end __construct()

	/**
	 * One page, newest first.
	 *
	 * @param array<string, mixed> $filters  Keys of FILTER_COLUMNS plus `from` and `to` (dates).
	 * @param string|null          $search   Full text on the change summary.
	 * @param int                  $limit    Page size, at most MAX_LIMIT.
	 * @param int|null             $beforeId The cursor: only rows with a smaller id.
	 *
	 * @return array{results: AuditTrail[], nextCursor: int|null} The rows and the cursor of the next page, null at the end.
	 *
	 * @throws \InvalidArgumentException When `from` or `to` is not a date.
	 *
	 * @spec openspec/changes/audit-log-page/specs/audit-trail-immutable/spec.md#requirement-an-instance-wide-audit-list-with-filters
	 */
	public function page(array $filters, ?string $search, int $limit, ?int $beforeId = null): array {
		$limit = max(1, min($limit, self::MAX_LIMIT));

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('openregister_audit_trails');

		$this->applyFilters(qb: $qb, filters: $filters, search: $search);

		if ($beforeId !== null && $beforeId > 0) {
			$qb->andWhere($qb->expr()->lt('id', $qb->createNamedParameter($beforeId, IQueryBuilder::PARAM_INT)));
		}

		// One row more than the page, to know whether a next page exists
		// without counting anything.
		$qb->orderBy('id', 'DESC')->setMaxResults($limit + 1);

		$rows = [];
		$result = $qb->executeQuery();
		while (($row = $result->fetch()) !== false) {
			$rows[] = $this->hydrate(row: $row);
		}

		$result->closeCursor();

		$nextCursor = null;
		if (count($rows) > $limit) {
			$rows = array_slice($rows, 0, $limit);
			$nextCursor = (int)end($rows)->getId();
		}

		return ['results' => $rows, 'nextCursor' => $nextCursor];
	}//end page()

	/**
	 * Every row matching the filters, in pages, up to a ceiling.
	 *
	 * @param array<string, mixed> $filters The filters, as for page().
	 * @param string|null          $search  Full text on the change summary.
	 * @param int                  $ceiling The most rows to return.
	 *
	 * @return array{results: AuditTrail[], truncated: bool} The rows, and whether more matched than the ceiling.
	 *
	 * @spec openspec/changes/audit-log-page/specs/audit-trail-immutable/spec.md#requirement-the-filtered-audit-list-exports-with-its-hash-chain
	 */
	public function collect(array $filters, ?string $search, int $ceiling): array {
		$rows = [];
		$cursor = null;
		$held = 0;
		do {
			$page = $this->page(
				filters: $filters,
				search: $search,
				limit: min(self::MAX_LIMIT, ($ceiling - $held + 1)),
				beforeId: $cursor
			);
			$rows = array_merge($rows, $page['results']);
			$held = count($rows);
			$cursor = $page['nextCursor'];
		} while ($cursor !== null && $held <= $ceiling);

		$truncated = count($rows) > $ceiling;

		return ['results' => array_slice($rows, 0, $ceiling), 'truncated' => $truncated];
	}//end collect()

	/**
	 * Apply the filters and the full-text term.
	 *
	 * @param IQueryBuilder        $qb      The query.
	 * @param array<string, mixed> $filters The filters.
	 * @param string|null          $search  The full-text term.
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException When `from` or `to` is not a date.
	 */
	private function applyFilters(IQueryBuilder $qb, array $filters, ?string $search): void {
		foreach (self::FILTER_COLUMNS as $key => $column) {
			$this->applyListFilter(qb: $qb, column: $column, value: ($filters[$key] ?? null));
		}

		foreach (['from' => 'gte', 'to' => 'lte'] as $key => $operator) {
			$value = trim((string)($filters[$key] ?? ''));
			if ($value === '') {
				continue;
			}

			$date = $this->date(value: $value, endOfDay: ($key === 'to'));
			$qb->andWhere($qb->expr()->$operator('created', $qb->createNamedParameter($date, IQueryBuilder::PARAM_STR)));
		}

		$search = trim((string)$search);
		if ($search !== '') {
			$qb->andWhere(
				$qb->expr()->like('changed', $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($search) . '%'))
			);
		}
	}//end applyFilters()

	/**
	 * One equality or list filter; an empty value is no filter.
	 *
	 * @param IQueryBuilder $qb     The query.
	 * @param string        $column The column.
	 * @param mixed         $value  A value, a comma-separated list, or an array.
	 *
	 * @return void
	 */
	private function applyListFilter(IQueryBuilder $qb, string $column, mixed $value): void {
		if ($value === null || $value === '' || $value === []) {
			return;
		}

		$values = $value;
		if (is_array($value) === false) {
			$values = explode(',', (string)$value);
		}

		$values = array_values(array_filter(array_map(static fn ($v): string => trim((string)$v), $values), static fn (string $v): bool => $v !== ''));
		if ($values === []) {
			return;
		}

		if (count($values) === 1) {
			$qb->andWhere($qb->expr()->eq($column, $qb->createNamedParameter($values[0])));
			return;
		}

		$qb->andWhere($qb->expr()->in($column, $qb->createNamedParameter($values, IQueryBuilder::PARAM_STR_ARRAY)));
	}//end applyListFilter()

	/**
	 * An entity from a database row.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return AuditTrail The entry.
	 */
	private function hydrate(array $row): AuditTrail {
		$entry = new AuditTrail();
		return $entry->fromRow($row);
	}//end hydrate()

	/**
	 * A period bound as the database's datetime text.
	 *
	 * A bare date as `to` means the whole of that day: "until 9 October"
	 * that stops at midnight would drop the day the reader named.
	 *
	 * @param string $value    The bound as given.
	 * @param bool   $endOfDay Whether a bare date means the end of the day.
	 *
	 * @return string The bound, `Y-m-d H:i:s`.
	 *
	 * @throws \InvalidArgumentException When the value is not a date.
	 */
	private function date(string $value, bool $endOfDay): string {
		try {
			$date = new DateTimeImmutable($value);
		} catch (Exception) {
			throw new InvalidArgumentException(sprintf('"%s" is not a date.', $value));
		}

		if ($endOfDay === true && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
			$date = $date->setTime(23, 59, 59);
		}

		return $date->format('Y-m-d H:i:s');
	}//end date()
}//end class
