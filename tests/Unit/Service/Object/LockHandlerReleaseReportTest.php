<?php

/**
 * What a release reports: whether there was a lock to release.
 *
 * 🔴 IT USED TO REPORT `true` EITHER WAY. Releasing a held lock and releasing
 * an object that carried none both answered `true`, so a caller could not tell
 * "I handed mine back" from "somebody had already taken it away". A UI that
 * releases when its editor closes reported success on a lock it never held, and
 * the HTTP endpoint above it had nothing to turn into a status.
 *
 * 🔑 IDEMPOTENCE IS UNCHANGED, AND THAT IS THE POINT OF THE SECOND TEST.
 * Releasing a lock that is not there is still not an error: nothing throws, and
 * it still needs no unlock permission, because an empty or expired `_locked`
 * gives nothing to authorize. That property is why the post-write defensive
 * unlocks and the engine's release layers can call this blindly
 * (openregister#195). Only the REPORT changed, so the test asserts both halves:
 * false, and no exception.
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

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\AdvisoryLockStore;
use OCA\OpenRegister\Service\Object\LockHandler;
use OCA\OpenRegister\Service\Object\RunLockRegistry;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Object\LockHandler
 *
 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-a-lock-is-released-through-its-own-endpoint-and-a-release-says-whether-there-was-one
 */
final class LockHandlerReleaseReportTest extends TestCase {

	private const OBJ = 'obj-11111111-2222-3333-4444-555555555555';

	private const HOLDER = 'anna';

	private MagicMapper $magic;

	private IUserSession $session;

	/**
	 * The caller is the lock holder, so authorization never stands in the way.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->magic = $this->createMock(MagicMapper::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn(self::HOLDER);
		$this->session = $this->createMock(IUserSession::class);
		$this->session->method('getUser')->willReturn($user);
	}

	/**
	 * The handler under test.
	 *
	 * @return LockHandler The handler.
	 */
	private function handler(): LockHandler {
		return new LockHandler(
			$this->magic,
			$this->createMock(AuditTrailMapper::class),
			$this->createMock(LoggerInterface::class),
			$this->session,
			$this->createMock(IGroupManager::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(AdvisoryLockStore::class),
			$this->createMock(RunLockRegistry::class)
		);
	}

	/**
	 * Resolve every lookup to this object.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return void
	 */
	private function resolvesTo(ObjectEntity $object): void {
		$this->magic->method('findAcrossAllSources')->willReturn(
			[
				'object' => $object,
				'register' => $this->createMock(Register::class),
				'schema' => $this->createMock(Schema::class),
			]
		);
	}

	/**
	 * An object, locked by the caller or not locked at all.
	 *
	 * The lock is written by the PRODUCTION writer rather than hand-built:
	 * a hand-written `_locked` payload is what let the original guard defect
	 * survive its own unit test for months.
	 *
	 * @param boolean $locked Whether it carries a live lock.
	 *
	 * @return ObjectEntity The object.
	 */
	private function object(bool $locked): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid(self::OBJ);
		$object->setRegister('1');
		$object->setSchema('2');
		$object->setOwner(self::HOLDER);

		if ($locked === true) {
			$object->lock($this->session, 'editing', 3600, null);
		}

		return $object;
	}

	/**
	 * 🔴 Releasing a held lock reports that one was released.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-a-lock-is-released-through-its-own-endpoint-and-a-release-says-whether-there-was-one
	 */
	public function testReleasingAHeldLockReportsTrue(): void {
		$object = $this->object(locked: true);
		self::assertTrue($object->isLocked(), 'the fixture really is locked');
		$this->resolvesTo($object);

		self::assertTrue($this->handler()->unlock(identifier: self::OBJ));

		// 🔑 THE CLEARING ITSELF IS NOT ASSERTED HERE, ON PURPOSE. The handler
		// hands the release to `MagicMapper::unlockObject()`, which is a mock,
		// so `isLocked()` on this fixture would still read true however well
		// the handler behaved. Asserting it would be asserting the mock.
		// `ObjectEntityRunLockTest` owns that half, over the real entity.
	}

	/**
	 * 🔴 Releasing an object that carries no lock reports FALSE, and throws not.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-a-lock-is-released-through-its-own-endpoint-and-a-release-says-whether-there-was-one
	 */
	public function testReleasingNothingReportsFalseAndIsStillIdempotent(): void {
		$object = $this->object(locked: false);
		self::assertFalse($object->isLocked(), 'the fixture really is unlocked');
		$this->resolvesTo($object);

		// No try/catch and no expectException: the assertion IS that this call
		// returns rather than throwing. An unlock that raised here would break
		// every defensive release in the engine.
		self::assertFalse(
			$this->handler()->unlock(identifier: self::OBJ),
			'"there was nothing to release" is not the same answer as "I released it"'
		);
	}

	/**
	 * The two answers are different, which is the whole property.
	 *
	 * Each test above passes on an implementation that returns ITS value in
	 * both cases; only comparing them catches that.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-a-lock-is-released-through-its-own-endpoint-and-a-release-says-whether-there-was-one
	 */
	public function testAReleaseAndANoOpAreNotTheSameAnswer(): void {
		$held = new self('held');
		$held->setUp();
		$heldObject = $held->object(locked: true);
		$held->resolvesTo($heldObject);
		$releasedAnswer = $held->handler()->unlock(identifier: self::OBJ);

		$none = new self('none');
		$none->setUp();
		$none->resolvesTo($none->object(locked: false));
		$noOpAnswer = $none->handler()->unlock(identifier: self::OBJ);

		self::assertNotSame($releasedAnswer, $noOpAnswer);
	}
}//end class
