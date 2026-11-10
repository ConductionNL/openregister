<?php

/**
 * Behaviour tests for LockAuthorization.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Object\LockAuthorization;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Who may lock, unlock and break a lock.
 *
 * Locks carry a far-future absolute expiration so the decision never depends on the clock.
 *
 * @covers \OCA\OpenRegister\Service\Object\LockAuthorization
 */
class LockAuthorizationBehaviourTest extends TestCase {

	private const FUTURE = '2999-01-01T00:00:00+00:00';

	/**
	 * @var IUserSession&MockObject
	 */
	private IUserSession $session;

	/**
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager $groups;

	/**
	 * @var SchemaMapperInterface&MockObject
	 */
	private SchemaMapperInterface $schemas;

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->session = $this->createMock(IUserSession::class);
		$this->groups  = $this->createMock(IGroupManager::class);
		$this->schemas = $this->createMock(SchemaMapperInterface::class);
	}//end setUp()

	/**
	 * Sign a user in (or nobody), with admin status.
	 *
	 * @param string|null $uid   The user id, null for no session.
	 * @param bool        $admin Whether the user is an admin.
	 *
	 * @return void
	 */
	private function signIn(?string $uid, bool $admin=false): void {
		if ($uid === null) {
			$this->session->method('getUser')->willReturn(null);
			return;
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->session->method('getUser')->willReturn($user);
		$this->groups->method('isAdmin')->with($uid)->willReturn($admin);
	}//end signIn()

	/**
	 * An object locked by someone.
	 *
	 * @param array<string, mixed> $lock   Lock fields beyond the expiration.
	 * @param string|null          $owner  The object owner.
	 * @param string|null          $schema The schema id.
	 *
	 * @return ObjectEntity
	 */
	private function locked(array $lock, ?string $owner=null, ?string $schema=null): ObjectEntity {
		$object = new ObjectEntity();
		$object->setLocked(array_merge(['expiration' => self::FUTURE], $lock));
		$object->setOwner($owner);
		$object->setSchema($schema);
		return $object;
	}//end locked()

	/**
	 * The subject.
	 *
	 * @param PermissionHandlerInterface|null $permissions The permission handler.
	 *
	 * @return LockAuthorization
	 */
	private function auth(?PermissionHandlerInterface $permissions=null): LockAuthorization {
		return new LockAuthorization(
			userSession: $this->session,
			groupManager: $this->groups,
			schemaMapper: $this->schemas,
			permissionHandler: $permissions
		);
	}//end auth()

	/**
	 * Locking requires update permission on the object, checked with RBAC on.
	 *
	 * @return void
	 */
	public function testAssertMayLockChecksUpdatePermission(): void {
		$schema = new Schema();
		$object = new ObjectEntity();
		$object->setOwner('alice');
		$permissions = $this->createMock(PermissionHandlerInterface::class);
		$permissions->expects($this->once())->method('checkPermission')
			->with($schema, 'update', null, 'alice', true, $object)
			->willThrowException(new RuntimeException('denied'));

		$this->expectExceptionMessage('denied');
		$this->auth(permissions: $permissions)->assertMayLock(['object' => $object, 'schema' => $schema]);
	}//end testAssertMayLockChecksUpdatePermission()

	/**
	 * Without a session nobody may unlock or break.
	 *
	 * @return void
	 */
	public function testNoSessionMayNeitherUnlockNorBreak(): void {
		$this->signIn(uid: null);

		$this->assertFalse($this->auth()->mayUnlock(object: $this->locked(lock: ['user' => 'bob'])));
		$this->assertFalse($this->auth()->mayBreak());
	}//end testNoSessionMayNeitherUnlockNorBreak()

	/**
	 * Only an admin may break a lock.
	 *
	 * @return void
	 */
	public function testOnlyAdminMayBreak(): void {
		$this->signIn(uid: 'root', admin: true);
		$this->assertTrue($this->auth()->mayBreak());
	}//end testOnlyAdminMayBreak()

	/**
	 * A non-admin may not break a lock.
	 *
	 * @return void
	 */
	public function testNonAdminMayNotBreak(): void {
		$this->signIn(uid: 'alice');
		$this->assertFalse($this->auth()->mayBreak());
	}//end testNonAdminMayNotBreak()

	/**
	 * The holder of a user lock may unlock it; an admin may unlock anything.
	 *
	 * @return void
	 */
	public function testHolderAndAdminMayUnlock(): void {
		$this->signIn(uid: 'alice');
		$this->assertTrue($this->auth()->mayUnlock(object: $this->locked(lock: ['user' => 'alice'])));
	}//end testHolderAndAdminMayUnlock()

	/**
	 * An admin may unlock a run lock held by another run.
	 *
	 * @return void
	 */
	public function testAdminMayUnlockARunLock(): void {
		$this->signIn(uid: 'root', admin: true);
		$this->assertTrue($this->auth()->mayUnlock(object: $this->locked(lock: ['kind' => 'run', 'runUuid' => 'r-1'])));
	}//end testAdminMayUnlockARunLock()

	/**
	 * The owner of an object may release another user's lock on it.
	 *
	 * @return void
	 */
	public function testOwnerMayReleaseAnotherUsersLock(): void {
		$this->signIn(uid: 'alice');
		$this->schemas->expects($this->never())->method('find');

		$this->assertTrue($this->auth()->mayUnlock(object: $this->locked(lock: ['user' => 'bob'], owner: 'alice', schema: '3')));
	}//end testOwnerMayReleaseAnotherUsersLock()

	/**
	 * A run lock is never released by an owner or manager, only by the run or an admin.
	 *
	 * @return void
	 */
	public function testRunLockIsNotReleasedByOwner(): void {
		$this->signIn(uid: 'alice');
		$this->schemas->expects($this->never())->method('find');

		$this->assertFalse(
			$this->auth()->mayUnlock(object: $this->locked(lock: ['kind' => 'run', 'runUuid' => 'r-1'], owner: 'alice'), runUuid: 'r-2')
		);
	}//end testRunLockIsNotReleasedByOwner()

	/**
	 * A member of a group the schema's `manage` rule names may release, as a plain id
	 * or as a `{group: ...}` entry.
	 *
	 * @return void
	 */
	public function testManageRuleGroupMayRelease(): void {
		$this->signIn(uid: 'carol');
		$schema = new Schema();
		$schema->setAuthorization(['manage' => [['group' => 'stewards'], 'other']]);
		$this->schemas->expects($this->once())->method('find')->with(3)->willReturn($schema);
		$this->groups->method('getUserGroupIds')->willReturn(['staff', 'stewards']);

		$this->assertTrue($this->auth()->mayUnlock(object: $this->locked(lock: ['user' => 'bob'], owner: 'alice', schema: '3')));
	}//end testManageRuleGroupMayRelease()

	/**
	 * A plain string entry in the `manage` rule admits its group.
	 *
	 * @return void
	 */
	public function testManageRuleStringEntryMayRelease(): void {
		$this->signIn(uid: 'carol');
		$schema = new Schema();
		$schema->setAuthorization(['manage' => ['stewards']]);
		$this->schemas->method('find')->willReturn($schema);
		$this->groups->method('getUserGroupIds')->willReturn(['stewards']);

		$this->assertTrue($this->auth()->mayUnlock(object: $this->locked(lock: ['user' => 'bob'], owner: 'alice', schema: '3')));
	}//end testManageRuleStringEntryMayRelease()

	/**
	 * A user whose groups the manage rule does not name may not release.
	 *
	 * @return void
	 */
	public function testManageRuleWithoutTheUsersGroupRefuses(): void {
		$this->signIn(uid: 'carol');
		$schema = new Schema();
		$schema->setAuthorization(['manage' => [['group' => 'stewards'], ['user' => 'carol'], 'admins']]);
		$this->schemas->method('find')->willReturn($schema);
		$this->groups->method('getUserGroupIds')->willReturn(['staff']);

		$this->assertFalse($this->auth()->mayUnlock(object: $this->locked(lock: ['user' => 'bob'], owner: 'alice', schema: '3')));
	}//end testManageRuleWithoutTheUsersGroupRefuses()

	/**
	 * Every way the manage rule can be missing refuses: no schema id, empty schema id,
	 * an unreadable schema, no authorization, no `manage` key, unreadable groups.
	 *
	 * @return void
	 */
	public function testMissingManageRuleRefuses(): void {
		$this->signIn(uid: 'carol');
		$noManage = new Schema();
		$noManage->setAuthorization(['read' => ['stewards']]);
		$empty = new Schema();
		$withManage = new Schema();
		$withManage->setAuthorization(['manage' => ['stewards']]);
		$this->schemas->method('find')->willReturnCallback(
			static fn (int $id): Schema => match ($id) {
				1 => throw new RuntimeException('gone'),
				2 => $noManage,
				3 => $empty,
				default => $withManage,
			}
		);
		$this->groups->method('getUserGroupIds')->willThrowException(new RuntimeException('ldap down'));

		foreach ([null, '', '1', '2', '3', '4'] as $schemaId) {
			$this->assertFalse(
				$this->auth()->mayUnlock(object: $this->locked(lock: ['user' => 'bob'], owner: 'alice', schema: $schemaId)),
				'schema ' . var_export($schemaId, true)
			);
		}
	}//end testMissingManageRuleRefuses()
}//end class
