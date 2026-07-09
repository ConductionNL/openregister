<?php

/**
 * Unit tests for UserDirectoryObjectSourceProvider.
 *
 * Covers:
 *  - isEnabled() is always true (core NC service)
 *  - findAll() maps IUser instances onto virtual ObjectEntity instances
 *  - admin scoping (sees all users) vs plain-user scoping (sees only self)
 *  - find() resolves by uid, and returns null when absent or denied
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
 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-3.1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectSource;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ObjectSource\UserDirectoryObjectSourceProvider;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Test class for UserDirectoryObjectSourceProvider.
 */
class UserDirectoryObjectSourceProviderTest extends TestCase
{
    /**
     * Build a mock IUser.
     *
     * @param string $uid   The user id.
     * @param string $name  The display name.
     * @param string $email The email address.
     *
     * @return IUser The mock user.
     */
    private function user(string $uid, string $name, string $email): IUser
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $user->method('getDisplayName')->willReturn($name);
        $user->method('getEMailAddress')->willReturn($email);
        return $user;
    }//end user()

    /**
     * Build a provider with an acting user, admin flag and directory contents.
     *
     * @param IUser|null        $acting  The acting (session) user, or null.
     * @param bool              $isAdmin Whether the acting user is an admin.
     * @param array<int, IUser> $all     The full user directory (admin view).
     *
     * @return UserDirectoryObjectSourceProvider The provider under test.
     */
    private function provider(?IUser $acting, bool $isAdmin, array $all): UserDirectoryObjectSourceProvider
    {
        $userManager = $this->createMock(IUserManager::class);
        $userManager->method('search')->willReturnCallback(
            static function ($pattern) use ($all) {
                if ($pattern === '') {
                    return $all;
                }

                return array_values(
                    array_filter($all, static fn(IUser $u) => str_contains(strtolower($u->getUID()), strtolower((string) $pattern)))
                );
            }
        );
        $userManager->method('get')->willReturnCallback(
            static function ($uid) use ($all) {
                foreach ($all as $u) {
                    if ($u->getUID() === $uid) {
                        return $u;
                    }
                }

                return null;
            }
        );

        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($acting);

        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isAdmin')->willReturn($isAdmin);

        return new UserDirectoryObjectSourceProvider($userManager, $session, $groupManager, new NullLogger());
    }//end provider()

    /**
     * The register/schema pair the provider is bound to.
     *
     * @return array{0: Register, 1: Schema} The register and schema.
     */
    private function binding(): array
    {
        $register = new Register();
        $register->setId(7);
        $schema = new Schema();
        $schema->setId(70);
        return [$register, $schema];
    }//end binding()

    /**
     * getId() is the stable provider id.
     *
     * @return void
     */
    public function testGetId(): void
    {
        $this->assertSame('user-directory-source', $this->provider(null, false, [])->getId());
    }//end testGetId()

    /**
     * isEnabled() is always true (core NC service).
     *
     * @return void
     */
    public function testIsEnabledAlwaysTrue(): void
    {
        $this->assertTrue($this->provider(null, false, [])->isEnabled());
    }//end testIsEnabledAlwaysTrue()

    /**
     * An admin sees every user, mapped onto virtual ObjectEntity instances.
     *
     * @return void
     */
    public function testAdminSeesAllUsers(): void
    {
        [$register, $schema] = $this->binding();
        $admin = $this->user('admin', 'Administrator', 'admin@example.org');
        $bob   = $this->user('bob', 'Bob Bakker', 'bob@example.org');

        $objects = $this->provider($admin, true, [$admin, $bob])->findAll($register, $schema);

        $this->assertCount(2, $objects);
        $data = $objects[0]->getObject();
        $this->assertSame('admin', $data['id']);
        $this->assertSame('Administrator', $data['displayName']);
        $this->assertSame('admin@example.org', $data['email']);
        $this->assertSame('admin', $objects[0]->getUuid());
        $this->assertSame('70', $objects[0]->getSchema());
    }//end testAdminSeesAllUsers()

    /**
     * A plain user sees only themselves regardless of directory size.
     *
     * @return void
     */
    public function testPlainUserSeesOnlySelf(): void
    {
        [$register, $schema] = $this->binding();
        $alice = $this->user('alice', 'Alice', 'alice@example.org');
        $bob   = $this->user('bob', 'Bob', 'bob@example.org');

        $objects = $this->provider($alice, false, [$alice, $bob])->findAll($register, $schema);

        $this->assertCount(1, $objects);
        $this->assertSame('alice', $objects[0]->getUuid());
    }//end testPlainUserSeesOnlySelf()

    /**
     * find() resolves any uid for an admin.
     *
     * @return void
     */
    public function testFindByUidAsAdmin(): void
    {
        [$register, $schema] = $this->binding();
        $admin = $this->user('admin', 'Administrator', 'admin@example.org');
        $bob   = $this->user('bob', 'Bob', 'bob@example.org');

        $provider = $this->provider($admin, true, [$admin, $bob]);
        $this->assertSame('Bob', $provider->find($register, $schema, 'bob')?->getObject()['displayName']);
        $this->assertNull($provider->find($register, $schema, 'ghost'));
    }//end testFindByUidAsAdmin()

    /**
     * find() denies a plain user reading someone else (null == not-found).
     *
     * @return void
     */
    public function testFindDeniedForOtherUser(): void
    {
        [$register, $schema] = $this->binding();
        $alice = $this->user('alice', 'Alice', 'alice@example.org');
        $bob   = $this->user('bob', 'Bob', 'bob@example.org');

        $provider = $this->provider($alice, false, [$alice, $bob]);
        $this->assertNull($provider->find($register, $schema, 'bob'));
        $this->assertSame('Alice', $provider->find($register, $schema, 'alice')?->getObject()['displayName']);
    }//end testFindDeniedForOtherUser()

    /**
     * No acting user degrades findAll to an empty list.
     *
     * @return void
     */
    public function testNoActingUserEmptyList(): void
    {
        [$register, $schema] = $this->binding();
        $this->assertCount(0, $this->provider(null, false, [])->findAll($register, $schema));
    }//end testNoActingUserEmptyList()
}//end class
