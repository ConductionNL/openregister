<?php

/**
 * Golden test: who may release and break a lock, and who may not.
 *
 * Pins every route through the unlock authorization (holder, administrator, object
 * owner, schema-manage group, and each refusal) and the administrator-only break, so a
 * restructuring of LockHandler cannot widen or narrow who may release a lock.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Service\Object\UnlockRequest;
use Exception;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\AdvisoryLockStore;
use OCA\OpenRegister\Service\Object\LockAuthorization;
use OCA\OpenRegister\Service\Object\LockHandler;
use OCA\OpenRegister\Service\Object\LockStores;
use OCA\OpenRegister\Service\Object\RunLockRegistry;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Object\LockHandler
 */
final class LockHandlerAccessGoldenTest extends TestCase {

	private const OBJ = 'obj-aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

	private const HOLDER = 'anna';

	private const OWNER = 'olaf';

	private const ADMIN = 'root';

	private const RUN = 'run-11111111-2222-3333-4444-555555555555';

	/**
	 * Every unlock route: [caller, lock kind, schema authorization, caller groups, expected outcome].
	 *
	 * A lock kind of 'user' is held by HOLDER; 'run' is held by RUN. An authorization of
	 * 'throws' makes the schema lookup fail; groups of null make the group lookup fail.
	 *
	 * @return array<string, array{?string, string, array|string, ?array, string}>
	 */
	public static function unlockRoutes(): array {
		return [
			'anonymous is refused' => [null, 'user', ['manage' => ['editors']], ['editors'], 'refused'],
			'administrator releases a user lock' => [self::ADMIN, 'user', [], [], 'allowed'],
			'administrator releases a run lock' => [self::ADMIN, 'run', [], [], 'allowed'],
			'holder releases own lock' => [self::HOLDER, 'user', [], [], 'allowed'],
			'object owner releases a user lock' => [self::OWNER, 'user', [], [], 'allowed'],
			'object owner may not release a run lock' => [self::OWNER, 'run', ['manage' => ['editors']], ['editors'], 'refused'],
			'manage group by name releases' => ['bob', 'user', ['manage' => ['editors']], ['staff', 'editors'], 'allowed'],
			'manage group by entry releases' => ['bob', 'user', ['manage' => [['group' => 'editors']]], ['editors'], 'allowed'],
			'manage group elsewhere is refused' => ['bob', 'user', ['manage' => ['others']], ['editors'], 'refused'],
			'malformed manage entry is refused' => ['bob', 'user', ['manage' => [['name' => 'editors'], 7]], ['editors'], 'refused'],
			'no manage rule is refused' => ['bob', 'user', ['read' => ['editors']], ['editors'], 'refused'],
			'empty authorization is refused' => ['bob', 'user', [], ['editors'], 'refused'],
			'unresolvable schema is refused' => ['bob', 'user', 'throws', ['editors'], 'refused'],
			'unresolvable groups are refused' => ['bob', 'user', ['manage' => ['editors']], null, 'refused'],
			'manage group may not release a run lock' => ['bob', 'run', ['manage' => ['editors']], ['editors'], 'refused'],
		];
	}//end unlockRoutes()

	/**
	 * Unlock is allowed or refused exactly as before.
	 *
	 * @param string|null  $caller   The caller's uid, or null for anonymous.
	 * @param string       $kind     The lock kind.
	 * @param array|string $auth     The schema authorization, or 'throws'.
	 * @param array|null   $groups   The caller's groups, or null when the lookup fails.
	 * @param string       $expected 'allowed' or 'refused'.
	 *
	 * @return void
	 */
	#[DataProvider('unlockRoutes')]
	public function testUnlockRoute(?string $caller, string $kind, array|string $auth, ?array $groups, string $expected): void {
		$handler = $this->handler(caller: $caller, auth: $auth, groups: $groups, object: $this->lockedObject(kind: $kind));

		$this->assertSame($expected, $this->outcome(fn () => $handler->unlock(
			request: new UnlockRequest(identifier: self::OBJ, advisory: false, runUuid: null, break: false)
		)));
	}//end testUnlockRoute()

	/**
	 * A run releases its own run lock, and another run may not.
	 *
	 * @return void
	 */
	public function testRunRoutes(): void {
		$own = $this->handler(caller: 'bob', auth: [], groups: [], object: $this->lockedObject(kind: 'run'));
		$this->assertSame('allowed', $this->outcome(fn () => $own->unlock(
			request: new UnlockRequest(identifier: self::OBJ, advisory: false, runUuid: self::RUN, break: false)
		)));

		$other = $this->handler(caller: 'bob', auth: [], groups: [], object: $this->lockedObject(kind: 'run'));
		$this->assertSame('refused', $this->outcome(fn () => $other->unlock(
			request: new UnlockRequest(identifier: self::OBJ, advisory: false, runUuid: 'run-other', break: false)
		)));
	}//end testRunRoutes()

	/**
	 * A break is allowed to an administrator only, and skips the holder check.
	 *
	 * @return void
	 */
	public function testBreakLockRoutes(): void {
		foreach ([[null, 'refused'], ['bob', 'refused'], [self::HOLDER, 'refused'], [self::ADMIN, 'allowed']] as $case) {
			$caller   = $case[0];
			$expected = $case[1];
			$handler   = $this->handler(caller: $caller, auth: ['manage' => ['editors']], groups: ['editors'], object: $this->lockedObject(kind: 'run'));
			$this->assertSame($expected, $this->outcome(fn () => $handler->breakLock(self::OBJ)), 'break by ' . ($caller ?? 'anonymous'));
		}
	}//end testBreakLockRoutes()

	/**
	 * An unlocked object releases nothing and refuses nobody, anonymous included.
	 *
	 * @return void
	 */
	public function testNothingToReleaseNeedsNoRight(): void {
		$object = new ObjectEntity();
		$object->setUuid(self::OBJ);
		$object->setSchema('2');
		$object->setOwner(self::OWNER);

		$handler = $this->handler(caller: null, auth: [], groups: [], object: $object);
		$this->assertFalse($handler->unlock(request: new UnlockRequest(identifier: self::OBJ, advisory: false, runUuid: null, break: false)));
	}//end testNothingToReleaseNeedsNoRight()

	/**
	 * Run a call and name its outcome.
	 *
	 * @param callable $call The call.
	 *
	 * @return string 'allowed' or 'refused'.
	 */
	private function outcome(callable $call): string {
		try {
			$call();
		} catch (Exception $e) {
			return 'refused';
		}

		return 'allowed';
	}//end outcome()

	/**
	 * An object locked by HOLDER (user lock) or by RUN (run lock), owned by OWNER.
	 *
	 * @param string $kind 'user' or 'run'.
	 *
	 * @return ObjectEntity The object.
	 */
	private function lockedObject(string $kind): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid(self::OBJ);
		$object->setRegister('1');
		$object->setSchema('2');
		$object->setOwner(self::OWNER);

		$object->lock($this->session(uid: self::HOLDER), 'editing', 3600, ($kind === 'run' ? self::RUN : null));

		return $object;
	}//end lockedObject()

	/**
	 * A session for a caller.
	 *
	 * @param string|null $uid The caller, or null for anonymous.
	 *
	 * @return IUserSession The session.
	 */
	private function session(?string $uid): IUserSession {
		$session = $this->createMock(IUserSession::class);
		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
			return $session;
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session->method('getUser')->willReturn($user);

		return $session;
	}//end session()

	/**
	 * The handler under test, for one caller and one schema.
	 *
	 * @param string|null  $caller The caller.
	 * @param array|string $auth   The schema authorization, or 'throws'.
	 * @param array|null   $groups The caller's groups, or null when the lookup fails.
	 * @param ObjectEntity $object The object every lookup resolves to.
	 *
	 * @return LockHandler The handler.
	 */
	private function handler(?string $caller, array|string $auth, ?array $groups, ObjectEntity $object): LockHandler {
		$magic = $this->createMock(MagicMapper::class);
		$magic->method('findAcrossAllSources')->willReturn(
			['object' => $object, 'register' => $this->createMock(Register::class), 'schema' => $this->createMock(Schema::class)]
		);
		$magic->method('unlockObjectEntity')->willReturn($object);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturnCallback(static fn ($uid): bool => $uid === self::ADMIN);
		if ($groups === null) {
			$groupManager->method('getUserGroupIds')->willThrowException(new RuntimeException('groups unavailable'));
		} else {
			$groupManager->method('getUserGroupIds')->willReturn($groups);
		}

		$schemaMapper = $this->createMock(SchemaMapper::class);
		if ($auth === 'throws') {
			$schemaMapper->method('find')->willThrowException(new RuntimeException('schema unavailable'));
		} else {
			$schema = new Schema();
			$schema->setAuthorization($auth);
			$schemaMapper->method('find')->willReturn($schema);
		}

		return new LockHandler(
			new LockStores(
				magicMapper: $magic,
				auditTrailMapper: $this->createMock(AuditTrailMapper::class),
				advisory: $this->createMock(AdvisoryLockStore::class),
				runLocks: $this->createMock(RunLockRegistry::class)
			),
			$this->createMock(LoggerInterface::class),
			new LockAuthorization($this->session(uid: $caller), $groupManager, $schemaMapper)
		);
	}//end handler()
}//end class
