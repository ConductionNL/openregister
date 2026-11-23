<?php

/**
 * ObjectActivityAudit reads the object in the system context and writes the entry.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/send-at-on-the-messaging-leaf/specs/integration-message-dispatch/spec.md#requirement-a-send-may-be-scheduled-and-bound-to-an-object
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.
// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\MagicMapper\MagicObjectFinderInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectActivityAuditInterface;
use OCA\OpenRegister\Service\CoreServiceBindings;
use OCA\OpenRegister\Service\Object\ObjectActivityAudit;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Object\ObjectActivityAudit
 * @uses   \OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent
 * @uses   \OCA\OpenRegister\Db\AuditTrail
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 */
class ObjectActivityAuditTest extends TestCase {

	public function testTheObjectIsReadWithoutAUserAndTheEntryWrittenOnIt(): void {
		$case = new ObjectEntity();
		$case->setUuid('case-1');
		$objects = $this->createMock(MagicObjectFinderInterface::class);
		$objects->expects($this->once())->method('find')
			->with('case-1', null, null, false, false, false)
			->willReturn($case);
		$events     = [];
		$auditTrail = $this->createMock(AuditTrailMapperInterface::class);
		$auditTrail->expects($this->once())->method('recordObjectEvent')->willReturnCallback(function (ObjectAuditEvent $event) use (&$events) {
			$events[] = $event;
			return new AuditTrail();
		});

		(new ObjectActivityAudit($objects, $auditTrail))->record(objectUuid: 'case-1', action: 'message.sent', context: ['message' => '7']);

		$this->assertCount(1, $events);
		$this->assertSame($case, $events[0]->object);
		$this->assertSame('message.sent', $events[0]->action);
		$this->assertSame(['message' => '7'], $events[0]->context);
	}

	public function testAMissingObjectPropagatesAndWritesNothing(): void {
		$objects = $this->createMock(MagicObjectFinderInterface::class);
		$objects->method('find')->willThrowException(new DoesNotExistException('gone'));
		$auditTrail = $this->createMock(AuditTrailMapperInterface::class);
		$auditTrail->expects($this->never())->method('recordObjectEvent');

		$this->expectException(DoesNotExistException::class);
		(new ObjectActivityAudit($objects, $auditTrail))->record(objectUuid: 'case-1', action: 'message.sent');
	}

	public function testAFailedWritePropagates(): void {
		$objects = $this->createMock(MagicObjectFinderInterface::class);
		$objects->method('find')->willReturn(new ObjectEntity());
		$auditTrail = $this->createMock(AuditTrailMapperInterface::class);
		$auditTrail->method('recordObjectEvent')->willThrowException(new RuntimeException('db down'));

		$this->expectException(RuntimeException::class);
		(new ObjectActivityAudit($objects, $auditTrail))->record(objectUuid: 'case-1', action: 'message.failed');
	}

	public function testThePortIsBoundToThisClassInCore(): void {
		$this->assertSame(ObjectActivityAudit::class, CoreServiceBindings::BINDINGS[ObjectActivityAuditInterface::class]);
	}
}//end class
