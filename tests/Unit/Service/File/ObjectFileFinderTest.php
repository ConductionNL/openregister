<?php

/**
 * Unit test for ObjectFileFinder: finding an object's file by id or by path,
 * the fallback from file name to full path, and every way the lookup refuses.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\File\ObjectFileFinder;
use OCA\OpenRegister\Service\FileHandlerSupportInterface;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\File\ObjectFileFinder
 * @uses   \OCA\OpenRegister\Service\File\ObjectFileLookup
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 */
class ObjectFileFinderTest extends TestCase {

	/**
	 * The file service.
	 *
	 * @var FileHandlerSupportInterface&MockObject
	 */
	private FileHandlerSupportInterface&MockObject $files;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The error messages logged.
	 *
	 * @var array<int, string>
	 */
	private array $errors = [];

	/**
	 * Mocked file service and a logger that keeps its errors.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->files  = $this->createMock(FileHandlerSupportInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->logger->method('error')->willReturnCallback(
			function (string $message): void {
				$this->errors[] = $message;
			}
		);
	}//end setUp()

	/**
	 * The finder.
	 *
	 * @return ObjectFileFinder
	 */
	private function finder(): ObjectFileFinder {
		return new ObjectFileFinder(logger: $this->logger, fileService: $this->files);
	}//end finder()

	/**
	 * A file node with a name.
	 *
	 * @param string $name The name.
	 *
	 * @return File&MockObject
	 */
	private function file(string $name): File&MockObject {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getPath')->willReturn('/objects/'.$name);
		$file->method('getId')->willReturn(9);
		return $file;
	}//end file()

	/**
	 * A folder for the object, found by the file service, whose get() answers by the callback.
	 *
	 * @param callable $get What get() does with a name.
	 *
	 * @return Folder&MockObject
	 */
	private function folder(callable $get): Folder&MockObject {
		$folder = $this->createMock(Folder::class);
		$folder->method('getPath')->willReturn('/objects');
		$folder->method('get')->willReturnCallback($get);
		$this->files->method('getObjectFolder')->willReturn($folder);
		return $folder;
	}//end folder()

	/**
	 * A path is cleaned to its file name.
	 *
	 * @param string $path The path given.
	 * @param string $name Its file name.
	 *
	 * @return void
	 */
	private function cleans(string $path, string $name): void {
		$this->files->method('extractFileNameFromPath')->with($path)->willReturn(['cleanPath' => $path, 'fileName' => $name]);
	}//end cleans()

	/**
	 * A file found by id is checked for ownership and returned.
	 *
	 * @return void
	 */
	public function testAFileIsFoundById(): void {
		$object = new ObjectEntity();
		$file   = $this->file(name: 'a.pdf');
		$this->files->expects($this->once())->method('getFile')->with($object, 9)->willReturn($file);
		$this->files->expects($this->once())->method('checkOwnership')->with($file);

		$this->assertSame($file, $this->finder()->find(object: $object, file: 9));
	}//end testAFileIsFoundById()

	/**
	 * An id that names no file is refused.
	 *
	 * @return void
	 */
	public function testAnUnknownIdIsRefused(): void {
		$this->files->method('getFile')->willReturn(null);
		$this->files->expects($this->never())->method('checkOwnership');

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('File with ID 9 does not exist');
		$this->finder()->find(object: new ObjectEntity(), file: 9);
	}//end testAnUnknownIdIsRefused()

	/**
	 * A path is looked up by its file name in the object's folder first.
	 *
	 * @return void
	 */
	public function testAPathIsFoundByItsFileName(): void {
		$file = $this->file(name: 'a.pdf');
		$this->cleans(path: 'docs/a.pdf', name: 'a.pdf');
		$asked  = [];
		$folder = $this->folder(
			get: function (string $name) use (&$asked, $file) {
				$asked[] = $name;
				return $file;
			}
		);
		$folder->method('getDirectoryListing')->willReturn([$file]);
		$this->files->expects($this->once())->method('checkOwnership')->with($file);

		$this->assertSame($file, $this->finder()->find(object: new ObjectEntity(), file: 'docs/a.pdf'));
		$this->assertSame(['a.pdf'], $asked);
	}//end testAPathIsFoundByItsFileName()

	/**
	 * When the file name is not in the folder the full path is tried, and a listing that fails is only logged.
	 *
	 * @return void
	 */
	public function testAPathFallsBackToTheFullPath(): void {
		$file = $this->file(name: 'a.pdf');
		$this->cleans(path: 'docs/a.pdf', name: 'a.pdf');
		$asked  = [];
		$folder = $this->folder(
			get: function (string $name) use (&$asked, $file) {
				$asked[] = $name;
				if ($name === 'a.pdf') {
					throw new NotFoundException('no a.pdf');
				}

				return $file;
			}
		);
		$folder->method('getDirectoryListing')->willThrowException(new Exception('listing denied'));

		$this->assertSame($file, $this->finder()->find(object: new ObjectEntity(), file: 'docs/a.pdf'));
		$this->assertSame(['a.pdf', 'docs/a.pdf'], $asked);
		$this->assertSame([' Error listing object folder contents: listing denied'], $this->errors);
	}//end testAPathFallsBackToTheFullPath()

	/**
	 * A path found under neither name is refused, naming both.
	 *
	 * @return void
	 */
	public function testAPathFoundUnderNeitherNameIsRefused(): void {
		$this->cleans(path: 'docs/a.pdf', name: 'a.pdf');
		$folder = $this->folder(get: fn (string $name) => throw new NotFoundException('missing '.$name));
		$folder->method('getDirectoryListing')->willReturn([]);

		try {
			$this->finder()->find(object: new ObjectEntity(), file: 'docs/a.pdf');
			$this->fail('a missing file must be refused');
		} catch (Exception $e) {
			$this->assertSame('File not found.', $e->getMessage());
		}

		$this->assertSame([" File 'a.pdf' and 'docs/a.pdf' not found in object folder. NotFoundException: missing docs/a.pdf"], $this->errors);
	}//end testAPathFoundUnderNeitherNameIsRefused()

	/**
	 * Any other error from the folder is refused as not found, without trying the full path.
	 *
	 * @return void
	 */
	public function testAnUnexpectedFolderErrorIsRefused(): void {
		$this->cleans(path: 'a.pdf', name: 'a.pdf');
		$asked  = [];
		$folder = $this->folder(
			get: function (string $name) use (&$asked) {
				$asked[] = $name;
				throw new RuntimeException('storage offline');
			}
		);
		$folder->method('getDirectoryListing')->willReturn([]);

		try {
			$this->finder()->find(object: new ObjectEntity(), file: 'a.pdf');
			$this->fail('an unexpected error must be refused');
		} catch (Exception $e) {
			$this->assertSame('File not found.', $e->getMessage());
		}

		$this->assertSame(['a.pdf'], $asked);
		$this->assertSame([' Unexpected error getting file from object folder: storage offline'], $this->errors);
	}//end testAnUnexpectedFolderErrorIsRefused()

	/**
	 * An object without a folder is refused.
	 *
	 * @return void
	 */
	public function testAnObjectWithoutAFolderIsRefused(): void {
		$this->cleans(path: 'a.pdf', name: 'a.pdf');
		$this->files->method('getObjectFolder')->willReturn(null);

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Object folder not found.');
		$this->finder()->find(object: new ObjectEntity(), file: 'a.pdf');
	}//end testAnObjectWithoutAFolderIsRefused()

	/**
	 * A node that is not a file is refused and its ownership never checked.
	 *
	 * @return void
	 */
	public function testANodeThatIsNoFileIsRefused(): void {
		$this->cleans(path: 'sub', name: 'sub');
		$this->folder(get: fn (string $name) => $this->createMock(Folder::class))->method('getDirectoryListing')->willReturn([]);
		$this->files->expects($this->never())->method('checkOwnership');

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('File not found.');
		$this->finder()->find(object: new ObjectEntity(), file: 'sub');
	}//end testANodeThatIsNoFileIsRefused()
}//end class
