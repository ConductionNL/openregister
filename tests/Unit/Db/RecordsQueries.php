<?php

/**
 * Query recording harness for mapper behaviour tests.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IParameter;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Builds IDBConnection doubles whose query builders record every call.
 *
 * Each `getQueryBuilder()` opens a new entry in `$this->queries`, a list of
 * rendered calls such as `where(eq(object_uuid, :p0))`. Bound values are kept
 * in `$this->params` keyed by placeholder. `executeQuery()` hands out the next
 * queued result set from `$this->results`; `executeStatement()` answers
 * `$this->affected`.
 */
trait RecordsQueries {

	/**
	 * Recorded calls per query builder.
	 *
	 * @var list<list<string>>
	 */
	protected array $queries = [];

	/**
	 * Bound parameters, placeholder => [value, type].
	 *
	 * @var array<string, array{0: mixed, 1: mixed}>
	 */
	protected array $params = [];

	/**
	 * Result sets handed out by executeQuery(), in order.
	 *
	 * @var list<list<array<string, mixed>>>
	 */
	protected array $results = [];

	/**
	 * Rows affected by executeStatement().
	 *
	 * @var integer
	 */
	protected int $affected = 1;

	/**
	 * Outcomes of the next executeStatement() calls, in order: an int is
	 * returned, a Throwable is thrown. When empty, `$affected` is returned.
	 *
	 * @var list<int|\Throwable>
	 */
	protected array $outcomes = [];

	/**
	 * Build a connection whose query builders record into this test.
	 *
	 * @return IDBConnection&MockObject
	 */
	protected function recordingDb(): IDBConnection&MockObject {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->recordingQb());
		$db->method('escapeLikeParameter')->willReturnCallback(
			static fn (string $value): string => addcslashes($value, '\\_%')
		);
		$db->method('getDatabasePlatform')->willReturnCallback(
			fn () => $this->createMock(\Doctrine\DBAL\Platforms\MySQLPlatform::class)
		);
		return $db;
	}//end recordingDb()

	/**
	 * All calls of query $index joined into one line.
	 *
	 * @param int $index The query number (0-based).
	 *
	 * @return string
	 */
	protected function sql(int $index=0): string {
		return implode(' ', ($this->queries[$index] ?? []));
	}//end sql()

	/**
	 * The value bound to a placeholder.
	 *
	 * @param string $placeholder The placeholder, e.g. ':p0'.
	 *
	 * @return mixed
	 */
	protected function bound(string $placeholder): mixed {
		return $this->params[$placeholder][0];
	}//end bound()

	/**
	 * Every bound value in binding order.
	 *
	 * @return list<mixed>
	 */
	protected function boundValues(): array {
		return array_values(array_map(static fn (array $p): mixed => $p[0], $this->params));
	}//end boundValues()

	/**
	 * Render a call argument.
	 *
	 * @param mixed $value The argument.
	 *
	 * @return string
	 */
	private function renderArg(mixed $value): string {
		if ($value === null) {
			return 'null';
		}

		if (is_bool($value) === true) {
			return $value ? 'true' : 'false';
		}

		if (is_scalar($value) === true) {
			return (string) $value;
		}

		if (is_array($value) === true) {
			return '[' . implode(', ', array_map(fn ($v): string => $this->renderArg($v), $value)) . ']';
		}

		if ($value instanceof \Stringable) {
			return (string) $value;
		}

		if ($value instanceof IQueryBuilder) {
			return 'subquery';
		}

		return get_class($value);
	}//end renderArg()

	/**
	 * Render name(args).
	 *
	 * @param string $name The method.
	 * @param array  $args The arguments.
	 *
	 * @return string
	 */
	private function renderCall(string $name, array $args): string {
		while ($args !== [] && end($args) === null) {
			array_pop($args);
		}

		return $name . '(' . implode(', ', array_map(fn ($v): string => $this->renderArg($v), $args)) . ')';
	}//end renderCall()

	/**
	 * Build one recording query builder.
	 *
	 * @return IQueryBuilder&MockObject
	 */
	private function recordingQb(): IQueryBuilder&MockObject {
		$index           = count($this->queries);
		$this->queries[] = [];

		$qb   = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$func = $this->createMock(IFunctionBuilder::class);

		$own = ['expr', 'func', 'getTableName', 'getColumnName', 'createNamedParameter', 'createParameter', 'createFunction', 'executeQuery', 'executeStatement', 'getLastInsertId'];
		foreach ((new ReflectionClass(IQueryBuilder::class))->getMethods() as $method) {
			$type = $method->getReturnType();
			$name = $method->getName();
			if (in_array($name, $own, true) === true) {
				continue;
			}

			$chains = ($type === null && preg_match('/@return\s+(\$this|self|static|IQueryBuilder)\b/', (string) $method->getDocComment()) === 1);
			if ($chains === true || ($type instanceof ReflectionNamedType && in_array($type->getName(), [IQueryBuilder::class, 'self', 'static'], true))) {
				$qb->method($name)->willReturnCallback(
					function (mixed ...$args) use ($qb, $name, $index): IQueryBuilder {
						$this->queries[$index][] = $this->renderCall($name, $args);
						return $qb;
					}
				);
			}
		}

		$qb->method('expr')->willReturn($expr);
		$qb->method('func')->willReturn($func);
		$qb->method('getTableName')->willReturnCallback(static fn (string $table): string => '*PREFIX*' . $table);
		$qb->method('getColumnName')->willReturnCallback(static fn (string $column): string => $column);
		$qb->method('createNamedParameter')->willReturnCallback(
			function (mixed $value, mixed $type=IQueryBuilder::PARAM_STR, ?string $placeholder=null): string {
				$key                = ($placeholder !== null) ? ':' . $placeholder : ':p' . count($this->params);
				$this->params[$key] = [$value, $type];
				return $key;
			}
		);
		$qb->method('createParameter')->willReturnCallback(
			static fn (string $name): IParameter => new RecordedFragment(':' . $name)
		);
		$qb->method('createFunction')->willReturnCallback(
			static fn (string $call): IQueryFunction => new RecordedFragment($call)
		);
		$qb->method('executeQuery')->willReturnCallback(
			function () use ($index): IResult {
				$this->queries[$index][] = 'executeQuery()';
				return $this->recordedResult(rows: (array_shift($this->results) ?? []));
			}
		);
		$qb->method('executeStatement')->willReturnCallback(
			function () use ($index): int {
				$this->queries[$index][] = 'executeStatement()';
				$outcome                 = array_shift($this->outcomes);
				if ($outcome instanceof \Throwable) {
					throw $outcome;
				}

				return ($outcome ?? $this->affected);
			}
		);
		$qb->method('getLastInsertId')->willReturn(42);

		foreach ([$expr, $func] as $builder) {
			$interface = ($builder === $expr) ? IExpressionBuilder::class : IFunctionBuilder::class;
			foreach ((new ReflectionClass($interface))->getMethods() as $method) {
				$name = $method->getName();
				$type = $method->getReturnType();
				if ($type instanceof ReflectionNamedType && $type->getName() === 'string') {
					$builder->method($name)->willReturnCallback(
						fn (mixed ...$args): string => $this->renderCall($name, $args)
					);
					continue;
				}

				$builder->method($name)->willReturnCallback(
					fn (mixed ...$args): RecordedFragment => new RecordedFragment($this->renderCall($name, $args))
				);
			}
		}

		return $qb;
	}//end recordingQb()

	/**
	 * A result over fixed rows.
	 *
	 * @param list<array<string, mixed>> $rows The rows.
	 *
	 * @return IResult&MockObject
	 */
	private function recordedResult(array $rows): IResult&MockObject {
		$result = $this->createMock(IResult::class);
		$cursor = 0;
		$next   = static function () use (&$cursor, $rows): array|false {
			$row = ($rows[$cursor] ?? false);
			$cursor++;
			return $row;
		};
		$result->method('fetch')->willReturnCallback($next);
		$result->method('fetchAssociative')->willReturnCallback($next);
		$result->method('fetchAll')->willReturnCallback(
			static function (int $mode=\PDO::FETCH_ASSOC) use ($rows): array {
				if ($mode === \PDO::FETCH_COLUMN) {
					return array_map(static fn (array $row): mixed => reset($row), $rows);
				}

				return $rows;
			}
		);
		$result->method('fetchAllAssociative')->willReturn($rows);
		$result->method('fetchFirstColumn')->willReturn(array_map(static fn (array $row): mixed => reset($row), $rows));
		$result->method('fetchOne')->willReturnCallback(
			static function () use (&$cursor, $rows): mixed {
				$row = ($rows[$cursor] ?? false);
				$cursor++;
				return ($row === false) ? false : reset($row);
			}
		);
		$result->method('rowCount')->willReturn(count($rows));
		$result->method('closeCursor')->willReturn(true);
		return $result;
	}//end recordedResult()
}//end trait
