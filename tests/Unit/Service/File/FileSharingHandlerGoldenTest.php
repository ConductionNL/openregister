<?php

/**
 * Golden test for the share decisions of FileSharingHandler.
 *
 * Pins who a share is made between with every collaborator handed in through
 * the constructor: a folder is shared only with a user that exists, a file is
 * not shared twice with the same user, and every share is made by and looked
 * up for the OpenRegister system account.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
// phpcs:disable PEAR.Commenting.FunctionComment.WrongStyle -- the section banners above tests are banners, not doc comments.
// phpcs:disable PEAR.Commenting.FunctionComment.MissingReturn -- PHPUnit fixtures and tests; the signature IS the contract.

use OCA\OpenRegister\Files\Service\File\FileOwnershipHandlerInterface;
use OCA\OpenRegister\Files\Service\File\FileSharingHandler;
use OCA\OpenRegister\Files\Service\File\FileSharingAccounts;
use OCA\OpenRegister\Files\Service\File\ShareUrlBuilder;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Share\IManager;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use OCA\OpenRegister\Files\Service\File\UserShareGrant;

class FileSharingHandlerGoldenTest extends TestCase {

	/**
	 * The share manager.
	 *
	 * @var IManager&MockObject
	 */
	private IManager&MockObject $shares;

	/**
	 * What was set on the last new share, by setter.
	 *
	 * @var array<string, mixed>
	 */
	private array $set = [];

	/**
	 * The shares handed to createShare().
	 *
	 * @var list<IShare>
	 */
	private array $created = [];

	/**
	 * Build the handler the way the container does: every collaborator through the constructor.
	 *
	 * @param list<string> $existingUsers The users the user manager knows.
	 */
	private function handler(array $existingUsers = ['anna']): FileSharingHandler {
		$share = $this->createMock(IShare::class);
		foreach (['setNode', 'setNodeId', 'setNodeType', 'setShareType', 'setPermissions', 'setSharedBy', 'setSharedWith'] as $setter) {
			$share->method($setter)->willReturnCallback(
				function ($value) use ($setter, $share): IShare {
					$this->set[$setter] = $value;
					return $share;
				}
			);
		}

		$this->shares = $this->createMock(IManager::class);
		$this->shares->method('newShare')->willReturn($share);
		$this->shares->method('createShare')->willReturnCallback(
			function (IShare $created): IShare {
				$this->created[] = $created;
				return $created;
			}
		);

		$users = $this->createMock(IUserManager::class);
		$users->method('userExists')->willReturnCallback(static fn (string $uid): bool => in_array($uid, $existingUsers, true));

		$system = $this->createMock(IUser::class);
		$system->method('getUID')->willReturn('openregister');
		$ownership = $this->createMock(FileOwnershipHandlerInterface::class);
		$ownership->method('getUser')->willReturn($system);

		return new FileSharingHandler(
			shareManager: $this->shares,
			accounts: new FileSharingAccounts(
				userManager: $users,
				fileOwnershipHandler: $ownership
			),
			shareUrls: $this->createMock(ShareUrlBuilder::class),
			logger: new NullLogger()
		);
	}//end handler()

	private function folder(): Folder {
		$folder = $this->createMock(Folder::class);
		$folder->method('getPath')->willReturn('/openregister/files/Register/Object');
		$folder->method('getId')->willReturn(55);
		$folder->method('getName')->willReturn('Object');

		return $folder;
	}//end folder()

	// ---- Allowed. ----------------------------------------------------------

	public function testAFolderIsSharedWithAnExistingUserByTheSystemAccount(): void {
		$share = $this->handler()->shareFolderWithUser(new UserShareGrant($this->folder(), 'anna', 1));

		$this->assertNotNull($share);
		$this->assertCount(1, $this->created);
		$this->assertSame(55, $this->set['setNodeId']);
		$this->assertSame('folder', $this->set['setNodeType']);
		$this->assertSame(IShare::TYPE_USER, $this->set['setShareType']);
		$this->assertSame(1, $this->set['setPermissions']);
		$this->assertSame('anna', $this->set['setSharedWith']);
		$this->assertSame('openregister', $this->set['setSharedBy']);
	}//end testAFolderIsSharedWithAnExistingUserByTheSystemAccount()

	public function testAFileIsSharedWithANewUserByTheSystemAccount(): void {
		$handler = $this->handler();
		$this->shares->method('getSharesBy')->willReturn([]);

		$handler->shareFileWithUser(new UserShareGrant($this->createMock(File::class), 'anna', 17));

		$this->assertCount(1, $this->created);
		$this->assertSame('anna', $this->set['setSharedWith']);
		$this->assertSame('openregister', $this->set['setSharedBy']);
		$this->assertSame(17, $this->set['setPermissions']);
	}//end testAFileIsSharedWithANewUserByTheSystemAccount()

	public function testSharesAreLookedUpForTheSystemAccount(): void {
		$handler = $this->handler();
		$file = $this->createMock(File::class);
		$this->shares->expects($this->once())->method('getSharesBy')->with('openregister', 3, $file, true)->willReturn([]);

		$this->assertSame([], $handler->findShares($file));
	}//end testSharesAreLookedUpForTheSystemAccount()

	// ---- Refused. ----------------------------------------------------------

	public function testAFolderIsNotSharedWithAUserThatDoesNotExist(): void {
		$this->assertNull($this->handler(existingUsers: [])->shareFolderWithUser(new UserShareGrant($this->folder(), 'ghost')));
		$this->assertSame([], $this->created);
	}//end testAFolderIsNotSharedWithAUserThatDoesNotExist()

	public function testAFileIsNotSharedTwiceWithTheSameUser(): void {
		$handler = $this->handler();
		$existing = $this->createMock(IShare::class);
		$existing->method('getSharedWith')->willReturn('anna');
		$this->shares->method('getSharesBy')->willReturn([$existing]);

		$handler->shareFileWithUser(new UserShareGrant($this->createMock(File::class), 'anna'));

		$this->assertSame([], $this->created);
	}//end testAFileIsNotSharedTwiceWithTheSameUser()
}//end class
