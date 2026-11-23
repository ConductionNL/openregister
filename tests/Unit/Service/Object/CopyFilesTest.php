<?php

/**
 * Unit tests for CopyFiles.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectFileCopierInterface;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\Object\CopyFiles;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The files a copy takes along, each reported on its own.
 *
 * @covers \OCA\OpenRegister\Service\Object\CopyFiles
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 *
 * @spec openspec/changes/records-copy-with-links/specs/objects-crud/spec.md#requirement-an-object-can-be-copied-with-the-links-the-caller-chooses
 */
class CopyFilesTest extends TestCase {

	/**
	 * The file service double.
	 *
	 * @var FileService&MockObject
	 */
	private FileService&MockObject $files;

	/**
	 * The copies asked for, as [source uuid, file id, target uuid].
	 *
	 * @var list<array{0: string|null, 1: int, 2: string|null}>
	 */
	private array $copied = [];

	/**
	 * A source with two files and a subfolder, the second file failing to copy, reports each file.
	 *
	 * @return void
	 */
	public function testEachFileIsCopiedOntoTheCopyAndAFailedOneIsReported(): void {
		$this->files->method('getFiles')->willReturn([$this->file(id: 11, name: 'besluit.pdf'), $this->folder(), $this->file(id: 12, name: 'bijlage.docx')]);
		$this->files->method('copyFile')->willReturnCallback(
			function (ObjectEntity $sourceObject, int $fileId, ObjectEntity $targetObject): File {
				$this->copied[] = [$sourceObject->getUuid(), $fileId, $targetObject->getUuid()];
				if ($fileId === 12) {
					throw new RuntimeException('disk full');
				}

				return $this->file(id: 99, name: 'besluit.pdf');
			}
		);

		$outcomes = $this->service()->copy(source: $this->object(uuid: 'u-1'), copy: $this->object(uuid: 'u-2'));

		$this->assertSame(
			[
				['id' => '11', 'title' => 'besluit.pdf', 'outcome' => 'copied', 'reason' => null],
				['id' => '12', 'title' => 'bijlage.docx', 'outcome' => 'failed', 'reason' => 'The file could not be copied.'],
			],
			$outcomes
		);
		$this->assertSame([['u-1', 11, 'u-2'], ['u-1', 12, 'u-2']], $this->copied);
	}//end testEachFileIsCopiedOntoTheCopyAndAFailedOneIsReported()

	/**
	 * A source without a file folder has nothing to copy, and nothing is copied.
	 *
	 * @return void
	 */
	public function testASourceWithoutFilesReportsNone(): void {
		$this->files->method('getFiles')->willThrowException(new NotFoundException('no folder'));
		$this->files->expects($this->never())->method('copyFile');

		$this->assertSame([], $this->service()->copy(source: $this->object(uuid: 'u-1'), copy: $this->object(uuid: 'u-2')));
	}//end testASourceWithoutFilesReportsNone()

	/**
	 * The copy endpoint reaches the service through the Kernel port, and an object that is not a stored entity has no files to copy.
	 *
	 * @return void
	 */
	public function testAnObjectThatIsNotAStoredEntityHasNoFilesToCopy(): void {
		$this->files->expects($this->never())->method('getFiles');
		$this->files->expects($this->never())->method('copyFile');

		$service = $this->service();
		$this->assertInstanceOf(ObjectFileCopierInterface::class, $service);
		$this->assertSame([], $service->copy(source: $this->createMock(ObjectEntityInterface::class), copy: $this->object(uuid: 'u-2')));
		$this->assertSame([], $service->copy(source: $this->object(uuid: 'u-1'), copy: $this->createMock(ObjectEntityInterface::class)));
	}//end testAnObjectThatIsNotAStoredEntityHasNoFilesToCopy()

	/**
	 * Set up the file service double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->files  = $this->createMock(FileService::class);
		$this->copied = [];
	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @return CopyFiles
	 */
	private function service(): CopyFiles {
		return new CopyFiles(files: $this->files, logger: $this->createMock(LoggerInterface::class));
	}//end service()

	/**
	 * An object with this uuid.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return ObjectEntity
	 */
	private function object(string $uuid): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		return $object;
	}//end object()

	/**
	 * A file node.
	 *
	 * @param integer $id   Its id.
	 * @param string  $name Its name.
	 *
	 * @return File&MockObject
	 */
	private function file(int $id, string $name): File&MockObject {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		$file->method('getName')->willReturn($name);
		return $file;
	}//end file()

	/**
	 * A folder node, which is not a file to copy.
	 *
	 * @return Folder&MockObject
	 */
	private function folder(): Folder&MockObject {
		return $this->createMock(Folder::class);
	}//end folder()
}//end class
