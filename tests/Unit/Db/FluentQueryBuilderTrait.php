<?php

/**
 * A fluent IQueryBuilder double for exercising QBMapper subclasses without a database.
 *
 * Every builder method returns the builder itself, parameters and functions
 * stringify to their input, and the execute calls return what the test hands
 * in. The point is not to assert SQL text (the platform does that) but to
 * walk the mapper's own code — which predicate it adds, which column it sets,
 * what it does with an affected-row count — so a mapper method that would
 * throw or branch wrongly is caught here rather than on the first request.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IParameter;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Builds a recording, fluent query builder and a connection that serves it.
 */
trait FluentQueryBuilderTrait {

	/**
	 * Every raw SQL fragment handed to createFunction(), in order.
	 *
	 * @var array<int, string>
	 */
	private array $functions = [];

	/**
	 * Every (method, first argument) the builder saw, in order.
	 *
	 * @var array<int, array{0: string, 1: mixed}>
	 */
	private array $calls = [];

	/**
	 * A connection whose getQueryBuilder() always hands back one fluent builder.
	 *
	 * @param int $affectedRows What executeStatement() reports.
	 * @param array<int, array<string, mixed>> $rows What executeQuery() yields.
	 *
	 * @return IDBConnection&MockObject The connection.
	 */
	private function connectionWith(int $affectedRows = 1, array $rows = []): IDBConnection&MockObject {
		$db = $this->createMock(originalClassName: IDBConnection::class);
		$platform = $this->createMock(originalClassName: MySQLPlatform::class);
		$platform->method('quoteIdentifier')->willReturnCallback(static fn (string $s): string => '`' . $s . '`');
		$db->method('getDatabasePlatform')->willReturn($platform);
		$db->method('escapeLikeParameter')->willReturnArgument(0);
		$db->method('getQueryBuilder')->willReturnCallback(
			fn (): IQueryBuilder => $this->fluentBuilder(affectedRows: $affectedRows, rows: $rows)
		);

		return $db;
	}//end connectionWith()

	/**
	 * The fluent builder itself.
	 *
	 * @param int $affectedRows What executeStatement() reports.
	 * @param array<int, array<string, mixed>> $rows What executeQuery() yields.
	 *
	 * @return IQueryBuilder&MockObject The builder.
	 */
	private function fluentBuilder(int $affectedRows, array $rows): IQueryBuilder&MockObject {
		$qb = $this->createMock(originalClassName: IQueryBuilder::class);

		$record = function (string $method) use ($qb) {
			return function (mixed ...$args) use ($qb, $method) {
				$this->calls[] = [$method, ($args[0] ?? null)];

				return $qb;
			};
		};
		foreach (['select', 'selectAlias', 'from', 'where', 'andWhere', 'update', 'insert', 'delete', 'set', 'setValue', 'orderBy', 'addOrderBy', 'groupBy', 'setMaxResults', 'setFirstResult'] as $method) {
			$qb->method($method)->willReturnCallback($record($method));
		}

		// PHPUnit refuses to configure __toString on a mock, so the three
		// value types are tiny real implementations instead.
		$qb->method('createNamedParameter')->willReturn(self::stringable(IParameter::class, ':p'));

		$qb->method('createFunction')->willReturnCallback(
			function (string $sql): IQueryFunction {
				$this->functions[] = $sql;

				return self::stringable(IQueryFunction::class, $sql);
			}
		);

		$composite = new class () implements ICompositeExpression {
			/**
			 * @param array<int, mixed> $parts Ignored.
			 */
			public function addMultiple(array $parts = []): ICompositeExpression {
				return $this;
			}

			/**
			 * @param mixed $part Ignored.
			 */
			public function add($part): ICompositeExpression {
				return $this;
			}

			public function count(): int {
				return 1;
			}

			public function getType(): string {
				return 'OR';
			}

			public function __toString(): string {
				return '(composite)';
			}
		};
		$expr = $this->createMock(originalClassName: IExpressionBuilder::class);
		foreach (['eq', 'neq', 'lt', 'gt', 'in', 'like', 'isNull', 'isNotNull'] as $method) {
			$expr->method($method)->willReturnCallback(
				function (mixed ...$args) use ($method): string {
					$this->calls[] = ['expr.' . $method, ($args[0] ?? null)];

					return $method . '(' . (string)($args[0] ?? '') . ')';
				}
			);
		}

		$expr->method('orX')->willReturn($composite);
		$expr->method('andX')->willReturn($composite);
		$qb->method('expr')->willReturn($expr);

		$function = self::stringable(IQueryFunction::class, 'fn()');
		$func = $this->createMock(originalClassName: IFunctionBuilder::class);
		$func->method('count')->willReturn($function);
		$func->method('max')->willReturn($function);
		$qb->method('func')->willReturn($func);

		$qb->method('executeStatement')->willReturn($affectedRows);
		$qb->method('getLastInsertId')->willReturn(77);

		$result = $this->createMock(originalClassName: IResult::class);
		$queue = $rows;
		$result->method('fetch')->willReturnCallback(
			static function () use (&$queue): array|false {
				if ($queue === []) {
					return false;
				}

				return array_shift($queue);
			}
		);
		$result->method('fetchAll')->willReturn($rows);
		$qb->method('executeQuery')->willReturn($result);
		$qb->method('getTableName')->willReturn('openregister_tasks');

		return $qb;
	}//end fluentBuilder()

	/**
	 * A real, stringable IParameter or IQueryFunction.
	 *
	 * @param class-string $interface IParameter::class or IQueryFunction::class.
	 * @param string $text What it stringifies to.
	 *
	 * @return IParameter|IQueryFunction The value.
	 */
	private static function stringable(string $interface, string $text): IParameter|IQueryFunction {
		if ($interface === IParameter::class) {
			return new class ($text) implements IParameter {
				public function __construct(private readonly string $text) {
				}

				public function __toString(): string {
					return $this->text;
				}
			};
		}

		return new class ($text) implements IQueryFunction {
			public function __construct(private readonly string $text) {
			}

			public function __toString(): string {
				return $this->text;
			}
		};
	}//end stringable()

	/**
	 * Whether the builder saw a call.
	 *
	 * @param string $method The builder or `expr.` method.
	 * @param mixed $firstArgument Its first argument, or null for any.
	 *
	 * @return boolean True when recorded.
	 */
	private function saw(string $method, mixed $firstArgument = null): bool {
		foreach ($this->calls as [$seenMethod, $seenArgument]) {
			if ($seenMethod === $method && ($firstArgument === null || $seenArgument === $firstArgument)) {
				return true;
			}
		}

		return false;
	}//end saw()
}//end trait
