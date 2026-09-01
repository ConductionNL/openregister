<?php

/**
 * OrganisationMapperMemoTest — the request-scoped active-organisation memo.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\OrganisationMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the per-request memoisation of getActiveOrganisationUuidForUser().
 *
 * The getter runs on the hot tenant-filter path (once per mapper query), so before the
 * memo a single object write issued ~1,000 identical preference reads. These tests assert
 * that repeated look-ups collapse to one DB round-trip, that distinct users are cached
 * independently, that a "no active organisation" (null) result is cached too, and that
 * writing through setActiveOrganisationForUser() keeps the memo coherent.
 *
 * @covers \OCA\OpenRegister\Db\OrganisationMapper
 */
class OrganisationMapperMemoTest extends TestCase {

	/**
	 * Number of times executeQuery() has been invoked on the DB scaffolding.
	 *
	 * @var int
	 */
	private int $selectCount = 0;

	/**
	 * The value the mocked preferences SELECT returns for `configvalue`.
	 *
	 * @var string|false
	 */
	private string|false $storedConfigValue = '286a9152-0000-0000-0000-000000000000';

	/**
	 * DB connection mock.
	 *
	 * @var IDBConnection&MockObject
	 */
	private IDBConnection&MockObject $db;

	/**
	 * Wire a DB connection whose every query counts and returns the stored preference row.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');

		$cursor = $this->createMock(IResult::class);
		$cursor->method('fetch')->willReturnCallback(
			function () {
				if ($this->storedConfigValue === false) {
					return false;
				}

				return ['configvalue' => $this->storedConfigValue];
			}
		);

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('select')->willReturnSelf();
		$qb->method('update')->willReturnSelf();
		$qb->method('insert')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('set')->willReturnSelf();
		$qb->method('values')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturn('p');
		$qb->method('executeQuery')->willReturnCallback(
			function () use ($cursor): IResult {
				$this->selectCount++;
				return $cursor;
			}
		);
		$qb->method('executeStatement')->willReturn(1);

		$this->db = $this->createMock(IDBConnection::class);
		$this->db->method('getQueryBuilder')->willReturn($qb);
	}//end setUp()

	/**
	 * Build the mapper under test against the counting DB scaffolding.
	 *
	 * @return OrganisationMapper
	 */
	private function makeMapper(): OrganisationMapper {
		return new OrganisationMapper(
			$this->db,
			$this->createMock(LoggerInterface::class),
			$this->createMock(IEventDispatcher::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(IConfig::class)
		);
	}//end makeMapper()

	/**
	 * Repeated look-ups for the same user hit the DB exactly once.
	 *
	 * @return void
	 */
	public function testRepeatedLookupsIssueOneQuery(): void {
		$mapper = $this->makeMapper();

		$first = $mapper->getActiveOrganisationUuidForUser('alice');
		for ($i = 0; $i < 50; $i++) {
			$mapper->getActiveOrganisationUuidForUser('alice');
		}

		$this->assertSame('286a9152-0000-0000-0000-000000000000', $first);
		$this->assertSame(1, $this->selectCount, '51 look-ups for one user must issue a single preferences query');
	}//end testRepeatedLookupsIssueOneQuery()

	/**
	 * Distinct users are memoised independently (one query each).
	 *
	 * @return void
	 */
	public function testDistinctUsersAreCachedIndependently(): void {
		$mapper = $this->makeMapper();

		$mapper->getActiveOrganisationUuidForUser('alice');
		$mapper->getActiveOrganisationUuidForUser('bob');
		$mapper->getActiveOrganisationUuidForUser('alice');
		$mapper->getActiveOrganisationUuidForUser('bob');

		$this->assertSame(2, $this->selectCount, 'two distinct users must issue exactly two queries');
	}//end testDistinctUsersAreCachedIndependently()

	/**
	 * A "no active organisation" (null) result is cached and not re-queried.
	 *
	 * @return void
	 */
	public function testNullResultIsCached(): void {
		$this->storedConfigValue = false;
		$mapper = $this->makeMapper();

		$this->assertNull($mapper->getActiveOrganisationUuidForUser('carol'));
		$this->assertNull($mapper->getActiveOrganisationUuidForUser('carol'));
		$this->assertNull($mapper->getActiveOrganisationUuidForUser('carol'));

		$this->assertSame(1, $this->selectCount, 'a cached null must not re-query the preferences table');
	}//end testNullResultIsCached()

	/**
	 * Writing through the setter updates the memo without a fresh read.
	 *
	 * @return void
	 */
	public function testSetterUpdatesTheMemo(): void {
		$mapper = $this->makeMapper();

		// Prime the memo (1 SELECT), then write a new value through the setter.
		$mapper->getActiveOrganisationUuidForUser('dave');
		$queriesAfterPrime = $this->selectCount;

		$mapper->setActiveOrganisationForUser('dave', 'new-org-uuid');

		// The subsequent read must return the written value from the memo — no new SELECT
		// for the getter itself (the setter's own existence-probe SELECT is separate).
		$selectsBeforeRead = $this->selectCount;
		$value = $mapper->getActiveOrganisationUuidForUser('dave');

		$this->assertSame('new-org-uuid', $value, 'the getter must reflect the in-request write');
		$this->assertSame($selectsBeforeRead, $this->selectCount, 'reading after a setter write must not hit the DB');
		$this->assertGreaterThanOrEqual(1, $queriesAfterPrime);
	}//end testSetterUpdatesTheMemo()
}//end class
