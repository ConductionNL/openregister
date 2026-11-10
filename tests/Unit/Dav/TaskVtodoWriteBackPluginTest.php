<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The in-band hook: the plugin subscribes to the three write hooks, hands a
 * projected VTODO to the same gate the event listener uses, replaces the
 * body with the engine's rendering when the gate accepts, and turns every
 * refusal into a DAV 403 so the client never records the change.
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-an-unauthorized-write-back-is-undone-and-explained
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-ticking-off-the-vtodo-completes-the-engine-task-through-authorization
 * @spec openspec/changes/flow-task-inbox-projections/specs/object-interactions/spec.md#requirement-tasks-on-objects-via-caldav-vtodo
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Dav;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Dav\TaskVtodoWriteBackPlugin;
use OCA\OpenRegister\Exception\TaskAccessDeniedException;
use OCA\OpenRegister\Exception\TaskConflictException;
use OCA\OpenRegister\Service\Task\TaskVtodoWriteBackGate;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\ICollection;
use Sabre\DAV\IFile;
use Sabre\DAV\INode;
use Sabre\DAV\Server;
use Sabre\DAV\Tree;

class TaskVtodoWriteBackPluginTest extends TestCase {
	private const UUID = '00000000-0000-0000-0000-000000000002';

	private TaskVtodoWriteBackGate&MockObject $gate;

	/**
	 * The Sabre server, mocked: the real class needs a tree and an HTTP
	 * sapi, and the suite runs both against the test stubs and, in the CI
	 * container, against the server's real Sabre.
	 */
	private Server&MockObject $server;

	/** @var array<string, array{0: callable, 1: int}> Hooks the plugin subscribed. */
	private array $subscriptions = [];

	protected function setUp(): void {
		parent::setUp();
		$this->gate = $this->createMock(TaskVtodoWriteBackGate::class);
		$this->gate->method('isProjected')->willReturnCallback(
			static fn (string $ics): bool => str_contains($ics, 'X-OPENREGISTER-TASK:')
		);
		$this->subscriptions = [];
		$this->server = $this->createMock(Server::class);
		$this->server->method('on')->willReturnCallback(
			function (string $event, callable $callback, int $priority = 100): void {
				$this->subscriptions[$event] = [$callback, $priority];
			}
		);
	}

	private function plugin(?string $principal = 'principals/users/EXAMPLE_APPROVER_USER'): TaskVtodoWriteBackPlugin {
		$plugin = new TaskVtodoWriteBackPlugin($this->gate, new NullLogger());
		$auth = null;
		if ($principal !== null) {
			$auth = new class($principal) {
				public function __construct(private string $principal) {
				}

				public function getCurrentPrincipal(): string {
					return $this->principal;
				}
			};
		}

		$this->server->method('getPlugin')->willReturnCallback(
			static fn (string $name): ?object => $name === 'auth' ? $auth : null
		);

		$plugin->initialize($this->server);

		return $plugin;
	}

	private function projected(string $status): string {
		return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VTODO\r\nUID:" . self::UUID . "\r\nSUMMARY:Approve\r\nSTATUS:{$status}\r\n"
			. 'X-OPENREGISTER-TASK:' . self::UUID . "\r\nEND:VTODO\r\nEND:VCALENDAR\r\n";
	}

	public function testItSubscribesToTheThreeWriteHooks(): void {
		$this->plugin();

		$this->assertArrayHasKey('beforeWriteContent', $this->subscriptions);
		$this->assertArrayHasKey('beforeCreateFile', $this->subscriptions);
		$this->assertArrayHasKey('beforeUnbind', $this->subscriptions);
		$this->assertSame(90, $this->subscriptions['beforeWriteContent'][1]);
	}

	public function testAnAcceptedTickStoresTheEnginesRendering(): void {
		$this->gate->expects($this->once())->method('handleWrite')
			->with($this->projected('COMPLETED'), 'EXAMPLE_APPROVER_USER')
			->willReturn('RENDERED-BY-ENGINE');
		$data = $this->projected('COMPLETED');
		$modified = false;

		$this->plugin()->beforeWriteContent('calendars/approver/personal/x.ics', $this->createMock(INode::class), $data, $modified);

		$this->assertSame('RENDERED-BY-ENGINE', $data);
		$this->assertTrue($modified);
	}

	public function testARefusalIsADavForbiddenNamingTheReason(): void {
		$this->gate->method('handleWrite')->willThrowException(new TaskAccessDeniedException("Verb 'complete' denied: 'stranger' is not the assignee."));
		$data = $this->projected('COMPLETED');
		$modified = false;

		try {
			$this->plugin('principals/users/stranger')->beforeWriteContent('calendars/approver/personal/x.ics', $this->createMock(INode::class), $data, $modified);
			$this->fail('expected a Forbidden');
		} catch (Forbidden $forbidden) {
			$this->assertStringContainsString('not the assignee', $forbidden->getMessage());
		}

		$this->assertFalse($modified, 'a refused write replaces nothing');
	}

	public function testAConflictAndAnUnknownTaskAreForbiddenToo(): void {
		$data = $this->projected('NEEDS-ACTION');
		$modified = false;
		$this->gate->method('handleWrite')->willReturnOnConsecutiveCalls(
			$this->throwException(new TaskConflictException('terminal')),
			$this->throwException(new DoesNotExistException('gone')),
			$this->throwException(new \RuntimeException('database down'))
		);
		$plugin = $this->plugin();

		foreach (['terminal', 'does not exist', 'could not verify'] as $fragment) {
			try {
				$plugin->beforeWriteContent('calendars/a/p/x.ics', $this->createMock(INode::class), $data, $modified);
				$this->fail('expected a Forbidden');
			} catch (Forbidden $forbidden) {
				$this->assertStringContainsString($fragment, $forbidden->getMessage());
			}
		}
	}

	public function testAStreamBodyIsReadAndAStandaloneVtodoPassesUntouched(): void {
		$standalone = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VTODO\r\nUID:x\r\nSUMMARY:Buy milk\r\nSTATUS:COMPLETED\r\nEND:VTODO\r\nEND:VCALENDAR\r\n";
		$stream = fopen('php://memory', 'r+');
		fwrite($stream, $standalone);
		rewind($stream);
		$this->gate->expects($this->never())->method('handleWrite');
		$modified = false;

		$this->plugin()->beforeWriteContent('calendars/a/p/x.ics', $this->createMock(INode::class), $stream, $modified);

		$this->assertIsResource($stream);
		$this->assertFalse($modified);
		$this->assertSame($standalone, stream_get_contents($stream), 'the stream is rewound for the next handler');
	}

	public function testANonCalendarPathIsNotInspected(): void {
		$this->gate->expects($this->never())->method('isProjected');
		$data = $this->projected('COMPLETED');
		$modified = false;

		$this->plugin()->beforeWriteContent('files/admin/notes.ics', $this->createMock(INode::class), $data, $modified);
	}

	public function testCreatingAVtodoThatForgesATaskIdentityIsForbidden(): void {
		$data = $this->projected('NEEDS-ACTION');
		$modified = false;

		$this->expectException(Forbidden::class);
		$this->expectExceptionMessage('cannot create an engine task');
		$this->plugin()->beforeCreateFile('calendars/stranger/personal/forged.ics', $data, $this->createMock(ICollection::class), $modified);
	}

	public function testCreatingAnOrdinaryVtodoIsAllowed(): void {
		$data = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VTODO\r\nUID:x\r\nSUMMARY:Buy milk\r\nEND:VTODO\r\nEND:VCALENDAR\r\n";
		$modified = false;

		$this->plugin()->beforeCreateFile('calendars/a/personal/new.ics', $data, $this->createMock(ICollection::class), $modified);
		$this->assertFalse($modified);
	}

	public function testDeletingAProjectedVtodoIsRefusedWithTheReason(): void {
		$node = $this->createMock(IFile::class);
		$node->method('get')->willReturn($this->projected('IN-PROCESS'));
		$tree = $this->createMock(Tree::class);
		$tree->method('getNodeForPath')->with('calendars/approver/personal/x.ics')->willReturn($node);
		$this->server->tree = $tree;

		$this->expectException(Forbidden::class);
		$this->expectExceptionMessage('Complete or cancel the task');
		$this->plugin()->beforeUnbind('calendars/approver/personal/x.ics');
	}

	public function testWithoutAnAuthPluginTheActorIsNullAndTheGateDecides(): void {
		$this->gate->expects($this->once())->method('handleWrite')->with($this->anything(), null)
			->willThrowException(new TaskAccessDeniedException('no acting identity'));
		$data = $this->projected('COMPLETED');
		$modified = false;

		$this->expectException(Forbidden::class);
		$this->plugin(null)->beforeWriteContent('calendars/a/p/x.ics', $this->createMock(INode::class), $data, $modified);
	}
}
