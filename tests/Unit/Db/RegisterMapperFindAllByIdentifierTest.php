<?php

/**
 * RegisterMapper::findAllByIdentifier() matches what the importer's register lookups match (pp-f11).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\RegisterMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class RegisterMapperFindAllByIdentifierTest extends TestCase {

	/**
	 * Run the lookup against a recording query builder.
	 *
	 * @param string $identifier The identifier.
	 *
	 * @return list<array{0: string, 1: mixed}> The comparisons.
	 */
	private function record(string $identifier): array {
		$params = [];
		$eq = [];

		$func = $this->createMock(IFunctionBuilder::class);
		$func->method('lower')->willReturnCallback(
			function (string $column): IQueryFunction {
				$lower = $this->createMock(IQueryFunction::class);
				$lower->method('__toString')->willReturn('LOWER(' . $column . ')');
				return $lower;
			}
		);
		$composite = $this->createMock(ICompositeExpression::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(
			function ($column, $param) use (&$eq, &$params): string {
				$eq[] = [(string)$column, $params[$param] ?? $param];
				return 'cmp';
			}
		);
		$expr->method('orX')->willReturn($composite);
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturn(false);

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('func')->willReturn($func);
		$qb->method('createNamedParameter')->willReturnCallback(
			function ($value) use (&$params): string {
				$name = ':p' . count($params);
				$params[$name] = $value;
				return $name;
			}
		);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willThrowException(new \LogicException('no organisation filter expected'));
		$qb->method('setMaxResults')->willReturnSelf();
		$qb->method('executeQuery')->willReturn($result);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$mapper = (new \ReflectionClass(RegisterMapper::class))->newInstanceWithoutConstructor();
		$property = new \ReflectionProperty(\OCP\AppFramework\Db\QBMapper::class, 'db');
		$property->setValue($mapper, $db);

		$this->assertSame([], $mapper->findAllByIdentifier($identifier));

		return $eq;
	}//end record()

	public function testAnUpperCaseUuidIsMatchedCaseInsensitively(): void {
		$this->assertSame(
			[['LOWER(uuid)', '5f1c9d0e-aaaa'], ['LOWER(slug)', '5f1c9d0e-aaaa']],
			$this->record('5F1C9D0E-AAAA')
		);
	}

	public function testANumericIdentifierAlsoMatchesTheId(): void {
		$this->assertContains(['id', 3], $this->record('3'));
	}
}
