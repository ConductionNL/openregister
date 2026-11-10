<?php

/**
 * Golden outcomes of renaming, copying and moving a file attached to an object.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/file-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\File\CreateFileHandler;
use OCA\OpenRegister\Service\File\FileLockHandler;
use OCA\OpenRegister\Service\File\FileUpload;
use OCA\OpenRegister\Service\File\FolderManagementHandler;
use OCA\OpenRegister\Service\File\ReadFileHandler;
use OCA\OpenRegister\Service\FileService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionProperty;

/**
 * Pins the allowed and refused outcomes of FileService::renameFile(), copyFile() and moveFile().
 */
class FileServiceRenameCopyGoldenTest extends TestCase {

	/**
	 * The lock handler double.
	 *
	 * @var FileLockHandler&MockObject
	 */
	private FileLockHandler&MockObject $locks;

	/**
	 * The read handler double.
	 *
	 * @var ReadFileHandler&MockObject
	 */
	private ReadFileHandler&MockObject $reader;

	/**
	 * The folder handler double.
	 *
	 * @var FolderManagementHandler&MockObject
	 */
	private FolderManagementHandler&MockObject $folders;

	/**
	 * The create handler double.
	 *
	 * @var CreateFileHandler&MockObject
	 */
	private CreateFileHandler&MockObject $creator;

	/**
	 * A file service holding doubles for the collaborators rename, copy and move reach.
	 *
	 * @return FileService The service.
	 */
	private function service(): FileService {
		$this->locks = $this->createMock(FileLockHandler::class);
		$this->reader = $this->createMock(ReadFileHandler::class);
		$this->folders = $this->createMock(FolderManagementHandler::class);
		$this->creator = $this->createMock(CreateFileHandler::class);

		$service = $this->getMockBuilder(FileService::class)
			->disableOriginalConstructor()
			->onlyMethods(['deleteFile'])
			->getMock();
		$service->method('deleteFile')->willReturn(true);

		$collaborators = [
			'fileLockHandler' => $this->locks,
			'readFileHandler' => $this->reader,
			'folderManagementHandler' => $this->folders,
			'createFileHandler' => $this->creator,
			'logger' => $this->createMock(LoggerInterface::class),
		];
		foreach ($collaborators as $name => $value) {
			(new ReflectionProperty(FileService::class, $name))->setValue($service, $value);
		}

		return $service;
	}//end service()

	/**
	 * An object with a uuid.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return ObjectEntity The object.
	 */
	private function object(string $uuid): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);

		return $object;
	}//end object()

	/**
	 * A file in a parent folder that holds the given names.
	 *
	 * @param array<int, string> $taken Names already in the parent folder.
	 *
	 * @return File&MockObject The file.
	 */
	private function fileIn(array $taken): File&MockObject {
		$parent = $this->createMock(Folder::class);
		$parent->method('getPath')->willReturn('/admin/files/obj');
		$parent->method('get')->willReturnCallback(
			function (string $name) use ($taken) {
				if (in_array($name, $taken, true) === true) {
					return $this->createMock(File::class);
				}

				throw new NotFoundException($name);
			}
		);

		$file = $this->createMock(File::class);
		$file->method('getParent')->willReturn($parent);
		$file->method('getName')->willReturn('report.pdf');
		$file->method('getContent')->willReturn('bytes');

		return $file;
	}//end fileIn()

	/**
	 * Run a call and return the exception message it throws, or null.
	 *
	 * @param callable $call The call.
	 *
	 * @return string|null The message.
	 */
	private function refusal(callable $call): ?string {
		try {
			$call();
		} catch (Exception $e) {
			return $e->getMessage();
		}

		return null;
	}//end refusal()

	/**
	 * A locked file is refused before it is looked up or renamed.
	 *
	 * @return void
	 */
	public function testRenameOfALockedFileIsRefused(): void {
		$service = $this->service();
		$this->locks->method('assertCanModify')->willThrowException(new Exception('locked'));
		$this->reader->expects($this->never())->method('getFile');

		$this->assertSame('locked', $this->refusal(call: fn () => $service->renameFile(object: $this->object(uuid: 'a'), fileId: 7, newName: 'new.pdf')));
	}//end testRenameOfALockedFileIsRefused()

	/**
	 * Missing file, empty name, invalid characters and a taken name are each refused without a move.
	 *
	 * @return void
	 */
	public function testRenameRefusals(): void {
		$service = $this->service();
		$this->locks->expects($this->exactly(4))->method('assertCanModify')->with(7);
		$file = $this->fileIn(taken: ['taken.pdf']);
		$file->expects($this->never())->method('move');
		$this->reader->method('getFile')->willReturnOnConsecutiveCalls(null, $file, $file, $file);

		$object = $this->object(uuid: 'a');
		$rename = fn (string $name) => fn () => $service->renameFile(object: $object, fileId: 7, newName: $name);
		$this->assertSame('File not found', $this->refusal(call: $rename('x.pdf')));
		$this->assertSame('File name is required', $this->refusal(call: $rename('  ')));
		$this->assertSame('File name contains invalid characters', $this->refusal(call: $rename('a/b.pdf')));
		$this->assertSame('A file with name "taken.pdf" already exists for this object', $this->refusal(call: $rename('taken.pdf')));
	}//end testRenameRefusals()

	/**
	 * A free, valid name moves the file within its folder.
	 *
	 * @return void
	 */
	public function testRenameMovesTheFileWithinItsFolder(): void {
		$service = $this->service();
		$file = $this->fileIn(taken: []);
		$file->expects($this->once())->method('move')->with('/admin/files/obj/new.pdf');
		$this->reader->expects($this->once())->method('getFile')->with($this->anything(), 7)->willReturn($file);

		$this->assertSame($file, $service->renameFile(object: $this->object(uuid: 'a'), fileId: 7, newName: 'new.pdf'));
	}//end testRenameMovesTheFileWithinItsFolder()

	/**
	 * A target without a uuid is refused before the lock is even checked.
	 *
	 * @return void
	 */
	public function testCopyToATargetWithoutUuidIsRefused(): void {
		$service = $this->service();
		$this->locks->expects($this->never())->method('assertCanModify');
		$this->creator->expects($this->never())->method('addFile');

		$message = $this->refusal(call: fn () => $service->copyFile(sourceObject: $this->object(uuid: 'a'), fileId: 7, targetObject: new ObjectEntity()));
		$this->assertSame('Target object has no UUID; cannot resolve target folder for file copy', $message);
	}//end testCopyToATargetWithoutUuidIsRefused()

	/**
	 * A locked source and a missing source are refused without a copy.
	 *
	 * @return void
	 */
	public function testCopyOfALockedOrMissingSourceIsRefused(): void {
		$service = $this->service();
		$calls = 0;
		$this->locks->method('assertCanModify')->willReturnCallback(
			function () use (&$calls): void {
				$calls++;
				if ($calls === 1) {
					throw new Exception('locked');
				}
			}
		);
		$this->reader->method('getFile')->willReturn(null);
		$this->creator->expects($this->never())->method('addFile');

		$copy = fn () => $service->copyFile(sourceObject: $this->object(uuid: 'a'), fileId: 7, targetObject: $this->object(uuid: 'b'));
		$this->assertSame('locked', $this->refusal(call: $copy));
		$this->assertSame('Source file not found', $this->refusal(call: $copy));
	}//end testCopyOfALockedOrMissingSourceIsRefused()

	/**
	 * A copy lands in the target folder under the first free name.
	 *
	 * @return void
	 */
	public function testCopyAddsTheFileUnderAFreeName(): void {
		$service = $this->service();
		$this->reader->method('getFile')->willReturn($this->fileIn(taken: []));
		$folder = $this->createMock(Folder::class);
		$folder->method('nodeExists')->willReturnCallback(fn (string $name): bool => in_array($name, ['report.pdf', 'report (1).pdf'], true));
		$target = $this->object(uuid: 'b');
		$this->folders->method('getObjectFolder')->with($target)->willReturn($folder);
		$copy = $this->createMock(File::class);
		$this->creator->expects($this->once())->method('addFile')
			->with(new \OCA\OpenRegister\Service\File\FileTarget(object: $target), new FileUpload(fileName: 'report (2).pdf', content: 'bytes', share: false))
			->willReturn($copy);

		$this->assertSame($copy, $service->copyFile(sourceObject: $this->object(uuid: 'a'), fileId: 7, targetObject: $target));
	}//end testCopyAddsTheFileUnderAFreeName()

	/**
	 * A locked source is refused before anything is copied.
	 *
	 * @return void
	 */
	public function testMoveOfALockedFileIsRefused(): void {
		$service = $this->service();
		$this->locks->method('assertCanModify')->willThrowException(new Exception('locked'));
		$this->creator->expects($this->never())->method('addFile');

		$move = fn () => $service->moveFile(sourceObject: $this->object(uuid: 'a'), fileId: 7, targetObject: $this->object(uuid: 'b'));
		$this->assertSame('locked', $this->refusal(call: $move));
	}//end testMoveOfALockedFileIsRefused()
}//end class
