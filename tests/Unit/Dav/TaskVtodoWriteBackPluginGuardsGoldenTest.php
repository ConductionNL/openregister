<?php

/**
 * Characterisation of two TaskVtodoWriteBackPlugin guards: the acting user it reads from the
 * DAV auth plugin (or none), and the delete guard that refuses removing a projected task's
 * calendar entry while letting every other delete through.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Dav
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Dav;

use OCA\OpenRegister\Dav\TaskVtodoWriteBackPlugin;
use OCA\OpenRegister\Service\Task\TaskVtodoWriteBackGate;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;
use RuntimeException;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\IFile;
use Sabre\DAV\INode;
use Sabre\DAV\Server;
use Sabre\DAV\Tree;

/**
 * Golden actor and delete guard of the VTODO write-back plugin.
 *
 * @covers \OCA\OpenRegister\Dav\TaskVtodoWriteBackPlugin
 */
final class TaskVtodoWriteBackPluginGuardsGoldenTest extends TestCase {

	/**
	 * A plugin whose server answers this auth plugin.
	 *
	 * @param object|null    $auth The auth plugin, or null for none.
	 * @param Tree|null      $tree The DAV tree.
	 * @param bool           $init Whether the plugin is initialised with a server.
	 *
	 * @return TaskVtodoWriteBackPlugin
	 */
	private function plugin(?object $auth, ?Tree $tree=null, bool $init=true): TaskVtodoWriteBackPlugin {
		$gate = $this->createMock(TaskVtodoWriteBackGate::class);
		$gate->method('isProjected')->willReturnCallback(static fn (string $ics): bool => str_contains($ics, 'X-OPENREGISTER-TASK:'));
		$plugin = new TaskVtodoWriteBackPlugin($gate, new NullLogger());
		if ($init === false) {
			return $plugin;
		}

		$server = $this->createMock(Server::class);
		$server->method('getPlugin')->willReturnCallback(static fn (string $name): ?object => $name === 'auth' ? $auth : null);
		if ($tree !== null) {
			$server->tree = $tree;
		}

		$plugin->initialize($server);

		return $plugin;
	}//end plugin()

	/**
	 * An auth plugin answering a principal, or throwing.
	 *
	 * @param string|null $principal The principal, or null to throw.
	 *
	 * @return object
	 */
	private static function auth(?string $principal): object {
		return new class($principal) {
			public function __construct(private ?string $principal) {
			}

			public function getCurrentPrincipal(): string {
				if ($this->principal === null) {
					throw new RuntimeException('no session');
				}

				return $this->principal;
			}
		};
	}//end auth()

	/**
	 * The acting user the plugin reads.
	 *
	 * @param TaskVtodoWriteBackPlugin $plugin The plugin.
	 *
	 * @return string|null
	 */
	private function actorOf(TaskVtodoWriteBackPlugin $plugin): ?string {
		return (new ReflectionMethod(TaskVtodoWriteBackPlugin::class, 'actor'))->invoke($plugin);
	}//end actorOf()

	/**
	 * Only a users principal with a uid names an actor.
	 *
	 * @return void
	 */
	public function testActor(): void {
		$this->assertSame('anna', $this->actorOf($this->plugin(auth: self::auth('principals/users/anna'))));
		$this->assertSame('a/b', $this->actorOf($this->plugin(auth: self::auth('principals/users/a/b'))));
		$this->assertNull($this->actorOf($this->plugin(auth: self::auth('principals/users/'))));
		$this->assertNull($this->actorOf($this->plugin(auth: self::auth('principals/groups/admin'))));
		$this->assertNull($this->actorOf($this->plugin(auth: self::auth(null))));
		$this->assertNull($this->actorOf($this->plugin(auth: new class {
		})));
		$this->assertNull($this->actorOf($this->plugin(auth: null)));
		$this->assertNull($this->actorOf($this->plugin(auth: null, init: false)));
	}//end testActor()

	/**
	 * A tree that answers one node, or throws.
	 *
	 * @param INode|null $node The node, or null to throw.
	 *
	 * @return Tree
	 */
	private function tree(?INode $node): Tree {
		$tree = $this->createMock(Tree::class);
		if ($node === null) {
			$tree->method('getNodeForPath')->willThrowException(new RuntimeException('gone'));
			return $tree;
		}

		$tree->method('getNodeForPath')->willReturn($node);
		return $tree;
	}//end tree()

	/**
	 * A file node with a body.
	 *
	 * @param mixed $body The body.
	 *
	 * @return IFile
	 */
	private function file(mixed $body): IFile {
		$file = $this->createMock(IFile::class);
		$file->method('get')->willReturn($body);
		return $file;
	}//end file()

	/**
	 * Deleting a projected task's calendar entry is refused with the reason.
	 *
	 * @return void
	 */
	public function testDeletingAProjectedEntryIsRefused(): void {
		$plugin = $this->plugin(auth: null, tree: $this->tree(node: $this->file(body: "BEGIN:VTODO\r\nX-OPENREGISTER-TASK:t-1\r\nEND:VTODO")));

		try {
			$plugin->beforeUnbind('calendars/anna/personal/x.ics');
			$this->fail('The delete was let through.');
		} catch (Forbidden $e) {
			$this->assertSame('This entry is an OpenRegister task. Complete or cancel the task instead of deleting its calendar entry.', $e->getMessage());
		}
	}//end testDeletingAProjectedEntryIsRefused()

	/**
	 * Every other delete is let through.
	 *
	 * @return void
	 */
	public function testOtherDeletesAreAllowed(): void {
		$ordinary = "BEGIN:VTODO\r\nSUMMARY:x\r\nEND:VTODO";
		$this->plugin(auth: null, tree: $this->tree(node: $this->file(body: $ordinary)))->beforeUnbind('calendars/anna/personal/x.ics');
		$this->plugin(auth: null, tree: $this->tree(node: $this->file(body: "X-OPENREGISTER-TASK:t")))->beforeUnbind('files/anna/x.ics');
		$this->plugin(auth: null, tree: $this->tree(node: null))->beforeUnbind('calendars/anna/personal/x.ics');
		$this->plugin(auth: null, tree: $this->tree(node: $this->createMock(INode::class)))->beforeUnbind('calendars/anna/personal/x.ics');
		$this->plugin(auth: null, tree: $this->tree(node: $this->file(body: null)))->beforeUnbind('calendars/anna/personal/x.ics');
		$this->plugin(auth: null, init: false)->beforeUnbind('calendars/anna/personal/x.ics');

		$this->addToAssertionCount(1);
	}//end testOtherDeletesAreAllowed()
}//end class
