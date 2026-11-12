<?php

/**
 * FilePropertyFiles clears stored files and looks up existing ones.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\Object\SaveObject\FilePropertyFiles;
use OCA\OpenRegister\Service\Object\SaveObject\FilePropertyLog;
use OCA\OpenRegister\Service\Object\SaveObject\FilePropertyTarget;
use OCA\OpenRegister\Service\Object\SaveObject\FileStoreRequest;
use OCP\Files\File;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Object\SaveObject\FilePropertyFiles
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\FilePropertyLog
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\FilePropertyTarget
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\FileStoreRequest
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 */
final class FilePropertyFilesTest extends TestCase {

	/**
	 * Clearing an array property deletes its numeric stored ids, survives a failed delete and returns [].
	 *
	 * @return void
	 */
	public function testClearingAnArrayDeletesNumericIds(): void {
		$deleted     = [];
		$fileService = $this->createMock(FileService::class);
		$fileService->method('deleteFile')->willReturnCallback(
			function (mixed $file) use (&$deleted): bool {
				$deleted[] = $file;
				if ($file === 2) {
					throw new Exception('gone');
				}

				return true;
			}
		);
		$entity = new ObjectEntity();
		$entity->setObject(['docs' => [1, 'x', 2, '3']]);

		$cleared = $this->files(fileService: $fileService)->clear(new FilePropertyTarget($entity, 'docs', true, []));

		$this->assertSame([], $cleared);
		$this->assertSame([1, 2, 3], $deleted);
	}//end testClearingAnArrayDeletesNumericIds()

	/**
	 * Clearing a single property deletes its one stored id and returns null.
	 *
	 * @return void
	 */
	public function testClearingASingleFileReturnsNull(): void {
		$fileService = $this->createMock(FileService::class);
		$fileService->expects($this->once())->method('deleteFile')->with(7);
		$entity = new ObjectEntity();
		$entity->setObject(['doc' => 7]);

		$this->assertNull($this->files(fileService: $fileService)->clear(new FilePropertyTarget($entity, 'doc', false, [])));
	}//end testClearingASingleFileReturnsNull()

	/**
	 * holdsFile() is true for a file the object holds, false for an absent or unreadable one.
	 *
	 * @return void
	 */
	public function testHoldsFile(): void {
		$fileService = $this->createMock(FileService::class);
		$fileService->method('getFile')->willReturnCallback(
			function (mixed $object = null, string|int $file = ''): ?File {
				if ($file === 13) {
					throw new Exception('denied');
				}

				if ($file === 99) {
					return null;
				}

				return $this->createMock(File::class);
			}
		);
		$files   = $this->files(fileService: $fileService);
		$request = new FileStoreRequest(new ObjectEntity(), 'doc', [], null);

		$this->assertTrue($files->holdsFile($request, '5'));
		$this->assertFalse($files->holdsFile($request, 99));
		$this->assertFalse($files->holdsFile($request, 13));
	}//end testHoldsFile()

	/**
	 * The operations over this file service and a silent logger.
	 *
	 * @param FileService $fileService The file service.
	 *
	 * @return FilePropertyFiles
	 */
	private function files(FileService $fileService): FilePropertyFiles {
		return new FilePropertyFiles(new FilePropertyLog($this->createMock(LoggerInterface::class)), $fileService);
	}//end files()
}//end class
