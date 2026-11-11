<?php

/**
 * How a consistency probe that fails while running is reported: a write
 * refusal raised by the query propagates, any other failure reads as an
 * unavailable probe rather than a clean one.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Operations
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Operations;

use OCA\OpenRegister\Exception\ConsistencyCheckWouldWriteException;
use OCA\OpenRegister\Service\Operations\ConsistencyCheckService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * Failure-mode tests for {@see ConsistencyCheckService}.
 */
class ConsistencyCheckServiceFailureTest extends TestCase {

	/**
	 * A service with one SELECT probe whose execution throws.
	 *
	 * @param Throwable $failure What executeQuery throws.
	 *
	 * @return ConsistencyCheckService The service.
	 */
	private function serviceFailingWith(Throwable $failure): ConsistencyCheckService {
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('getSQL')->willReturn('  SELECT * FROM oc_things');
		$qb->method('setMaxResults')->with(ConsistencyCheckService::MAX_ROWS_PER_PROBE)->willReturnSelf();
		$qb->method('executeQuery')->willThrowException($failure);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		return new ConsistencyCheckService(
			db: $db,
			probes: [
				'things' => [
					'title' => 'Things',
					'description' => 'Rows without a parent.',
					'repair' => 'Delete them.',
					'table' => 'openregister_things',
					'query' => static fn (IQueryBuilder $builder): IQueryBuilder => $builder,
				],
			]
		);
	}//end serviceFailingWith()

	/**
	 * A write refusal raised while executing is not reported as an
	 * unavailable probe: it propagates to the caller.
	 *
	 * @return void
	 */
	public function testAWriteRefusalRaisedWhileRunningPropagates(): void {
		$service = $this->serviceFailingWith(new ConsistencyCheckWouldWriteException(message: 'would write', probe: 'things'));

		$this->expectException(ConsistencyCheckWouldWriteException::class);
		$this->expectExceptionMessage('would write');
		$service->checkOne(slug: 'things');
	}//end testAWriteRefusalRaisedWhileRunningPropagates()

	/**
	 * Any other failure reads as an unavailable probe naming the cause, with
	 * no count claimed.
	 *
	 * @return void
	 */
	public function testAnyOtherFailureReadsAsUnavailable(): void {
		$finding = $this->serviceFailingWith(new RuntimeException('no such table'))->checkOne(slug: 'things');

		$this->assertSame('no such table', $finding['unavailable']);
		$this->assertSame(0, $finding['count']);
		$this->assertSame([], $finding['objects']);
		$this->assertSame('Things', $finding['title']);
	}//end testAnyOtherFailureReadsAsUnavailable()
}//end class
