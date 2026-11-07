<?php

/**
 * A query-builder double that writes every call, with every argument, into one log.
 *
 * Unlike FluentQueryBuilderTrait this double renders the expressions and the
 * bound parameters, so a golden test can pin the generated SQL shape and the
 * values handed to it: which table, which columns, which predicates in which
 * order, and the value and array-ness of each named parameter.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Tests\Support\ResultRowReaderTrait;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IParameter;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;

/**
 * Records builder calls as readable lines.
 */
trait RecordingQueryBuilderTrait {
	use ResultRowReaderTrait;

	/**
	 * Every builder call, rendered, in order.
	 *
	 * @var list<string>
	 */
	private array $sqlLog = [];

	/**
	 * What each executeStatement() call returns, in order; a Throwable is thrown.
	 *
	 * @var list<int|\Throwable>
	 */
	private array $statementResults = [];

	/**
	 * The row sets each executeQuery() call yields, in order.
	 *
	 * @var list<list<array<string, mixed>>>
	 */
	private array $queryResults = [];

	/**
	 * Number of named parameters created so far.
	 *
	 * @var integer
	 */
	private int $parameterCount = 0;

	/**
	 * A connection whose every getQueryBuilder() call hands out a new recording builder.
	 *
	 * @return IDBConnection The connection.
	 */
	private function recordingConnection(): IDBConnection {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(
			function (): IQueryBuilder {
				$this->sqlLog[] = '-- new builder';
				return $this->recordingBuilder();
			}
		);

		return $db;
	}//end recordingConnection()

	/**
	 * Render one argument for the log.
	 *
	 * @param mixed $value The argument.
	 *
	 * @return string The rendering.
	 */
	private static function renderArgument(mixed $value): string {
		if (is_string($value) === true) {
			return $value;
		}

		if ($value instanceof \Stringable) {
			return (string)$value;
		}

		return json_encode($value, JSON_THROW_ON_ERROR);
	}//end renderArgument()

	/**
	 * Render a call.
	 *
	 * @param string $method The method name.
	 * @param array<int, mixed> $args The arguments.
	 *
	 * @return string The rendering.
	 */
	private static function renderCall(string $method, array $args): string {
		return $method . '(' . implode(', ', array_map(self::renderArgument(...), $args)) . ')';
	}//end renderCall()

	/**
	 * Build a recording builder.
	 *
	 * @return IQueryBuilder The builder.
	 */
	private function recordingBuilder(): IQueryBuilder {
		$qb = $this->createMock(IQueryBuilder::class);
		$fluent = ['select', 'selectAlias', 'from', 'where', 'andWhere', 'update', 'insert', 'delete', 'set', 'setValue', 'orderBy', 'groupBy', 'setMaxResults'];
		foreach ($fluent as $method) {
			$qb->method($method)->willReturnCallback(
				function (mixed ...$args) use ($qb, $method): IQueryBuilder {
					$this->sqlLog[] = self::renderCall($method, $args);
					return $qb;
				}
			);
		}

		$qb->method('createNamedParameter')->willReturnCallback(
			function (mixed $value, mixed $type = IQueryBuilder::PARAM_STR): IParameter {
				$name = ':p' . (++$this->parameterCount);
				$suffix = '';
				if ($type === IQueryBuilder::PARAM_STR_ARRAY) {
					$suffix = ' [STR_ARRAY]';
				}

				$this->sqlLog[] = 'param ' . $name . ' = ' . self::renderParameterValue($value) . $suffix;
				return self::recordedText(IParameter::class, $name);
			}
		);
		$qb->method('createFunction')->willReturnCallback(
			fn (string $sql): IQueryFunction => self::recordedText(IQueryFunction::class, $sql)
		);
		$qb->method('expr')->willReturn($this->recordingExpressions());
		$qb->method('executeStatement')->willReturnCallback(
			function (): int {
				$this->sqlLog[] = 'executeStatement()';
				$next = array_shift($this->statementResults) ?? 0;
				if ($next instanceof \Throwable) {
					throw $next;
				}

				return $next;
			}
		);
		$qb->method('executeQuery')->willReturnCallback(
			function (): IResult {
				$this->sqlLog[] = 'executeQuery()';
				return $this->recordingResult(rows: (array_shift($this->queryResults) ?? []));
			}
		);

		return $qb;
	}//end recordingBuilder()

	/**
	 * Render a parameter value, masking a wall-clock timestamp.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string The rendering.
	 */
	private static function renderParameterValue(mixed $value): string {
		if ($value instanceof \DateTimeInterface) {
			return '<datetime>';
		}

		if (is_string($value) === true && preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $value) === 1) {
			return '<timestamp>';
		}

		return json_encode($value, JSON_THROW_ON_ERROR);
	}//end renderParameterValue()

	/**
	 * An expression builder whose expressions render to readable text.
	 *
	 * @return IExpressionBuilder The expression builder.
	 */
	private function recordingExpressions(): IExpressionBuilder {
		$expr = $this->createMock(IExpressionBuilder::class);
		foreach (['eq', 'neq', 'in', 'iLike', 'like', 'isNull', 'isNotNull', 'lt', 'gt'] as $method) {
			$expr->method($method)->willReturnCallback(
				fn (mixed ...$args): string => self::renderCall($method, array_slice($args, 0, 2))
			);
		}

		return $expr;
	}//end recordingExpressions()

	/**
	 * A result that yields the given rows.
	 *
	 * @param list<array<string, mixed>> $rows The rows.
	 *
	 * @return IResult The result.
	 */
	private function recordingResult(array $rows): IResult {
		$result = $this->createMock(IResult::class);
		$queue = $rows;
		$next = static function () use (&$queue): array|false {
			if ($queue === []) {
				return false;
			}

			return array_shift($queue);
		};
		$this->stubRowReader($result, $next);
		$this->stubAllRowsReader($result, $rows);
		$result->method('closeCursor')->willReturnCallback(
			function (): bool {
				$this->sqlLog[] = 'closeCursor()';
				return true;
			}
		);

		return $result;
	}//end recordingResult()

	/**
	 * A stringable parameter or function.
	 *
	 * @param class-string $interface IParameter::class or IQueryFunction::class.
	 * @param string $text What it renders to.
	 *
	 * @return IParameter|IQueryFunction The value.
	 */
	private static function recordedText(string $interface, string $text): IParameter|IQueryFunction {
		if ($interface === IParameter::class) {
			return new class ($text) implements IParameter, \Stringable {
				public function __construct(private readonly string $text) {
				}

				public function __toString(): string {
					return $this->text;
				}
			};
		}

		return new class ($text) implements IQueryFunction, \Stringable {
			public function __construct(private readonly string $text) {
			}

			public function __toString(): string {
				return $this->text;
			}
		};
	}//end recordedText()
}//end trait
