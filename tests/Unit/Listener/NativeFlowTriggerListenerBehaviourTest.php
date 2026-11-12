<?php

/**
 * NativeFlowTriggerListenerBehaviourTest — update/delete triggers and partial payloads.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Listener\NativeFlowTriggerListener;
use OCA\OpenRegister\Service\Flow\FlowTriggerServiceInterface;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Share\Events\ShareDeletedEvent;
use OCP\Share\IShare;
use OCP\SystemTag\TagAssignedEvent;
use OCP\SystemTag\TagUnassignedEvent;
use OCP\User\Events\UserDeletedEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Listener\NativeFlowTriggerListener
 */
class NativeFlowTriggerListenerBehaviourTest extends TestCase {

	private FlowTriggerServiceInterface&MockObject $triggers;

	private NativeFlowTriggerListener $listener;

	protected function setUp(): void {
		$this->triggers = $this->createMock(FlowTriggerServiceInterface::class);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$this->listener = new NativeFlowTriggerListener($this->triggers, $session);
	}

	public function testAFileWriteFiresTheUpdatedTriggerSkippingUnreadableFields(): void {
		$node = $this->createMock(Node::class);
		$node->method('getId')->willReturn(5);
		$node->method('getPath')->willThrowException(new NotFoundException('gone'));
		$node->method('getName')->willReturn('a.txt');
		$node->method('getMimetype')->willReturn('text/plain');
		$event = $this->createMock(NodeWrittenEvent::class);
		$event->method('getNode')->willReturn($node);

		$this->triggers->expects($this->once())->method('fire')
			->with('file.updated', [], null, ['payload' => ['fileId' => 5, 'name' => 'a.txt', 'mimetype' => 'text/plain']]);

		$this->listener->handle($event);
	}

	public function testAUserDeletionFiresWithTheDeletedUsersUid(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('carol');
		$event = $this->createMock(UserDeletedEvent::class);
		$event->method('getUser')->willReturn($user);

		$this->triggers->expects($this->once())->method('fire')
			->with('user.deleted', [], null, ['payload' => ['uid' => 'carol']]);

		$this->listener->handle($event);
	}

	public function testAShareDeletionFiresTheDeletedTrigger(): void {
		$share = $this->createMock(IShare::class);
		$share->method('getId')->willReturn('8');
		$share->method('getNodeId')->willReturn(3);
		$share->method('getShareType')->willReturn(3);
		$share->method('getSharedWith')->willReturn('');
		$share->method('getNode')->willThrowException(new NotFoundException('gone'));
		$event = $this->createMock(ShareDeletedEvent::class);
		$event->method('getShare')->willReturn($share);

		$this->triggers->expects($this->once())->method('fire')
			->with('share.deleted', [], null, ['payload' => ['shareId' => '8', 'nodeId' => 3, 'shareType' => 3, 'sharedWith' => '']]);

		$this->listener->handle($event);
	}

	/**
	 * The payload's object fields, without asserting on the tags entry.
	 *
	 * @param string        $type The object type.
	 * @param array<string> $ids  The object ids.
	 *
	 * @return \PHPUnit\Framework\Constraint\Callback
	 */
	private function tagContext(string $type, array $ids): \PHPUnit\Framework\Constraint\Callback {
		return $this->callback(
			static fn (array $ctx): bool => $ctx['payload']['objectType'] === $type && $ctx['payload']['objectIds'] === $ids
		);
	}

	public function testATagAssignmentFiresTheAssignedTrigger(): void {
		$this->triggers->expects($this->once())->method('fire')
			->with('tag.assigned', [], null, $this->tagContext('files', ['10', '11']));

		$this->listener->handle(new TagAssignedEvent('files', ['10', '11'], [3]));
	}

	public function testATagRemovalFiresTheUnassignedTrigger(): void {
		$this->triggers->expects($this->once())->method('fire')
			->with('tag.unassigned', [], null, $this->tagContext('files', ['12']));

		$this->listener->handle(new TagUnassignedEvent('files', ['12'], [4]));
	}
}
