<?php

/**
 * Unit test for GdprRegisterAccess.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\Support\ControllerSession;
use OCA\OpenRegister\Controller\Support\GdprRegisterAccess;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Who the caller is and whether the GDPR register treats them as admin.
 *
 * @covers \OCA\OpenRegister\Controller\Support\GdprRegisterAccess
 * @uses   \OCA\OpenRegister\Controller\Support\ControllerSession
 */
class GdprRegisterAccessTest extends TestCase {
	private function access(?IUser $user, array $groups = []): GdprRegisterAccess {
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn($groups);

		return new GdprRegisterAccess(new ControllerSession($userSession, $groupManager, $this->createMock(LoggerInterface::class)));
	}

	public function testUserIsTheSessionUser(): void {
		$user = $this->createMock(IUser::class);

		$this->assertSame($user, $this->access($user)->user());
		$this->assertNull($this->access(null)->user());
	}

	public function testGroupIdsAskTheGroupManagerForThatUser(): void {
		$user        = $this->createMock(IUser::class);
		$userSession = $this->createMock(IUserSession::class);
		$groups      = $this->createMock(IGroupManager::class);
		$groups->expects($this->once())->method('getUserGroupIds')->with($user)->willReturn(['staff']);

		$access = new GdprRegisterAccess(new ControllerSession($userSession, $groups, $this->createMock(LoggerInterface::class)));

		$this->assertSame(['staff'], $access->groupIds($user));
	}

	public function testAdminMembershipMakesTheCallerAdmin(): void {
		$this->assertTrue($this->access($this->createMock(IUser::class), ['staff', 'admin'])->isAdmin());
	}

	public function testOtherGroupsOrNoUserAreNotAdmin(): void {
		$this->assertFalse($this->access($this->createMock(IUser::class), ['staff', 'Admin', 'admins'])->isAdmin());
		$this->assertFalse($this->access(null, ['admin'])->isAdmin());
	}
}
