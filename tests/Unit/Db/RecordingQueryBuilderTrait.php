<?php

/**
 * A query builder double that records every call with all of its arguments.
 *
 * Each builder the connection hands out gets a number, and every call made on
 * it, on its expression builder and on its function builder is written to one
 * shared log as text: `#1 andWhere(eq(t._owner, :p1))`. Parameters render as
 * numbered placeholders and their values are logged where they are created,
 * so a test can pin the exact query a method builds, in order, and a change in
 * any predicate, column, operator or bound value shows up as a log diff.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Tests\Support\ResultRowReaderTrait;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\ILiteral;
use OCP\DB\QueryBuilder\IParameter;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use PHPUnit\Framework\MockObject\MockObject;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Builds query builders that log every call, and serves rows to executeQuery().
 */
trait RecordingQueryBuilderTrait {
	use ResultRowReaderTrait;

	/**
	 * Every call on every builder, in order.
	 *
	 * @var array<int, string>
	 */
	private array $queryLog = [];

	/**
	 * Row sets served to executeQuery(), one set per call, in order.
	 *
	 * @var array<int, array<int, array<string, mixed>>>
	 */
	private array $queuedRows = [];

	/**
	 * How many builders were handed out.
	 *
	 * @var int
	 */
	private int $builderCount = 0;

	/**
	 * How many named parameters were created, over all builders.
	 *
	 * @var int
	 */
	private int $parameterCount = 0;

	/**
	 * A new recording builder.
	 *
	 * @return IQueryBuilder&MockObject The builder.
	 */
	private function recordingBuilder(): IQueryBuilder&MockObject {
		$this->builderCount++;
		$tag = '#' . $this->builderCount;
		$qb = $this->createMock(IQueryBuilder::class);
		$expr = $this->recordingHelper(IExpressionBuilder::class, $tag . ' expr.');
		$func = $this->recordingHelper(IFunctionBuilder::class, $tag . ' func.');

		$special = [
			'expr', 'func', 'executeQuery', 'executeStatement', 'createNamedParameter', 'createPositionalParameter',
			'createParameter', 'createFunction', 'getSQL', 'getTableName', 'prefixTableName', 'getColumnName',
		];
		foreach ((new ReflectionClass(IQueryBuilder::class))->getMethods() as $method) {
			$name = $method->getName();
			if (in_array($name, $special, true) === true || str_starts_with($name, 'get') === true) {
				continue;
			}

			$qb->method($name)->willReturnCallback(
				function (mixed ...$args) use ($qb, $tag, $name): IQueryBuilder {
					$this->queryLog[] = $tag . ' ' . $name . '(' . $this->renderArgs($args) . ')';
					return $qb;
				}
			);
		}

		$qb->method('expr')->willReturn($expr);
		$qb->method('func')->willReturn($func);
		$qb->method('createFunction')->willReturnCallback(
			fn (string $sql): IQueryFunction => $this->textValue(IQueryFunction::class, $sql)
		);
		$qb->method('createNamedParameter')->willReturnCallback(
			function (mixed $value, mixed $type = IQueryBuilder::PARAM_STR) use ($tag): IParameter {
				$this->parameterCount++;
				$placeholder = ':p' . $this->parameterCount;
				$this->queryLog[] = $tag . ' param ' . $placeholder . ' = ' . $this->renderArgs([$value, $type]);
				return $this->textValue(IParameter::class, $placeholder);
			}
		);
		$qb->method('getTableName')->willReturnArgument(0);
		$qb->method('getColumnName')->willReturnArgument(0);
		$qb->method('getSQL')->willReturn('(subquery ' . $tag . ')');
		$qb->method('executeStatement')->willReturnCallback(
			function () use ($tag): int {
				$this->queryLog[] = $tag . ' executeStatement()';
				return 1;
			}
		);
		$qb->method('executeQuery')->willReturnCallback(
			function () use ($tag): IResult {
				$this->queryLog[] = $tag . ' executeQuery()';
				$rows = array_shift($this->queuedRows) ?? [];
				return $this->servedResult($rows);
			}
		);

		return $qb;
	}//end recordingBuilder()

	/**
	 * An expression or function builder that logs each call and answers with text.
	 *
	 * @param class-string $interface IExpressionBuilder::class or IFunctionBuilder::class.
	 * @param string       $prefix    The log prefix, such as '#1 expr.'.
	 *
	 * @return MockObject The helper.
	 */
	private function recordingHelper(string $interface, string $prefix): MockObject {
		$helper = $this->createMock($interface);
		foreach ((new ReflectionClass($interface))->getMethods() as $method) {
			$name = $method->getName();
			$returnType = $method->getReturnType();
			$returns = 'string';
			if ($returnType instanceof ReflectionNamedType === true) {
				$returns = $returnType->getName();
			}

			$helper->method($name)->willReturnCallback(
				function (mixed ...$args) use ($prefix, $name, $returns): mixed {
					$text = $name . '(' . $this->renderArgs($args) . ')';
					$this->queryLog[] = $prefix . $text;
					if ($returns === ICompositeExpression::class) {
						return $this->composite($text, count($args));
					}

					if ($returns === ILiteral::class || $returns === IQueryFunction::class) {
						return $this->textValue($returns, $text);
					}

					return $text;
				}
			);
		}

		return $helper;
	}//end recordingHelper()

	/**
	 * Call arguments as text: strings bare, arrays as JSON, the rest exported.
	 *
	 * @param array<int|string, mixed> $args The arguments.
	 *
	 * @return string The arguments joined by commas.
	 */
	private function renderArgs(array $args): string {
		$parts = [];
		foreach ($args as $arg) {
			if (is_string($arg) === true || $arg instanceof \Stringable === true) {
				$parts[] = (string)$arg;
				continue;
			}

			if (is_array($arg) === true) {
				$parts[] = json_encode($arg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
				continue;
			}

			if (is_object($arg) === true) {
				$parts[] = get_class($arg);
				continue;
			}

			$parts[] = var_export($arg, true);
		}

		return implode(', ', $parts);
	}//end renderArgs()

	/**
	 * A result serving the rows, then false.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows.
	 *
	 * @return IResult&MockObject The result.
	 */
	private function servedResult(array $rows): IResult&MockObject {
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
		$result->method('fetchOne')->willReturnCallback(
			static function () use (&$queue): mixed {
				if ($queue === []) {
					return false;
				}

				$row = array_shift($queue);
				return reset($row);
			}
		);

		return $result;
	}//end servedResult()

	/**
	 * A real stringable IParameter, ILiteral or IQueryFunction.
	 *
	 * @param class-string $interface The value interface.
	 * @param string       $text      What it stringifies to.
	 *
	 * @return IParameter|ILiteral|IQueryFunction The value.
	 */
	private function textValue(string $interface, string $text): IParameter|ILiteral|IQueryFunction {
		if ($interface === IParameter::class) {
			return new class ($text) implements IParameter {
				public function __construct(private readonly string $text) {
				}

				public function __toString(): string {
					return $this->text;
				}
			};
		}

		if ($interface === ILiteral::class) {
			return new class ($text) implements ILiteral {
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
	}//end textValue()

	/**
	 * A composite expression that stringifies to its call text plus later parts.
	 *
	 * @param string $text  The andX()/orX() call text.
	 * @param int    $count How many parts the call was given.
	 *
	 * @return ICompositeExpression The composite.
	 */
	private function composite(string $text, int $count): ICompositeExpression {
		return new class ($text, $count) implements ICompositeExpression {
			/**
			 * Parts added after construction.
			 *
			 * @var array<int, string>
			 */
			private array $parts = [];

			public function __construct(private readonly string $text, private readonly int $initial) {
			}

			/**
			 * @param array<int, mixed> $parts The parts.
			 */
			public function addMultiple(array $parts = []): ICompositeExpression {
				foreach ($parts as $part) {
					$this->parts[] = (string)$part;
				}

				return $this;
			}

			/**
			 * @param mixed $part The part.
			 */
			public function add($part): ICompositeExpression {
				$this->parts[] = (string)$part;
				return $this;
			}

			public function count(): int {
				return count($this->parts) + $this->initial;
			}

			public function getType(): string {
				return 'composite';
			}

			public function __toString(): string {
				if ($this->parts === []) {
					return $this->text;
				}

				return $this->text . '+[' . implode(', ', $this->parts) . ']';
			}
		};
	}//end composite()
}//end trait
