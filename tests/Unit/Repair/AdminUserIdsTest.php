<?php

/**
 * Unit tests for AdminUserIds: the ids of the members of the admin group.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Repair;

use OCA\OpenRegister\Repair\AdminUserIds;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Repair\AdminUserIds
 */
final class AdminUserIdsTest extends TestCase {

	public function testTheIdsOfTheAdminGroupsMembersInOrder(): void {
		$users = array_map(function (string $uid): IUser {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			return $user;
		}, ['admin', 'beheerder']);
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn($users);
		$groups = $this->createMock(IGroupManager::class);
		$groups->expects($this->once())->method('get')->with('admin')->willReturn($group);

		$this->assertSame(['admin', 'beheerder'], AdminUserIds::of($groups));
	}

	public function testAnEmptyOrMissingAdminGroupGivesNoIds(): void {
		$empty = $this->createMock(IGroup::class);
		$empty->method('getUsers')->willReturn([]);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->willReturnOnConsecutiveCalls($empty, null);

		$this->assertSame([], AdminUserIds::of($groups));
		$this->assertSame([], AdminUserIds::of($groups));
	}
}
