<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenRegister\Listener\NativeFlowTriggerListener;
use OCA\OpenRegister\Service\Flow\FlowTriggerService;
use OCP\EventDispatcher\Event;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Node;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Share\Events\ShareCreatedEvent;
use OCP\Share\IShare;
use OCP\User\Events\UserCreatedEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class NativeFlowTriggerListenerTest extends TestCase
{
    private FlowTriggerService&MockObject $triggers;

    private NativeFlowTriggerListener $listener;

    protected function setUp(): void
    {
        $this->triggers = $this->createMock(FlowTriggerService::class);
        $session        = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn(null);

        $this->listener = new NativeFlowTriggerListener($this->triggers, $session);
    }

    private function node(): Node
    {
        $node = $this->createMock(Node::class);
        $node->method('getId')->willReturn(42);
        $node->method('getPath')->willReturn('/admin/files/report.pdf');
        $node->method('getName')->willReturn('report.pdf');
        $node->method('getMimetype')->willReturn('application/pdf');
        return $node;
    }

    public function testAFileCreationFiresWithItsPayload(): void
    {
        $this->triggers->expects($this->once())->method('fire')
            ->with(
                'file.created',
                [],
                null,
                ['payload' => ['fileId' => 42, 'path' => '/admin/files/report.pdf', 'name' => 'report.pdf', 'mimetype' => 'application/pdf']]
            )
            ->willReturn(1);

        $event = $this->createMock(NodeCreatedEvent::class);
        $event->method('getNode')->willReturn($this->node());

        $this->listener->handle($event);
    }

    public function testAFileDeletionFiresTheDeletedTrigger(): void
    {
        $this->triggers->expects($this->once())->method('fire')
            ->with('file.deleted', [], null, $this->anything())
            ->willReturn(1);

        $event = $this->createMock(NodeDeletedEvent::class);
        $event->method('getNode')->willReturn($this->node());

        $this->listener->handle($event);
    }

    public function testAUserCreationFiresWithTheUid(): void
    {
        $this->triggers->expects($this->once())->method('fire')
            ->with('user.created', [], null, ['payload' => ['uid' => 'alice']])
            ->willReturn(1);

        $event = $this->createMock(UserCreatedEvent::class);
        $event->method('getUid')->willReturn('alice');

        $this->listener->handle($event);
    }

    public function testAFileEventCarriesNoObjectSubject(): void
    {
        // The subject is empty — a file is not an OpenRegister object — so the
        // flow matches on the trigger id alone.
        $this->triggers->expects($this->once())->method('fire')
            ->with($this->anything(), [], $this->anything(), $this->anything())
            ->willReturn(0);

        $event = $this->createMock(NodeCreatedEvent::class);
        $event->method('getNode')->willReturn($this->node());
        $this->listener->handle($event);
    }

    public function testAShareCreationFiresWithItsPayload(): void
    {
        $node = $this->createMock(Node::class);
        $node->method('getPath')->willReturn('/admin/files/shared.txt');

        $share = $this->createMock(IShare::class);
        $share->method('getId')->willReturn('7');
        $share->method('getNodeId')->willReturn(99);
        $share->method('getShareType')->willReturn(0);
        $share->method('getSharedWith')->willReturn('bob');
        $share->method('getNode')->willReturn($node);

        $this->triggers->expects($this->once())->method('fire')
            ->with(
                'share.created',
                [],
                null,
                ['payload' => ['shareId' => '7', 'nodeId' => 99, 'shareType' => 0, 'sharedWith' => 'bob', 'path' => '/admin/files/shared.txt']]
            )
            ->willReturn(1);

        $event = $this->createMock(ShareCreatedEvent::class);
        $event->method('getShare')->willReturn($share);
        $this->listener->handle($event);
    }

    // Tag events (TagAssignedEvent / TagUnassignedEvent) are newer OCP classes
    // absent from the composer-only test stubs, so they cannot be mocked here;
    // the tag path is live-verified on an instance where the class exists.

    public function testAnUnrelatedEventFiresNothing(): void
    {
        $this->triggers->expects($this->never())->method('fire');
        $this->listener->handle(new class extends Event {});
    }

    public function testTheActingUserIsAttributed(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('bob');
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($user);
        $listener = new NativeFlowTriggerListener($this->triggers, $session);

        $this->triggers->expects($this->once())->method('fire')
            ->with('user.created', [], 'bob', $this->anything())
            ->willReturn(1);

        $event = $this->createMock(UserCreatedEvent::class);
        $event->method('getUid')->willReturn('carol');
        $listener->handle($event);
    }
}
