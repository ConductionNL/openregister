<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

/*
 * Golden test: which files ReadFileHandler hands out and which it refuses.
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

use OCA\OpenRegister\Db\MagicMapper\MagicObjectFinderInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Files\Db\FileMapperInterface;
use OCA\OpenRegister\Files\Service\File\FileOwnershipHandler;
use OCA\OpenRegister\Service\File\FileValidationHandler;
use OCA\OpenRegister\Service\File\FolderManagementHandler;
use OCA\OpenRegister\Service\File\ReadFileHandler;
use OCA\OpenRegister\Service\FileService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Pins the allowed and refused outcomes of every ReadFileHandler read.
 *
 * The refusal is FileValidationHandler::checkOwnership(): a file the session
 * cannot read is never returned. By id it reads as "not found" (null); by name
 * the refusal propagates.
 */
class ReadFileHandlerAccessGoldenTest extends TestCase {

	/** @var IRootFolder&MockObject */
	private $rootFolder;

	/** @var FolderManagementHandler&MockObject */
	private $folders;

	/** @var FileValidationHandler&MockObject */
	private $validation;

	/** @var MagicObjectFinderInterface&MockObject */
	private $objects;

	/** @var FileMapperInterface&MockObject */
	private $fileMapper;

	/** @var FileService&MockObject */
	private $fileService;

	protected function setUp(): void {
		parent::setUp();
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->folders = $this->createMock(FolderManagementHandler::class);
		$this->validation = $this->createMock(FileValidationHandler::class);
		$this->objects = $this->createMock(MagicObjectFinderInterface::class);
		$this->fileMapper = $this->createMock(FileMapperInterface::class);
		$this->fileService = $this->createMock(FileService::class);
		$this->fileService->method('extractFileNameFromPath')->willReturnCallback(
			static fn (string $path): array => ['cleanPath' => trim($path, '/'), 'fileName' => basename($path)]
		);
	}//end setUp()

	/**
	 * Build the handler with the mocks above, matching constructor parameters by type.
	 *
	 * @param bool $withFileMapper Whether the optional OR-side file mapper is wired.
	 *
	 * @return ReadFileHandler The handler.
	 */
	private function handler(bool $withFileMapper = true): ReadFileHandler {
		$byType = [
			IRootFolder::class                => $this->rootFolder,
			FolderManagementHandler::class    => $this->folders,
			FileValidationHandler::class      => $this->validation,
			FileOwnershipHandler::class       => $this->createMock(FileOwnershipHandler::class),
			MagicObjectFinderInterface::class => $this->objects,
			LoggerInterface::class            => $this->createMock(LoggerInterface::class),
			FileMapperInterface::class        => null,
		];
		if ($withFileMapper === true) {
			$byType[FileMapperInterface::class] = $this->fileMapper;
		}

		$args = [];
		foreach ((new ReflectionClass(ReadFileHandler::class))->getConstructor()->getParameters() as $param) {
			$type = (string)$param->getType();
			$type = ltrim($type, '?');
			if (array_key_exists($type, $byType) === true) {
				$args[] = $byType[$type];
				continue;
			}

			$args[] = $this->createMock($type);
		}

		$handler = new ReadFileHandler(...$args);
		$handler->setFileService($this->fileService);
		return $handler;
	}//end handler()

	private function file(int $id): File&MockObject {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		return $file;
	}//end file()

	private function refuse(): void {
		$this->validation->method('checkOwnership')->willThrowException(new NotPermittedException('not readable'));
	}//end refuse()

	public function testByIdReturnsAReadableFile(): void {
		$file = $this->file(7);
		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->with(7)->willReturn([$file]);
		$this->folders->method('getObjectFolder')->willReturn($folder);
		$this->validation->expects($this->once())->method('checkOwnership')->with($file);

		$this->assertSame($file, $this->handler()->getFile(new ObjectEntity(), 7));
	}//end testByIdReturnsAReadableFile()

	public function testByNumericStringIdRefusesAnUnreadableFileAsNull(): void {
		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->willReturn([$this->file(7)]);
		$this->folders->method('getObjectFolder')->willReturn($folder);
		$this->refuse();

		$this->assertNull($this->handler()->getFile(new ObjectEntity(), '7'));
	}//end testByNumericStringIdRefusesAnUnreadableFileAsNull()

	public function testByIdReturnsNullForAFolderOrNothing(): void {
		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->willReturnOnConsecutiveCalls([$this->createMock(Folder::class)], []);
		$this->folders->method('getObjectFolder')->willReturn($folder);
		$this->validation->expects($this->never())->method('checkOwnership');

		$handler = $this->handler();
		$this->assertNull($handler->getFile(new ObjectEntity(), 7));
		$this->assertNull($handler->getFile(new ObjectEntity(), 8));
	}//end testByIdReturnsNullForAFolderOrNothing()

	public function testByNameReturnsAReadableFile(): void {
		$file = $this->file(3);
		$folder = $this->createMock(Folder::class);
		$folder->method('get')->with('a.txt')->willReturn($file);
		$this->folders->method('getObjectFolder')->willReturn($folder);

		$this->assertSame($file, $this->handler()->getFile(new ObjectEntity(), 'a.txt'));
	}//end testByNameReturnsAReadableFile()

	public function testByNameFallsBackToTheFullPath(): void {
		$file = $this->file(3);
		$folder = $this->createMock(Folder::class);
		$folder->method('get')->willReturnCallback(
			static function (string $path) use ($file): Node {
				if ($path === 'sub/a.txt') {
					return $file;
				}

				throw new NotFoundException($path);
			}
		);
		$this->folders->method('getObjectFolder')->willReturn($folder);

		$this->assertSame($file, $this->handler()->getFile(new ObjectEntity(), '/sub/a.txt'));
	}//end testByNameFallsBackToTheFullPath()

	public function testByNameReturnsNullWhenNeitherPathExists(): void {
		$folder = $this->createMock(Folder::class);
		$folder->method('get')->willThrowException(new NotFoundException('gone'));
		$this->folders->method('getObjectFolder')->willReturn($folder);

		$this->assertNull($this->handler()->getFile(new ObjectEntity(), 'sub/a.txt'));
	}//end testByNameReturnsNullWhenNeitherPathExists()

	public function testByNameRefusalPropagates(): void {
		$folder = $this->createMock(Folder::class);
		$folder->method('get')->willReturn($this->file(3));
		$this->folders->method('getObjectFolder')->willReturn($folder);
		$this->refuse();

		$this->expectException(NotPermittedException::class);
		$this->handler()->getFile(new ObjectEntity(), 'a.txt');
	}//end testByNameRefusalPropagates()

	public function testByNameWithoutAFolderIsNull(): void {
		$this->folders->method('getObjectFolder')->willReturn(null);

		$this->assertNull($this->handler()->getFile(new ObjectEntity(), 'a.txt'));
	}//end testByNameWithoutAFolderIsNull()

	public function testAStringObjectIsLookedUpFirst(): void {
		$entity = new ObjectEntity();
		$this->objects->expects($this->once())->method('find')->with('uuid-1')->willReturn($entity);
		$this->folders->expects($this->once())->method('getObjectFolder')->with($entity)->willReturn(null);

		$this->assertNull($this->handler()->getFile('uuid-1', 'a.txt'));
	}//end testAStringObjectIsLookedUpFirst()

	public function testFileByIdReturnsAReadableFile(): void {
		$file = $this->file(9);
		$this->rootFolder->method('getById')->with(9)->willReturn([$file]);

		$this->assertSame($file, $this->handler()->getFileById(9));
	}//end testFileByIdReturnsAReadableFile()

	public function testFileByIdRefusesAnUnreadableFileAsNull(): void {
		$this->rootFolder->method('getById')->willReturn([$this->file(9)]);
		$this->refuse();

		$this->assertNull($this->handler()->getFileById(9));
	}//end testFileByIdRefusesAnUnreadableFileAsNull()

	public function testFileByIdIsNullForAFolderOrNothing(): void {
		$this->rootFolder->method('getById')->willReturnOnConsecutiveCalls([], [$this->createMock(Folder::class)]);
		$this->validation->expects($this->never())->method('checkOwnership');

		$handler = $this->handler();
		$this->assertNull($handler->getFileById(9));
		$this->assertNull($handler->getFileById(9));
	}//end testFileByIdIsNullForAFolderOrNothing()

	public function testFilesOfAStringObjectAreLookedUpWithoutRbacOrTenancy(): void {
		$entity = new ObjectEntity();
		$this->objects->expects($this->once())->method('findAcrossAllSources')
			->with('uuid-1', false, false, false)
			->willReturn(['object' => $entity]);
		$this->fileService->expects($this->once())->method('getFilesForEntity')
			->with($entity, false)
			->willReturn([]);

		$this->assertSame([], $this->handler()->getFiles('uuid-1'));
	}//end testFilesOfAStringObjectAreLookedUpWithoutRbacOrTenancy()

	public function testFilesAreReturnedUnfilteredWithoutACategoryOrMapper(): void {
		$files = [$this->file(1), $this->file(2)];
		$this->fileService->method('getFilesForEntity')->willReturn($files);
		$this->fileMapper->expects($this->never())->method('findByFileIds');

		$this->assertSame($files, $this->handler()->getFiles(new ObjectEntity(), true));
		$this->assertSame($files, $this->handler(false)->getFiles(new ObjectEntity(), false, 'cat'));
	}//end testFilesAreReturnedUnfilteredWithoutACategoryOrMapper()

	public function testFilesAreFilteredByCategory(): void {
		$one = $this->file(1);
		$two = $this->file(2);
		$three = $this->file(3);
		$broken = $this->createMock(File::class);
		$broken->method('getId')->willThrowException(new \RuntimeException('no id'));
		$zero = $this->file(0);
		$this->fileService->method('getFilesForEntity')->willReturn([$one, $two, $broken, $three, $zero, 'not-a-node']);

		$this->fileMapper->expects($this->once())->method('findByFileIds')->with([1, 2, 3])->willReturn(
			[1 => $this->row('cat'), 2 => $this->row('other')]
		);

		$this->assertSame([$one], $this->handler()->getFiles(new ObjectEntity(), false, 'cat'));
	}//end testFilesAreFilteredByCategory()

	public function testCategoryFilterOfNoUsableIdsIsEmpty(): void {
		$this->fileService->method('getFilesForEntity')->willReturnOnConsecutiveCalls([], [$this->file(0)]);
		$this->fileMapper->expects($this->never())->method('findByFileIds');

		$handler = $this->handler();
		$this->assertSame([], $handler->getFiles(new ObjectEntity(), false, 'cat'));
		$this->assertSame([], $handler->getFiles(new ObjectEntity(), false, 'cat'));
	}//end testCategoryFilterOfNoUsableIdsIsEmpty()

	private function row(string $category): object {
		return new class($category) {
			public function __construct(private readonly string $category) {
			}

			public function getCategory(): string {
				return $this->category;
			}
		};
	}//end row()
}//end class
