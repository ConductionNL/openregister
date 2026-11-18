<?php

/**
 * Tests for AuditEntryWriter: Core's audit entries become sealed audit trail rows.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Audit;

use DateTimeImmutable;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\AuditEntry;
use OCA\OpenRegister\Service\Audit\AuditEntryWriter;
use OCP\DB\Exception as DBException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Audit\AuditEntryWriter
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Core\AuditEntry
 * @uses   \OCA\OpenRegister\Db\AuditTrail
 */
class AuditEntryWriterTest extends TestCase {

	/**
	 * Each entry becomes one row with the same fields, inserted in order in one call.
	 *
	 * @return void
	 */
	public function testEachEntryBecomesARow(): void {
		$rows   = [];
		$mapper = $this->createMock(AuditTrailMapperInterface::class);
		$mapper->expects($this->once())->method('insertAuditTrails')->willReturnCallback(
			function (array $entries) use (&$rows): array {
				$rows = $entries;
				return $entries;
			}
		);
		$created = new DateTimeImmutable('2026-11-18 10:00:00');

		(new AuditEntryWriter(mapper: $mapper))->writeAuditEntries(
			entries: [
				new AuditEntry(uuid: 'u-1', action: 'organisation.status', user: 'admin', userName: 'Admin', changed: ['status' => ['old' => 'a', 'new' => 'b']], created: $created, objectUuid: 'org-1'),
				new AuditEntry(uuid: 'u-2', action: 'settings.changed', user: 'system', userName: 'System', changed: [], created: $created),
			]
		);

		$this->assertCount(2, $rows);
		$this->assertInstanceOf(AuditTrail::class, $rows[0]);
		$this->assertSame('u-1', $rows[0]->getUuid());
		$this->assertSame('organisation.status', $rows[0]->getAction());
		$this->assertSame('admin', $rows[0]->getUser());
		$this->assertSame('Admin', $rows[0]->getUserName());
		$this->assertSame(['status' => ['old' => 'a', 'new' => 'b']], $rows[0]->getChanged());
		$this->assertSame('2026-11-18 10:00:00', $rows[0]->getCreated()->format('Y-m-d H:i:s'));
		$this->assertSame('org-1', $rows[0]->getObjectUuid());
		$this->assertSame('u-2', $rows[1]->getUuid());
		$this->assertNull($rows[1]->getObjectUuid());
	}//end testEachEntryBecomesARow()

	/**
	 * A database failure reaches the caller.
	 *
	 * @return void
	 */
	public function testAFailedWriteIsThrown(): void {
		$mapper = $this->createMock(AuditTrailMapperInterface::class);
		$mapper->method('insertAuditTrails')->willThrowException(new DBException('down'));

		$this->expectException(DBException::class);
		(new AuditEntryWriter(mapper: $mapper))->writeAuditEntries(
			entries: [new AuditEntry(uuid: 'u-1', action: 'a', user: 'system', userName: 'System', changed: [], created: new DateTimeImmutable())]
		);
	}//end testAFailedWriteIsThrown()
}//end class
