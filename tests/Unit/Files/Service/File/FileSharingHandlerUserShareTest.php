<?php

/**
 * Creating user shares for files and folders, and what happens when that fails.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Files\Service\File
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Files\Service\File;

use Exception;
use OCA\OpenRegister\Files\Service\File\FileOwnershipHandlerInterface;
use OCA\OpenRegister\Files\Service\File\FileSharingAccounts;
use OCA\OpenRegister\Files\Service\File\FileSharingHandler;
use OCA\OpenRegister\Files\Service\File\ShareUrlBuilder;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Share\IManager;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Files\Service\File\UserShareGrant;

/**
 * A file is shared once per user; a folder only with an existing user; failures are
 * re-thrown for files and swallowed into null for folders.
 *
 * @covers \OCA\OpenRegister\Files\Service\File\FileSharingHandler
 * @covers \OCA\OpenRegister\Files\Service\File\FileSharingAccounts
 */
final class FileSharingHandlerUserShareTest extends TestCase {

	private IManager&MockObject $shares;

	private IUserManager&MockObject $users;

	private LoggerInterface&MockObject $logger;

	private FileSharingHandler $handler;

	/**
	 * The system account is "openregister".
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->shares = $this->createMock(IManager::class);
		$this->users = $this->createMock(IUserManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$system = $this->createMock(IUser::class);
		$system->method('getUID')->willReturn('openregister');
		$ownership = $this->createMock(FileOwnershipHandlerInterface::class);
		$ownership->method('getUser')->willReturn($system);
		$this->handler = new FileSharingHandler(
			shareManager: $this->shares,
			accounts: new FileSharingAccounts(userManager: $this->users, fileOwnershipHandler: $ownership),
			shareUrls: $this->createMock(ShareUrlBuilder::class),
			logger: $this->logger
		);
	}//end setUp()

	/**
	 * A share whose sharedWith is the given user.
	 *
	 * @param string $userId The user.
	 *
	 * @return IShare The share.
	 */
	private function shareWith(string $userId): IShare {
		$share = $this->createMock(IShare::class);
		$share->method('getSharedWith')->willReturn($userId);
		return $share;
	}//end shareWith()

	/**
	 * A file already shared with the user is not shared again.
	 *
	 * @return void
	 */
	public function testAFileAlreadySharedWithTheUserIsNotSharedAgain(): void {
		$file = $this->createMock(File::class);
		$this->shares->expects($this->once())->method('getSharesBy')
			->with('openregister', IShare::TYPE_USER, $file)
			->willReturn([$this->shareWith(userId: 'bob'), $this->shareWith(userId: 'alice')]);
		$this->shares->expects($this->never())->method('newShare');

		$this->handler->shareFileWithUser(grant: new UserShareGrant(node: $file, userId: 'alice'));
	}//end testAFileAlreadySharedWithTheUserIsNotSharedAgain()

	/**
	 * A file share that cannot be created is logged and re-thrown.
	 *
	 * @return void
	 */
	public function testAFailedFileShareIsLoggedAndRethrown(): void {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('a.pdf');
		$this->shares->method('getSharesBy')->willReturn([]);
		$this->shares->method('newShare')->willReturn($this->createMock(IShare::class));
		$failure = new Exception('quota');
		$this->shares->method('createShare')->willThrowException($failure);
		$this->logger->expects($this->once())->method('error')->with('[FileSharingHandler] Failed to share file a.pdf with user alice: quota');

		$this->expectExceptionObject($failure);
		$this->handler->shareFileWithUser(grant: new UserShareGrant(node: $file, userId: 'alice'));
	}//end testAFailedFileShareIsLoggedAndRethrown()

	/**
	 * A folder is shared by node id as a folder, by the system account, with the given permissions.
	 *
	 * @return void
	 */
	public function testAFolderIsSharedByNodeIdWithAnExistingUser(): void {
		$folder = $this->createMock(Folder::class);
		$folder->method('getPath')->willReturn('/openregister/files/case-1');
		$folder->method('getId')->willReturn(77);
		$this->users->method('userExists')->with('alice')->willReturn(true);
		$share = $this->createMock(IShare::class);
		$share->expects($this->once())->method('setNodeId')->with(77);
		$share->expects($this->once())->method('setNodeType')->with('folder');
		$share->expects($this->once())->method('setShareType')->with(IShare::TYPE_USER);
		$share->expects($this->once())->method('setPermissions')->with(1);
		$share->expects($this->once())->method('setSharedBy')->with('openregister');
		$share->expects($this->once())->method('setSharedWith')->with('alice');
		$share->expects($this->never())->method('setNode');
		$this->shares->method('newShare')->willReturn($share);
		$this->shares->expects($this->once())->method('createShare')->with($share);

		$this->assertSame($share, $this->handler->shareFolderWithUser(grant: new UserShareGrant(node: $folder, userId: 'alice', permissions: 1)));
	}//end testAFolderIsSharedByNodeIdWithAnExistingUser()

	/**
	 * A folder is never shared with a user that does not exist.
	 *
	 * @return void
	 */
	public function testAFolderIsNotSharedWithAnUnknownUser(): void {
		$this->users->method('userExists')->willReturn(false);
		$this->shares->expects($this->never())->method('newShare');
		$this->logger->expects($this->once())->method('warning');

		$this->assertNull($this->handler->shareFolderWithUser(grant: new UserShareGrant(node: $this->createMock(Folder::class), userId: 'ghost')));
	}//end testAFolderIsNotSharedWithAnUnknownUser()

	/**
	 * A failing folder share is logged twice (create, then folder) and returns null.
	 *
	 * @return void
	 */
	public function testAFailedFolderShareIsNull(): void {
		$folder = $this->createMock(Folder::class);
		$folder->method('getPath')->willReturn('/x');
		$folder->method('getName')->willReturn('x');
		$this->users->method('userExists')->willReturn(true);
		$this->shares->method('newShare')->willReturn($this->createMock(IShare::class));
		$this->shares->method('createShare')->willThrowException(new Exception('denied'));
		$errors = [];
		$this->logger->method('error')->willReturnCallback(static function (string $message) use (&$errors): void {
			$errors[] = $message;
		});

		$this->assertNull($this->handler->shareFolderWithUser(grant: new UserShareGrant(node: $folder, userId: 'alice')));
		$this->assertSame(
			[
				'[FileSharingHandler] Failed to create share for x by user openregister: denied',
				"[FileSharingHandler] Failed to share folder 'x' with user 'alice': Failed to create share: denied",
			],
			$errors
		);
	}//end testAFailedFolderShareIsNull()
}//end class
