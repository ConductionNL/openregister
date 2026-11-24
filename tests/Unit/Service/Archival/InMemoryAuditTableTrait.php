<?php

/**
 * An audit trail table held in memory, behind the query-builder calls the
 * audit diff store and the hash service make.
 *
 * Only the shapes those two classes build are understood: a select with
 * comparisons on columns, ordered by id and optionally limited; an update of
 * columns by id; and an insert. Anything else fails the test loudly instead
 * of answering something plausible.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/gdpr-data-subject-rights/spec.md#requirement-the-trail-keeps-the-act-and-loses-the-values-req-aao-006
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- test double.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- mock callbacks use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Archival;

use DateTimeInterface;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IParameter;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use RuntimeException;

/**
 * The audit table as rows in memory, and a connection over it.
 */
trait InMemoryAuditTableTrait {

	/**
	 * The rows, by id, as the database would hand them back.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $auditRows = [];

	/**
	 * A connection whose query builders read and write $auditRows.
	 *
	 * @return IDBConnection The connection.
	 */
	private function auditConnection(): IDBConnection {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->auditBuilder());

		return $db;
	}//end auditConnection()

	/**
	 * Store a row the way the database would: JSON text and formatted dates.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return void
	 */
	private function seedAuditRow(array $row): void {
		$this->auditRows[(int)$row['id']] = array_map(fn (mixed $v): mixed => $this->stored(value: $v), $row);
		ksort($this->auditRows);
	}//end seedAuditRow()

	private function stored(mixed $value): mixed {
		if (is_array($value) === true) {
			return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		}

		if ($value instanceof DateTimeInterface) {
			return $value->format('Y-m-d H:i:s');
		}

		return $value;
	}//end stored()

	/**
	 * One query builder over the table.
	 *
	 * @return IQueryBuilder The builder.
	 */
	private function auditBuilder(): IQueryBuilder {
		$state = (object)['kind' => null, 'columns' => [], 'conditions' => [], 'sets' => [], 'order' => 'ASC', 'limit' => null, 'lastId' => null];
		$conditions = [];

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($this->auditExpressions(conditions: $conditions));
		$qb->method('createNamedParameter')->willReturnCallback(fn (mixed $value): IParameter => $this->auditParameter(value: $value));
		$qb->method('select')->willReturnCallback(function (mixed ...$columns) use ($qb, $state): IQueryBuilder {
			$state->kind = 'select';
			$state->columns = $columns;
			return $qb;
		});
		$qb->method('from')->willReturnSelf();
		foreach (['where', 'andWhere'] as $method) {
			$qb->method($method)->willReturnCallback(function (string $token) use ($qb, $state, &$conditions): IQueryBuilder {
				$state->conditions[] = $conditions[$token];
				return $qb;
			});
		}

		$qb->method('orderBy')->willReturnCallback(function (string $column, string $order) use ($qb, $state): IQueryBuilder {
			$state->order = $order;
			return $qb;
		});
		$qb->method('setMaxResults')->willReturnCallback(function (?int $limit) use ($qb, $state): IQueryBuilder {
			$state->limit = $limit;
			return $qb;
		});
		$qb->method('update')->willReturnCallback(function () use ($qb, $state): IQueryBuilder {
			$state->kind = 'update';
			return $qb;
		});
		$qb->method('insert')->willReturnCallback(function () use ($qb, $state): IQueryBuilder {
			$state->kind = 'insert';
			return $qb;
		});
		foreach (['set', 'setValue'] as $method) {
			$qb->method($method)->willReturnCallback(function (string $column, IParameter $value) use ($qb, $state): IQueryBuilder {
				$state->sets[$column] = $value->value;
				return $qb;
			});
		}

		$qb->method('executeQuery')->willReturnCallback(fn (): IResult => $this->auditResult(rows: $this->selected(state: $state)));
		$qb->method('executeStatement')->willReturnCallback(fn (): int => $this->written(state: $state));
		$qb->method('getLastInsertId')->willReturnCallback(fn (): int => (int)$state->lastId);

		return $qb;
	}//end auditBuilder()

	/**
	 * The comparisons the builders use, each kept as a predicate under the token it returns.
	 *
	 * @param array<string, callable> $conditions The predicates, by token (filled).
	 *
	 * @return IExpressionBuilder The expression builder.
	 */
	private function auditExpressions(array &$conditions): IExpressionBuilder {
		$expr = $this->createMock(IExpressionBuilder::class);
		$compare = [
			'eq' => fn (mixed $a, mixed $b): bool => (string)$a === (string)$b,
			'neq' => fn (mixed $a, mixed $b): bool => (string)$a !== (string)$b,
			'gt' => fn (mixed $a, mixed $b): bool => (int)$a > (int)$b,
			'lt' => fn (mixed $a, mixed $b): bool => (int)$a < (int)$b,
			'lte' => fn (mixed $a, mixed $b): bool => (int)$a <= (int)$b,
			'gte' => fn (mixed $a, mixed $b): bool => (int)$a >= (int)$b,
		];
		foreach ($compare as $name => $test) {
			$expr->method($name)->willReturnCallback(function (string $column, IParameter $value) use (&$conditions, $test): string {
				$token = 'c'.count($conditions);
				$conditions[$token] = fn (array $row): bool => (($row[$column] ?? null) !== null && $test($row[$column], $value->value));
				return $token;
			});
		}

		$expr->method('isNull')->willReturnCallback(function (string $column) use (&$conditions): string {
			$token = 'c'.count($conditions);
			$conditions[$token] = fn (array $row): bool => (($row[$column] ?? null) === null);
			return $token;
		});
		$expr->method('isNotNull')->willReturnCallback(function (string $column) use (&$conditions): string {
			$token = 'c'.count($conditions);
			$conditions[$token] = fn (array $row): bool => (($row[$column] ?? null) !== null);
			return $token;
		});

		return $expr;
	}//end auditExpressions()

	private function auditParameter(mixed $value): IParameter {
		return new class($this->stored(value: $value)) implements IParameter {
			public function __construct(public readonly mixed $value) {
			}

			public function __toString(): string {
				return ':param';
			}
		};
	}//end auditParameter()

	/**
	 * The rows a select answers with.
	 *
	 * @param object $state The builder's state.
	 *
	 * @return list<array<string, mixed>> The rows.
	 */
	private function selected(object $state): array {
		if ($state->kind !== 'select') {
			throw new RuntimeException('The in-memory audit table only answers selects with executeQuery().');
		}

		$rows = array_values(array_filter($this->auditRows, fn (array $row): bool => $this->rowMatches(state: $state, row: $row)));
		if ($state->order === 'DESC') {
			$rows = array_reverse($rows);
		}

		if ($state->limit !== null) {
			$rows = array_slice($rows, 0, $state->limit);
		}

		if ($state->columns === ['*']) {
			return $rows;
		}

		return array_map(fn (array $row): array => array_intersect_key($row + array_fill_keys($state->columns, null), array_flip($state->columns)), $rows);
	}//end selected()

	private function rowMatches(object $state, array $row): bool {
		foreach ($state->conditions as $condition) {
			if ($condition($row) === false) {
				return false;
			}
		}

		return true;
	}//end rowMatches()

	/**
	 * Apply an update or an insert.
	 *
	 * @param object $state The builder's state.
	 *
	 * @return int The rows written.
	 */
	private function written(object $state): int {
		if ($state->kind === 'insert') {
			$state->lastId = (max(array_merge([0], array_keys($this->auditRows))) + 1);
			$this->auditRows[$state->lastId] = ['id' => $state->lastId, 'hash' => null, 'previous_hash' => null, 'purged_at' => null] + $state->sets;
			return 1;
		}

		if ($state->kind !== 'update') {
			throw new RuntimeException('The in-memory audit table only writes updates and inserts.');
		}

		$count = 0;
		foreach ($this->auditRows as $id => $row) {
			if ($this->rowMatches(state: $state, row: $row) === true) {
				$this->auditRows[$id] = $state->sets + $row;
				$count++;
			}
		}

		return $count;
	}//end written()

	/**
	 * A result over rows.
	 *
	 * @param list<array<string, mixed>> $rows The rows.
	 *
	 * @return IResult The result.
	 */
	private function auditResult(array $rows): IResult {
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn($rows);
		$result->method('fetch')->willReturnCallback(function () use (&$rows): array|false {
			return (array_shift($rows) ?? false);
		});
		$result->method('fetchOne')->willReturnCallback(function () use ($rows): mixed {
			if ($rows === []) {
				return false;
			}

			return reset($rows[0]);
		});

		return $result;
	}//end auditResult()
}//end trait
