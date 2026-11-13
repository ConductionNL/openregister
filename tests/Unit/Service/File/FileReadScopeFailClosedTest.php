<?php

/**
 * File search hits and file reads fail closed when a lookup cannot answer.
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-files-follow-object-access/specs/file-actions/spec.md#requirement-reading-an-objects-files-follows-the-objects-read-rule-req-ofoa-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use OCA\OpenRegister\Files\Service\File\FileReadScope;
use OCA\OpenRegister\Files\Service\File\FileReadScopeRules;
use OCA\OpenRegister\Files\Service\File\FileReadScopeTree;
use OCA\OpenRegister\Files\Service\File\ObjectFileAccess;
use OCA\OpenRegister\Service\FileReaderInterface;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotPermittedException;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A caller without a file tree, a failing lookup and an orphaned object file all read as unreadable.
 *
 * @covers \OCA\OpenRegister\Files\Service\File\FileReadScope
 * @uses   \OCA\OpenRegister\Files\Service\File\FileReadScopeTree
 * @uses   \OCA\OpenRegister\Files\Service\File\FileReadScopeRules
 */
class FileReadScopeFailClosedTest extends TestCase {

	/**
	 * The caller's own file tree.
	 *
	 * @var Folder&MockObject
	 */
	private Folder&MockObject $userFolder;

	/**
	 * The Nextcloud root folder.
	 *
	 * @var IRootFolder&MockObject
	 */
	private IRootFolder&MockObject $root;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->userFolder = $this->createMock(Folder::class);
		$this->root       = $this->createMock(IRootFolder::class);
		$this->logger     = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * A scope for a signed-in caller.
	 *
	 * @param FileReaderInterface|null $files  The object file lookup.
	 * @param ObjectFileAccess|null    $access The object read rule.
	 *
	 * @return FileReadScope The scope.
	 */
	private function scope(?FileReaderInterface $files = null, ?ObjectFileAccess $access = null): FileReadScope {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('ana');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new FileReadScope(
			tree: new FileReadScopeTree(rootFolder: $this->root, userSession: $session),
			rules: new FileReadScopeRules(objectFileAccess: $access),
			logger: $this->logger,
			fileService: $files
		);
	}//end scope()

	public function testACallerWithoutAFileTreeSeesNoHits(): void {
		$this->root->method('getUserFolder')->with('ana')->willThrowException(new NotPermittedException('no home'));
		$this->logger->expects($this->once())
			->method('debug')
			->with('[FileReadScope] No file tree for the caller, nothing is readable', $this->callback(static fn (array $c): bool => $c['error'] === 'no home'));

		$hits = $this->scope()->readableResults([['entity_type' => 'file', 'entity_id' => 1]]);

		$this->assertSame([], $hits);
	}//end testACallerWithoutAFileTreeSeesNoHits()

	public function testACallerWithoutAFileTreeMayNotReadAFile(): void {
		$this->root->method('getUserFolder')->willThrowException(new NotPermittedException('no home'));

		$this->assertFalse($this->scope()->mayReadFile(1));
	}//end testACallerWithoutAFileTreeMayNotReadAFile()

	public function testOnlyFileHitsWithANumericIdAreKeptAndEachFileIsLookedUpOnce(): void {
		$this->root->method('getUserFolder')->willReturn($this->userFolder);
		$this->userFolder->expects($this->exactly(2))
			->method('getFirstNodeById')
			->willReturnCallback(fn (int $id) => $id === 5 ? $this->createMock(File::class) : null);

		$hits = [
			['entity_type' => 'file', 'entity_id' => '5', 'n' => 1],
			['entity_type' => 'object', 'entity_id' => 5, 'n' => 2],
			['entity_type' => 'file', 'entity_id' => 'abc', 'n' => 3],
			['entity_type' => 'file', 'entity_id' => 6, 'n' => 4],
			['entity_type' => 'file', 'entity_id' => 5, 'n' => 5],
			['entity_type' => 'file', 'n' => 6],
		];

		$this->assertSame([1, 5], array_column($this->scope()->readableResults($hits), 'n'));
	}//end testOnlyFileHitsWithANumericIdAreKeptAndEachFileIsLookedUpOnce()

	public function testAFailingLookupMakesTheFileUnreadable(): void {
		$this->root->method('getUserFolder')->willReturn($this->userFolder);
		$this->userFolder->method('getFirstNodeById')->willThrowException(new RuntimeException('storage offline'));
		$this->logger->expects($this->once())
			->method('debug')
			->with(
				'[FileReadScope] File lookup failed, treating the file as unreadable',
				$this->callback(static fn (array $c): bool => $c['fileId'] === 9 && $c['error'] === 'storage offline')
			);

		$this->assertFalse($this->scope()->mayReadFile(9));
	}//end testAFailingLookupMakesTheFileUnreadable()

	public function testAnObjectFileWhoseObjectIsGoneIsUnreadable(): void {
		$this->root->method('getUserFolder')->willReturn($this->userFolder);
		$this->userFolder->expects($this->never())->method('getFirstNodeById');

		$file  = $this->createMock(File::class);
		$files = $this->createMock(FileReaderInterface::class);
		$files->method('getFileById')->with(12)->willReturn($file);
		$files->method('isManagedFile')->with($file)->willReturn(true);
		$files->method('findObjectForFile')->with($file)->willReturn(null);

		$access = $this->createMock(ObjectFileAccess::class);
		$access->expects($this->never())->method('mayRead');

		$this->assertFalse($this->scope(files: $files, access: $access)->mayReadFile(12));
	}//end testAnObjectFileWhoseObjectIsGoneIsUnreadable()

	public function testAFileOutsideTheObjectStoreIsAnsweredByTheCallersOwnTree(): void {
		$this->root->method('getUserFolder')->willReturn($this->userFolder);
		$this->userFolder->expects($this->once())->method('getFirstNodeById')->with(13)->willReturn($this->createMock(File::class));

		$file  = $this->createMock(File::class);
		$files = $this->createMock(FileReaderInterface::class);
		$files->method('getFileById')->willReturn($file);
		$files->method('isManagedFile')->willReturn(false);
		$files->expects($this->never())->method('findObjectForFile');

		$this->assertTrue($this->scope(files: $files, access: $this->createMock(ObjectFileAccess::class))->mayReadFile(13));
	}//end testAFileOutsideTheObjectStoreIsAnsweredByTheCallersOwnTree()
}//end class
