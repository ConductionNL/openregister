<?php

/**
 * SchemaMapper::findAllByIdentifier() matches what the importer's lookups match.
 *
 * BundleManageGuard judges every schema a federated install would overwrite. The
 * importer resolves a bundle's schema by uuid, numeric id or slug in any case, so
 * the guard's lookup has to match all three, without an organisation filter.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Schemas\PropertyValidatorHandler;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SchemaMapperFindAllByIdentifierTest extends TestCase {

	/**
	 * Run the lookup against a recording query builder.
	 *
	 * @param string $identifier The identifier.
	 *
	 * @return array{eq: list<array{0: string, 1: mixed}>, where: int} The comparisons and the number of where() calls.
	 */
	private function record(string $identifier): array {
		$params = [];
		$eq = [];
		$where = 0;

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
		$composite->method('add')->willReturnCallback(fn () => $composite);

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
		$qb->method('where')->willReturnCallback(
			function () use (&$where, $qb) {
				$where++;
				return $qb;
			}
		);
		$qb->method('andWhere')->willThrowException(new \LogicException('no organisation filter expected'));
		$qb->method('setMaxResults')->willReturnSelf();
		$qb->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$mapper = new SchemaMapper(
			$db,
			$this->createMock(IEventDispatcher::class),
			$this->createMock(PropertyValidatorHandler::class),
			$this->createMock(OrganisationMapper::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(LoggerInterface::class)
		);

		$this->assertSame([], $mapper->findAllByIdentifier($identifier));

		return ['eq' => $eq, 'where' => $where];
	}//end record()

	public function testASlugMatchesUuidAndLowerCasedSlug(): void {
		$recorded = $this->record('Publication');

		$this->assertSame([['LOWER(uuid)', 'publication'], ['LOWER(slug)', 'publication']], $recorded['eq']);
		$this->assertSame(1, $recorded['where']);
	}

	public function testANumericIdentifierAlsoMatchesTheId(): void {
		$recorded = $this->record('12');

		$this->assertContains(['id', 12], $recorded['eq']);
		$this->assertContains(['LOWER(slug)', '12'], $recorded['eq']);
	}
}
