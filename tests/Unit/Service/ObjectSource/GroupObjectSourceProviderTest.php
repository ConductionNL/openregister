<?php

/**
 * Unit tests for GroupObjectSourceProvider.
 *
 * Covers:
 *  - isEnabled() is always true (core NC service)
 *  - findAll() maps IGroup instances onto virtual ObjectEntity instances
 *  - admin scoping (sees all groups) vs plain-user scoping (own groups only)
 *  - find() resolves by gid, and returns null when absent or denied
 *  - no acting user degrades to an empty list (fail closed)
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-3.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectSource;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ObjectSource\GroupObjectSourceProvider;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Test class for GroupObjectSourceProvider.
 */
class GroupObjectSourceProviderTest extends TestCase {
	/**
	 * Build a mock IGroup, optionally with the acting user as a member.
	 *
	 * @param string $gid The group id.
	 * @param string $name The display name.
	 * @param IUser|null $member A user considered a member, or null.
	 *
	 * @return IGroup The mock group.
	 */
	private function group(string $gid, string $name, ?IUser $member = null): IGroup {
		$group = $this->createMock(IGroup::class);
		$group->method('getGID')->willReturn($gid);
		$group->method('getDisplayName')->willReturn($name);
		$group->method('inGroup')->willReturnCallback(
			static fn (IUser $u) => $member !== null && $u->getUID() === $member->getUID()
		);
		return $group;
	}//end group()

	/**
	 * Build a provider with an acting user, admin flag, and group contents.
	 *
	 * @param IUser|null $acting The acting (session) user, or null.
	 * @param bool $isAdmin Whether the acting user is an admin.
	 * @param array<int, IGroup> $all All groups (admin view).
	 * @param array<int, IGroup> $ownGroups The acting user's own groups.
	 *
	 * @return GroupObjectSourceProvider The provider under test.
	 */
	private function provider(?IUser $acting, bool $isAdmin, array $all, array $ownGroups = []): GroupObjectSourceProvider {
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn($isAdmin);
		$groupManager->method('search')->willReturnCallback(
			static function ($pattern) use ($all) {
				if ($pattern === '') {
					return $all;
				}

				return array_values(
					array_filter($all, static fn (IGroup $g) => str_contains(strtolower($g->getGID()), strtolower((string)$pattern)))
				);
			}
		);
		$groupManager->method('getUserGroups')->willReturn($ownGroups);
		$groupManager->method('get')->willReturnCallback(
			static function ($gid) use ($all) {
				foreach ($all as $g) {
					if ($g->getGID() === $gid) {
						return $g;
					}
				}

				return null;
			}
		);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($acting);

		return new GroupObjectSourceProvider($groupManager, $session, new NullLogger());
	}//end provider()

	/**
	 * A mock acting user with the given uid.
	 *
	 * @param string $uid The user id.
	 *
	 * @return IUser The mock user.
	 */
	private function actor(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}//end actor()

	/**
	 * The register/schema pair the provider is bound to.
	 *
	 * @return array{0: Register, 1: Schema} The register and schema.
	 */
	private function binding(): array {
		$register = new Register();
		$register->setId(7);
		$schema = new Schema();
		$schema->setId(71);
		return [$register, $schema];
	}//end binding()

	/**
	 * getId() is the stable provider id.
	 *
	 * @return void
	 */
	public function testGetId(): void {
		$this->assertSame('group-source', $this->provider(null, false, [])->getId());
	}//end testGetId()

	/**
	 * isEnabled() is always true (core NC service).
	 *
	 * @return void
	 */
	public function testIsEnabledAlwaysTrue(): void {
		$this->assertTrue($this->provider(null, false, [])->isEnabled());
	}//end testIsEnabledAlwaysTrue()

	/**
	 * An admin sees every group, mapped onto virtual ObjectEntity instances.
	 *
	 * @return void
	 */
	public function testAdminSeesAllGroups(): void {
		[$register, $schema] = $this->binding();
		$admin = $this->actor('admin');
		$groups = [$this->group('admin', 'admin'), $this->group('users', 'users')];

		$objects = $this->provider($admin, true, $groups)->findAll($register, $schema);

		$this->assertCount(2, $objects);
		$data = $objects[0]->getObject();
		$this->assertSame('admin', $data['id']);
		$this->assertSame('admin', $data['displayName']);
		$this->assertSame('admin', $objects[0]->getUuid());
		$this->assertSame('71', $objects[0]->getSchema());
	}//end testAdminSeesAllGroups()

	/**
	 * A plain user sees only the groups they belong to.
	 *
	 * @return void
	 */
	public function testPlainUserSeesOwnGroups(): void {
		[$register, $schema] = $this->binding();
		$alice = $this->actor('alice');
		$staff = $this->group('staff', 'Staff', $alice);
		$all = [$this->group('admin', 'admin'), $staff, $this->group('users', 'users')];

		$objects = $this->provider($alice, false, $all, [$staff])->findAll($register, $schema);

		$this->assertCount(1, $objects);
		$this->assertSame('staff', $objects[0]->getUuid());
	}//end testPlainUserSeesOwnGroups()

	/**
	 * find() resolves any gid for an admin, null for an unknown gid.
	 *
	 * @return void
	 */
	public function testFindByGidAsAdmin(): void {
		[$register, $schema] = $this->binding();
		$admin = $this->actor('admin');
		$all = [$this->group('admin', 'admin'), $this->group('users', 'Users')];

		$provider = $this->provider($admin, true, $all);
		$this->assertSame('Users', $provider->find($register, $schema, 'users')?->getObject()['displayName']);
		$this->assertNull($provider->find($register, $schema, 'ghost'));
	}//end testFindByGidAsAdmin()

	/**
	 * find() denies a plain user reading a group they do not belong to.
	 *
	 * @return void
	 */
	public function testFindDeniedForNonMember(): void {
		[$register, $schema] = $this->binding();
		$alice = $this->actor('alice');
		$staff = $this->group('staff', 'Staff', $alice);
		$admin = $this->group('admin', 'admin');

		$provider = $this->provider($alice, false, [$admin, $staff], [$staff]);
		$this->assertNull($provider->find($register, $schema, 'admin'));
		$this->assertSame('Staff', $provider->find($register, $schema, 'staff')?->getObject()['displayName']);
	}//end testFindDeniedForNonMember()

	/**
	 * No acting user degrades findAll to an empty list.
	 *
	 * @return void
	 */
	public function testNoActingUserEmptyList(): void {
		[$register, $schema] = $this->binding();
		$this->assertCount(0, $this->provider(null, false, [])->findAll($register, $schema));
	}//end testNoActingUserEmptyList()
}//end class
