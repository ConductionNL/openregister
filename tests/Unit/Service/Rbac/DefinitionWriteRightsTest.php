<?php

/**
 * Tests for DefinitionWriteRights.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/mcp-discovery/spec.md#requirement-a-definition-tool-holds-the-caller-to-the-rights-of-the-rest-routes
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Service\Rbac\DefinitionWriteRights;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Rbac\DefinitionWriteRights
 */
class DefinitionWriteRightsTest extends TestCase {

	/**
	 * Rights for a user; the mocks answer only for that user.
	 *
	 * @param string|null        $uid    The signed-in user, or null for none.
	 * @param bool               $admin  Whether the user is an administrator.
	 * @param array<int, string> $groups The groups of the user.
	 *
	 * @return DefinitionWriteRights
	 */
	private function rights(?string $uid, bool $admin = false, array $groups = []): DefinitionWriteRights {
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturnCallback(static fn (string $asked): bool => $admin === true && $asked === $uid);
		$groupManager->method('getUserGroupIds')->willReturnCallback(
			static function (IUser $asked) use ($user, $groups): array {
				if ($asked === $user) {
					return $groups;
				}

				return [];
			}
		);

		return new DefinitionWriteRights($session, $groupManager);
	}//end rights()

	public function testIsAdminAsksForTheSignedInUser(): void {
		self::assertTrue($this->rights(uid: 'root', admin: true)->isAdmin());
		self::assertFalse($this->rights(uid: 'mallory')->isAdmin());
		self::assertFalse($this->rights(uid: null)->isAdmin());
	}//end testIsAdminAsksForTheSignedInUser()

	/**
	 * @return array<string, array{0: array<int, string>, 1: array|null, 2: bool}>
	 */
	public static function manageCases(): array {
		return [
			'a group id in the rule'          => [['beheer'], ['manage' => ['beheer']], true],
			'a group entry in the rule'       => [['beheer'], ['manage' => [['group' => 'beheer']]], true],
			'another group in the rule'       => [['andere'], ['manage' => ['beheer']], false],
			'no groups at all'                => [[], ['manage' => ['beheer']], false],
			'an entry without a group'        => [['beheer'], ['manage' => [['match' => 'x']]], false],
			'a rule that is no list'          => [['beheer'], ['manage' => 'beheer'], false],
			'no manage rule'                  => [['beheer'], ['read' => ['beheer']], false],
			'no authorization'                => [['beheer'], null, false],
		];
	}//end manageCases()

	#[DataProvider('manageCases')]
	public function testMayManageReadsTheManageRule(array $groups, ?array $authorization, bool $expected): void {
		self::assertSame($expected, $this->rights(uid: 'mallory', groups: $groups)->mayManage(authorization: $authorization));
	}//end testMayManageReadsTheManageRule()

	public function testAnAdministratorMayManageWithoutARule(): void {
		self::assertTrue($this->rights(uid: 'root', admin: true)->mayManage(authorization: null));
	}//end testAnAdministratorMayManageWithoutARule()

	public function testNobodyManagesWithoutASession(): void {
		self::assertFalse($this->rights(uid: null)->mayManage(authorization: ['manage' => ['beheer']]));
	}//end testNobodyManagesWithoutASession()
}//end class
