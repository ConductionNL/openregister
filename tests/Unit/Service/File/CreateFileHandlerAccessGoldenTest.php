<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

/*
 * Golden test: what CreateFileHandler writes and what it refuses.
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

use OCA\OpenRegister\Service\File\CreateFileSources;
use Exception;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Files\Service\File\FileOwnershipHandler;
use OCA\OpenRegister\Service\File\CreateFileHandler;
use OCA\OpenRegister\Service\File\FileValidationHandler;
use OCA\OpenRegister\Service\File\FolderManagementHandler;
use OCA\OpenRegister\Service\FileService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotPermittedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the allowed and refused outcomes of addFile() and saveFile().
 *
 * Refusals: an executable is blocked before any node is created; a node the
 * session cannot read is refused before content is written; an empty name is
 * refused before any node is created. Each refusal is rethrown with the
 * handler's own message and type.
 */
class CreateFileHandlerAccessGoldenTest extends TestCase {

	/** @var FolderManagementHandler&MockObject */
	private $folders;

	/** @var FileValidationHandler&MockObject */
	private $validation;

	/** @var FileOwnershipHandler&MockObject */
	private $ownership;

	/** @var MagicMapperInterface&MockObject */
	private $objects;

	/** @var FileService&MockObject */
	private $fileService;

	/** @var Folder&MockObject */
	private $folder;

	/** @var File&MockObject */
	private $file;

	/** @var list<string> */
	private array $calls = [];

	protected function setUp(): void {
		parent::setUp();
		$this->folders = $this->createMock(FolderManagementHandler::class);
		$this->validation = $this->createMock(FileValidationHandler::class);
		$this->ownership = $this->createMock(FileOwnershipHandler::class);
		$this->objects = $this->createMock(MagicMapperInterface::class);
		$this->fileService = $this->createMock(FileService::class);
		$this->folder = $this->createMock(Folder::class);
		$this->file = $this->createMock(File::class);
		$this->file->method('getId')->willReturn(11);
		$this->file->method('getPath')->willReturn('/or/doc.txt');
		$this->folders->method('getObjectFolder')->willReturn($this->folder);
		$this->fileService->method('generateObjectTag')->willReturn('object:u1');

		$this->validation->method('blockExecutableFile')->willReturnCallback(function (): void {
			$this->calls[] = 'block';
		});
		$this->folder->method('newFile')->willReturnCallback(function (): File {
			$this->calls[] = 'newFile';
			return $this->file;
		});
		$this->file->method('putContent')->willReturnCallback(function (): void {
			$this->calls[] = 'put';
		});
		$this->ownership->method('transferFileOwnershipIfNeeded')->willReturnCallback(function (): void {
			$this->calls[] = 'own';
		});
	}//end setUp()

	private function handler(): CreateFileHandler {
		$handler = new CreateFileHandler(
			new CreateFileSources($this->folders, $this->objects, $this->createMock(LoggerInterface::class)),
			$this->validation,
			$this->ownership
		);
		$handler->setFileService($this->fileService);
		return $handler;
	}//end handler()

	public function testAllowedAddRunsTheChecksBeforeTheWrite(): void {
		$this->validation->method('checkOwnership')->willReturnCallback(function (): void {
			$this->calls[] = 'check';
		});
		$this->fileService->expects($this->once())->method('createShareLink')->with('/or/doc.txt');
		$this->fileService->expects($this->once())->method('attachTagsToFile')->with('11', ['object:u1', 't1']);

		$result = $this->handler()->addFile(new ObjectEntity(), 'doc.txt', 'plain', true, ['t1']);

		$this->assertSame($this->file, $result);
		$this->assertSame(['block', 'newFile', 'check', 'put', 'own'], $this->calls);
	}//end testAllowedAddRunsTheChecksBeforeTheWrite()

	public function testAnExecutableIsRefusedBeforeANodeIsCreated(): void {
		$this->validation = $this->createMock(FileValidationHandler::class);
		$this->validation->method('blockExecutableFile')->willThrowException(new Exception('executable'));
		$this->folder->expects($this->never())->method('newFile');

		try {
			$this->handler()->addFile(new ObjectEntity(), 'evil.exe', "MZ\x90\x00");
			$this->fail('An executable must be refused.');
		} catch (NotPermittedException $e) {
			$this->fail('The refusal is a plain exception.');
		} catch (Exception $e) {
			$this->assertSame('Failed to create file evil.exe: executable', $e->getMessage());
		}
	}//end testAnExecutableIsRefusedBeforeANodeIsCreated()

	public function testAnUnreadableNodeIsRefusedBeforeContentIsWritten(): void {
		$this->validation->method('checkOwnership')->willThrowException(new NotPermittedException('not readable'));
		$this->file->expects($this->never())->method('putContent');
		$this->ownership->expects($this->never())->method('transferFileOwnershipIfNeeded');

		$this->expectException(NotPermittedException::class);
		$this->expectExceptionMessage('Cannot create file doc.txt: not readable');
		$this->handler()->addFile(new ObjectEntity(), 'doc.txt', 'plain');
	}//end testAnUnreadableNodeIsRefusedBeforeContentIsWritten()

	public function testAnEmptyNameIsRefusedBeforeANodeIsCreated(): void {
		$this->folder->expects($this->never())->method('newFile');

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Failed to create file : Failed to create file because no filename has been provided for object ');
		$this->handler()->addFile(new ObjectEntity(), '', 'plain');
	}//end testAnEmptyNameIsRefusedBeforeANodeIsCreated()

	public function testAFolderPermissionRefusalIsRethrown(): void {
		$this->folder = $this->createMock(Folder::class);
		$this->folder->method('newFile')->willThrowException(new NotPermittedException('no create'));
		$this->folders = $this->createMock(FolderManagementHandler::class);
		$this->folders->method('getObjectFolder')->willReturn($this->folder);

		$this->expectException(NotPermittedException::class);
		$this->expectExceptionMessage('Cannot create file doc.txt: no create');
		$this->handler()->addFile(new ObjectEntity(), 'doc.txt', 'plain');
	}//end testAFolderPermissionRefusalIsRethrown()

	public function testContentIsDecodedFromADataUriAndBase64(): void {
		$written = [];
		$this->file = $this->createMock(File::class);
		$this->file->method('putContent')->willReturnCallback(function ($content) use (&$written): void {
			$written[] = $content;
		});
		$this->folder->method('newFile')->willReturn($this->file);

		$handler = $this->handler();
		$handler->addFile(new ObjectEntity(), 'a.txt', 'data:text/plain;base64,' . base64_encode('hello'));
		$handler->addFile(new ObjectEntity(), 'b.txt', base64_encode('world'));
		$handler->addFile(new ObjectEntity(), 'c.txt', 'data:text/plain,raw%20text');

		$this->assertSame(['hello', 'world', 'data:text/plain,raw%20text'], $written);
	}//end testContentIsDecodedFromADataUriAndBase64()

	public function testAStringObjectThatDoesNotExistStillGetsAFile(): void {
		$this->objects->method('find')->willThrowException(new DoesNotExistException('later'));
		$this->folders = $this->createMock(FolderManagementHandler::class);
		$this->folders->expects($this->once())->method('getObjectFolder')
			->with('uuid-later', 5)
			->willReturn($this->folder);

		$this->assertSame($this->file, $this->handler()->addFile('uuid-later', 'doc.txt', 'plain', false, [], null, null, 5));
	}//end testAStringObjectThatDoesNotExistStillGetsAFile()

	public function testSaveUpdatesAnExistingFile(): void {
		$entity = new ObjectEntity();
		$existing = $this->createMock(File::class);
		$existing->method('getId')->willReturn(4);
		$this->fileService->method('getFile')->with($entity, 'doc.txt')->willReturn($existing);
		$this->fileService->expects($this->once())->method('updateFile')
			->with(4, 'new', ['t'], $entity)
			->willReturn($existing);
		$this->folder->expects($this->never())->method('newFile');

		$this->assertSame($existing, $this->handler()->saveFile($entity, 'doc.txt', 'new', true, ['t']));
	}//end testSaveUpdatesAnExistingFile()

	public function testSaveCreatesAMissingFile(): void {
		$this->fileService->method('getFile')->willReturn(null);
		$this->fileService->expects($this->never())->method('updateFile');

		$this->assertSame($this->file, $this->handler()->saveFile(new ObjectEntity(), 'doc.txt', 'new'));
	}//end testSaveCreatesAMissingFile()

	public function testSaveRethrowsARefusalAsItsOwn(): void {
		$existing = $this->createMock(File::class);
		$existing->method('getId')->willReturn(4);
		$this->fileService->method('getFile')->willReturn($existing);
		$this->fileService->method('updateFile')->willThrowException(new NotPermittedException('locked'));

		$this->expectException(NotPermittedException::class);
		$this->expectExceptionMessage('Cannot save file doc.txt: locked');
		$this->handler()->saveFile(new ObjectEntity(), 'doc.txt', 'new');
	}//end testSaveRethrowsARefusalAsItsOwn()

	public function testSaveRethrowsAFailureAsItsOwn(): void {
		$this->fileService->method('getFile')->willThrowException(new Exception('boom'));

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Failed to save file doc.txt: boom');
		$this->handler()->saveFile(new ObjectEntity(), 'doc.txt', 'new');
	}//end testSaveRethrowsAFailureAsItsOwn()
}//end class
