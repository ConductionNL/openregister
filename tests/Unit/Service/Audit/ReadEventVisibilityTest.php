<?php

/**
 * Who may see that somebody read an object: administrators, and the groups
 * `audit.readEventsVisibleTo` names; nobody else, and nobody on a failure.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/audit-read-events-visible-to/specs/audit-trail-immutable/spec.md#requirement-read-entries-are-shown-only-to-the-groups-named-for-them
 */

declare(strict_types=1);

namespace Unit\Service\Audit;

use OCA\OpenRegister\Service\Audit\ReadEventVisibility;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ReadEventVisibilityTest extends TestCase {

	/**
	 * The rule for one user, the named groups and the user's groups.
	 *
	 * @param string|null $uid      The signed-in user, null for none.
	 * @param string      $setting  The setting as stored.
	 * @param array       $groups   The user's groups.
	 * @param bool        $isAdmin  Whether the user is an administrator.
	 * @param bool        $failing  Whether the group lookup throws.
	 *
	 * @return ReadEventVisibility
	 */
	private function rule(?string $uid, string $setting, array $groups = [], bool $isAdmin = false, bool $failing = false): ReadEventVisibility {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn($setting);
		$groupManager = $this->createMock(IGroupManager::class);
		if ($failing === true) {
			$groupManager->method('isAdmin')->willThrowException(new RuntimeException('down'));
		} else {
			$groupManager->method('isAdmin')->willReturn($isAdmin);
			$groupManager->method('isInGroup')->willReturnCallback(static fn (string $user, string $group): bool => in_array($group, $groups, true));
		}

		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		return new ReadEventVisibility($appConfig, $groupManager, $session);
	}//end rule()

	public function testAnAdministratorAlwaysSeesReads(): void {
		$this->assertTrue($this->rule('admin', '[]', isAdmin: true)->maySeeReadEvents());
	}

	public function testAMemberOfANamedGroupSeesReads(): void {
		$this->assertTrue($this->rule('pieter', '["dossiq-sensitive"]', ['dossiq-sensitive'])->maySeeReadEvents());
	}

	public function testAnyoneElseDoesNot(): void {
		$this->assertFalse($this->rule('daan', '["dossiq-sensitive"]', ['kcc'])->maySeeReadEvents());
		$this->assertFalse($this->rule('daan', '[]', ['dossiq-sensitive'])->maySeeReadEvents(), 'no named group means only administrators');
		$this->assertFalse($this->rule('daan', 'not json', ['dossiq-sensitive'])->maySeeReadEvents(), 'an unreadable setting names no group');
	}

	public function testItFailsClosed(): void {
		$this->assertFalse($this->rule(null, '["dossiq-sensitive"]', ['dossiq-sensitive'])->maySeeReadEvents(), 'no user');
		$this->assertFalse($this->rule('pieter', '["dossiq-sensitive"]', ['dossiq-sensitive'], failing: true)->maySeeReadEvents(), 'a failing lookup');
	}

	public function testFilterDropsReadsOnlyForThoseWhoMayNotSeeThem(): void {
		$entries = [['action' => 'read'], ['action' => 'update'], ['action' => 'READ']];

		$this->assertSame([['action' => 'update']], $this->rule('daan', '[]')->filter($entries));
		$this->assertSame($entries, $this->rule('admin', '[]', isAdmin: true)->filter($entries));
	}
}
