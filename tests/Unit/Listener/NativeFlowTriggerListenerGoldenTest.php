<?php

/**
 * Golden test for the triggers the native flow trigger listener fires.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Listener\NativeFlowTriggerListener;
use OCA\OpenRegister\Service\Flow\FlowTriggerService;
use OCP\EventDispatcher\Event;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\Node;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Share\Events\ShareCreatedEvent;
use OCP\Share\Events\ShareDeletedEvent;
use OCP\Share\IShare;
use OCP\User\Events\UserCreatedEvent;
use OCP\User\Events\UserDeletedEvent;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Records every fire() call for each kind of event, with readable and unreadable
 * fields, with and without a signed-in user, against a recorded golden.
 *
 * @covers \OCA\OpenRegister\Listener\NativeFlowTriggerListener
 */
class NativeFlowTriggerListenerGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/Golden/native-flow-trigger-listener.json';

	/**
	 * Whether a readable node still fails on getPath().
	 *
	 * @var bool
	 */
	private bool $pathFails = false;

	/**
	 * A node whose accessors answer, or throw when $broken.
	 *
	 * @param bool $broken Whether every accessor throws.
	 *
	 * @return Node
	 */
	private function node(bool $broken): Node {
		$node = $this->createMock(Node::class);
		$values = ['getId' => 5, 'getPath' => '/u/files/a.txt', 'getName' => 'a.txt', 'getMimetype' => 'text/plain'];
		foreach ($values as $method => $value) {
			$fails = $broken || ($method === 'getPath' && $this->pathFails);
			$fails ? $node->method($method)->willThrowException(new RuntimeException('gone')) : $node->method($method)->willReturn($value);
		}

		return $node;
	}

	/**
	 * A share whose node answers, or throws when $broken.
	 *
	 * @param bool $broken Whether the node accessors throw.
	 *
	 * @return IShare
	 */
	private function share(bool $broken): IShare {
		$share = $this->createMock(IShare::class);
		$share->method('getId')->willReturn('7');
		$share->method('getNodeId')->willReturn(99);
		$share->method('getShareType')->willReturn(3);
		$share->method('getSharedWith')->willReturn('bob');
		$broken ? $share->method('getNode')->willThrowException(new RuntimeException('gone')) : $share->method('getNode')->willReturn($this->node(broken: false));
		return $share;
	}

	/**
	 * Every event to replay.
	 *
	 * @return array<string, Event>
	 */
	private function events(): array {
		$events = [];
		foreach ([NodeCreatedEvent::class, NodeWrittenEvent::class, NodeDeletedEvent::class] as $class) {
			foreach ([false, true] as $broken) {
				$event = $this->createMock($class);
				$event->method('getNode')->willReturn($this->node(broken: $broken));
				$events[$class . ($broken ? ' broken' : '')] = $event;
			}
		}

		foreach ([ShareCreatedEvent::class, ShareDeletedEvent::class] as $class) {
			foreach ([false, true] as $broken) {
				$event = $this->createMock($class);
				$event->method('getShare')->willReturn($this->share(broken: $broken));
				$events[$class . ($broken ? ' broken' : '')] = $event;
			}
		}

		$created = $this->createMock(UserCreatedEvent::class);
		$created->method('getUid')->willReturn('carol');
		$events[UserCreatedEvent::class] = $created;
		$gone = $this->createMock(IUser::class);
		$gone->method('getUID')->willReturn('dave');
		$deleted = $this->createMock(UserDeletedEvent::class);
		$deleted->method('getUser')->willReturn($gone);
		$events[UserDeletedEvent::class] = $deleted;
		$this->pathFails = true;
		$partly = $this->createMock(NodeWrittenEvent::class);
		$partly->method('getNode')->willReturn($this->node(broken: false));
		$events['path unreadable'] = $partly;
		$this->pathFails = false;
		$events['plain'] = new Event();
		return $events;
	}

	/**
	 * The listener fires as recorded for every event, signed in and not.
	 *
	 * @return void
	 */
	public function testTriggersMatchTheGolden(): void {
		$actual = [];
		foreach (['anonymous' => null, 'signed in' => 'erin'] as $who => $uid) {
			foreach ($this->events() as $name => $event) {
				$fired = [];
				$triggers = $this->createMock(FlowTriggerService::class);
				$triggers->method('fire')->willReturnCallback(function (...$args) use (&$fired) {
					$fired[] = $args;
					return 0;
				});
				$session = $this->createMock(IUserSession::class);
				if ($uid !== null) {
					$user = $this->createMock(IUser::class);
					$user->method('getUID')->willReturn($uid);
					$session->method('getUser')->willReturn($user);
				}

				(new NativeFlowTriggerListener($triggers, $session))->handle($event);
				$actual[$who][$name] = $fired;
			}
		}

		$json = json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, $json);
		}

		$this->assertSame(file_get_contents(self::GOLDEN), $json);
	}
}
