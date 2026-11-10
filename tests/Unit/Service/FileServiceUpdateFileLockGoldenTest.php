<?php

/**
 * Overwriting a file by id honours its lock; a path is handed on unchecked.
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
 * @spec openspec/specs/file-actions/spec.md#file-update-guards-locks-preserves-object-tags-and-persists-or-side-metadata-separately
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\File\FileLockHandler;
use OCA\OpenRegister\Service\File\UpdateFileHandler;
use OCA\OpenRegister\Service\FileService;
use OCP\Files\File;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use OCA\OpenRegister\Service\File\FileOverwrite;

/**
 * Pins the allowed and refused outcomes of FileService::overwriteFile().
 */
class FileServiceUpdateFileLockGoldenTest extends TestCase {

	/**
	 * The lock handler double.
	 *
	 * @var FileLockHandler&MockObject
	 */
	private FileLockHandler&MockObject $locks;

	/**
	 * The update handler double.
	 *
	 * @var UpdateFileHandler&MockObject
	 */
	private UpdateFileHandler&MockObject $updater;

	/**
	 * A file service holding doubles for the lock and update handlers.
	 *
	 * @return FileService The service.
	 */
	private function service(): FileService {
		$this->locks = $this->createMock(FileLockHandler::class);
		$this->updater = $this->createMock(UpdateFileHandler::class);

		$service = $this->getMockBuilder(FileService::class)
			->disableOriginalConstructor()
			->onlyMethods([])
			->getMock();
		(new ReflectionProperty(FileService::class, 'fileLockHandler'))->setValue($service, $this->locks);
		(new ReflectionProperty(FileService::class, 'updateFileHandler'))->setValue($service, $this->updater);

		return $service;
	}//end service()

	/**
	 * A file id locked by someone else is refused before anything is written.
	 *
	 * @return void
	 */
	public function testALockedFileIdIsRefused(): void {
		$service = $this->service();
		$this->locks->expects($this->once())->method('assertCanModify')->with(7)->willThrowException(new Exception('locked'));
		$this->updater->expects($this->never())->method('updateFile');

		$this->expectExceptionMessage('locked');
		$service->overwriteFile(overwrite: new FileOverwrite(filePath: 7, content: 'x'));
	}//end testALockedFileIdIsRefused()

	/**
	 * An unlocked file id is written with every argument handed on.
	 *
	 * @return void
	 */
	public function testAnUnlockedFileIdIsWritten(): void {
		$service = $this->service();
		$object = new ObjectEntity();
		$file = $this->createMock(File::class);
		$this->locks->expects($this->once())->method('assertCanModify')->with(7);
		$this->updater->expects($this->once())->method('updateFile')->with(7, 'x', ['t'], $object)->willReturn($file);

		$this->assertSame($file, $service->overwriteFile(overwrite: new FileOverwrite(filePath: 7, content: 'x', tags: ['t'], object: $object)));
	}//end testAnUnlockedFileIdIsWritten()

	/**
	 * A path is not lock-checked here and is handed on with the defaults.
	 *
	 * @return void
	 */
	public function testAPathIsHandedOnWithoutALockCheck(): void {
		$service = $this->service();
		$file = $this->createMock(File::class);
		$this->locks->expects($this->never())->method('assertCanModify');
		$this->updater->expects($this->once())->method('updateFile')->with('a/b.txt', null, [], null)->willReturn($file);

		$this->assertSame($file, $service->overwriteFile(overwrite: new FileOverwrite(filePath: 'a/b.txt')));
	}//end testAPathIsHandedOnWithoutALockCheck()
}//end class
