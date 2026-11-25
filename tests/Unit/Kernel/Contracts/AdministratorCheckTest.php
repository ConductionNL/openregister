<?php

/**
 * An administrator is whoever Nextcloud's own check says is one.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/platform-provided-capabilities/specs/platform-provided-capabilities/spec.md#REQ-PPC-005
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Kernel\Contracts;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Kernel\Contracts\AdministratorCheck;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/**
 * The helper takes IGroupManager::isAdmin()'s answer, which applies allowed_admin_ranges, and fails closed.
 */
final class AdministratorCheckTest extends TestCase {

	/**
	 * A member of the admin group outside `allowed_admin_ranges` is no administrator.
	 *
	 * Nextcloud answers `isAdmin()` false for an admin-group member whose address
	 * is outside the range. The helper must take that answer, not the group list.
	 *
	 * @spec openspec/changes/platform-provided-capabilities/specs/platform-provided-capabilities/spec.md#REQ-PPC-005
	 */
	public function testAnAdminGroupMemberOutsideTheAllowedRangeIsNotAnAdministrator(): void {
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn(['admin', 'staff']);
		$groupManager->expects($this->once())->method('isAdmin')->with('anna')->willReturn(false);

		$this->assertFalse(AdministratorCheck::isAdministrator(groupManager: $groupManager, user: 'anna'));
	}

	/**
	 * Inside the range the same member is an administrator, by uid or by user.
	 *
	 * @spec openspec/changes/platform-provided-capabilities/specs/platform-provided-capabilities/spec.md#REQ-PPC-005
	 */
	public function testNextcloudsAnswerDecidesForAUserOrAUid(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('anna');
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $uid === 'anna');

		$this->assertTrue(AdministratorCheck::isAdministrator(groupManager: $groupManager, user: $user));
		$this->assertTrue(AdministratorCheck::isAdministrator(groupManager: $groupManager, user: 'anna'));
		$this->assertFalse(AdministratorCheck::isAdministrator(groupManager: $groupManager, user: 'bert'));
	}

	/**
	 * No manager, no user, or a manager that answers nothing: no administrator.
	 *
	 * @spec openspec/changes/platform-provided-capabilities/specs/platform-provided-capabilities/spec.md#REQ-PPC-005
	 */
	public function testTheHelperFailsClosed(): void {
		$silent = $this->createMock(IGroupManager::class);
		$admin  = $this->createMock(IGroupManager::class);
		$admin->method('isAdmin')->willReturn(true);

		$this->assertFalse(AdministratorCheck::isAdministrator(groupManager: null, user: 'anna'));
		$this->assertFalse(AdministratorCheck::isAdministrator(groupManager: $admin, user: null));
		$this->assertFalse(AdministratorCheck::isAdministrator(groupManager: $silent, user: 'anna'));
	}
}//end class
