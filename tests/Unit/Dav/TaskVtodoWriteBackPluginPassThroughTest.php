<?php

/**
 * TaskVtodoWriteBackPlugin pass-through and actor resolution tests.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Dav
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Dav;

use OCA\OpenRegister\Dav\TaskVtodoWriteBackPlugin;
use OCA\OpenRegister\Service\Task\TaskVtodoWriteBackGateInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\ICollection;
use Sabre\DAV\INode;
use Sabre\DAV\Server;
use Sabre\DAV\Tree;

/**
 * Writes that are not projected tasks pass untouched, deletes of plain entries
 * pass, and the acting uid is only taken from a users principal.
 */
class TaskVtodoWriteBackPluginPassThroughTest extends TestCase {

	/**
	 * A projected task document.
	 */
	private const PROJECTED = "BEGIN:VCALENDAR\r\nBEGIN:VTODO\r\nX-OPENREGISTER-TASK:t-1\r\nEND:VTODO\r\nEND:VCALENDAR\r\n";

	/**
	 * A plain calendar document.
	 */
	private const PLAIN = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nSUMMARY:Lunch\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

	/**
	 * The gate double.
	 *
	 * @var TaskVtodoWriteBackGateInterface&MockObject
	 */
	private TaskVtodoWriteBackGateInterface&MockObject $gate;

	/**
	 * Build the gate double; it recognises the X-OPENREGISTER-TASK marker.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->gate = $this->createMock(TaskVtodoWriteBackGateInterface::class);
		$this->gate->method('isProjected')->willReturnCallback(
			static fn (string $ics): bool => str_contains($ics, 'X-OPENREGISTER-TASK:')
		);
	}//end setUp()

	/**
	 * A plugin initialised on a server with the given auth plugin and tree.
	 *
	 * @param object|null $auth The auth plugin, or null.
	 * @param Tree|null   $tree The DAV tree, or null.
	 *
	 * @return TaskVtodoWriteBackPlugin
	 */
	private function plugin(?object $auth, ?Tree $tree=null): TaskVtodoWriteBackPlugin {
		$server = $this->createMock(Server::class);
		$server->method('getPlugin')->willReturnCallback(static fn (string $name): ?object => ($name === 'auth') ? $auth : null);
		if ($tree !== null) {
			$server->tree = $tree;
		}

		$plugin = new TaskVtodoWriteBackPlugin($this->gate, $this->createMock(LoggerInterface::class));
		$plugin->initialize($server);
		return $plugin;
	}//end plugin()

	/**
	 * An auth plugin answering a fixed principal.
	 *
	 * @param string $principal The principal.
	 *
	 * @return object
	 */
	private function auth(string $principal): object {
		return new class($principal) {
			/**
			 * Constructor.
			 *
			 * @param string $principal The principal.
			 */
			public function __construct(private string $principal) {
			}//end __construct()

			/**
			 * The current principal.
			 *
			 * @return string
			 */
			public function getCurrentPrincipal(): string {
				return $this->principal;
			}//end getCurrentPrincipal()
		};
	}//end auth()

	/**
	 * Send a projected write and answer the actor the gate was handed.
	 *
	 * @param TaskVtodoWriteBackPlugin $plugin The plugin.
	 *
	 * @return string|null The actor.
	 */
	private function actorSeenBy(TaskVtodoWriteBackPlugin $plugin): ?string {
		$seen = 'unset';
		$this->gate->method('handleWrite')->willReturnCallback(
			static function (string $calendarData, ?string $actor) use (&$seen): ?string {
				$seen = $actor;
				return null;
			}
		);
		$data     = self::PROJECTED;
		$modified = false;
		$plugin->beforeWriteContent('calendars/jan/personal/t.ics', $this->createMock(INode::class), $data, $modified);
		$this->assertFalse($modified);
		$this->assertSame(self::PROJECTED, $data);
		return $seen;
	}//end actorSeenBy()

	/**
	 * The plugin names itself.
	 *
	 * @return void
	 */
	public function testPluginName(): void {
		$this->assertSame('openregister-task-write-back', (new TaskVtodoWriteBackPlugin($this->gate, $this->createMock(LoggerInterface::class)))->getPluginName());
	}//end testPluginName()

	/**
	 * A users principal yields its uid.
	 *
	 * @return void
	 */
	public function testAUsersPrincipalYieldsTheUid(): void {
		$this->assertSame('jan', $this->actorSeenBy($this->plugin($this->auth('principals/users/jan'))));
	}//end testAUsersPrincipalYieldsTheUid()

	/**
	 * Every principal that is not a named user yields no actor.
	 *
	 * @return void
	 */
	public function testNonUserPrincipalsYieldNoActor(): void {
		$throwing = new class {
			/**
			 * The current principal.
			 *
			 * @return string
			 */
			public function getCurrentPrincipal(): string {
				throw new RuntimeException('no session');
			}//end getCurrentPrincipal()
		};

		$this->assertNull($this->actorSeenBy($this->plugin($this->auth('principals/system/system'))));
		$this->assertNull($this->actorSeenBy($this->plugin($this->auth('principals/users/'))));
		$this->assertNull($this->actorSeenBy($this->plugin($throwing)));
		$this->assertNull($this->actorSeenBy($this->plugin(new \stdClass())));
		$this->assertNull($this->actorSeenBy($this->plugin(null)));
	}//end testNonUserPrincipalsYieldNoActor()

	/**
	 * A plugin that was never initialised on a server has no actor.
	 *
	 * @return void
	 */
	public function testAnUninitialisedPluginHasNoActor(): void {
		$this->assertNull($this->actorSeenBy(new TaskVtodoWriteBackPlugin($this->gate, $this->createMock(LoggerInterface::class))));
	}//end testAnUninitialisedPluginHasNoActor()

	/**
	 * Writes outside calendars, plain entries and unreadable bodies never reach the gate.
	 *
	 * @return void
	 */
	public function testNonTaskWritesPassUntouched(): void {
		$this->gate->expects($this->never())->method('handleWrite');
		$plugin = $this->plugin($this->auth('principals/users/jan'));
		$node   = $this->createMock(INode::class);

		foreach ([['files/jan/t.ics', self::PROJECTED], ['calendars/jan/personal/e.ics', self::PLAIN], ['calendars/jan/personal/e.ics', 42]] as [$path, $body]) {
			$data     = $body;
			$modified = false;
			$plugin->beforeWriteContent($path, $node, $data, $modified);
			$this->assertSame($body, $data);
			$this->assertFalse($modified);
		}
	}//end testNonTaskWritesPassUntouched()

	/**
	 * Creating a plain entry, or anything outside calendars, is allowed.
	 *
	 * @return void
	 */
	public function testCreatingAPlainEntryIsAllowed(): void {
		$plugin = $this->plugin($this->auth('principals/users/jan'));
		$parent = $this->createMock(ICollection::class);
		$stream = fopen('php://memory', 'r+');
		fwrite($stream, self::PLAIN);
		rewind($stream);

		foreach ([['calendars/jan/personal/e.ics', $stream], ['files/jan/t.ics', self::PROJECTED], ['calendars/jan/personal/e.ics', null]] as [$path, $body]) {
			$data     = $body;
			$modified = false;
			$plugin->beforeCreateFile($path, $data, $parent, $modified);
			$this->assertFalse($modified);
		}

		$this->assertSame(self::PLAIN, stream_get_contents($stream), 'the stream is rewound for Sabre to read');
	}//end testCreatingAPlainEntryIsAllowed()

	/**
	 * Creating a projected task from a stream body is refused.
	 *
	 * @return void
	 */
	public function testCreatingAProjectedTaskFromAStreamIsRefused(): void {
		$stream = fopen('php://memory', 'r+');
		fwrite($stream, self::PROJECTED);
		rewind($stream);
		$modified = false;

		$this->expectException(Forbidden::class);
		$this->plugin($this->auth('principals/users/jan'))
			->beforeCreateFile('calendars/jan/personal/t.ics', $stream, $this->createMock(ICollection::class), $modified);
	}//end testCreatingAProjectedTaskFromAStreamIsRefused()

	/**
	 * Deleting outside calendars, without a server, an unresolvable node, a node
	 * without a body or a plain entry is allowed.
	 *
	 * @return void
	 */
	public function testDeletingAnythingButATaskIsAllowed(): void {
		$plain = new class(self::PLAIN) {
			/**
			 * Constructor.
			 *
			 * @param string $body The body.
			 */
			public function __construct(private string $body) {
			}//end __construct()

			/**
			 * The body.
			 *
			 * @return string
			 */
			public function get(): string {
				return $this->body;
			}//end get()
		};
		$tree = $this->createMock(Tree::class);
		$tree->method('getNodeForPath')->willReturnCallback(
			static fn (string $path): object => match ($path) {
				'calendars/jan/personal/plain.ics' => $plain,
				'calendars/jan/personal/bare.ics' => new \stdClass(),
				default => throw new RuntimeException('not found'),
			}
		);
		$this->gate->expects($this->once())->method('isProjected')->with(self::PLAIN)->willReturn(false);
		$plugin = $this->plugin(null, $tree);

		$plugin->beforeUnbind('files/jan/plain.ics');
		(new TaskVtodoWriteBackPlugin($this->gate, $this->createMock(LoggerInterface::class)))->beforeUnbind('calendars/jan/personal/plain.ics');
		$plugin->beforeUnbind('calendars/jan/personal/missing.ics');
		$plugin->beforeUnbind('calendars/jan/personal/bare.ics');
		$plugin->beforeUnbind('calendars/jan/personal/plain.ics');
	}//end testDeletingAnythingButATaskIsAllowed()

	/**
	 * Deleting a projected task entry is refused.
	 *
	 * @return void
	 */
	public function testDeletingATaskIsRefused(): void {
		$task = new class(self::PROJECTED) {
			/**
			 * Constructor.
			 *
			 * @param string $body The body.
			 */
			public function __construct(private string $body) {
			}//end __construct()

			/**
			 * The body.
			 *
			 * @return string
			 */
			public function get(): string {
				return $this->body;
			}//end get()
		};
		$tree = $this->createMock(Tree::class);
		$tree->method('getNodeForPath')->willReturn($task);

		$this->expectException(Forbidden::class);
		$this->expectExceptionMessage('Complete or cancel the task');
		$this->plugin(null, $tree)->beforeUnbind('/calendars/jan/personal/t.ics');
	}//end testDeletingATaskIsRefused()
}//end class
