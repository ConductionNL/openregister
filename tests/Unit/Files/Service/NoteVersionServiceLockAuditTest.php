<?php

/**
 * The note-locked entry on an object's audit trail: its action, who locked
 * the note and when, and a lost entry logged without undoing the lock.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Files\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/notes-leaf-rich-text-lock-export/specs/object-interactions/spec.md#requirement-a-note-can-be-locked-and-a-locked-note-is-immutable
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Files\Service;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Files\Db\NoteVersionMapper;
use OCA\OpenRegister\Files\Service\NoteVersionService;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Files\Service\NoteVersionService
 */
class NoteVersionServiceLockAuditTest extends TestCase {

	public function testALockIsRecordedOnTheObjectWithWhoAndWhen(): void {
		$recorded = null;
		$trail = $this->createMock(AuditTrailMapperInterface::class);
		$trail->expects($this->once())->method('recordObjectEvent')->willReturnCallback(
			function (ObjectAuditEvent $event) use (&$recorded): AuditTrail {
				$recorded = $event;
				return new AuditTrail();
			}
		);
		$object = new ObjectEntity();
		$service = new NoteVersionService($this->createMock(NoteVersionMapper::class), $trail, $this->createMock(IUserManager::class), $this->createMock(LoggerInterface::class));

		$this->assertTrue($service->auditLock($object, ['id' => '12', 'lockedBy' => 'sanne', 'lockedAt' => '2026-11-13T10:00:00+00:00']));
		$this->assertSame($object, $recorded->object);
		$this->assertSame('note.locked', $recorded->action);
		$this->assertSame(['noteId' => 12, 'lockedBy' => 'sanne', 'lockedAt' => '2026-11-13T10:00:00+00:00'], $recorded->context);
	}

	public function testALostLockEntryIsLoggedAsALockAndReportedFalse(): void {
		$trail = $this->createMock(AuditTrailMapperInterface::class);
		$trail->method('recordObjectEvent')->willThrowException(new RuntimeException('audit table locked'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')
			->with('[NoteVersionService] Note 12 was locked but the audit entry could not be written', $this->arrayHasKey('exception'));
		$service = new NoteVersionService($this->createMock(NoteVersionMapper::class), $trail, $this->createMock(IUserManager::class), $logger);

		$this->assertFalse($service->auditLock(new ObjectEntity(), ['id' => 12]));
	}

	public function testALostEditEntryStillSaysEdited(): void {
		$trail = $this->createMock(AuditTrailMapperInterface::class);
		$trail->method('recordObjectEvent')->willThrowException(new RuntimeException('audit table locked'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')
			->with('[NoteVersionService] Note 12 was edited but the audit entry could not be written', $this->arrayHasKey('exception'));
		$service = new NoteVersionService($this->createMock(NoteVersionMapper::class), $trail, $this->createMock(IUserManager::class), $logger);

		$this->assertFalse($service->auditEdit(new ObjectEntity(), ['id' => 12, 'versionCount' => 2]));
	}
}
