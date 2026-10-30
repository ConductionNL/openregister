<?php

/**
 * Moving a file to another object is a move only when the source file is gone.
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
 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md#requirement-file-writes-honour-the-frozen-and-archived-marker-req-oas-007
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\File\FileLockHandler;
use OCA\OpenRegister\Service\FileService;
use OCP\Files\File;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionProperty;

/**
 * @covers \OCA\OpenRegister\Service\FileService::moveFile
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 */
class FileServiceMoveFileTest extends TestCase {

	/**
	 * A file service whose copy and delete are doubles, so only the move rule runs.
	 *
	 * @return FileService&MockObject The service.
	 */
	private function service(): FileService&MockObject {
		$service = $this->getMockBuilder(FileService::class)
			->disableOriginalConstructor()
			->onlyMethods(['copyFile', 'deleteFile'])
			->getMock();

		foreach (['fileLockHandler' => $this->createMock(FileLockHandler::class), 'logger' => $this->createMock(LoggerInterface::class)] as $name => $value) {
			$property = new ReflectionProperty(FileService::class, $name);
			$property->setValue($service, $value);
		}

		return $service;
	}//end service()

	/**
	 * A source that could not be deleted is not moved: the copy is taken back and the caller told.
	 *
	 * @return void
	 */
	public function testASourceThatSurvivesItsDeleteIsNotMoved(): void {
		$copy = $this->createMock(File::class);
		$source = new ObjectEntity();
		$target = new ObjectEntity();

		$service = $this->service();
		$service->method('copyFile')->willReturn($copy);
		$deleted = [];
		$service->method('deleteFile')->willReturnCallback(
			function ($file) use (&$deleted, $copy): bool {
				$deleted[] = $file;

				return $file === $copy;
			}
		);

		try {
			$service->moveFile(sourceObject: $source, fileId: 7, targetObject: $target);
			$this->fail('Expected the move to fail');
		} catch (Exception $e) {
			$this->assertStringContainsString('could not be removed from the source object', $e->getMessage());
		}

		$this->assertSame([7, $copy], $deleted);
	}//end testASourceThatSurvivesItsDeleteIsNotMoved()

	/**
	 * Control: a source that is deleted makes the copy the moved file.
	 *
	 * @return void
	 */
	public function testADeletedSourceIsAMove(): void {
		$copy = $this->createMock(File::class);

		$service = $this->service();
		$service->method('copyFile')->willReturn($copy);
		$service->expects($this->once())->method('deleteFile')->with(7)->willReturn(true);

		$this->assertSame($copy, $service->moveFile(sourceObject: new ObjectEntity(), fileId: 7, targetObject: new ObjectEntity()));
	}//end testADeletedSourceIsAMove()
}//end class
