<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/federation-scope-enforcement/spec.md#requirement-a-share-never-grants-more-than-its-creator-holds-req-fse-005
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\FederatedShareMapper;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\Tenancy\TenancyServices;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The administrator report reads every live outgoing share, across organisations.
 *
 * @covers \OCA\OpenRegister\Db\FederatedShareMapper
 */
class FederatedShareMapperLiveOutgoingTest extends TestCase {

	public function testOnlyLiveOutgoingSharesAreReadWithoutTheOrganisationFilter(): void {
		$conditions = [];
		$params     = [];
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(static function (string $col, $val) use (&$conditions): string {
			$conditions[] = "$col = $val";
			return 'c';
		});
		$expr->method('notIn')->willReturnCallback(static function (string $col, $val) use (&$conditions): string {
			$conditions[] = "$col NOT IN $val";
			return 'c';
		});

		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturn(false);
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		foreach (['select', 'from', 'where', 'andWhere', 'orderBy'] as $method) {
			$qb->method($method)->willReturnSelf();
		}

		$qb->method('createNamedParameter')->willReturnCallback(static function ($value) use (&$params): string {
			$params[] = $value;
			return ':p' . (count($params) - 1);
		});
		$qb->method('executeQuery')->willReturn($result);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$mapper = new class($db, new TenancyServices($this->createMock(OrganisationMapper::class), $this->createMock(IUserSession::class), $this->createMock(IGroupManager::class)), $this->createMock(IAppConfig::class)) extends FederatedShareMapper {

			public bool $filtered = false;

			protected function applyOrganisationFilter(
				IQueryBuilder $qb,
				string $columnName = 'organisation',
				bool $allowNullOrg = false,
				string $tableAlias = '',
				bool $multiTenancyEnabled = true,
			): void {
				$this->filtered = true;
			}
		};

		$this->assertSame([], $mapper->findLiveOutgoingAcrossOrganisations());
		$this->assertFalse($mapper->filtered);
		$this->assertSame(['direction = :p0', 'status NOT IN :p1'], $conditions);
		$this->assertSame(['outgoing', ['revoked', 'declined']], $params);
	}
}
