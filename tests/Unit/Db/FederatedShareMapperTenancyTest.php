<?php

/**
 * The tenancy guard behind FederationController's two ungated read/write endpoints.
 *
 * `FederationController::shares()` and `::revokeShare()` are `#[NoAdminRequired]`
 * and carry no guard in the controller body — deliberately, because the guard
 * lives one layer down: every query this mapper builds for a SESSION caller
 * passes through `MultiTenancyTrait::applyOrganisationFilter()`, which fails
 * CLOSED (`1 = 0`) when there is no active organisation. `revokeShare()` reaches
 * it through `updateFromArray()` → `find()`, so another organisation's share is
 * a `DoesNotExistException` → 404 rather than a 403 that would confirm the id
 * exists.
 *
 * That sentence is the reason recorded in the two `@no-admin-idor-exempt` tags,
 * and this is what makes it a checkable claim rather than an assertion. Delete
 * the `applyOrganisationFilter()` call from `find()` or `findAll()` and these
 * tests go red — which is the whole point: the exemption is only honest for as
 * long as the enforcement it names is still there.
 *
 * `findByToken()` is asserted to be filter-FREE on purpose. A remote instance
 * presenting a share token has no local session, so an organisation filter there
 * would reject every legitimate federated read; the token itself is the
 * credential. Pinning both sides stops a future "consistency" change from
 * quietly closing the federation endpoint or quietly opening the local one.
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

use OCA\OpenRegister\Db\FederatedShareMapper;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Organisation scoping on the federated-share queries a local session reaches.
 *
 * @covers \OCA\OpenRegister\Db\FederatedShareMapper
 */
class FederatedShareMapperTenancyTest extends TestCase {

	/**
	 * The mapper under test, instrumented to record organisation-filter calls.
	 *
	 * @var FederatedShareMapper
	 */
	private FederatedShareMapper $mapper;

	/**
	 * Build the mapper over a query builder that records nothing but its own
	 * fluency, plus an override that records whether the organisation filter ran.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('setMaxResults')->willReturnSelf();
		$qb->method('setFirstResult')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturn(':p');

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$this->mapper = new class($db, $this->createMock(OrganisationMapper::class), $this->createMock(IUserSession::class), $this->createMock(IGroupManager::class), $this->createMock(IAppConfig::class)) extends FederatedShareMapper {

			/**
			 * Names of the methods that reached the organisation filter.
			 *
			 * @var string[]
			 */
			public array $filtered = [];

			/**
			 * Record the call instead of building SQL, and stop the query there.
			 *
			 * @param IQueryBuilder $qb The query under construction.
			 * @param string $columnName The organisation column.
			 * @param bool $allowNullOrg Whether NULL-org rows are admitted.
			 * @param string $tableAlias The table alias.
			 * @param bool $multiTenancyEnabled Whether tenancy is on.
			 *
			 * @return void
			 */
			protected function applyOrganisationFilter(
				IQueryBuilder $qb,
				string $columnName = 'organisation',
				bool $allowNullOrg = false,
				string $tableAlias = '',
				bool $multiTenancyEnabled = true,
			): void {
				$this->filtered[] = ($allowNullOrg === true ? 'allow-null' : 'strict');
				throw new \RuntimeException('organisation-filter-reached');
			}
		};

	}//end setUp()

	/**
	 * `shares()` lists through `findAll()`. Every session-scoped listing is
	 * organisation-filtered before it can reach the database.
	 *
	 * @return void
	 */
	public function testFindAllAppliesTheOrganisationFilter(): void {
		$this->expectExceptionMessage('organisation-filter-reached');

		try {
			$this->mapper->findAll();
		} finally {
			$this->assertSame(['strict'], $this->mapper->filtered);
		}

	}//end testFindAllAppliesTheOrganisationFilter()

	/**
	 * `revokeShare()` writes through `updateFromArray()` → `find()`. The filter
	 * runs on the READ that precedes the write, which is what turns another
	 * organisation's share id into a 404 instead of a revocation.
	 *
	 * @return void
	 */
	public function testFindAppliesTheOrganisationFilter(): void {
		$this->expectExceptionMessage('organisation-filter-reached');

		try {
			$this->mapper->find(id: 42);
		} finally {
			$this->assertSame(['strict'], $this->mapper->filtered);
		}

	}//end testFindAppliesTheOrganisationFilter()

	/**
	 * The write path reaches the guarded read rather than updating by id
	 * directly. Without this, `find()` could stay filtered while a later
	 * refactor gave `updateFromArray()` its own unfiltered lookup.
	 *
	 * @return void
	 */
	public function testUpdateFromArrayReachesTheGuardedRead(): void {
		$this->expectExceptionMessage('organisation-filter-reached');

		try {
			$this->mapper->updateFromArray(id: 42, data: ['status' => 'revoked']);
		} finally {
			$this->assertSame(['strict'], $this->mapper->filtered);
		}

	}//end testUpdateFromArrayReachesTheGuardedRead()

	/**
	 * The deliberate exception: a remote instance presenting a share token has
	 * no local session, so this lookup must NOT be organisation-filtered. Pinned
	 * so the asymmetry stays a decision rather than an oversight.
	 *
	 * @return void
	 */
	public function testFindByTokenIsDeliberatelyNotOrganisationFiltered(): void {
		// findEntity() on the mocked builder yields no row; the point is that
		// the organisation filter was never reached on the way there.
		try {
			$this->mapper->findByToken(shareToken: 'a-token');
		} catch (\Throwable $e) {
			$this->assertStringNotContainsString('organisation-filter-reached', $e->getMessage());
		}

		$this->assertSame([], $this->mapper->filtered);

	}//end testFindByTokenIsDeliberatelyNotOrganisationFiltered()
}//end class
