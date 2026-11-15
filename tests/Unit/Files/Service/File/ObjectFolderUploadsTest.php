<?php

/**
 * ObjectFolderUploadsTest
 *
 * Pins how ObjectFolderUploads::uploadAll() stores a batch: each file on its
 * own, refusals named per file, and a path that is wrong for the whole batch
 * thrown.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Files\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Files\Service\File;

use Exception;
use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Files\Service\File\ObjectFolderBrowser;
use OCA\OpenRegister\Files\Service\File\ObjectFolderPath;
use OCA\OpenRegister\Files\Service\File\ObjectFolderUploads;
use OCA\OpenRegister\Service\File\FileTarget;
use OCA\OpenRegister\Service\File\FileUpload;
use OCA\OpenRegister\Service\FileServiceInterface;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Batch uploads into one object's folder.
 *
 * @covers \OCA\OpenRegister\Files\Service\File\ObjectFolderUploads
 * @uses   \OCA\OpenRegister\Files\Service\File\ObjectFolderBrowser
 * @uses   \OCA\OpenRegister\Files\Service\File\ObjectFolderPath
 * @uses   \OCA\OpenRegister\Files\Service\File\FolderUpload
 * @uses   \OCA\OpenRegister\Service\File\FileTarget
 * @uses   \OCA\OpenRegister\Service\File\FileUpload
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 */
final class ObjectFolderUploadsTest extends TestCase {

	private const ROOT = '/admin/files/Open Registers/obj';

	/**
	 * The file service the real browser writes through.
	 *
	 * @var FileServiceInterface&MockObject
	 */
	private FileServiceInterface&MockObject $fileService;

	/**
	 * The object folder.
	 *
	 * @var Folder&MockObject
	 */
	private Folder&MockObject $root;

	/**
	 * Temp files to remove.
	 *
	 * @var list<string>
	 */
	private array $tmp = [];

	/**
	 * What addFile() received, as [fileName, bytes].
	 *
	 * @var list<array{0: string, 1: string}>
	 */
	private array $added = [];

	protected function setUp(): void {
		$this->root = $this->createMock(Folder::class);
		$this->root->method('getPath')->willReturn(self::ROOT);
		$this->root->method('nodeExists')->willReturnCallback(static fn (string $name): bool => $name === 'taken.pdf');

		$this->fileService = $this->createMock(FileServiceInterface::class);
		$this->fileService->method('getObjectFolder')->willReturn($this->root);
	}//end setUp()

	protected function tearDown(): void {
		foreach ($this->tmp as $path) {
			if (is_file($path) === true) {
				unlink($path);
			}
		}
	}//end tearDown()

	/**
	 * An upload as PHP hands it over, backed by a real temp file.
	 *
	 * @param string $name  The client file name.
	 * @param string $bytes The content.
	 * @param int    $error The PHP upload error code.
	 *
	 * @return array{name: string, tmp_name: string, error: int}
	 */
	private function upload(string $name, string $bytes = 'data', int $error = UPLOAD_ERR_OK): array {
		$path = tempnam(sys_get_temp_dir(), 'ofu');
		file_put_contents($path, $bytes);
		$this->tmp[] = $path;
		return ['name' => $name, 'tmp_name' => $path, 'error' => $error];
	}//end upload()

	/**
	 * The pipeline stores each file as a node at ROOT/<fileName>, unless it is refused.
	 *
	 * @param string|null $refuse A file name the pipeline throws on.
	 *
	 * @return ObjectFolderUploads
	 */
	private function uploads(?string $refuse = null): ObjectFolderUploads {
		$this->fileService->method('addFile')->willReturnCallback(
			function (FileTarget $target, FileUpload $upload) use ($refuse): File {
				$this->added[] = [$upload->fileName, stream_get_contents($upload->content)];
				if ($upload->fileName === $refuse) {
					throw new Exception('Executables are not allowed');
				}

				$file = $this->createMock(File::class);
				$file->method('getId')->willReturn(40 + count($this->added));
				$file->method('getName')->willReturn(basename($upload->fileName));
				$file->method('getMimetype')->willReturn('application/pdf');
				$file->method('getSize')->willReturn(4);
				$file->method('getMTime')->willReturn(1700000000);
				$file->method('getPath')->willReturn(self::ROOT.'/'.$upload->fileName);
				return $file;
			}
		);

		return new ObjectFolderUploads(new ObjectFolderBrowser($this->fileService));
	}//end uploads()

	public function testEachFileIsStoredOrRejectedOnItsOwn(): void {
		$uploads = $this->uploads(refuse: 'tool.exe');

		$outcome = $uploads->uploadAll(
			new ObjectFolderPath(new ObjectEntity(), ''),
			[
				$this->upload('a.pdf', 'AAAA'),
				$this->upload('taken.pdf'),
				$this->upload('tool.exe'),
				$this->upload('bad:name.pdf'),
				$this->upload('broken.pdf', 'x', UPLOAD_ERR_PARTIAL),
				$this->upload('b.pdf', 'BBBB'),
			]
		);

		self::assertSame(
			[
				['id' => 41, 'name' => 'a.pdf', 'type' => 'file', 'mimetype' => 'application/pdf', 'size' => 4, 'mtime' => 1700000000, 'path' => 'a.pdf'],
				['id' => 43, 'name' => 'b.pdf', 'type' => 'file', 'mimetype' => 'application/pdf', 'size' => 4, 'mtime' => 1700000000, 'path' => 'b.pdf'],
			],
			$outcome['stored']
		);
		self::assertSame(
			[
				['name' => 'taken.pdf', 'error' => 'A file or folder with that name is already here', 'conflict' => true],
				['name' => 'tool.exe', 'error' => 'Executables are not allowed', 'conflict' => false],
				['name' => 'bad:name.pdf', 'error' => 'A name cannot contain / \\ : * ? " < > |', 'conflict' => false],
				['name' => 'broken.pdf', 'error' => 'The upload failed', 'conflict' => false],
			],
			$outcome['rejected']
		);
		// The bytes reach the pipeline as sent; refused names never get there.
		self::assertSame([['a.pdf', 'AAAA'], ['tool.exe', 'data'], ['b.pdf', 'BBBB']], $this->added);
	}//end testEachFileIsStoredOrRejectedOnItsOwn()

	public function testAnUnreadableTempFileIsRejected(): void {
		$uploads = $this->uploads();

		$outcome = $uploads->uploadAll(
			new ObjectFolderPath(new ObjectEntity(), ''),
			[['name' => 'gone.pdf', 'tmp_name' => sys_get_temp_dir().'/ofu-missing-'.uniqid(), 'error' => UPLOAD_ERR_OK]]
		);

		self::assertSame(['stored' => [], 'rejected' => [['name' => 'gone.pdf', 'error' => 'The upload failed', 'conflict' => false]]], $outcome);
		self::assertSame([], $this->added);
	}//end testAnUnreadableTempFileIsRejected()

	public function testAFileInASubfolderIsNamedByItsPathFromTheObjectFolder(): void {
		$sub = $this->createMock(Folder::class);
		$sub->method('getPath')->willReturn(self::ROOT.'/docs/2026');
		$sub->method('nodeExists')->willReturn(false);
		$this->root->expects(self::once())->method('get')->with('docs/2026')->willReturn($sub);
		$uploads = $this->uploads();

		$outcome = $uploads->uploadAll(new ObjectFolderPath(new ObjectEntity(), '/docs/2026/'), [$this->upload('c.pdf', 'CCCC')]);

		self::assertSame('docs/2026/c.pdf', $outcome['stored'][0]['path']);
		self::assertSame([], $outcome['rejected']);
		self::assertSame([['docs/2026/c.pdf', 'CCCC']], $this->added);
	}//end testAFileInASubfolderIsNamedByItsPathFromTheObjectFolder()

	public function testAPathLeavingTheObjectFolderStopsTheWholeBatch(): void {
		$uploads = $this->uploads();

		try {
			$uploads->uploadAll(new ObjectFolderPath(new ObjectEntity(), 'docs/../..'), [$this->upload('a.pdf'), $this->upload('b.pdf')]);
			self::fail('A path leaving the object folder must be thrown');
		} catch (InvalidArgumentException $e) {
			self::assertSame(ObjectFolderBrowser::BAD_PATH, $e->getCode());
			self::assertSame('The path is not a folder of this object', $e->getMessage());
		}

		self::assertSame([], $this->added);
	}//end testAPathLeavingTheObjectFolderStopsTheWholeBatch()

	public function testAPathThatIsNoFolderOfTheObjectStopsTheWholeBatch(): void {
		$this->root->method('get')->willReturn($this->createMock(File::class));
		$uploads = $this->uploads();

		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('Not a folder');

		$uploads->uploadAll(new ObjectFolderPath(new ObjectEntity(), 'readme.txt'), [$this->upload('a.pdf')]);
	}//end testAPathThatIsNoFolderOfTheObjectStopsTheWholeBatch()
}//end class
