<?php

/**
 * The Files and WebDAV door of the file freeze (REQ-OAS-007).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md#requirement-file-writes-honour-the-frozen-and-archived-marker-req-oas-007
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Listener\FrozenNodeWriteListener;
use OCA\OpenRegister\Service\Object\FileWriteGuard;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\Exception as DbException;
use OCP\Exceptions\AbortedEventException;
use OCP\Files\Events\Node\BeforeNodeCreatedEvent;
use OCP\Files\Events\Node\BeforeNodeDeletedEvent;
use OCP\Files\Events\Node\BeforeNodeRenamedEvent;
use OCP\Files\Events\Node\BeforeNodeWrittenEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\HintException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Real Nextcloud event classes, a node tree under the openregister home.
 */
class FrozenNodeWriteListenerTest extends TestCase {

	private const UUID = 'c0ffee00-0000-4000-8000-00000000d5e7';

	private const FOLDER_ID = 4711;

	private MagicMapper&MockObject $objects;

	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->objects = $this->createMock(MagicMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	private function listener(): FrozenNodeWriteListener {
		return new FrozenNodeWriteListener(guard: new FileWriteGuard(), objects: $this->objects, logger: $this->logger);
	}//end listener()

	/**
	 * A folder node with a name, an id and a parent.
	 */
	private function folder(string $name, int $id, string $path, ?Folder $parent): Folder&MockObject {
		$folder = $this->createMock(Folder::class);
		$folder->method('getName')->willReturn($name);
		$folder->method('getId')->willReturn($id);
		$folder->method('getPath')->willReturn($path);
		if ($parent !== null) {
			$folder->method('getParent')->willReturn($parent);
		}

		return $folder;
	}//end folder()

	/**
	 * `/openregister/files/Woo Register/<uuid>/levering-manifest.pdf`.
	 */
	private function fileInObjectFolder(string $home = '/openregister/files'): File&MockObject {
		$files = $this->folder('files', 2, $home, null);
		$register = $this->folder('Woo Register', 3, $home . '/Woo Register', $files);
		$objectFolder = $this->folder(self::UUID, self::FOLDER_ID, $home . '/Woo Register/' . self::UUID, $register);

		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('levering-manifest.pdf');
		$file->method('getId')->willReturn(9001);
		$file->method('getPath')->willReturn($home . '/Woo Register/' . self::UUID . '/levering-manifest.pdf');
		$file->method('getParent')->willReturn($objectFolder);

		return $file;
	}//end fileInObjectFolder()

	private function frozenObject(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid(self::UUID);
		$object->setFolder((string)self::FOLDER_ID);
		$object->setFrozen(['by' => 'dossiq', 'at' => '2026-10-10T09:00:00+00:00', 'reason' => 'geleverd', 'state' => null]);

		return $object;
	}//end frozenObject()

	public function testAWriteIntoAFrozenObjectFolderIsAborted(): void {
		$this->objects->method('findAcrossAllSources')->willReturn(['object' => $this->frozenObject(), 'register' => null, 'schema' => null]);

		try {
			$this->listener()->handle(new BeforeNodeWrittenEvent($this->fileInObjectFolder()));
			$this->fail('A write into a frozen object folder went through');
		} catch (HintException $e) {
			$this->assertStringContainsString('frozen by dossiq', $e->getMessage());
			$this->assertSame(409, $e->getCode());
		}
	}//end testAWriteIntoAFrozenObjectFolderIsAborted()

	public function testACreateInAFrozenObjectFolderIsAborted(): void {
		$this->objects->method('findAcrossAllSources')->willReturn(['object' => $this->frozenObject()]);

		$this->expectException(HintException::class);
		$this->listener()->handle(new BeforeNodeCreatedEvent($this->fileInObjectFolder()));
	}//end testACreateInAFrozenObjectFolderIsAborted()

	public function testADeleteAndARenameAreAbortedThroughAbortOperation(): void {
		$this->objects->method('findAcrossAllSources')->willReturn(['object' => $this->frozenObject()]);

		try {
			$this->listener()->handle(new BeforeNodeDeletedEvent($this->fileInObjectFolder()));
			$this->fail('A delete in a frozen object folder went through');
		} catch (AbortedEventException $e) {
			$this->assertStringContainsString('frozen by dossiq', $e->getMessage());
		}

		// Moving a file OUT of the frozen folder is a rename whose source is in it.
		$outside = $this->createMock(File::class);
		$outside->method('getPath')->willReturn('/alice/files/elsewhere.pdf');

		$this->expectException(AbortedEventException::class);
		$this->listener()->handle(new BeforeNodeRenamedEvent($this->fileInObjectFolder(), $outside));
	}//end testADeleteAndARenameAreAbortedThroughAbortOperation()

	public function testAnUnfrozenObjectFolderAcceptsTheWrite(): void {
		$object = $this->frozenObject();
		$object->setFrozen(null);
		$this->objects->method('findAcrossAllSources')->willReturn(['object' => $object]);

		$this->listener()->handle(new BeforeNodeWrittenEvent($this->fileInObjectFolder()));
		$this->addToAssertionCount(1);
	}//end testAnUnfrozenObjectFolderAcceptsTheWrite()

	public function testAnUnresolvableOwnerInTheRegisterTreeIsRefused(): void {
		$this->objects->method('findAcrossAllSources')->willThrowException(new DbException('connection lost'));
		$this->logger->expects($this->once())->method('warning')->with(
			$this->stringContains('could not be resolved'),
			$this->callback(static fn (array $context): bool => $context['node'] === 9001)
		);

		$this->expectException(HintException::class);
		$this->listener()->handle(new BeforeNodeWrittenEvent($this->fileInObjectFolder()));
	}//end testAnUnresolvableOwnerInTheRegisterTreeIsRefused()

	public function testAFolderOfAnObjectStillBeingCreatedIsNotRefused(): void {
		$this->objects->method('findAcrossAllSources')->willThrowException(new DoesNotExistException('none'));

		$this->listener()->handle(new BeforeNodeCreatedEvent($this->fileInObjectFolder()));
		$this->addToAssertionCount(1);
	}//end testAFolderOfAnObjectStillBeingCreatedIsNotRefused()

	public function testAUuidFolderWhoseObjectPointsElsewhereIsNotTheOwner(): void {
		$object = $this->frozenObject();
		$object->setFolder('1234');
		$this->objects->method('findAcrossAllSources')->willReturn(['object' => $object]);

		$this->listener()->handle(new BeforeNodeWrittenEvent($this->fileInObjectFolder()));
		$this->addToAssertionCount(1);
	}//end testAUuidFolderWhoseObjectPointsElsewhereIsNotTheOwner()

	public function testANodeOutsideTheRegisterTreeIsIgnored(): void {
		$this->objects->expects($this->never())->method('findAcrossAllSources');

		$this->listener()->handle(new BeforeNodeWrittenEvent($this->fileInObjectFolder('/alice/files')));
		$this->addToAssertionCount(1);
	}//end testANodeOutsideTheRegisterTreeIsIgnored()

	public function testAnOtherEventIsIgnored(): void {
		$this->objects->expects($this->never())->method('findAcrossAllSources');

		$this->listener()->handle(new \OCP\Files\Events\Node\BeforeNodeReadEvent($this->createMock(Node::class)));
		$this->addToAssertionCount(1);
	}//end testAnOtherEventIsIgnored()
}//end class
