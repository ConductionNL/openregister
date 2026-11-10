<?php

/**
 * LockHandler release characterisation.
 *
 * Pins, on the unlock and break paths, the order of the unlock write, the
 * run-lock bookkeeping, the break and unlock audit entries and the log lines,
 * plus the refused unlock's message, warning and the absence of any write.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Service\Object\UnlockRequest;
use Exception;
use OCA\OpenRegister\Db\AuditTrail;
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
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden order of a lock release.
 */
final class LockHandlerReleaseOrderGoldenTest extends TestCase {

	private const OBJ = 'obj-11111111-2222-3333-4444-555555555555';

	private const RUN = 'run-aaaaaaaa-0000-0000-0000-000000000001';

	/** @var list<string> Every side effect, in order. */
	private array $events = [];

	private MagicMapper $magic;

	private IUserSession $session;

	private IGroupManager $groups;

	private Register $register;

	private Schema $schema;

	protected function setUp(): void {
		parent::setUp();
		$this->magic = $this->createMock(MagicMapper::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->groups = $this->createMock(IGroupManager::class);
		$this->register = new Register();
		$this->schema = new Schema();
	}//end setUp()

	/**
	 * The handler, with every collaborator recording into $events.
	 *
	 * @return LockHandler The handler.
	 */
	private function handler(): LockHandler {
		$audit = $this->createMock(AuditTrailMapper::class);
		$audit->method('createAuditTrailEntry')->willReturnCallback(
			function (ObjectEntity $object, string $action, array $context): AuditTrail {
				$this->events[] = 'entry:' . $action . ':' . json_encode($context);
				return new AuditTrail();
			}
		);
		$audit->method('createAuditTrail')->willReturnCallback(
			function (?ObjectEntity $old=null, ?ObjectEntity $new=null, ?string $action='update'): AuditTrail {
				$this->events[] = 'trail:' . $action . ':' . ($old?->getUuid()) . '>' . ($new?->getUuid());
				return new AuditTrail();
			}
		);
		$advisory = $this->createMock(AdvisoryLockStore::class);
		$advisory->method('release')->willReturnCallback(
			function (string $identifier): bool {
				$this->events[] = 'advisory-release:' . $identifier;
				return true;
			}
		);
		$runLocks = $this->createMock(RunLockRegistry::class);
		$runLocks->method('forget')->willReturnCallback(
			function (string $runUuid, string $objectUuid): void {
				$this->events[] = 'forget:' . $runUuid . ':' . $objectUuid;
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function ($message, array $context=[]) use ($level): void {
					$this->events[] = $level . ':' . $message;
				}
			);
		}

		return new LockHandler(
			new LockStores(magicMapper: $this->magic, auditTrailMapper: $audit, advisory: $advisory, runLocks: $runLocks),
			$logger,
			new LockAuthorization($this->session, $this->groups, $this->createMock(SchemaMapper::class))
		);
	}//end handler()

	/**
	 * Resolve the identifier to an object locked by a user, optionally for a run.
	 *
	 * @param string      $holder  The holder's uid.
	 * @param string|null $runUuid The run.
	 *
	 * @return void
	 */
	private function lockedBy(string $holder, ?string $runUuid): void {
		$object = new ObjectEntity();
		$object->setUuid(self::OBJ);
		$object->setRegister('1');
		$object->setSchema('2');
		$object->setOwner('owner-uid');
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($holder);
		$holderSession = $this->createMock(IUserSession::class);
		$holderSession->method('getUser')->willReturn($user);
		$object->lock($holderSession, 'step', 3600, $runUuid);

		$this->magic->method('findAcrossAllSources')->willReturnCallback(
			function (...$args) use ($object): array {
				$this->events[] = 'find:' . json_encode(array_slice($args, 1));
				return ['object' => $object, 'register' => $this->register, 'schema' => $this->schema];
			}
		);
		$this->magic->method('unlockObjectEntity')->willReturnCallback(
			function (ObjectEntity $entity, ?Register $register=null, ?Schema $schema=null, ?string $runUuid=null, bool $break=false): ObjectEntity {
				$this->events[] = 'unlock-write:' . json_encode([$register === $this->register, $schema === $this->schema, $runUuid, $break]);
				$after = new ObjectEntity();
				$after->setUuid(self::OBJ . '-after');
				return $after;
			}
		);
	}//end lockedBy()

	/**
	 * The caller is this user.
	 *
	 * @param string $uid   The uid.
	 * @param bool   $admin Whether the user is an administrator.
	 *
	 * @return void
	 */
	private function caller(string $uid, bool $admin=false): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->session->method('getUser')->willReturn($user);
		$this->groups->method('isAdmin')->willReturn($admin);
	}//end caller()

	public function testTheHolderReleasingARunLockPinsTheOrder(): void {
		$this->lockedBy('alice', self::RUN);
		$this->caller('alice');

		$this->assertTrue($this->handler()->unlock(
			request: new UnlockRequest(identifier: self::OBJ, advisory: false, runUuid: self::RUN, break: false)
		));

		$this->assertSame('debug:[LockHandler] Unlocking object', $this->events[0]);
		$this->assertStringStartsWith('find:', $this->events[1]);
		$this->assertSame(
			[
				'unlock-write:' . json_encode([true, true, self::RUN, false]),
				'forget:' . self::RUN . ':' . self::OBJ . '-after',
				'trail:unlock:' . self::OBJ . '>' . self::OBJ . '-after',
				'info:[LockHandler] Object unlocked successfully',
			],
			array_slice($this->events, 2)
		);
	}

	public function testABreakRecordsTheDisplacementBeforeTheUnlockTrail(): void {
		$this->lockedBy('alice', self::RUN);
		$this->caller('admin', true);

		$this->assertTrue($this->handler()->breakLock(identifier: self::OBJ));

		$writes = array_values(array_filter($this->events, static fn (string $e): bool => str_starts_with($e, 'debug:') === false && str_starts_with($e, 'find:') === false && str_starts_with($e, 'info:[LockHandler] Breaking') === false));
		$this->assertSame('unlock-write:' . json_encode([true, true, null, true]), $writes[0]);
		$this->assertSame('forget:' . self::RUN . ':' . self::OBJ . '-after', $writes[1]);
		$this->assertStringStartsWith('entry:lock.broken:{"displacedHolder":', $writes[2]);
		$this->assertStringContainsString('"displacedRun":"' . self::RUN . '"', $writes[2]);
		$this->assertSame('trail:unlock:' . self::OBJ . '>' . self::OBJ . '-after', $writes[3]);
		$this->assertSame('info:[LockHandler] Object unlocked successfully', $writes[4]);
	}

	public function testAnotherUserIsRefusedWithoutAnyWrite(): void {
		$this->lockedBy('alice', null);
		$this->caller('mallory');

		try {
			$this->handler()->unlock(request: new UnlockRequest(identifier: self::OBJ, advisory: false, runUuid: null, break: false));
			$this->fail('expected a refusal');
		} catch (Exception $e) {
			$this->assertSame('User does not have permission to unlock this object', $e->getMessage());
		}

		$this->assertContains('warning:[LockHandler] Unauthorized unlock attempt', $this->events);
		$this->assertContains('error:[LockHandler] Failed to unlock object', $this->events);
		$this->assertSame([], array_values(array_filter($this->events, static fn (string $e): bool => preg_match('/^(unlock-write|forget|trail|entry):/', $e) === 1)));
	}

	public function testAnUnknownIdentifierReleasesTheAdvisoryLock(): void {
		$this->magic->method('findAcrossAllSources')->willThrowException(new DoesNotExistException('none'));
		$this->magic->expects($this->never())->method('unlockObjectEntity');

		$this->assertTrue($this->handler()->unlock(
			request: new UnlockRequest(identifier: 'pending-key', advisory: false, runUuid: null, break: false)
		));
		$this->assertContains('advisory-release:pending-key', $this->events);
	}

	public function testTheAdvisoryFlagSkipsTheLookup(): void {
		$this->magic->expects($this->never())->method('findAcrossAllSources');

		$this->assertTrue($this->handler()->unlock(
			request: new UnlockRequest(identifier: 'pending-key', advisory: true, runUuid: null, break: false)
		));
		$this->assertSame(['debug:[LockHandler] Unlocking object', 'advisory-release:pending-key'], $this->events);
	}
}//end class
