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
use OCA\OpenRegister\Kernel\Contracts\Core\PlacedAuditEntry;
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
		$mapper = $this->createMock(originalClassName: AuditTrailMapperInterface::class);
		$mapper->expects($this->once())->method('insertAuditTrails')->willReturnCallback(
			function (array $entries) use (&$rows): array {
				$rows = $entries;
				return $entries;
			}
		);
		$created = new DateTimeImmutable('2026-11-18 10:00:00');

		(new AuditEntryWriter(mapper: $mapper))->writeAuditEntries(
			entries: [
				new AuditEntry(
					uuid: 'u-1',
					action: 'organisation.status',
					user: 'admin',
					userName: 'Admin',
					changed: ['status' => ['old' => 'a', 'new' => 'b']],
					created: $created,
					objectUuid: 'org-1'
				),
				new AuditEntry(uuid: 'u-2', action: 'settings.changed', user: 'system', userName: 'System', changed: [], created: $created),
			]
		);

		$this->assertCount(expectedCount: 2, haystack: $rows);
		$this->assertInstanceOf(expected: AuditTrail::class, actual: $rows[0]);
		$this->assertSame(expected: 'u-1', actual: $rows[0]->getUuid());
		$this->assertSame(expected: 'organisation.status', actual: $rows[0]->getAction());
		$this->assertSame(expected: 'admin', actual: $rows[0]->getUser());
		$this->assertSame(expected: 'Admin', actual: $rows[0]->getUserName());
		$this->assertSame(expected: ['status' => ['old' => 'a', 'new' => 'b']], actual: $rows[0]->getChanged());
		$this->assertSame(expected: '2026-11-18 10:00:00', actual: $rows[0]->getCreated()->format('Y-m-d H:i:s'));
		$this->assertSame(expected: 'org-1', actual: $rows[0]->getObjectUuid());
		$this->assertSame(expected: 'u-2', actual: $rows[1]->getUuid());
		$this->assertNull(actual: $rows[1]->getObjectUuid());
	}//end testEachEntryBecomesARow()

	/**
	 * A database failure reaches the caller.
	 *
	 * @return void
	 */
	public function testAnOrganisationIsStampedOnlyWhenGiven(): void {
		$rows   = [];
		$mapper = $this->createMock(originalClassName: AuditTrailMapperInterface::class);
		$mapper->method('insertAuditTrails')->willReturnCallback(
			static function (array $entries) use (&$rows): array {
				$rows = $entries;
				return $entries;
			}
		);

		(new AuditEntryWriter(mapper: $mapper))->writeAuditEntries(
			entries: [
				new AuditEntry(uuid: 'u-1', action: 'query.run', user: 'alice', userName: 'Alice', changed: [], created: new DateTimeImmutable(), organisationId: 'org-7'),
				new AuditEntry(uuid: 'u-2', action: 'query.run', user: 'alice', userName: 'Alice', changed: [], created: new DateTimeImmutable()),
			]
		);

		$this->assertSame('org-7', $rows[0]->getOrganisationId());
		$this->assertNull($rows[1]->getOrganisationId());
	}//end testAnOrganisationIsStampedOnlyWhenGiven()

	public function testAFailedWriteIsThrown(): void {
		$mapper = $this->createMock(originalClassName: AuditTrailMapperInterface::class);
		$mapper->method('insertAuditTrails')->willThrowException(new DBException('down'));

		$this->expectException(exception: DBException::class);
		(new AuditEntryWriter(mapper: $mapper))->writeAuditEntries(
			entries: [new AuditEntry(uuid: 'u-1', action: 'a', user: 'system', userName: 'System', changed: [], created: new DateTimeImmutable())]
		);
	}//end testAFailedWriteIsThrown()

	/**
	 * A system line is one row by user 'system' with a fresh uuid, dated now.
	 *
	 * @return void
	 */
	public function testASystemLineIsOneSystemRow(): void {
		$rows   = [];
		$mapper = $this->createMock(originalClassName: AuditTrailMapperInterface::class);
		$mapper->expects($this->once())->method('insertAuditTrails')->willReturnCallback(
			function (array $entries) use (&$rows): array {
				$rows = $entries;
				return $entries;
			}
		);
		$before = new DateTimeImmutable('-1 second');

		(new AuditEntryWriter(mapper: $mapper))->recordSystemLine(action: 'bag.load', userName: 'BAG load', changed: ['created' => 3]);

		$this->assertCount(expectedCount: 1, haystack: $rows);
		$this->assertSame(expected: 'bag.load', actual: $rows[0]->getAction());
		$this->assertSame(expected: 'system', actual: $rows[0]->getUser());
		$this->assertSame(expected: 'BAG load', actual: $rows[0]->getUserName());
		$this->assertSame(expected: ['created' => 3], actual: $rows[0]->getChanged());
		$this->assertMatchesRegularExpression(pattern: '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-/', string: (string) $rows[0]->getUuid());
		$this->assertGreaterThanOrEqual(expected: $before->getTimestamp(), actual: $rows[0]->getCreated()->getTimestamp());
		$this->assertNull(actual: $rows[0]->getObjectUuid());
	}//end testASystemLineIsOneSystemRow()
	/**
	 * A placed entry is filed under its register and schema; a missing one stays empty.
	 *
	 * @return void
	 */
	public function testAPlacedEntryIsFiledUnderItsRegisterAndSchema(): void {
		$rows   = [];
		$mapper = $this->createMock(originalClassName: AuditTrailMapperInterface::class);
		$mapper->expects($this->once())->method('insertAuditTrails')->willReturnCallback(
			function (array $entries) use (&$rows): array {
				$rows = $entries;
				return $entries;
			}
		);
		$entry = new AuditEntry(uuid: 'u-1', action: 'a', user: 'alice', userName: 'alice', changed: ['property' => 'bsn'], created: new DateTimeImmutable(), objectUuid: 'o-1');

		(new AuditEntryWriter(mapper: $mapper))->writeAuditEntries(
			entries: [new PlacedAuditEntry(entry: $entry, register: 14, schema: 24), new PlacedAuditEntry(entry: $entry, schema: 24)]
		);

		$this->assertSame(expected: 14, actual: $rows[0]->getRegister());
		$this->assertSame(expected: 24, actual: $rows[0]->getSchema());
		$this->assertSame(expected: 'o-1', actual: $rows[0]->getObjectUuid());
		$this->assertSame(expected: ['property' => 'bsn'], actual: $rows[0]->getChanged());
		$this->assertNull(actual: $rows[1]->getRegister());
		$this->assertSame(expected: 24, actual: $rows[1]->getSchema());
	}//end testAPlacedEntryIsFiledUnderItsRegisterAndSchema()
}//end class
