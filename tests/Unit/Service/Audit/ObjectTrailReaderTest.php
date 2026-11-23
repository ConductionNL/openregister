<?php

/**
 * Tests for ObjectTrailReader: one object's audit trail rows, read as plain fields for Core.
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

use DateTime;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrail\ActionLookupOptions;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\ObjectTrailReaderInterface;
use OCA\OpenRegister\Service\Audit\ObjectTrailReader;
use OCA\OpenRegister\Service\ContractBindings;
use OCP\DB\Exception as DBException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Audit\ObjectTrailReader
 */
class ObjectTrailReaderTest extends TestCase {

	/**
	 * The object's rows are looked up by uuid, every action, up to the limit, and read as fields in order.
	 *
	 * @return void
	 */
	public function testTheRowsAreReadAsFieldsNewestFirst(): void {
		$dated = new AuditTrail();
		$dated->setAction('update');
		$dated->setUser('ana');
		$dated->setUserName('Ana');
		$dated->setChanged(['authorization' => ['old' => null, 'new' => ['read' => ['a']]]]);
		$dated->setCreated(new DateTime('2026-02-01T09:00:00+01:00'));
		$undated = new AuditTrail();
		$undated->setAction('create');

		$mapper = $this->createMock(originalClassName: AuditTrailMapperInterface::class);
		$mapper->expects($this->once())->method('findObjectActions')->with(
			'object-1',
			$this->callback(static fn (ActionLookupOptions $options): bool => $options->actions === [] && $options->limit === 7)
		)->willReturn([$dated, $undated]);

		$entries = (new ObjectTrailReader(mapper: $mapper))->objectTrail(objectUuid: 'object-1', limit: 7);

		$this->assertSame(['action', 'user', 'userName', 'changed', 'created'], array_keys($entries[0]));
		$this->assertSame('update', $entries[0]['action']);
		$this->assertSame('ana', $entries[0]['user']);
		$this->assertSame('Ana', $entries[0]['userName']);
		$this->assertSame(['authorization' => ['old' => null, 'new' => ['read' => ['a']]]], $entries[0]['changed']);
		$this->assertSame('2026-02-01T09:00:00+01:00', $entries[0]['created']->format('c'));
		$this->assertSame('create', $entries[1]['action']);
		$this->assertNull($entries[1]['user']);
		$this->assertNull($entries[1]['created']);
	}//end testTheRowsAreReadAsFieldsNewestFirst()

	/**
	 * A failed read reaches the caller.
	 *
	 * @return void
	 */
	public function testAFailedReadIsThrown(): void {
		$mapper = $this->createMock(originalClassName: AuditTrailMapperInterface::class);
		$mapper->method('findObjectActions')->willThrowException(new DBException('down'));

		$this->expectException(DBException::class);
		(new ObjectTrailReader(mapper: $mapper))->objectTrail(objectUuid: 'object-1', limit: 7);
	}//end testAFailedReadIsThrown()

	/**
	 * Core's port is bound to this reader.
	 *
	 * @return void
	 */
	public function testThePortIsBoundToTheReader(): void {
		$this->assertSame(ObjectTrailReader::class, ContractBindings::BINDINGS[ObjectTrailReaderInterface::class]);
		$this->assertInstanceOf(ObjectTrailReaderInterface::class, new ObjectTrailReader(mapper: $this->createMock(AuditTrailMapperInterface::class)));
	}//end testThePortIsBoundToTheReader()
}//end class
