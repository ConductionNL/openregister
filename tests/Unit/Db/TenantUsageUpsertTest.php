<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\TenantUsage;
use OCA\OpenRegister\Db\TenantUsageMapper;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Pins what an hourly usage upsert writes: a new bucket is inserted with the
 * reading as given, an existing bucket adds the requests and bandwidth and
 * replaces the storage figure.
 *
 * @covers \OCA\OpenRegister\Db\TenantUsageMapper
 */
final class TenantUsageUpsertTest extends TestCase {

	/**
	 * A mapper whose lookup finds the given row and whose writes echo the entity.
	 *
	 * @param TenantUsage|null $existing The row the lookup returns.
	 *
	 * @return TenantUsageMapper&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function mapper(?TenantUsage $existing): TenantUsageMapper {
		$mapper = $this->getMockBuilder(TenantUsageMapper::class)
			->setConstructorArgs([$this->createMock(IDBConnection::class)])
			->onlyMethods(['findByOrgAndPeriod', 'insert', 'update'])
			->getMock();
		$mapper->method('findByOrgAndPeriod')->willReturn($existing);

		return $mapper;
	}//end mapper()

	public function testANewBucketIsInsertedWithTheReadingAsGiven(): void {
		$period = new DateTime('2026-03-01T10:00:00+00:00');
		$mapper = $this->mapper(null);
		$mapper->expects($this->never())->method('update');
		$mapper->expects($this->once())->method('insert')->willReturnArgument(0);

		$row = $mapper->upsertUsage('org-1', $period, 4, 512, 9);

		$this->assertSame(
			['org-1', $period, 4, 512, 9],
			[$row->getOrganisationUuid(), $row->getPeriod(), $row->getRequestCount(), $row->getBandwidthBytes(), $row->getStorageBytes()]
		);
		$this->assertInstanceOf(DateTime::class, $row->getCreated());
		$this->assertInstanceOf(DateTime::class, $row->getUpdated());
	}//end testANewBucketIsInsertedWithTheReadingAsGiven()

	public function testAnExistingBucketAddsTrafficAndReplacesStorage(): void {
		$existing = new TenantUsage();
		$existing->setOrganisationUuid('org-1');
		$existing->setRequestCount(10);
		$existing->setBandwidthBytes(100);
		$existing->setStorageBytes(7);

		$mapper = $this->mapper($existing);
		$mapper->expects($this->never())->method('insert');
		$mapper->expects($this->once())->method('update')->willReturnArgument(0);

		$row = $mapper->upsertUsage('org-1', new DateTime('2026-03-01T10:00:00+00:00'), 4, 512, 0);

		$this->assertSame($existing, $row);
		$this->assertSame([14, 612, 0], [$row->getRequestCount(), $row->getBandwidthBytes(), $row->getStorageBytes()]);
		$this->assertInstanceOf(DateTime::class, $row->getUpdated());
	}//end testAnExistingBucketAddsTrafficAndReplacesStorage()
}//end class
