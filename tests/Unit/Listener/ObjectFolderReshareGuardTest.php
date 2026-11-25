<?php

/**
 * A share of an OpenRegister folder made by another user never carries re-share.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/object-level-sharing/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Listener\ObjectFolderReshareGuard;
use OCP\Constants;
use OCP\EventDispatcher\Event;
use OCP\Share\Events\BeforeShareCreatedEvent;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Listener\ObjectFolderReshareGuard
 */
final class ObjectFolderReshareGuardTest extends TestCase {

	/**
	 * A share about to be created, recording the permissions it ends with.
	 *
	 * @param string $owner       The share owner.
	 * @param string $sharedBy    Who shares.
	 * @param int    $permissions The permissions asked for.
	 *
	 * @return IShare
	 */
	private function share(string $owner, string $sharedBy, int $permissions): IShare {
		$share = $this->createMock(IShare::class);
		$share->method('getShareOwner')->willReturn($owner);
		$share->method('getSharedBy')->willReturn($sharedBy);
		$current = $permissions;
		$share->method('getPermissions')->willReturnCallback(static function () use (&$current): int {
			return $current;
		});
		$share->method('setPermissions')->willReturnCallback(static function (int $p) use (&$current, $share): IShare {
			$current = $p;
			return $share;
		});

		return $share;
	}//end share()

	/**
	 * A user sharing an OpenRegister folder hands on everything but the re-share bit.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/object-level-sharing/spec.md
	 */
	public function testAnotherUsersShareOfAnOpenRegisterFolderLosesTheShareBit(): void {
		$share = $this->share(owner: 'openregister', sharedBy: 'alice', permissions: Constants::PERMISSION_ALL);

		(new ObjectFolderReshareGuard())->handle(new BeforeShareCreatedEvent($share));

		$this->assertSame(Constants::PERMISSION_ALL & ~Constants::PERMISSION_SHARE, $share->getPermissions());
	}//end testAnotherUsersShareOfAnOpenRegisterFolderLosesTheShareBit()

	/**
	 * The OpenRegister account's own shares (the sharer views) and other people's files are untouched.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/object-level-sharing/spec.md
	 */
	public function testTheAccountsOwnSharesAndOtherFilesAreLeftAlone(): void {
		$view  = $this->share(owner: 'openregister', sharedBy: 'openregister', permissions: Constants::PERMISSION_READ | Constants::PERMISSION_SHARE);
		$other = $this->share(owner: 'bob', sharedBy: 'bob', permissions: Constants::PERMISSION_ALL);

		$guard = new ObjectFolderReshareGuard();
		$guard->handle(new BeforeShareCreatedEvent($view));
		$guard->handle(new BeforeShareCreatedEvent($other));
		$guard->handle(new Event());

		$this->assertSame(Constants::PERMISSION_READ | Constants::PERMISSION_SHARE, $view->getPermissions());
		$this->assertSame(Constants::PERMISSION_ALL, $other->getPermissions());
	}//end testTheAccountsOwnSharesAndOtherFilesAreLeftAlone()
}//end class
