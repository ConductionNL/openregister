<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

/*
 * Golden test: what DeleteFileHandler deletes and what it refuses.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\File\DeleteFileHandler;
use OCA\OpenRegister\Service\File\FileLockHandler;
use OCA\OpenRegister\Service\File\FileValidationHandler;
use OCA\OpenRegister\Service\File\ReadFileHandler;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotPermittedException;
use OCP\Lock\LockedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Pins the allowed and refused outcomes of deleteFile() and the order of its checks.
 *
 * The lock check, the readability check and the delete permission all run
 * before delete(); each refusal propagates and nothing is deleted.
 */
class DeleteFileHandlerAccessGoldenTest extends TestCase {

	/** @var IRootFolder&MockObject */
	private $rootFolder;

	/** @var ReadFileHandler&MockObject */
	private $reader;

	/** @var FileValidationHandler&MockObject */
	private $validation;

	/** @var FileLockHandler&MockObject */
	private $locks;

	/** @var list<string> */
	private array $calls = [];

	protected function setUp(): void {
		parent::setUp();
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->reader = $this->createMock(ReadFileHandler::class);
		$this->validation = $this->createMock(FileValidationHandler::class);
		$this->locks = $this->createMock(FileLockHandler::class);
	}//end setUp()

	/**
	 * Build the handler with the mocks above, matching constructor parameters by type.
	 *
	 * @return DeleteFileHandler The handler.
	 */
	private function handler(): DeleteFileHandler {
		$byType = [
			IRootFolder::class           => $this->rootFolder,
			ReadFileHandler::class       => $this->reader,
			FileValidationHandler::class => $this->validation,
			FileLockHandler::class       => $this->locks,
			LoggerInterface::class       => $this->createMock(LoggerInterface::class),
		];
		$args = [];
		foreach ((new ReflectionClass(DeleteFileHandler::class))->getConstructor()->getParameters() as $param) {
			$type = ltrim((string)$param->getType(), '?');
			if (array_key_exists($type, $byType) === true) {
				$args[] = $byType[$type];
				continue;
			}

			$args[] = $this->createMock($type);
		}

		return new DeleteFileHandler(...$args);
	}//end handler()

	/**
	 * A file that records the calls made on it.
	 *
	 * @param bool $deletable Whether the session may delete it.
	 *
	 * @return File&MockObject The file.
	 */
	private function file(bool $deletable = true): File&MockObject {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(5);
		$file->method('getName')->willReturn('doc.txt');
		$file->method('isDeletable')->willReturnCallback(function () use ($deletable): bool {
			$this->calls[] = 'isDeletable';
			return $deletable;
		});
		$file->method('delete')->willReturnCallback(function (): void {
			$this->calls[] = 'delete';
		});
		$this->locks->method('assertCanModify')->willReturnCallback(function (int $fileId): void {
			$this->calls[] = 'lock:' . $fileId;
		});
		$this->validation->method('checkOwnership')->willReturnCallback(function (): void {
			$this->calls[] = 'check';
		});
		return $file;
	}//end file()

	public function testANodeIsDeletedAfterEveryCheck(): void {
		$file = $this->file();
		$this->rootFolder->expects($this->once())->method('getFirstNodeById')->with(5)->willReturn(null);
		$this->reader->expects($this->never())->method('getFile');

		$this->assertTrue($this->handler()->deleteFile($file));
		$this->assertSame(['lock:5', 'check', 'isDeletable', 'delete'], $this->calls);
	}//end testANodeIsDeletedAfterEveryCheck()

	public function testAPathOrIdIsResolvedInTheObjectFirst(): void {
		$object = new ObjectEntity();
		$file = $this->file();
		$this->reader->expects($this->exactly(2))->method('getFile')
			->willReturnCallback(function (?ObjectEntity $given, string|int $name) use ($object, $file): File {
				$this->assertSame($object, $given);
				$this->assertContains($name, ['doc.txt', 5]);
				return $file;
			});

		$handler = $this->handler();
		$this->assertTrue($handler->deleteFile('doc.txt', $object));
		$this->assertTrue($handler->deleteFile(5, $object));
	}//end testAPathOrIdIsResolvedInTheObjectFirst()

	public function testAMissingFileIsFalseWithoutAnyCheck(): void {
		$this->reader->method('getFile')->willReturn(null);
		$this->locks->expects($this->never())->method('assertCanModify');

		$this->assertFalse($this->handler()->deleteFile('gone.txt'));
	}//end testAMissingFileIsFalseWithoutAnyCheck()

	public function testAFolderIsNeverDeleted(): void {
		$folder = $this->createMock(Folder::class);
		$folder->expects($this->never())->method('delete');
		$this->locks->expects($this->never())->method('assertCanModify');

		$this->assertFalse($this->handler()->deleteFile($folder));
	}//end testAFolderIsNeverDeleted()

	public function testALockHeldByAnotherRefusesBeforeAnyOtherCheck(): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(5);
		$file->expects($this->never())->method('delete');
		$this->locks->method('assertCanModify')->willThrowException(new LockedException('doc.txt'));
		$this->validation->expects($this->never())->method('checkOwnership');

		$this->expectException(LockedException::class);
		$this->handler()->deleteFile($file);
	}//end testALockHeldByAnotherRefusesBeforeAnyOtherCheck()

	public function testAnUnreadableFileIsRefused(): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(5);
		$file->expects($this->never())->method('delete');
		$file->expects($this->never())->method('isDeletable');
		$this->validation->method('checkOwnership')->willThrowException(new NotPermittedException('not readable'));

		$this->expectException(NotPermittedException::class);
		$this->expectExceptionMessage('not readable');
		$this->handler()->deleteFile($file);
	}//end testAnUnreadableFileIsRefused()

	public function testAFileWithoutDeletePermissionIsRefused(): void {
		$file = $this->file(false);
		$this->rootFolder->expects($this->never())->method('getFirstNodeById');

		try {
			$this->handler()->deleteFile($file);
			$this->fail('A file without delete permission must be refused.');
		} catch (NotPermittedException $e) {
			$this->assertSame('File doc.txt is not deletable by the current session', $e->getMessage());
		}

		$this->assertSame(['lock:5', 'check', 'isDeletable'], $this->calls);
	}//end testAFileWithoutDeletePermissionIsRefused()

	public function testAFailingDeleteIsFalse(): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(5);
		$file->method('isDeletable')->willReturn(true);
		$file->method('delete')->willThrowException(new Exception('storage down'));

		$this->assertFalse($this->handler()->deleteFile($file));
	}//end testAFailingDeleteIsFalse()

	public function testAFileThatSurvivesItsDeleteIsFalse(): void {
		$file = $this->file();
		$this->rootFolder->method('getFirstNodeById')->willReturn($this->createMock(Node::class));

		$this->assertFalse($this->handler()->deleteFile($file));
		$this->assertSame(['lock:5', 'check', 'isDeletable', 'delete'], $this->calls);
	}//end testAFileThatSurvivesItsDeleteIsFalse()
}//end class
