<?php

/**
 * DbalAggregator — runs a pushed-down aggregation against an external database.
 *
 * Builds the aggregate SELECT (scalar, categorical groupBy or date-bucket
 * series) for one DbalAggregationQuery and shapes the result rows. The
 * provider resolves the source, the schema columns and the connection first;
 * this class only turns that bound query into SQL and numbers.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectSource
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
 * @spec openspec/specs/dbal-virtual-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectSource;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Query\QueryBuilder;
use Psr\Log\LoggerInterface;

/**
 * Turns a bound aggregation into SQL and shapes the rows it returns.
 *
 * @spec openspec/specs/dbal-virtual-registers/spec.md
 */
final class DbalAggregator {

	/**
	 * The date-bucket gap units a series can ask for.
	 *
	 * @var list<string>
	 */
	private const GAPS = ['minute', 'hour', 'day', 'week', 'month', 'quarter', 'year'];

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Secret-free diagnostics.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Bind an aggregation to its connection and table expression.
	 *
	 * @param Connection      $connection  The DBAL connection.
	 * @param string          $table       The quoted table (or derived query) expression.
	 * @param DbalAggregation $aggregation The metric, field, filter and columns.
	 *
	 * @return DbalAggregationQuery The bound query.
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function query(Connection $connection, string $table, DbalAggregation $aggregation): DbalAggregationQuery {
		return new DbalAggregationQuery(
			connection: $connection,
			aggExpr: $this->expression(connection: $connection, aggregation: $aggregation),
			table: $table,
			aggregation: $aggregation
		);
	}//end query()

	/**
	 * Run the aggregation in the requested shape.
	 *
	 * @param DbalAggregationQuery $query The bound aggregation.
	 * @param DbalAggregationShape $shape The groupBy / dateBucket shape.
	 *
	 * @return array<string, mixed>|null `{value}` or `{groups}`, or null when the shape is not answerable.
	 *
	 * @throws DbalObjectSourceException When the external database returns an error.
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function run(DbalAggregationQuery $query, DbalAggregationShape $shape): ?array {
		try {
			// Date-bucket series (chart widgets).
			if ($shape->dateBucket !== null) {
				return $this->dateBucket(query: $query, dateBucket: $shape->dateBucket);
			}

			// Categorical groupBy.
			$groupField = (string)($shape->groupBy['field'] ?? '');
			if ($groupField !== '') {
				return $this->grouped(query: $query, groupField: $groupField);
			}

			return $this->scalar(query: $query);
		} catch (DbalException $e) {
			$this->logger->warning('[ObjectSource:dbal-source] aggregation query failed: ' . $e->getMessage());
			throw new DbalObjectSourceException('The external database returned an error.', 502, $e);
		}//end try
	}//end run()

	/**
	 * The aggregate SELECT expression of the metric.
	 *
	 * @param Connection      $connection  The DBAL connection.
	 * @param DbalAggregation $aggregation The metric and field.
	 *
	 * @return string The SQL expression.
	 */
	private function expression(Connection $connection, DbalAggregation $aggregation): string {
		if ($aggregation->metric === 'count') {
			return 'COUNT(*)';
		}

		$col = $this->quote(connection: $connection, identifier: (string)$aggregation->field);
		return match ($aggregation->metric) {
			'sum' => 'SUM(' . $col . ')',
			'avg' => 'AVG(' . $col . ')',
			'min' => 'MIN(' . $col . ')',
			'max' => 'MAX(' . $col . ')',
			default => 'COUNT(*)',
		};
	}//end expression()

	/**
	 * Categorical groupBy: one `{key, value}` per distinct value of the field.
	 *
	 * @param DbalAggregationQuery $query      The bound aggregation.
	 * @param string               $groupField The column to group by.
	 *
	 * @return array{groups: list<array{key: mixed, value: int|float|null}>}|null Null when the field is not a column.
	 */
	private function grouped(DbalAggregationQuery $query, string $groupField): ?array {
		if (in_array($groupField, $query->aggregation->columns, true) === false) {
			return null;
		}

		$quotedGroup = $this->quote(connection: $query->connection, identifier: $groupField);
		$qb = $query->connection->createQueryBuilder();
		$qb->select($quotedGroup . ' AS grpkey', $query->aggExpr . ' AS value')->from($query->table);
		$this->applyFilter(qb: $qb, query: $query);
		$qb->groupBy($quotedGroup);

		$groups = [];
		foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
			$groups[] = ['key' => ($row['grpkey'] ?? null), 'value' => $this->numify(query: $query, value: ($row['value'] ?? null))];
		}

		return ['groups' => $groups];
	}//end grouped()

	/**
	 * Scalar aggregation: one value over every matching row.
	 *
	 * @param DbalAggregationQuery $query The bound aggregation.
	 *
	 * @return array{value: int|float|null}
	 */
	private function scalar(DbalAggregationQuery $query): array {
		$qb = $query->connection->createQueryBuilder();
		$qb->select($query->aggExpr)->from($query->table);
		$this->applyFilter(qb: $qb, query: $query);

		return ['value' => $this->numify(query: $query, value: $qb->executeQuery()->fetchOne())];
	}//end scalar()

	/**
	 * Date-bucket series: one `{key, value}` per gap unit between start and end.
	 *
	 * @param DbalAggregationQuery $query      The bound aggregation.
	 * @param array<string, mixed> $dateBucket `{field, gap, start, end}`.
	 *
	 * @return array{groups: list<array{key: string, value: int|float|null}>}|null Null when the bucket is not answerable.
	 */
	private function dateBucket(DbalAggregationQuery $query, array $dateBucket): ?array {
		$bucketField = (string)($dateBucket['field'] ?? '');
		if (in_array($bucketField, $query->aggregation->columns, true) === false) {
			return null;
		}

		$bucketExpr = $this->bucketExpression(connection: $query->connection, field: $bucketField, gap: (string)($dateBucket['gap'] ?? ''));
		if ($bucketExpr === null) {
			return null;
		}

		$groups = [];
		foreach ($this->bucketQuery(query: $query, dateBucket: $dateBucket, bucketExpr: $bucketExpr)->executeQuery()->fetchAllAssociative() as $row) {
			$groups[] = ['key' => $this->isoBucketKey(raw: ($row['bucket'] ?? null)), 'value' => $this->numify(query: $query, value: ($row['value'] ?? null))];
		}

		return ['groups' => $groups];
	}//end dateBucket()

	/**
	 * The SELECT of a date-bucket series, bounded to [start, end).
	 *
	 * @param DbalAggregationQuery $query      The bound aggregation.
	 * @param array<string, mixed> $dateBucket `{field, gap, start, end}`.
	 * @param string               $bucketExpr The bucket SQL expression.
	 *
	 * @return QueryBuilder The query, ready to execute.
	 */
	private function bucketQuery(DbalAggregationQuery $query, array $dateBucket, string $bucketExpr): QueryBuilder {
		$boundLhs = $this->boundColumn(connection: $query->connection, field: (string)$dateBucket['field']);

		$qb = $query->connection->createQueryBuilder();
		$qb->select($bucketExpr . ' AS bucket', $query->aggExpr . ' AS value')->from($query->table);
		$qb->andWhere($boundLhs . ' >= ' . $qb->createNamedParameter((string)($dateBucket['start'] ?? '')));
		$qb->andWhere($boundLhs . ' < ' . $qb->createNamedParameter((string)($dateBucket['end'] ?? '')));
		$this->applyFilter(qb: $qb, query: $query);
		$qb->groupBy('bucket')->orderBy('bucket', 'ASC');

		return $qb;
	}//end bucketQuery()

	/**
	 * The left-hand side the bucket bounds compare against.
	 *
	 * The bounds arrive as ISO-8601 strings; comparing a date/timestamp column
	 * to an untyped param is dialect-fragile, so the column is pinned to
	 * timestamptz on Postgres.
	 *
	 * @param Connection $connection The DBAL connection.
	 * @param string     $field      The bucket column.
	 *
	 * @return string The quoted column, cast on Postgres.
	 */
	private function boundColumn(Connection $connection, string $field): string {
		$boundLhs = $this->quote(connection: $connection, identifier: $field);
		if ($connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
			$boundLhs .= '::timestamptz';
		}

		return $boundLhs;
	}//end boundColumn()

	/**
	 * The SQL expression that truncates the column to the gap unit.
	 *
	 * @param Connection $connection The DBAL connection.
	 * @param string     $field      The bucket column.
	 * @param string     $gap        The gap unit.
	 *
	 * @return string|null The expression, or null when the platform cannot bucket that gap in SQL.
	 */
	private function bucketExpression(Connection $connection, string $field, string $gap): ?string {
		if (in_array($gap, self::GAPS, true) === false) {
			return null;
		}

		$platform = $connection->getDatabasePlatform();
		$col      = $this->quote(connection: $connection, identifier: $field);

		// Postgres date_trunc handles every supported gap unit natively.
		if ($platform instanceof PostgreSQLPlatform) {
			return "date_trunc('" . $gap . "', " . $col . '::timestamptz)';
		}

		if ($platform instanceof AbstractMySQLPlatform) {
			return $this->mysqlBucketExpression(col: $col, gap: $gap);
		}

		return null;
	}//end bucketExpression()

	/**
	 * MySQL: DATE_FORMAT covers the fixed-width gaps; week and quarter need
	 * bespoke maths, so those are not bucketed in SQL.
	 *
	 * @param string $col The quoted column.
	 * @param string $gap The gap unit.
	 *
	 * @return string|null The expression, or null for week and quarter.
	 */
	private function mysqlBucketExpression(string $col, string $gap): ?string {
		return match ($gap) {
			'minute' => 'DATE_FORMAT(' . $col . ", '%Y-%m-%dT%H:%i:00Z')",
			'hour' => 'DATE_FORMAT(' . $col . ", '%Y-%m-%dT%H:00:00Z')",
			'day' => 'DATE_FORMAT(' . $col . ", '%Y-%m-%dT00:00:00Z')",
			'month' => 'DATE_FORMAT(' . $col . ", '%Y-%m-01T00:00:00Z')",
			'year' => 'DATE_FORMAT(' . $col . ", '%Y-01-01T00:00:00Z')",
			default => null,
		};
	}//end mysqlBucketExpression()

	/**
	 * Add the aggregation filter: equality for a scalar criterion, operators for an array.
	 *
	 * Keys that are not schema columns are ignored.
	 *
	 * @param QueryBuilder         $qb    The query being built.
	 * @param DbalAggregationQuery $query The bound aggregation.
	 *
	 * @return void
	 */
	private function applyFilter(QueryBuilder $qb, DbalAggregationQuery $query): void {
		foreach ($query->aggregation->filter as $col => $criterion) {
			if (is_string($col) === false || in_array($col, $query->aggregation->columns, true) === false) {
				continue;
			}

			$quoted = $this->quote(connection: $query->connection, identifier: $col);
			if (is_array($criterion) === false) {
				$qb->andWhere($quoted . ' = ' . $qb->createNamedParameter($criterion));
				continue;
			}

			$this->applyOperatorCriterion(qb: $qb, quoted: $quoted, criterion: $criterion);
		}
	}//end applyFilter()

	/**
	 * Add one `{op: value}` criterion per operator; unknown operators are ignored.
	 *
	 * @param QueryBuilder         $qb        The query being built.
	 * @param string               $quoted    The quoted column.
	 * @param array<string, mixed> $criterion Operator => operand.
	 *
	 * @return void
	 */
	private function applyOperatorCriterion(QueryBuilder $qb, string $quoted, array $criterion): void {
		foreach ($criterion as $op => $onValue) {
			if ((string)$op === 'in' || (string)$op === 'notIn') {
				$this->applyListCriterion(qb: $qb, quoted: $quoted, operator: (string)$op, operand: $onValue);
				continue;
			}

			$this->applyComparison(qb: $qb, quoted: $quoted, operator: (string)$op, operand: $onValue);
		}
	}//end applyOperatorCriterion()

	/**
	 * Add a `gt` / `gte` / `lt` / `lte` / `ne` comparison; any other operator adds nothing.
	 *
	 * @param QueryBuilder $qb       The query being built.
	 * @param string       $quoted   The quoted column.
	 * @param string       $operator The operator.
	 * @param mixed        $operand  The value to compare with.
	 *
	 * @return void
	 */
	private function applyComparison(QueryBuilder $qb, string $quoted, string $operator, mixed $operand): void {
		$sqlOn = match ($operator) {
			'gt' => '>',
			'gte' => '>=',
			'lt' => '<',
			'lte' => '<=',
			'ne' => '<>',
			default => null,
		};
		if ($sqlOn !== null) {
			$qb->andWhere($quoted . ' ' . $sqlOn . ' ' . $qb->createNamedParameter($operand));
		}
	}//end applyComparison()

	/**
	 * Add an `in` / `notIn` criterion. An empty list matches nothing for
	 * `in` and excludes nothing for `notIn`.
	 *
	 * @param QueryBuilder $qb       The query being built.
	 * @param string       $quoted   The quoted column.
	 * @param string       $operator `in` or `notIn`.
	 * @param mixed        $operand  The list of values.
	 *
	 * @return void
	 */
	private function applyListCriterion(QueryBuilder $qb, string $quoted, string $operator, mixed $operand): void {
		$list = [];
		if (is_array($operand) === true) {
			$list = array_values($operand);
		}

		if ($list === []) {
			$qb->andWhere(match ($operator) { 'in' => '1 = 0', default => '1 = 1' });
			return;
		}

		$params  = array_map(static fn (mixed $item): string => $qb->createNamedParameter($item), $list);
		$keyword = match ($operator) { 'in' => ' IN (', default => ' NOT IN (' };
		$qb->andWhere($quoted . $keyword . implode(', ', $params) . ')');
	}//end applyListCriterion()

	/**
	 * Cast a raw aggregate value: count is an int, the other metrics a float or null.
	 *
	 * @param DbalAggregationQuery $query The bound aggregation (for its metric).
	 * @param mixed                $value The raw value.
	 *
	 * @return int|float|null
	 */
	private function numify(DbalAggregationQuery $query, mixed $value): int|float|null {
		if ($query->aggregation->metric === 'count') {
			return (int)$value;
		}

		if ($value === null || is_numeric($value) === false) {
			return null;
		}

		return (float)$value;
	}//end numify()

	/**
	 * Normalise a bucket label to an ISO-8601 UTC key; an unparseable label is kept as is.
	 *
	 * @param mixed $raw The raw bucket value.
	 *
	 * @return string The key.
	 */
	private function isoBucketKey(mixed $raw): string {
		$label = (string)$raw;
		$stamp = strtotime($label);
		if ($stamp === false) {
			return $label;
		}

		return gmdate('Y-m-d\TH:i:s\Z', $stamp);
	}//end isoBucketKey()

	/**
	 * Quote an identifier for the connection's platform.
	 *
	 * @param Connection $connection The DBAL connection.
	 * @param string     $identifier The identifier.
	 *
	 * @return string The quoted identifier.
	 */
	private function quote(Connection $connection, string $identifier): string {
		return $connection->getDatabasePlatform()->quoteIdentifier($identifier);
	}//end quote()
}//end class
