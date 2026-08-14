<?php

declare(strict_types=1);

/*
 * DeleteFileHandler Unit Tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 * @author   OpenRegister Team
 * @license  EUPL-1.2
 * @link     https://github.com/OpenRegister/OpenRegister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\File\DeleteFileHandler;
use OCA\OpenRegister\Service\File\FileLockHandler;
use OCA\OpenRegister\Service\File\FileOwnershipHandler;
use OCA\OpenRegister\Service\File\FileValidationHandler;
use OCA\OpenRegister\Service\File\ReadFileHandler;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotPermittedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for DeleteFileHandler.
 *
 * Covers the per-action delete-permission gate: a readable file the session
 * may not delete MUST be refused with NotPermittedException before delete().
 */
class DeleteFileHandlerTest extends TestCase {

	/**
	 * @var DeleteFileHandler
	 */
	private DeleteFileHandler $handler;

	/**
	 * @var IRootFolder&MockObject
	 */
	private $rootFolder;

	/**
	 * @var ReadFileHandler&MockObject
	 */
	private $readFileHandler;

	/**
	 * @var FileValidationHandler&MockObject
	 */
	private $fileValidHandler;

	/**
	 * @var FileOwnershipHandler&MockObject
	 */
	private $fileOwnershipHandler;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private $logger;

	/**
	 * @var FileLockHandler&MockObject
	 */
	private $fileLockHandler;

	protected function setUp(): void {
		parent::setUp();

		// IRootFolder extends OC\Hooks\Emitter, which is only present when the
		// Nextcloud server source tree is on the include path (Docker / CI).
		// Skip cleanly in a bare composer-autoload context (local worktree).
		if (interface_exists('OC\\Hooks\\Emitter') === false) {
			$this->markTestSkipped('Nextcloud server classes unavailable; run in the Docker test environment.');
		}

		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->readFileHandler = $this->createMock(ReadFileHandler::class);
		$this->fileValidHandler = $this->createMock(FileValidationHandler::class);
		$this->fileOwnershipHandler = $this->createMock(FileOwnershipHandler::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->fileLockHandler = $this->createMock(FileLockHandler::class);

		$this->handler = new DeleteFileHandler(
			$this->rootFolder,
			$this->readFileHandler,
			$this->fileValidHandler,
			$this->fileOwnershipHandler,
			$this->logger,
			$this->fileLockHandler
		);
	}//end setUp()

	// =========================================================================
	// deleteFile - delete-permission gate
	// =========================================================================

	public function testDeleteRefusedWithoutDeletePermission(): void {
		// A readable file the session may not delete must be refused before any
		// delete() call. checkOwnership() (readability) passes; isDeletable() fails.
		// Resolve by id (avoids the `(string) $file` cast on a non-Stringable mock).
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(42);
		$file->method('getName')->willReturn('test.pdf');
		$file->method('isDeletable')->willReturn(false);
		$file->expects($this->never())->method('delete');

		$this->readFileHandler->method('getFile')->willReturn($file);

		$this->expectException(NotPermittedException::class);
		$this->expectExceptionMessage('is not deletable');

		$this->handler->deleteFile(file: 42, object: $this->createMock(ObjectEntity::class));
	}//end testDeleteRefusedWithoutDeletePermission()
}//end class
