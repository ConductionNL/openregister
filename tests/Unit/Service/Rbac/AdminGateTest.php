<?php

/**
 * The admin gate must fail closed on every way of not knowing.
 *
 * A dozen controllers carried their own copy of this check. The value of one
 * copy is that "unanswerable" is decided once, and decided as no: a gate that
 * returns true when it cannot resolve the caller is indistinguishable from no
 * gate at all, and it is the copy nobody reads that gets it wrong.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Rbac\AdminGate;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

class AdminGateTest extends TestCase {

	private function sessionFor(?string $uid): IUserSession {
		$session = $this->createMock(IUserSession::class);

		if ($uid === null) {
			$session->method('getUser')->willReturn(null);

			return $session;
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session->method('getUser')->willReturn($user);

		return $session;
	}

	private function groupManagerFor(bool $isAdmin): IGroupManager {
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn($isAdmin);

		return $groupManager;
	}

	public function testAnAdminPasses(): void {
		$gate = new AdminGate(
			userSession: $this->sessionFor('alice'),
			groupManager: $this->groupManagerFor(true)
		);

		$this->assertTrue($gate->isAdmin());
	}

	public function testAnOrdinaryAccountDoesNot(): void {
		$gate = new AdminGate(
			userSession: $this->sessionFor('bob'),
			groupManager: $this->groupManagerFor(false)
		);

		$this->assertFalse($gate->isAdmin());
	}

	public function testNobodySignedInIsRefused(): void {
		$gate = new AdminGate(
			userSession: $this->sessionFor(null),
			groupManager: $this->groupManagerFor(true)
		);

		$this->assertFalse($gate->isAdmin());
	}

	public function testAGateWithNoSessionRefusesRatherThanAssuming(): void {
		// The defaults exist so a unit test may build a controller without a
		// session. That convenience must never read as a yes.
		$this->assertFalse((new AdminGate())->isAdmin());
	}

	public function testAGateWithNoGroupManagerRefuses(): void {
		$gate = new AdminGate(userSession: $this->sessionFor('alice'));

		$this->assertFalse($gate->isAdmin());
	}

	public function testTheRefusalIsA403NamingWhatWasRefused(): void {
		$response = (new AdminGate())->refusal(doing: 'read the authorization model');

		$this->assertSame(403, $response->getStatus());
		$this->assertStringContainsString('read the authorization model', $response->getData()['error']);
	}

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

		$this->assertFalse(AdminGate::isAdministrator(groupManager: $groupManager, user: 'anna'));
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

		$this->assertTrue(AdminGate::isAdministrator(groupManager: $groupManager, user: $user));
		$this->assertTrue(AdminGate::isAdministrator(groupManager: $groupManager, user: 'anna'));
		$this->assertFalse(AdminGate::isAdministrator(groupManager: $groupManager, user: 'bert'));
	}

	/**
	 * No manager, no user, or a manager that answers nothing: no administrator.
	 *
	 * @spec openspec/changes/platform-provided-capabilities/specs/platform-provided-capabilities/spec.md#REQ-PPC-005
	 */
	public function testTheHelperFailsClosed(): void {
		$silent = $this->createMock(IGroupManager::class);

		$this->assertFalse(AdminGate::isAdministrator(groupManager: null, user: 'anna'));
		$this->assertFalse(AdminGate::isAdministrator(groupManager: $this->groupManagerFor(true), user: null));
		$this->assertFalse(AdminGate::isAdministrator(groupManager: $silent, user: 'anna'));
	}
}//end class
