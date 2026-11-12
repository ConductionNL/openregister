<?php

/**
 * Characterisation of ApplicationMapper's single-column reads: the RBAC check first, the
 * query on its column, the organisation filter where the read applies one, and the paging
 * and count of an organisation's applications.
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

use OCA\OpenRegister\Db\ApplicationMapper;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\Tenancy\TenancyServices;
use OCA\OpenRegister\Kernel\Contracts\EntityEventsInterface;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Golden single-column reads of ApplicationMapper.
 *
 * @covers \OCA\OpenRegister\Db\ApplicationMapper
 */
final class ApplicationMapperReadsGoldenTest extends TestCase {
	use RecordsQueries;

	/**
	 * What happened, in order.
	 *
	 * @var list<string>
	 */
	private array $steps = [];

	/**
	 * A mapper whose RBAC check and organisation filter are recorded.
	 *
	 * @param bool $refuse Whether the RBAC check refuses.
	 *
	 * @return ApplicationMapper&MockObject
	 */
	private function mapper(bool $refuse=false): ApplicationMapper&MockObject {
		$mapper = $this->getMockBuilder(ApplicationMapper::class)
			->setConstructorArgs([
				$this->recordingDb(),
				new TenancyServices($this->createMock(OrganisationMapper::class), $this->createMock(IUserSession::class), $this->createMock(IGroupManager::class)),
				$this->createMock(EntityEventsInterface::class),
			])
			->onlyMethods(['verifyRbacPermission', 'applyOrganisationFilter'])
			->getMock();
		$mapper->method('verifyRbacPermission')->willReturnCallback(
			function (string $action, string $entityType) use ($refuse): void {
				$this->steps[] = 'rbac ' . $action . ' ' . $entityType . ' after ' . count($this->queries) . ' queries';
				if ($refuse === true) {
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
	 * find() and findByUuid() read one application of the active organisation.
	 *
	 * @return void
	 */
	public function testFindByIdAndUuid(): void {
		$this->results[] = [['id' => 4, 'uuid' => 'a-4']];
		$this->results[] = [['id' => 5, 'uuid' => 'a-5']];
		$mapper = $this->mapper();

		$this->assertSame(4, $mapper->find(4)->getId());
		$this->assertSame(5, $mapper->findByUuid('a-5')->getId());

		$this->assertSame('select(*) from(openregister_applications) where(eq(id, :p0)) executeQuery()', $this->sql(0));
		$this->assertSame('select(*) from(openregister_applications) where(eq(uuid, :p1)) executeQuery()', $this->sql(1));
		$this->assertSame([[4, IQueryBuilder::PARAM_INT], ['a-5', IQueryBuilder::PARAM_STR]], array_values($this->params));
		$this->assertSame(
			[
				'rbac read application after 0 queries',
				'organisation filter after: select(*) from(openregister_applications) where(eq(id, :p0))',
				'rbac read application after 1 queries',
				'organisation filter after: select(*) from(openregister_applications) where(eq(uuid, :p1))',
			],
			$this->steps
		);
	}//end testFindByIdAndUuid()

	/**
	 * An organisation's applications, paged and newest first, and their count; neither is organisation-filtered.
	 *
	 * @return void
	 */
	public function testByOrganisation(): void {
		$this->results[] = [['id' => 1], ['id' => 2]];
		$this->results[] = [['COUNT(*)' => '7']];
		$mapper = $this->mapper();

		$this->assertCount(2, $mapper->findByOrganisation('org-1', new ListQuery(10, 20)));
		$this->assertSame(7, $mapper->countByOrganisation('org-1'));

		$this->assertSame(
			'select(*) from(openregister_applications) where(eq(organisation, :p0)) setMaxResults(10) setFirstResult(20) orderBy(created, DESC) executeQuery()',
			$this->sql(0)
		);
		$this->assertSame('select(COUNT(*)) from(openregister_applications) where(eq(organisation, :p1)) executeQuery()', $this->sql(1));
		$this->assertSame([['org-1', IQueryBuilder::PARAM_STR], ['org-1', IQueryBuilder::PARAM_STR]], array_values($this->params));
		$this->assertSame(['rbac read application after 0 queries', 'rbac read application after 1 queries'], $this->steps);
	}//end testByOrganisation()

	/**
	 * Without an offset the page starts at 0.
	 *
	 * @return void
	 */
	public function testByOrganisationWithoutOffset(): void {
		$this->mapper()->findByOrganisation('org-1', new ListQuery(5, null));

		$this->assertStringContainsString('setMaxResults(5) setFirstResult(0)', $this->sql());
	}//end testByOrganisationWithoutOffset()

	/**
	 * A refused RBAC check stops every read before its query.
	 *
	 * @return void
	 */
	public function testRefusedReadsSendNoQuery(): void {
		$calls = [
			fn (ApplicationMapper $m) => $m->find(1),
			fn (ApplicationMapper $m) => $m->findByUuid('u'),
			fn (ApplicationMapper $m) => $m->findByOrganisation('o', new ListQuery(1, 0)),
			fn (ApplicationMapper $m) => $m->countByOrganisation('o'),
		];
		foreach ($calls as $call) {
			try {
				$call($this->mapper(refuse: true));
				$this->fail('The read was let through.');
			} catch (RuntimeException $e) {
				$this->assertSame('refused', $e->getMessage());
			}
		}

		$this->assertSame([], $this->queries);
	}//end testRefusedReadsSendNoQuery()
}//end class
