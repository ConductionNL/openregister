<?php

/**
 * FileSharingCreateShareGoldenTest
 *
 * Pins, call by call, how FileSharingHandler::createShare() and
 * shareFileWithUser() build a share: which setters run with which values in
 * which order, who the sharer is, the log lines, and the failure path.
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
use OCA\OpenRegister\Files\Service\File\FileOwnershipHandlerInterface;
use OCA\OpenRegister\Files\Service\File\FileSharingAccounts;
use OCA\OpenRegister\Files\Service\File\FileSharingHandler;
use OCA\OpenRegister\Files\Service\File\ShareUrlBuilder;
use OCA\OpenRegister\Files\Service\File\UserShareGrant;
use OCP\Files\File;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Share\IManager;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden call sequences of FileSharingHandler::createShare() and shareFileWithUser().
 */
final class FileSharingCreateShareGoldenTest extends TestCase {

	/**
	 * Every setter, manager call and log line, in order.
	 *
	 * @var list<array>
	 */
	private array $events = [];

	/**
	 * The message createShare() on the manager fails with; null to succeed.
	 *
	 * @var string|null
	 */
	private ?string $failWith = null;

	/**
	 * The shares the system account already has on the file.
	 *
	 * @var list<IShare>
	 */
	private array $existing = [];

	/**
	 * The handler under test, over recording fakes.
	 *
	 * @return FileSharingHandler
	 */
	private function handler(): FileSharingHandler {
		$share = $this->createMock(IShare::class);
		foreach (['setNode', 'setNodeId', 'setNodeType', 'setShareType', 'setPermissions', 'setSharedBy', 'setSharedWith'] as $setter) {
			$share->method($setter)->willReturnCallback(
				function ($value) use ($setter, $share): IShare {
					if (is_object($value) === true) {
						$value = 'node:'.$value->getName();
					}

					$this->events[] = [$setter, $value];
					return $share;
				}
			);
		}

		$shares = $this->createMock(IManager::class);
		$shares->method('newShare')->willReturnCallback(
			function () use ($share): IShare {
				$this->events[] = ['newShare'];
				return $share;
			}
		);
		$shares->method('createShare')->willReturnCallback(
			function (IShare $created): IShare {
				$this->events[] = ['createShare'];
				if ($this->failWith !== null) {
					throw new Exception($this->failWith);
				}

				return $created;
			}
		);
		$shares->method('getSharesBy')->willReturnCallback(
			function (string $userId, int $shareType, $path): array {
				$this->events[] = ['getSharesBy', $userId, $shareType, $path->getName()];
				return $this->existing;
			}
		);

		$system = $this->createMock(IUser::class);
		$system->method('getUID')->willReturn('openregister');
		$ownership = $this->createMock(FileOwnershipHandlerInterface::class);
		$ownership->method('getUser')->willReturn($system);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message) use ($level): void {
					$this->events[] = [$level, $message];
				}
			);
		}

		return new FileSharingHandler(
			shareManager: $shares,
			accounts: new FileSharingAccounts(userManager: $this->createMock(IUserManager::class), fileOwnershipHandler: $ownership),
			shareUrls: $this->createMock(ShareUrlBuilder::class),
			logger: $logger
		);
	}//end handler()

	/**
	 * A file named report.pdf with an optional owner.
	 *
	 * @param string|null $ownerUid The owner's uid; null for none.
	 *
	 * @return File
	 */
	private function file(?string $ownerUid): File {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('report.pdf');
		$owner = null;
		if ($ownerUid !== null) {
			$owner = $this->createMock(IUser::class);
			$owner->method('getUID')->willReturn($ownerUid);
		}

		$file->method('getOwner')->willReturn($owner);
		return $file;
	}//end file()

	/**
	 * A file share: the file's owner shares, the node is set directly.
	 *
	 * @return void
	 */
	public function testFileShareByTheOwner(): void {
		$this->handler()->createShare(
			['path' => 'a/report.pdf', 'file' => $this->file(ownerUid: 'bob'), 'shareType' => IShare::TYPE_LINK, 'permissions' => 1]
		);

		self::assertSame(
			[
				['newShare'],
				['setNode', 'node:report.pdf'],
				['setShareType', IShare::TYPE_LINK],
				['setPermissions', 1],
				['setSharedBy', 'bob'],
				['createShare'],
				['info', 'Successfully created share for a/report.pdf by user bob'],
			],
			$this->events
		);
	}//end testFileShareByTheOwner()

	/**
	 * An ownerless file, a node id with and without a type, and no node at all: the system account shares.
	 *
	 * @return void
	 */
	public function testSharesByTheSystemAccount(): void {
		$handler = $this->handler();
		$handler->createShare(['path' => 'p1', 'file' => $this->file(ownerUid: null), 'shareType' => IShare::TYPE_USER, 'sharedWith' => 'anna']);
		$handler->createShare(['path' => 'p2', 'nodeId' => 7, 'shareType' => IShare::TYPE_USER, 'permissions' => null, 'sharedWith' => '']);
		$handler->createShare(['path' => 'p3', 'nodeId' => 8, 'nodeType' => 'folder', 'shareType' => IShare::TYPE_GROUP, 'permissions' => 0, 'sharedWith' => 'staff']);
		$handler->createShare(['path' => 'p4', 'nodeId' => 0, 'shareType' => IShare::TYPE_LINK]);

		self::assertSame(
			[
				['newShare'],
				['setNode', 'node:report.pdf'],
				['setShareType', IShare::TYPE_USER],
				['setSharedBy', 'openregister'],
				['setSharedWith', 'anna'],
				['createShare'],
				['info', 'Successfully created share for p1 by user openregister'],
				['newShare'],
				['setNodeId', 7],
				['setNodeType', 'file'],
				['setShareType', IShare::TYPE_USER],
				['setSharedBy', 'openregister'],
				['createShare'],
				['info', 'Successfully created share for p2 by user openregister'],
				['newShare'],
				['setNodeId', 8],
				['setNodeType', 'folder'],
				['setShareType', IShare::TYPE_GROUP],
				['setPermissions', 0],
				['setSharedBy', 'openregister'],
				['setSharedWith', 'staff'],
				['createShare'],
				['info', 'Successfully created share for p3 by user openregister'],
				['newShare'],
				['setShareType', IShare::TYPE_LINK],
				['setSharedBy', 'openregister'],
				['createShare'],
				['info', 'Successfully created share for p4 by user openregister'],
			],
			$this->events
		);
	}//end testSharesByTheSystemAccount()

	/**
	 * A refused share is logged and rethrown with the cause in the message.
	 *
	 * @return void
	 */
	public function testAFailedShareIsLoggedAndRethrown(): void {
		$this->failWith = 'quota';
		$handler = $this->handler();

		try {
			$handler->createShare(['path' => 'p9', 'nodeId' => 9, 'shareType' => IShare::TYPE_USER]);
			self::fail('no exception');
		} catch (Exception $e) {
			self::assertSame('Failed to create share: quota', $e->getMessage());
			self::assertSame(0, $e->getCode());
			self::assertNull($e->getPrevious());
		}

		self::assertSame(
			['error', '[FileSharingHandler] Failed to create share for p9 by user openregister: quota'],
			end($this->events)
		);
	}//end testAFailedShareIsLoggedAndRethrown()

	/**
	 * A file is shared with a user by the system account, once; a second user is shared anew.
	 *
	 * @return void
	 */
	public function testShareFileWithUser(): void {
		$annasShare = $this->createMock(IShare::class);
		$annasShare->method('getSharedWith')->willReturn('anna');
		$this->existing = [$annasShare];
		$handler = $this->handler();
		$file = $this->file(ownerUid: 'bob');

		$handler->shareFileWithUser(new UserShareGrant($file, 'anna'));
		$handler->shareFileWithUser(new UserShareGrant($file, 'carl', 17));

		self::assertSame(
			[
				['getSharesBy', 'openregister', IShare::TYPE_USER, 'report.pdf'],
				['info', '[FileSharingHandler] Share already exists for file report.pdf with user anna'],
				['getSharesBy', 'openregister', IShare::TYPE_USER, 'report.pdf'],
				['newShare'],
				['setNode', 'node:report.pdf'],
				['setShareType', IShare::TYPE_USER],
				['setSharedWith', 'carl'],
				['setSharedBy', 'openregister'],
				['setPermissions', 17],
				['createShare'],
				['info', '[FileSharingHandler] Created share for file report.pdf with user carl'],
			],
			$this->events
		);
	}//end testShareFileWithUser()

	/**
	 * A failed file share is logged and the same exception rethrown.
	 *
	 * @return void
	 */
	public function testShareFileWithUserFailure(): void {
		$this->failWith = 'denied';
		$handler = $this->handler();

		try {
			$handler->shareFileWithUser(new UserShareGrant($this->file(ownerUid: null), 'carl'));
			self::fail('no exception');
		} catch (Exception $e) {
			self::assertSame('denied', $e->getMessage());
		}

		self::assertSame(
			['error', '[FileSharingHandler] Failed to share file report.pdf with user carl: denied'],
			end($this->events)
		);
	}//end testShareFileWithUserFailure()
}//end class
