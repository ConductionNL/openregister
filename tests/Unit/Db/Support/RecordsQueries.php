<?php

/**
 * RecordsQueries: a query builder double that writes down what a mapper asks for.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit mock helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db\Support;

use OCP\DB\IResult;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\ILiteral;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use ReflectionClass;
use ReflectionNamedType;
use SplObjectStorage;

/**
 * A connection whose builders record each call as text and answer queries
 * from a queue of row sets.
 *
 * Every builder call lands in $this->queries[<builder index>] as
 * `method(arg, arg)`. Expressions render as `eq(col, 'value')`, so a test can
 * assert the exact filter a branch adds, and the exact value it binds.
 */
trait RecordsQueries {

	/**
	 * The calls of each builder, in creation order.
	 *
	 * @var list<list<string>>
	 */
	private array $queries = [];

	/**
	 * Row sets answered by the next executeQuery calls.
	 *
	 * @var list<list<array<string, mixed>>>
	 */
	private array $rowSets = [];

	/**
	 * Answers of the next executeStatement calls (1 when the queue is empty).
	 *
	 * @var list<int>
	 */
	private array $statementResults = [];

	/**
	 * Id answered by getLastInsertId.
	 *
	 * @var int
	 */
	private int $lastInsertId = 1;

	/**
	 * Rendered composite expressions.
	 *
	 * @var SplObjectStorage|null
	 */
	private ?SplObjectStorage $composites = null;

	/**
	 * A connection handing out recording builders.
	 *
	 * @return IDBConnection&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function recordingConnection(): IDBConnection {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->recordingBuilder());
		$db->method('escapeLikeParameter')->willReturnCallback(static fn (string $value): string => addcslashes($value, '\\%_'));

		return $db;
	}//end recordingConnection()

	/**
	 * Every recorded call, flattened.
	 *
	 * @return list<string>
	 */
	private function allCalls(): array {
		return array_merge([], ...$this->queries);
	}//end allCalls()

	/**
	 * A builder that records every call in a new slot of $this->queries.
	 *
	 * @return IQueryBuilder
	 */
	private function recordingBuilder(): IQueryBuilder {
		$this->composites ??= new SplObjectStorage();
		$index = count($this->queries);
		$this->queries[$index] = [];
		$qb = $this->createMock(IQueryBuilder::class);
		$fluent = [
			'automaticTablePrefix', 'setParameter', 'setParameters', 'setFirstResult', 'setMaxResults', 'select',
			'selectAlias', 'selectDistinct', 'addSelect', 'delete', 'update', 'insert', 'from', 'join', 'innerJoin',
			'leftJoin', 'rightJoin', 'set', 'where', 'andWhere', 'orWhere', 'groupBy', 'addGroupBy', 'setValue',
			'values', 'having', 'andHaving', 'orHaving', 'orderBy', 'addOrderBy', 'resetQueryParts', 'resetQueryPart',
			'runAcrossAllShards',
		];
		foreach ($fluent as $method) {
			$qb->method($method)->willReturnCallback(
				function (...$args) use ($method, $qb, $index) {
					$this->queries[$index][] = $method . '(' . $this->renderArgs(args: $args) . ')';
					return $qb;
				}
			);
		}

		$qb->method('createNamedParameter')->willReturnCallback(fn ($value, $type = IQueryBuilder::PARAM_STR): string => $this->renderValue(value: $value));
		$qb->method('createPositionalParameter')->willReturnCallback(fn ($value): string => $this->renderValue(value: $value));
		$qb->method('createParameter')->willReturnCallback(static fn ($name): string => ':' . $name);
		$qb->method('createFunction')->willReturnCallback(fn ($call): IQueryFunction => $this->queryFunction(text: (string) $call));
		$qb->method('expr')->willReturnCallback(fn (): IExpressionBuilder => $this->recordingExpressions());
		$qb->method('func')->willReturnCallback(fn (): IFunctionBuilder => $this->recordingFunctions());
		$qb->method('getLastInsertId')->willReturnCallback(fn (): int => $this->lastInsertId);
		$qb->method('getTableName')->willReturnCallback(static fn ($table): string => '*PREFIX*' . $table);
		$qb->method('getColumnName')->willReturnCallback(static fn ($column, $alias = ''): string => ($alias !== '' ? $alias . '.' : '') . $column);
		$qb->method('executeQuery')->willReturnCallback(
			function () use ($index): IResult {
				$this->queries[$index][] = 'executeQuery';
				return $this->resultOf(rows: (array_shift($this->rowSets) ?? []));
			}
		);
		$qb->method('executeStatement')->willReturnCallback(
			function () use ($index): int {
				$this->queries[$index][] = 'executeStatement';
				return (array_shift($this->statementResults) ?? 1);
			}
		);

		return $qb;
	}//end recordingBuilder()

	/**
	 * A result over a list of rows.
	 *
	 * @param list<array<string, mixed>> $rows The rows.
	 *
	 * @return IResult
	 */
	private function resultOf(array $rows): IResult {
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnCallback(
			static function () use (&$rows) {
				return (array_shift($rows) ?? false);
			}
		);
		$result->method('fetchAll')->willReturnCallback(
			static function () use (&$rows): array {
				$all = $rows;
				$rows = [];
				return $all;
			}
		);
		$result->method('fetchOne')->willReturnCallback(
			static function () use (&$rows) {
				$row = array_shift($rows);
				return ($row === null ? false : reset($row));
			}
		);
		$result->method('fetchAssociative')->willReturnCallback(
			static function () use (&$rows) {
				return (array_shift($rows) ?? false);
			}
		);
		$result->method('fetchFirstColumn')->willReturnCallback(
			static function () use (&$rows): array {
				$all = array_map(static fn (array $row) => reset($row), $rows);
				$rows = [];
				return $all;
			}
		);
		$result->method('closeCursor')->willReturn(true);

		return $result;
	}//end resultOf()

	/**
	 * Expressions rendered as text.
	 *
	 * @return IExpressionBuilder
	 */
	private function recordingExpressions(): IExpressionBuilder {
		$expr = $this->createMock(IExpressionBuilder::class);
		foreach ((new ReflectionClass(IExpressionBuilder::class))->getMethods() as $method) {
			$name = $method->getName();
			$type = $method->getReturnType();
			$returns = ($type instanceof ReflectionNamedType) ? $type->getName() : '';
			$expr->method($name)->willReturnCallback(
				function (...$args) use ($name, $returns) {
					$text = $name . '(' . $this->renderArgs(args: $args) . ')';
					if ($name === 'andX' || $name === 'orX' || $returns === ICompositeExpression::class) {
						$composite = $this->createMock(ICompositeExpression::class);
						$this->composites[$composite] = $text;
						return $composite;
					}

					if ($returns === ILiteral::class) {
						$literal = $this->createMock(ILiteral::class);
						$literal->method('__toString')->willReturn($text);
						return $literal;
					}

					if ($returns === IQueryFunction::class) {
						return $this->queryFunction(text: $text);
					}

					return $text;
				}
			);
		}

		return $expr;
	}//end recordingExpressions()

	/**
	 * Functions rendered as text.
	 *
	 * @return IFunctionBuilder
	 */
	private function recordingFunctions(): IFunctionBuilder {
		$func = $this->createMock(IFunctionBuilder::class);
		foreach ((new ReflectionClass(IFunctionBuilder::class))->getMethods() as $method) {
			$name = $method->getName();
			$func->method($name)->willReturnCallback(
				fn (...$args): IQueryFunction => $this->queryFunction(text: $name . '(' . $this->renderArgs(args: $args) . ')')
			);
		}

		return $func;
	}//end recordingFunctions()

	/**
	 * A query function reading as a text.
	 *
	 * @param string $text The text.
	 *
	 * @return IQueryFunction
	 */
	private function queryFunction(string $text): IQueryFunction {
		$function = $this->createMock(IQueryFunction::class);
		$function->method('__toString')->willReturn($text);

		return $function;
	}//end queryFunction()

	/**
	 * Arguments as text.
	 *
	 * @param array<int, mixed> $args The arguments.
	 *
	 * @return string
	 */
	private function renderArgs(array $args): string {
		// The mock passes optional parameters it was not given as null: drop them.
		while ($args !== [] && end($args) === null) {
			array_pop($args);
		}

		$parts = [];
		foreach ($args as $arg) {
			if ($arg instanceof ICompositeExpression) {
				$parts[] = ($this->composites[$arg] ?? 'composite');
				continue;
			}

			if (is_array($arg) === true) {
				$parts[] = json_encode($arg, JSON_UNESCAPED_SLASHES);
				continue;
			}

			if ($arg === null) {
				$parts[] = 'null';
				continue;
			}

			if (is_bool($arg) === true) {
				$parts[] = ($arg ? 'true' : 'false');
				continue;
			}

			if (is_object($arg) === true && method_exists($arg, '__toString') === false) {
				$parts[] = get_class($arg);
				continue;
			}

			$parts[] = (string) $arg;
		}

		return implode(', ', $parts);
	}//end renderArgs()

	/**
	 * A bound value as text: strings quoted, the rest JSON.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private function renderValue(mixed $value): string {
		if (is_string($value) === true) {
			return "'" . $value . "'";
		}

		if ($value instanceof \DateTimeInterface) {
			return "'" . $value->format('Y-m-d H:i:s') . "'";
		}

		return (string) json_encode($value, JSON_UNESCAPED_SLASHES);
	}//end renderValue()
}//end trait
