<?php

/**
 * FileOwnershipTransferGoldenTest
 *
 * Pins the exact sequence of a file and a folder ownership transfer: the log
 * lines, the chown on the node's storage and the share back to the caller,
 * plus the cases that are refused (no transfer) and the failure log.
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

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Files\Service\File;

use Exception;
use OCA\OpenRegister\Files\Service\File\FileOwnershipHandler;
use OCA\OpenRegister\Files\Service\File\FileSharingHandlerInterface;
use OCA\OpenRegister\Files\Service\File\UserShareGrant;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden event sequences of FileOwnershipHandler's file and folder transfers.
 */
final class FileOwnershipTransferGoldenTest extends TestCase {

	/**
	 * Every log line, chown and share, in order.
	 *
	 * @var list<array>
	 */
	private array $events = [];

	/**
	 * The uid the session is signed in as; null when signed out.
	 *
	 * @var string|null
	 */
	private ?string $sessionUid = 'alice';

	/**
	 * The handler under test, over recording fakes.
	 *
	 * @return FileOwnershipHandler
	 */
	private function handler(): FileOwnershipHandler {
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($this->user(uid: 'openregister'));
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(
			fn (): ?IUser => $this->sessionUid === null ? null : $this->user(uid: $this->sessionUid)
		);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message) use ($level): void {
					$this->events[] = [$level, $message];
				}
			);
		}

		return new FileOwnershipHandler(
			userManager: $users,
			groupManager: $this->createMock(IGroupManager::class),
			userSession: $session,
			logger: $logger
		);
	}//end handler()

	/**
	 * A user with the given uid.
	 *
	 * @param string $uid The uid.
	 *
	 * @return IUser
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}//end user()

	/**
	 * A storage that records chown calls.
	 *
	 * @return object
	 */
	private function storage(): object {
		$events = &$this->events;
		return new class($events) {
			/**
			 * Recorded events.
			 *
			 * @var array
			 */
			private array $events;

			/**
			 * Constructor.
			 *
			 * @param array $events The shared event list.
			 */
			public function __construct(array &$events) {
				$this->events = &$events;
			}

			/**
			 * Record a chown.
			 *
			 * @param string $path The internal path.
			 * @param string $uid  The new owner.
			 *
			 * @return void
			 */
			public function chown(string $path, string $uid): void {
				$this->events[] = ['chown', $path, $uid];
			}
		};
	}//end storage()

	/**
	 * A sharing handler that records its calls.
	 *
	 * @return FileSharingHandlerInterface
	 */
	private function sharing(): FileSharingHandlerInterface {
		$sharing = $this->createMock(FileSharingHandlerInterface::class);
		$sharing->method('shareFileWithUser')->willReturnCallback(
			function (UserShareGrant $grant) {
				$this->events[] = ['shareFile', $grant->node->getName(), $grant->userId];
				return null;
			}
		);
		$sharing->method('shareFolderWithUser')->willReturnCallback(
			function (UserShareGrant $grant) {
				$this->events[] = ['shareFolder', $grant->node->getName(), $grant->userId];
				return null;
			}
		);
		return $sharing;
	}//end sharing()

	/**
	 * A read-only file.
	 *
	 * @param string|null $ownerUid The owner, null for none.
	 *
	 * @return File
	 */
	private function file(?string $ownerUid): File {
		$file = $this->createMock(File::class);
		$file->method('isUpdateable')->willReturn(false);
		$file->method('getOwner')->willReturn($ownerUid === null ? null : $this->user(uid: $ownerUid));
		$file->method('getName')->willReturn('report.pdf');
		$file->method('getInternalPath')->willReturn('files/report.pdf');
		$file->method('getStorage')->willReturn($this->storage());
		return $file;
	}//end file()

	/**
	 * A folder.
	 *
	 * @param string|null $ownerUid The owner, null for none.
	 *
	 * @return Folder
	 */
	private function folder(?string $ownerUid): Folder {
		$folder = $this->createMock(Folder::class);
		$folder->method('getOwner')->willReturn($ownerUid === null ? null : $this->user(uid: $ownerUid));
		$folder->method('getName')->willReturn('case-1');
		$folder->method('getInternalPath')->willReturn('files/case-1');
		$folder->method('getStorage')->willReturn($this->storage());
		return $folder;
	}//end folder()

	/**
	 * A file the caller owns: logged, chowned to the system account, shared back, logged.
	 *
	 * @return void
	 */
	public function testFileTransfer(): void {
		$this->handler()->transferFileOwnershipIfNeeded(file: $this->file(ownerUid: 'alice'), fileSharingHandler: $this->sharing());
		$this->handler()->transferFileOwnershipIfNeeded(file: $this->file(ownerUid: 'alice'));

		$transfer = [
			['info', '[FileOwnershipHandler] Transferring file report.pdf from alice to openregister'],
			['chown', 'files/report.pdf', 'openregister'],
		];
		$done = ['info', '[FileOwnershipHandler] Successfully transferred and shared file report.pdf'];
		self::assertSame(
			[...$transfer, ['shareFile', 'report.pdf', 'alice'], $done, ...$transfer, $done],
			$this->events
		);
	}//end testFileTransfer()

	/**
	 * A folder the caller owns: logged, chowned to the system account, shared back, logged.
	 *
	 * @return void
	 */
	public function testFolderTransfer(): void {
		$this->handler()->transferFolderOwnershipIfNeeded(folder: $this->folder(ownerUid: 'alice'), fileSharingHandler: $this->sharing());
		$this->handler()->transferFolderOwnershipIfNeeded(folder: $this->folder(ownerUid: 'alice'));

		$transfer = [
			['info', '[FileOwnershipHandler] Transferring folder case-1 from alice to openregister'],
			['chown', 'files/case-1', 'openregister'],
		];
		$done = ['info', '[FileOwnershipHandler] Successfully transferred and shared folder case-1'];
		self::assertSame(
			[...$transfer, ['shareFolder', 'case-1', 'alice'], $done, ...$transfer, $done],
			$this->events
		);
	}//end testFolderTransfer()

	/**
	 * Refused: another owner, no owner, signed out, signed in as the system account.
	 *
	 * @return void
	 */
	public function testRefusedTransfers(): void {
		$handler = $this->handler();
		$handler->transferFileOwnershipIfNeeded(file: $this->file(ownerUid: 'bob'), fileSharingHandler: $this->sharing());
		$handler->transferFolderOwnershipIfNeeded(folder: $this->folder(ownerUid: 'bob'), fileSharingHandler: $this->sharing());
		$handler->transferFileOwnershipIfNeeded(file: $this->file(ownerUid: null), fileSharingHandler: $this->sharing());
		$handler->transferFolderOwnershipIfNeeded(folder: $this->folder(ownerUid: null), fileSharingHandler: $this->sharing());
		$this->sessionUid = null;
		$handler->transferFileOwnershipIfNeeded(file: $this->file(ownerUid: 'alice'), fileSharingHandler: $this->sharing());
		$handler->transferFolderOwnershipIfNeeded(folder: $this->folder(ownerUid: 'alice'), fileSharingHandler: $this->sharing());
		$this->sessionUid = 'openregister';
		$handler->transferFileOwnershipIfNeeded(file: $this->file(ownerUid: 'openregister'), fileSharingHandler: $this->sharing());
		$handler->transferFolderOwnershipIfNeeded(folder: $this->folder(ownerUid: 'openregister'), fileSharingHandler: $this->sharing());

		self::assertSame(
			[
				['warning', '[FileOwnershipHandler] File report.pdf has no owner, skipping ownership transfer'],
				['warning', '[FileOwnershipHandler] Folder case-1 has no owner, skipping ownership transfer'],
			],
			$this->events
		);
	}//end testRefusedTransfers()

	/**
	 * A failing share is logged after the chown; nothing is thrown.
	 *
	 * @return void
	 */
	public function testFailuresAreLogged(): void {
		$sharing = $this->createMock(FileSharingHandlerInterface::class);
		$sharing->method('shareFileWithUser')->willThrowException(new Exception('share refused'));
		$sharing->method('shareFolderWithUser')->willThrowException(new Exception('share refused'));

		$this->handler()->transferFileOwnershipIfNeeded(file: $this->file(ownerUid: 'alice'), fileSharingHandler: $sharing);
		$this->handler()->transferFolderOwnershipIfNeeded(folder: $this->folder(ownerUid: 'alice'), fileSharingHandler: $sharing);

		self::assertSame(
			[
				['info', '[FileOwnershipHandler] Transferring file report.pdf from alice to openregister'],
				['chown', 'files/report.pdf', 'openregister'],
				['error', '[FileOwnershipHandler] Failed to transfer file ownership for report.pdf: share refused'],
				['info', '[FileOwnershipHandler] Transferring folder case-1 from alice to openregister'],
				['chown', 'files/case-1', 'openregister'],
				['error', '[FileOwnershipHandler] Failed to transfer folder ownership for case-1: share refused'],
			],
			$this->events
		);
	}//end testFailuresAreLogged()
}//end class
