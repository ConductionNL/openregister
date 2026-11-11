<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Db\AccessLink;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\StoredEntity;
use OCA\OpenRegister\Files\Service\NoteService;
use OCA\OpenRegister\Files\Service\NoteAccounts;
use OCA\OpenRegister\Files\Service\NoteVersionService;
use OCA\OpenRegister\Kernel\Contracts\AccessLinkInterface;
use OCA\OpenRegister\Notifications\Service\TimelineVisibilityServiceInterface;
use OCP\Comments\ICommentsManager;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Contracts name the interfaces; the implementations get the stored row back or refuse.
 *
 * @covers \OCA\OpenRegister\Db\StoredEntity
 * @covers \OCA\OpenRegister\Db\AccessLink
 * @covers \OCA\OpenRegister\Files\Service\NoteService
 */
class StoredEntityObjectTest extends TestCase
{
    public function testAStoredRowComesBackAsItself(): void
    {
        $object = new ObjectEntity();

        $this->assertSame(expected: $object, actual: StoredEntity::object(object: $object));
    }//end testAStoredRowComesBackAsItself()

    public function testNullStaysNull(): void
    {
        $this->assertNull(StoredEntity::object(object: null));
    }//end testNullStaysNull()

    public function testAForeignImplementationIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        StoredEntity::object(object: $this->createMock(ObjectEntityInterface::class));
    }//end testAForeignImplementationIsRefused()

    public function testTheLinkAnswersItsSubjectThroughTheContract(): void
    {
        $link = new AccessLink();
        $link->setSubjectType(AccessLink::SUBJECT_FILE);
        $link->setSubjectId('object-uuid:12');

        $this->assertInstanceOf(AccessLinkInterface::class, $link);
        $this->assertSame(expected: AccessLink::SUBJECT_FILE, actual: $link->getSubjectType());
        $this->assertSame(expected: 'object-uuid:12', actual: $link->getSubjectId());
    }//end testTheLinkAnswersItsSubjectThroughTheContract()

    public function testANoteEditIsAuditedOnTheStoredRow(): void
    {
        $object   = new ObjectEntity();
        $versions = $this->createMock(NoteVersionService::class);
        $versions->expects($this->once())->method('auditEdit')->with($object, 7, 2)->willReturn(true);

        $this->assertTrue($this->noteService(versions: $versions)->auditEdit(object: $object, noteId: 7, versions: 2));
    }//end testANoteEditIsAuditedOnTheStoredRow()

    public function testANoteEditOnAForeignObjectWritesNoAuditEntry(): void
    {
        $versions = $this->createMock(NoteVersionService::class);
        $versions->expects($this->never())->method('auditEdit');

        $this->expectException(InvalidArgumentException::class);

        $this->noteService(versions: $versions)->auditEdit(
            object: $this->createMock(ObjectEntityInterface::class),
            noteId: 7,
            versions: 2
        );
    }//end testANoteEditOnAForeignObjectWritesNoAuditEntry()

    private function noteService(NoteVersionService $versions): NoteService
    {
        return new NoteService(
            commentsManager: $this->createMock(ICommentsManager::class),
            accounts: new NoteAccounts(
                userSession: $this->createMock(IUserSession::class),
                userManager: $this->createMock(IUserManager::class)
            ),
            visibility: $this->createMock(TimelineVisibilityServiceInterface::class),
            versions: $versions
        );
    }//end noteService()
}//end class
