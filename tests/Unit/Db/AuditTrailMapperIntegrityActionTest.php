<?php

/**
 * OpenRegister AuditTrailMapperIntegrityActionTest
 *
 * The audit trail records what referential integrity did to an object: the
 * row it builds, the scope it takes from the entry, and that it is stamped
 * before it is inserted.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Object\IntegrityAuditEntry;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\AuditTrailMapper::recordIntegrityAction
 * @covers \OCA\OpenRegister\Db\AuditTrailMapper::integrityRow
 * @uses   \OCA\OpenRegister\Db\AuditTrail
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Service\Object\IntegrityAuditEntry
 */
final class AuditTrailMapperIntegrityActionTest extends TestCase {

	/**
	 * The row carries the entry's action, object, change and actor, and a fresh uuid and moment.
	 *
	 * @return void
	 */
	public function testTheRowCarriesTheEntry(): void {
		$row = AuditTrailMapper::integrityRow(
			entry: new IntegrityAuditEntry(action: 'referential_integrity.set_null', objectUuid: 'obj-1', changed: ['property' => 'ref'], userId: 'admin')
		);

		self::assertSame('referential_integrity.set_null', $row->getAction());
		self::assertSame('obj-1', $row->getObjectUuid());
		self::assertSame(['property' => 'ref'], $row->getChanged());
		self::assertSame('admin', $row->getUser());
		self::assertNotEmpty($row->getUuid());
		self::assertNotNull($row->getCreated());
		self::assertNull($row->getSchema());
		self::assertNull($row->getRegister());
	}//end testTheRowCarriesTheEntry()

	/**
	 * A schema and register the entry names scope the row, as integers.
	 *
	 * @return void
	 */
	public function testTheRowIsScopedToTheNamedSchemaAndRegister(): void {
		$entry = (new IntegrityAuditEntry(action: 'a', objectUuid: 'o', changed: [], userId: 'u'))->within(schemaId: '5', registerId: '9');

		$row = AuditTrailMapper::integrityRow(entry: $entry);

		self::assertSame(5, $row->getSchema());
		self::assertSame(9, $row->getRegister());
	}//end testTheRowIsScopedToTheNamedSchemaAndRegister()

	/**
	 * The row is stamped from the entry's object, then inserted, and returned.
	 *
	 * @return void
	 */
	public function testTheRowIsStampedFromTheObjectThenInserted(): void {
		$object = new ObjectEntity();
		$steps  = [];

		$mapper = $this->getMockBuilder(AuditTrailMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['applyRetentionExpiry', 'insert'])
			->getMock();
		$mapper->expects(self::once())->method('applyRetentionExpiry')->willReturnCallback(
			static function (AuditTrail $auditTrail, ?ObjectEntity $objectEntity) use (&$steps, $object): AuditTrail {
				self::assertSame($object, $objectEntity);
				$steps[] = 'stamp';
				return $auditTrail;
			}
		);
		$mapper->expects(self::once())->method('insert')->willReturnCallback(
			static function (AuditTrail $auditTrail) use (&$steps): AuditTrail {
				$steps[] = 'insert:' . $auditTrail->getObjectUuid();
				return $auditTrail;
			}
		);

		$row = $mapper->recordIntegrityAction(entry: (new IntegrityAuditEntry(action: 'a', objectUuid: 'obj-2', changed: [], userId: 'u'))->about($object));

		self::assertSame(['stamp', 'insert:obj-2'], $steps);
		self::assertSame('obj-2', $row->getObjectUuid());
	}//end testTheRowIsStampedFromTheObjectThenInserted()

	/**
	 * A failed insert is thrown to the caller, who decides whether it blocks.
	 *
	 * @return void
	 */
	public function testAFailedInsertIsThrown(): void {
		$mapper = $this->getMockBuilder(AuditTrailMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['applyRetentionExpiry', 'insert'])
			->getMock();
		$mapper->method('applyRetentionExpiry')->willReturnArgument(0);
		$mapper->method('insert')->willThrowException(new \RuntimeException('insert failed'));

		$this->expectExceptionMessage('insert failed');
		$mapper->recordIntegrityAction(entry: new IntegrityAuditEntry(action: 'a', objectUuid: 'o', changed: [], userId: 'u'));
	}//end testAFailedInsertIsThrown()
}//end class
