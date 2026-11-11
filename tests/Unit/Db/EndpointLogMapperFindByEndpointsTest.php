<?php

/**
 * EndpointLogMapper::findByEndpoints and findByEndpoint build one IN query, newest first.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\EndpointLogMapper;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Db\EndpointLogScope;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\EndpointLogMapper
 * @uses   \OCA\OpenRegister\Db\EndpointLog
 */
class EndpointLogMapperFindByEndpointsTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private function mapper(): EndpointLogMapper {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->recordingBuilder());
		return new EndpointLogMapper($db);
	}

	public function testAnEmptyListMatchesNothingWithoutAQuery(): void {
		$this->assertSame([], $this->mapper()->findByEndpoints([], ListQuery::unbounded()));
		$this->assertSame([], $this->queryLog);
	}

	public function testItFiltersOnAllGivenEndpointsAndPages(): void {
		$this->queuedRows = [[['id' => 4]]];

		$found = $this->mapper()->findByEndpoints([3, 7], new ListQuery(limit: 5, offset: 10));

		$this->assertCount(1, $found);
		$this->assertSame(4, $found[0]->getId());
		$this->assertSame(self::expectedLog('[3,7]', true), $this->queryLog);
	}

	public function testOneEndpointIsTheSameQueryWithOneId(): void {
		$this->mapper()->findByEndpoint(3);

		$this->assertSame(self::expectedLog('[3]', false), $this->queryLog);
	}

	public function testAPageWithinVisibleEndpointsCoversAllOfThem(): void {
		$this->queuedRows = [[['id' => 4]], [['id' => 4], ['id' => 5]]];

		[$logs, $total] = $this->mapper()->findPageWithin(new EndpointLogScope([3, 7]), new ListQuery(5, 10));

		$this->assertCount(1, $logs);
		$this->assertSame(2, $total);
		$this->assertContains('#1 param :p1 = [3,7], 101', $this->queryLog);
		$this->assertContains('#2 param :p2 = [3,7], 101', $this->queryLog);
	}

	public function testAPageOfAVisibleEndpointNarrowsToIt(): void {
		$this->mapper()->findPageWithin(new EndpointLogScope([3, 7], 7), new ListQuery(5, 10));

		$this->assertContains('#1 param :p1 = [7], 101', $this->queryLog);
	}

	public function testAPageOfAnEndpointThatIsNotVisibleIsEmptyWithoutAQuery(): void {
		$this->assertSame([[], 0], $this->mapper()->findPageWithin(new EndpointLogScope([3, 7], 99), new ListQuery(5, 10)));
		$this->assertSame([], $this->queryLog);
	}

	/**
	 * @param string $ids   The rendered id list.
	 * @param bool   $paged Whether limit 5 / offset 10 were given.
	 *
	 * @return list<string>
	 */
	private static function expectedLog(string $ids, bool $paged): array {
		$log = [
			'#1 select(*)',
			'#1 from(openregister_endpoint_logs, NULL)',
			'#1 param :p1 = ' . $ids . ', 101',
			'#1 expr.in(endpoint_id, :p1, NULL)',
			'#1 where(in(endpoint_id, :p1, NULL))',
			'#1 orderBy(created, DESC)',
		];
		if ($paged === true) {
			$log[] = '#1 setMaxResults(5)';
			$log[] = '#1 setFirstResult(10)';
		}

		$log[] = '#1 executeQuery()';
		return $log;
	}
}
