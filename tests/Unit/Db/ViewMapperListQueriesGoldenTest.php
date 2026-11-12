<?php

/**
 * Characterisation of ViewMapper's two list reads: the RBAC check comes first, then the
 * query with its owner/public/shared condition, then the organisation filter; findAllFor()
 * keeps only the views that grant the caller something, each carrying that access.
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

use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\Tenancy\TenancyServices;
use OCA\OpenRegister\Search\Db\View;
use OCA\OpenRegister\Search\Db\ViewMapper;
use OCA\OpenRegister\Search\Db\ViewMapperAccess;
use OCA\OpenRegister\Service\Rbac\ViewerReach;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Golden queries, ordering of the guards and the visible set of the view lists.
 *
 * @covers \OCA\OpenRegister\Search\Db\ViewMapper
 */
final class ViewMapperListQueriesGoldenTest extends TestCase {
	use RecordsQueries;

	/**
	 * What happened, in order: the RBAC check, the queries' calls, the organisation filter.
	 *
	 * @var list<string>
	 */
	private array $steps = [];

	/**
	 * A mapper over a recording connection whose RBAC check and organisation filter are recorded.
	 *
	 * @param bool $rbacAllows Whether the RBAC check lets the read through.
	 *
	 * @return ViewMapper&MockObject
	 */
	private function mapper(bool $rbacAllows=true): ViewMapper&MockObject {
		$mapper = $this->getMockBuilder(ViewMapper::class)
			->setConstructorArgs([
				$this->recordingDb(),
				new ViewMapperAccess(
					new TenancyServices(
						$this->createMock(OrganisationMapper::class),
						$this->createMock(IUserSession::class),
						$this->createMock(IGroupManager::class)
					),
					$this->createMock(IAppConfig::class)
				),
				$this->createMock(IEventDispatcher::class),
			])
			->onlyMethods(['verifyRbacPermission', 'applyOrganisationFilter'])
			->getMock();
		$mapper->method('verifyRbacPermission')->willReturnCallback(
			function (string $action, string $entityType) use ($rbacAllows): void {
				$this->steps[] = 'rbac ' . $action . ' ' . $entityType . ' after ' . count($this->queries) . ' queries';
				if ($rbacAllows === false) {
					throw new RuntimeException('refused');
				}
			}
		);
		$mapper->method('applyOrganisationFilter')->willReturnCallback(
			function (IQueryBuilder $qb): void {
				$this->steps[] = 'organisation filter after: ' . implode(' ', end($this->queries));
			}
		);

		return $mapper;
	}//end mapper()

	/**
	 * findAll() without an owner lists every view, newest first, organisation filtered.
	 *
	 * @return void
	 */
	public function testFindAllWithoutOwner(): void {
		$this->results[] = [['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b']];

		$views = $this->mapper()->findAll();

		$this->assertSame([1, 2], array_map(static fn (View $view): int => $view->getId(), $views));
		$this->assertSame(
			[
				'rbac read view after 0 queries',
				'organisation filter after: select(*) from(openregister_views) orderBy(created, DESC)',
			],
			$this->steps
		);
		$this->assertSame('select(*) from(openregister_views) orderBy(created, DESC) executeQuery()', $this->sql());
	}//end testFindAllWithoutOwner()

	/**
	 * findAll() for an owner lists theirs and the public ones.
	 *
	 * @return void
	 */
	public function testFindAllForOwner(): void {
		$this->mapper()->findAll('pieter');

		$this->assertSame(
			'select(*) from(openregister_views) where(orX(eq(owner, :p0), eq(is_public, :p1))) orderBy(created, DESC) executeQuery()',
			$this->sql()
		);
		$this->assertSame(['pieter', IQueryBuilder::PARAM_STR], $this->params[':p0']);
		$this->assertSame([true, IQueryBuilder::PARAM_BOOL], $this->params[':p1']);
	}//end testFindAllForOwner()

	/**
	 * A refused RBAC check stops both lists before any query.
	 *
	 * @return void
	 */
	public function testRefusedRbacStopsBeforeAnyQuery(): void {
		foreach (['findAll', 'findAllFor'] as $method) {
			try {
				$args = ($method === 'findAll') ? [] : [new ViewerReach(userId: 'u', groups: [], isAdmin: false)];
				$this->mapper(rbacAllows: false)->$method(...$args);
				$this->fail('The read was let through.');
			} catch (RuntimeException $e) {
				$this->assertSame('refused', $e->getMessage());
			}
		}

		$this->assertSame([], $this->queries);
	}//end testRefusedRbacStopsBeforeAnyQuery()

	/**
	 * findAllFor() reads own, public and shared views, then keeps those that grant the caller
	 * something, each carrying that access.
	 *
	 * @return void
	 */
	public function testFindAllForKeepsWhatTheViewGrants(): void {
		$this->results[] = [
			['id' => 1, 'name' => 'Mine', 'owner' => 'pieter', 'is_public' => false, 'shared_with' => null],
			['id' => 2, 'name' => 'Admin private', 'owner' => 'admin', 'is_public' => false, 'shared_with' => null],
			['id' => 3, 'name' => 'Public', 'owner' => 'anouk', 'is_public' => true, 'shared_with' => null],
			['id' => 4, 'name' => 'Team', 'owner' => 'anouk', 'is_public' => false, 'shared_with' => '[{"group":"woo","mode":"read"}]'],
			['id' => 5, 'name' => 'Other team', 'owner' => 'anouk', 'is_public' => false, 'shared_with' => '[{"group":"vth","mode":"read"}]'],
		];

		$listed = $this->mapper()->findAllFor(new ViewerReach(userId: 'pieter', groups: ['woo'], isAdmin: false));

		$this->assertSame(
			'select(*) from(openregister_views) where(orX(eq(owner, :p0), eq(is_public, :p1), isNotNull(shared_with))) orderBy(created, DESC) executeQuery()',
			$this->sql()
		);
		$this->assertSame(['pieter', IQueryBuilder::PARAM_STR], $this->params[':p0']);
		$this->assertSame(
			[
				'rbac read view after 0 queries',
				'organisation filter after: select(*) from(openregister_views) where(orX(eq(owner, :p0), eq(is_public, :p1), isNotNull(shared_with))) orderBy(created, DESC)',
			],
			$this->steps
		);
		$this->assertSame(
			[[1, 'Mine'], [3, 'Public'], [4, 'Team']],
			array_map(static fn (View $view): array => [$view->getId(), $view->getName()], $listed)
		);
		$this->assertSame(['owner', 'read', 'read'], array_map(static fn (View $view): ?string => $view->getAccess(), $listed));
	}//end testFindAllForKeepsWhatTheViewGrants()
}//end class
