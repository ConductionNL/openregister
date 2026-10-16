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
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\HintException;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Real Nextcloud event classes, a node tree under the openregister home.
 */
class FrozenNodeWriteListenerTest extends TestCase {

	private const UUID = 'c0ffee00-0000-4000-8000-00000000d5e7';

	private const FOLDER_ID = 4711;

	private MagicMapper&MockObject $objects;

	private LoggerInterface&MockObject $logger;

	private IRootFolder&MockObject $rootFolder;

	private ContainerInterface&MockObject $container;

	/**
	 * How many times the listener asked the container for the mapper.
	 *
	 * @var integer
	 */
	private int $mapperRequests = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->objects = $this->createMock(MagicMapper::class);
		$this->mapperRequests = 0;
		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('get')->willReturnCallback(
			function (string $id): MagicMapper {
				$this->assertSame(MagicMapper::class, $id);
				$this->mapperRequests++;

				return $this->objects;
			}
		);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
	}//end setUp()

	private function listener(): FrozenNodeWriteListener {
		return new FrozenNodeWriteListener(guard: new FileWriteGuard(), container: $this->container, logger: $this->logger, rootFolder: $this->rootFolder);
	}//end listener()

	/**
	 * The app account's home answers `getById` with the given twins.
	 *
	 * @param array<int, Node> $twins The nodes in the home that carry the id.
	 */
	private function homeHolds(array $twins): void {
		$home = $this->createMock(Folder::class);
		$home->method('getById')->willReturn($twins);
		$this->rootFolder->method('getUserFolder')->with('openregister')->willReturn($home);
	}//end homeHolds()

	private function owner(string $uid): IUser&MockObject {
		$owner = $this->createMock(IUser::class);
		$owner->method('getUID')->willReturn($uid);

		return $owner;
	}//end owner()

	/**
	 * The file as the sharee alice sees it: `/alice/files/<share>/levering-manifest.pdf`, owned by the app account.
	 */
	private function fileThroughAShare(string $ownerUid = 'openregister'): File&MockObject {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('levering-manifest.pdf');
		$file->method('getId')->willReturn(9001);
		$file->method('getPath')->willReturn('/alice/files/Dossier/levering-manifest.pdf');
		$file->method('getOwner')->willReturn($this->owner($ownerUid));

		return $file;
	}//end fileThroughAShare()

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

	public function testAWriteThroughAShareeMountIsAborted(): void {
		$this->objects->method('findAcrossAllSources')->willReturn(['object' => $this->frozenObject()]);
		$this->homeHolds([$this->fileInObjectFolder()]);

		try {
			$this->listener()->handle(new BeforeNodeWrittenEvent($this->fileThroughAShare()));
			$this->fail('A write through a share into a frozen object went through');
		} catch (HintException $e) {
			$this->assertStringContainsString('frozen by dossiq', $e->getMessage());
		}

		try {
			$this->listener()->handle(new BeforeNodeDeletedEvent($this->fileThroughAShare()));
			$this->fail('A delete through a share of a frozen object went through');
		} catch (AbortedEventException $e) {
			$this->assertStringContainsString('frozen by dossiq', $e->getMessage());
		}

		// A rename of the shared file to somewhere in alice's own tree: the source is the shared file.
		$own = $this->createMock(File::class);
		$own->method('getPath')->willReturn('/alice/files/mine.pdf');
		$own->method('getOwner')->willReturn($this->owner('alice'));

		$this->expectException(AbortedEventException::class);
		$this->listener()->handle(new BeforeNodeRenamedEvent($this->fileThroughAShare(), $own));
	}//end testAWriteThroughAShareeMountIsAborted()

	public function testACreateThroughAShareeMountResolvesItsNearestExistingAncestor(): void {
		$this->objects->method('findAcrossAllSources')->willReturn(['object' => $this->frozenObject()]);
		// The shared folder, as the home holds it: `/openregister/files/Woo Register/<uuid>`.
		$files = $this->folder('files', 2, '/openregister/files', null);
		$register = $this->folder('Woo Register', 3, '/openregister/files/Woo Register', $files);
		$objectFolder = $this->folder(self::UUID, self::FOLDER_ID, '/openregister/files/Woo Register/' . self::UUID, $register);
		$this->homeHolds([$objectFolder]);

		$shared = $this->createMock(Folder::class);
		$shared->method('getId')->willReturn(self::FOLDER_ID);
		$shared->method('getOwner')->willReturn($this->owner('openregister'));
		$new = $this->createMock(File::class);
		$new->method('getPath')->willReturn('/alice/files/Dossier/new.pdf');
		$new->method('getOwner')->willThrowException(new NotFoundException('not stored yet'));
		$new->method('getParent')->willReturn($shared);

		$this->expectException(HintException::class);
		$this->listener()->handle(new BeforeNodeCreatedEvent($new));
	}//end testACreateThroughAShareeMountResolvesItsNearestExistingAncestor()

	public function testASharedNodeOfAnotherOwnerIsIgnoredWithoutALookup(): void {
		$this->objects->expects($this->never())->method('findAcrossAllSources');
		$this->rootFolder->expects($this->never())->method('getUserFolder');

		$this->listener()->handle(new BeforeNodeWrittenEvent($this->fileThroughAShare(ownerUid: 'bob')));
		$this->addToAssertionCount(1);
	}//end testASharedNodeOfAnotherOwnerIsIgnoredWithoutALookup()

	public function testAShareeNodeThatCannotBePlacedInTheHomeIsRefused(): void {
		$this->rootFolder->method('getUserFolder')->willThrowException(new \RuntimeException('home unavailable'));
		$this->logger->expects($this->once())->method('warning')->with($this->stringContains('could not be placed'), $this->anything());

		$this->expectException(HintException::class);
		$this->listener()->handle(new BeforeNodeWrittenEvent($this->fileThroughAShare()));
	}//end testAShareeNodeThatCannotBePlacedInTheHomeIsRefused()

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

	public function testANodeWhosePathCannotBeReadIsNotRefused(): void {
		$node = $this->createMock(File::class);
		$node->method('getPath')->willThrowException(new \RuntimeException('gone'));
		$this->objects->expects($this->never())->method('findAcrossAllSources');

		$this->listener()->handle(new BeforeNodeWrittenEvent($node));
		$this->addToAssertionCount(1);
	}//end testANodeWhosePathCannotBeReadIsNotRefused()

	public function testAFileDirectlyUnderFilesHasNoOwnerObject(): void {
		$files = $this->folder('files', 2, '/openregister/files', null);
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('loose.pdf');
		$file->method('getPath')->willReturn('/openregister/files/loose.pdf');
		$file->method('getParent')->willReturn($files);
		$this->objects->expects($this->never())->method('findAcrossAllSources');

		$this->listener()->handle(new BeforeNodeWrittenEvent($file));
		$this->addToAssertionCount(1);
	}//end testAFileDirectlyUnderFilesHasNoOwnerObject()

	public function testAFileWhoseParentCannotBeReadHasNoOwnerObject(): void {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('orphan.pdf');
		$file->method('getPath')->willReturn('/openregister/files/x/orphan.pdf');
		$file->method('getParent')->willThrowException(new \RuntimeException('no parent'));
		$this->objects->expects($this->never())->method('findAcrossAllSources');

		$this->listener()->handle(new BeforeNodeWrittenEvent($file));
		$this->addToAssertionCount(1);
	}//end testAFileWhoseParentCannotBeReadHasNoOwnerObject()

	public function testAFolderNamedLikeAnObjectWhoseIdCannotBeReadIsNotTheOwner(): void {
		$objectFolder = $this->createMock(Folder::class);
		$objectFolder->method('getName')->willReturn(self::UUID);
		$objectFolder->method('getId')->willThrowException(new \RuntimeException('no id'));
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('a.pdf');
		$file->method('getPath')->willReturn('/openregister/files/r/' . self::UUID . '/a.pdf');
		$file->method('getParent')->willReturn($objectFolder);
		$this->objects->expects($this->never())->method('findAcrossAllSources');

		$this->listener()->handle(new BeforeNodeWrittenEvent($file));
		$this->addToAssertionCount(1);
	}//end testAFolderNamedLikeAnObjectWhoseIdCannotBeReadIsNotTheOwner()

	public function testTheOwnerSearchStopsAfterTheDepthLimit(): void {
		// A chain deeper than the limit of eight levels, none of them named like a uuid.
		$node = $this->folder('deepest', 100, '/openregister/files/a', null);
		for ($level = 0; $level < 10; $level++) {
			$parent = $this->createMock(Folder::class);
			$parent->method('getName')->willReturn('level' . $level);
			$parent->method('getParent')->willReturn($node);
			$node = $parent;
		}

		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('deep.pdf');
		$file->method('getPath')->willReturn('/openregister/files/a/b/deep.pdf');
		$file->method('getParent')->willReturn($node);
		$this->objects->expects($this->never())->method('findAcrossAllSources');

		$this->listener()->handle(new BeforeNodeWrittenEvent($file));
		$this->addToAssertionCount(1);
	}//end testTheOwnerSearchStopsAfterTheDepthLimit()

	public function testAnAnswerWithoutAnObjectIsNotRefused(): void {
		$this->objects->method('findAcrossAllSources')->willReturn(['object' => null]);

		$this->listener()->handle(new BeforeNodeWrittenEvent($this->fileInObjectFolder()));
		$this->addToAssertionCount(1);
	}//end testAnAnswerWithoutAnObjectIsNotRefused()

	public function testTheLogLineSurvivesAFileWhoseIdCannotBeRead(): void {
		$files = $this->folder('files', 2, '/openregister/files', null);
		$objectFolder = $this->folder(self::UUID, self::FOLDER_ID, '/openregister/files/' . self::UUID, $files);
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('new.pdf');
		$file->method('getId')->willThrowException(new \RuntimeException('not stored yet'));
		$file->method('getPath')->willReturn('/openregister/files/' . self::UUID . '/new.pdf');
		$file->method('getParent')->willReturn($objectFolder);
		$this->objects->method('findAcrossAllSources')->willThrowException(new DbException('connection lost'));
		$this->logger->expects($this->once())->method('warning')->with(
			$this->anything(),
			$this->callback(static fn (array $context): bool => $context['node'] === null)
		);

		$this->expectException(HintException::class);
		$this->listener()->handle(new BeforeNodeWrittenEvent($file));
	}//end testTheLogLineSurvivesAFileWhoseIdCannotBeRead()

	public function testTheRefusalCarriesTheReasonFromTheMarker(): void {
		$refusal = \OCA\OpenRegister\Exception\ObjectStateWriteException::frozen($this->frozenObject());

		$this->assertSame('geleverd', $refusal->getReason());
	}//end testTheRefusalCarriesTheReasonFromTheMarker()

	public function testAnOtherEventIsIgnored(): void {
		$this->objects->expects($this->never())->method('findAcrossAllSources');

		$this->listener()->handle(new \OCP\Files\Events\Node\BeforeNodeReadEvent($this->createMock(Node::class)));
		$this->addToAssertionCount(1);
	}//end testAnOtherEventIsIgnored()

	public function testTheMapperIsNotBuiltForANodeOutsideEveryObjectFolder(): void {
		$files = $this->folder('files', 2, '/openregister/files', null);
		$loose = $this->createMock(File::class);
		$loose->method('getName')->willReturn('loose.pdf');
		$loose->method('getPath')->willReturn('/openregister/files/loose.pdf');
		$loose->method('getParent')->willReturn($files);

		$listener = $this->listener();
		$listener->handle(new BeforeNodeWrittenEvent($loose));
		$listener->handle(new BeforeNodeWrittenEvent($this->fileThroughAShare(ownerUid: 'bob')));
		$listener->handle(new BeforeNodeCreatedEvent($this->fileInObjectFolder('/alice/files')));
		$listener->handle(new BeforeNodeDeletedEvent($this->fileInObjectFolder('/alice/files')));

		$this->assertSame(0, $this->mapperRequests);
	}//end testTheMapperIsNotBuiltForANodeOutsideEveryObjectFolder()

	public function testTheMapperIsFetchedOnceANodeProvesToBeInAnObjectFolder(): void {
		$this->objects->method('findAcrossAllSources')->willReturn(['object' => $this->frozenObject()]);

		try {
			$this->listener()->handle(new BeforeNodeWrittenEvent($this->fileInObjectFolder()));
			$this->fail('Expected the write to be refused');
		} catch (HintException $e) {
			$this->assertSame(1, $this->mapperRequests);
		}
	}//end testTheMapperIsFetchedOnceANodeProvesToBeInAnObjectFolder()

	public function testAMapperThatCannotBeBuiltRefusesTheWrite(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('container failure'));
		$this->logger->expects($this->once())->method('warning')->with($this->stringContains('could not be resolved'), $this->anything());

		$listener = new FrozenNodeWriteListener(guard: new FileWriteGuard(), container: $container, logger: $this->logger, rootFolder: $this->rootFolder);

		$this->expectException(HintException::class);
		$listener->handle(new BeforeNodeWrittenEvent($this->fileInObjectFolder()));
	}//end testAMapperThatCannotBeBuiltRefusesTheWrite()
}//end class
