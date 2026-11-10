<?php

/**
 * Moving uploaded files and folders into the OpenRegister system account.
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
use OCA\OpenRegister\Files\Service\File\FileOwnershipHandler;
use OCA\OpenRegister\Files\Service\File\FileSharingHandlerInterface;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Storage\IStorage;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Files\Service\File\UserShareGrant;

/**
 * The system account is created on first use and joined to the app group; a node the caller
 * owns is shared back to them, and every other case leaves the node alone.
 *
 * @covers \OCA\OpenRegister\Files\Service\File\FileOwnershipHandler
 */
final class FileOwnershipHandlerTransferTest extends TestCase {

	private IUserManager&MockObject $users;

	private IGroupManager&MockObject $groups;

	private IUserSession&MockObject $session;

	private LoggerInterface&MockObject $logger;

	private FileSharingHandlerInterface&MockObject $sharing;

	/**
	 * Fresh collaborators per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->users = $this->createMock(IUserManager::class);
		$this->groups = $this->createMock(IGroupManager::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->sharing = $this->createMock(FileSharingHandlerInterface::class);
	}//end setUp()

	/**
	 * The handler under test.
	 *
	 * @return FileOwnershipHandler The handler.
	 */
	private function handler(): FileOwnershipHandler {
		return new FileOwnershipHandler(userManager: $this->users, groupManager: $this->groups, userSession: $this->session, logger: $this->logger);
	}//end handler()

	/**
	 * A user with the given uid.
	 *
	 * @param string $uid The uid.
	 *
	 * @return IUser&MockObject The user.
	 */
	private function user(string $uid): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}//end user()

	/**
	 * Signed in as alice, with the system account existing.
	 *
	 * @return void
	 */
	private function aliceWithSystemAccount(): void {
		$this->session->method('getUser')->willReturn($this->user(uid: 'alice'));
		$this->users->method('get')->with('openregister')->willReturn($this->user(uid: 'openregister'));
	}//end aliceWithSystemAccount()

	/**
	 * A file of the given owner, read-only to the caller.
	 *
	 * @param IUser|null $owner The owner.
	 *
	 * @return File&MockObject The file.
	 */
	private function readOnlyFile(?IUser $owner): File&MockObject {
		$file = $this->createMock(File::class);
		$file->method('isUpdateable')->willReturn(false);
		$file->method('getOwner')->willReturn($owner);
		$file->method('getName')->willReturn('report.pdf');
		$file->method('getStorage')->willReturn($this->createMock(IStorage::class));
		return $file;
	}//end readOnlyFile()

	/**
	 * An existing system account is returned as is.
	 *
	 * @return void
	 */
	public function testAnExistingSystemAccountIsReturned(): void {
		$system = $this->user(uid: 'openregister');
		$this->users->method('get')->with('openregister')->willReturn($system);
		$this->users->expects($this->never())->method('createUser');

		$this->assertSame($system, $this->handler()->getUser());
	}//end testAnExistingSystemAccountIsReturned()

	/**
	 * A missing system account is created without quota, the app group created, and both it and the caller joined.
	 *
	 * @return void
	 */
	public function testAMissingSystemAccountIsCreatedAndGrouped(): void {
		$system = $this->user(uid: 'openregister');
		$caller = $this->user(uid: 'alice');
		$system->expects($this->once())->method('setQuota')->with('none');
		$this->users->method('get')->willReturn(null);
		$this->users->expects($this->once())->method('createUser')
			->with('openregister', $this->matchesRegularExpression('/^[0-9a-f]{32}$/'))->willReturn($system);
		$group = $this->createMock(IGroup::class);
		$added = [];
		$group->method('addUser')->willReturnCallback(static function (IUser $user) use (&$added): void {
			$added[] = $user->getUID();
		});
		$this->groups->method('get')->with('openregister')->willReturn(null);
		$this->groups->expects($this->once())->method('createGroup')->with('openregister')->willReturn($group);
		$this->session->method('getUser')->willReturn($caller);

		$this->assertSame($system, $this->handler()->getUser());
		$this->assertSame(['openregister', 'alice'], $added);
	}//end testAMissingSystemAccountIsCreatedAndGrouped()

	/**
	 * Without a session only the system account joins the existing group.
	 *
	 * @return void
	 */
	public function testWithoutASessionOnlyTheSystemAccountJoins(): void {
		$system = $this->user(uid: 'openregister');
		$this->users->method('get')->willReturn(null);
		$this->users->method('createUser')->willReturn($system);
		$group = $this->createMock(IGroup::class);
		$group->expects($this->once())->method('addUser')->with($system);
		$this->groups->method('get')->willReturn($group);
		$this->groups->expects($this->never())->method('createGroup');
		$this->session->method('getUser')->willReturn(null);

		$this->assertSame($system, $this->handler()->getUser());
	}//end testWithoutASessionOnlyTheSystemAccountJoins()

	/**
	 * When the account cannot be created the handler refuses.
	 *
	 * @return void
	 */
	public function testAFailedAccountCreationThrows(): void {
		$this->users->method('get')->willReturn(null);
		$this->users->method('createUser')->willReturn(false);

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Failed to create OpenRegister user account.');
		$this->handler()->getUser();
	}//end testAFailedAccountCreationThrows()

	/**
	 * A read-only file the caller owns is shared back to the caller.
	 *
	 * @return void
	 */
	public function testAFileTheCallerOwnsIsSharedBack(): void {
		$this->aliceWithSystemAccount();
		$file = $this->readOnlyFile(owner: $this->user(uid: 'alice'));
		$this->sharing->expects($this->once())->method('shareFileWithUser')->with($this->equalTo(new UserShareGrant($file, 'alice')));
		$this->logger->expects($this->exactly(2))->method('info');

		$this->handler()->transferFileOwnershipIfNeeded(file: $file, fileSharingHandler: $this->sharing);
	}//end testAFileTheCallerOwnsIsSharedBack()

	/**
	 * Files that are writable, owned by someone else, ownerless, unauthenticated or already
	 * the system account's are left alone.
	 *
	 * @return void
	 */
	public function testOtherFilesAreLeftAlone(): void {
		$this->sharing->expects($this->never())->method('shareFileWithUser');

		$writable = $this->createMock(File::class);
		$writable->method('isUpdateable')->willReturn(true);
		$writable->expects($this->never())->method('getOwner');
		$this->handler()->transferFileOwnershipIfNeeded(file: $writable, fileSharingHandler: $this->sharing);

		$this->aliceWithSystemAccount();
		$this->handler()->transferFileOwnershipIfNeeded(file: $this->readOnlyFile(owner: $this->user(uid: 'bob')), fileSharingHandler: $this->sharing);

		$this->logger->expects($this->once())->method('warning')->with('[FileOwnershipHandler] File report.pdf has no owner, skipping ownership transfer');
		$this->handler()->transferFileOwnershipIfNeeded(file: $this->readOnlyFile(owner: null), fileSharingHandler: $this->sharing);
	}//end testOtherFilesAreLeftAlone()

	/**
	 * Signed out, or signed in as the system account itself, nothing is transferred.
	 *
	 * @return void
	 */
	public function testNoTransferWithoutCallerOrAsTheSystemAccount(): void {
		$this->sharing->expects($this->never())->method('shareFileWithUser');
		$this->sharing->expects($this->never())->method('shareFolderWithUser');
		$this->users->method('get')->willReturn($this->user(uid: 'openregister'));
		$this->session->method('getUser')->willReturnOnConsecutiveCalls(null, $this->user(uid: 'openregister'), null, $this->user(uid: 'openregister'));
		$file = $this->readOnlyFile(owner: $this->user(uid: 'openregister'));
		$folder = $this->createMock(Folder::class);
		$folder->expects($this->never())->method('getOwner');

		$this->handler()->transferFileOwnershipIfNeeded(file: $file, fileSharingHandler: $this->sharing);
		$this->handler()->transferFileOwnershipIfNeeded(file: $file, fileSharingHandler: $this->sharing);
		$this->handler()->transferFolderOwnershipIfNeeded(folder: $folder, fileSharingHandler: $this->sharing);
		$this->handler()->transferFolderOwnershipIfNeeded(folder: $folder, fileSharingHandler: $this->sharing);
	}//end testNoTransferWithoutCallerOrAsTheSystemAccount()

	/**
	 * A failure during a file transfer is logged as an error, not thrown.
	 *
	 * @return void
	 */
	public function testAFailingFileTransferIsLogged(): void {
		$this->aliceWithSystemAccount();
		$file = $this->readOnlyFile(owner: $this->user(uid: 'alice'));
		$this->sharing->method('shareFileWithUser')->willThrowException(new Exception('share refused'));
		$this->logger->expects($this->once())->method('error')
			->with('[FileOwnershipHandler] Failed to transfer file ownership for report.pdf: share refused');

		$this->handler()->transferFileOwnershipIfNeeded(file: $file, fileSharingHandler: $this->sharing);
	}//end testAFailingFileTransferIsLogged()

	/**
	 * A folder the caller owns is shared back; without a sharing handler it is only logged.
	 *
	 * @return void
	 */
	public function testAFolderTheCallerOwnsIsSharedBack(): void {
		$this->aliceWithSystemAccount();
		$folder = $this->createMock(Folder::class);
		$folder->method('getOwner')->willReturn($this->user(uid: 'alice'));
		$folder->method('getName')->willReturn('case-1');
		$folder->method('getStorage')->willReturn($this->createMock(IStorage::class));
		$this->sharing->expects($this->once())->method('shareFolderWithUser')->with($this->equalTo(new UserShareGrant($folder, 'alice')));

		$this->handler()->transferFolderOwnershipIfNeeded(folder: $folder, fileSharingHandler: $this->sharing);
		$this->handler()->transferFolderOwnershipIfNeeded(folder: $folder);
	}//end testAFolderTheCallerOwnsIsSharedBack()

	/**
	 * Folders owned by someone else or by nobody are left alone; a failure is logged.
	 *
	 * @return void
	 */
	public function testOtherFoldersAreLeftAloneAndFailuresLogged(): void {
		$this->aliceWithSystemAccount();
		$this->sharing->expects($this->never())->method('shareFolderWithUser');
		$bobs = $this->createMock(Folder::class);
		$bobs->method('getOwner')->willReturn($this->user(uid: 'bob'));
		$ownerless = $this->createMock(Folder::class);
		$ownerless->method('getOwner')->willReturn(null);
		$ownerless->method('getName')->willReturn('orphan');
		$broken = $this->createMock(Folder::class);
		$broken->method('getOwner')->willThrowException(new Exception('storage gone'));
		$broken->method('getName')->willReturn('broken');
		$this->logger->expects($this->once())->method('warning')->with('[FileOwnershipHandler] Folder orphan has no owner, skipping ownership transfer');
		$this->logger->expects($this->once())->method('error')->with('[FileOwnershipHandler] Failed to transfer folder ownership for broken: storage gone');

		$this->handler()->transferFolderOwnershipIfNeeded(folder: $bobs, fileSharingHandler: $this->sharing);
		$this->handler()->transferFolderOwnershipIfNeeded(folder: $ownerless, fileSharingHandler: $this->sharing);
		$this->handler()->transferFolderOwnershipIfNeeded(folder: $broken, fileSharingHandler: $this->sharing);
	}//end testOtherFoldersAreLeftAloneAndFailuresLogged()
}//end class
