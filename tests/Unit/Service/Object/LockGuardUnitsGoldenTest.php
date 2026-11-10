<?php

declare(strict_types=1);

/**
 * Lock and guard units golden (characterisation) test
 *
 * Pins, on the code as it stood before the D06 complexity refactor, the
 * allowed AND refused outcomes of the lock and guard units that refactor
 * touches: advisory lock acquisition (held, expired, unreadable, free), the
 * run-lock record (skipped, recorded, failure swallowed), the orphan-delete
 * guard (every refusing arm and the allowing path), the group override
 * clause, the draft caller rule and the conflict refusal wording.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use DateTime;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RunObjectLock;
use OCA\OpenRegister\Db\RunObjectLockMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\DraftCaller;
use OCA\OpenRegister\Service\Object\AdvisoryLockStore;
use OCA\OpenRegister\Service\Object\ConflictReport;
use OCA\OpenRegister\Service\Object\DraftCallerRule;
use OCA\OpenRegister\Service\Object\GroupRuleEntries;
use OCA\OpenRegister\Service\Object\GroupRuleRequest;
use OCA\OpenRegister\Service\Object\OrphanDeletionGuard;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\LockRequest;
use OCA\OpenRegister\Service\Object\LockRunStep;
use OCA\OpenRegister\Service\Object\RunLockRegistry;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use OCA\OpenRegister\Exception\LockedException;
use OCA\OpenRegister\Service\Object\DraftSubject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Golden outcomes of lock and guard units.
 */
class LockGuardUnitsGoldenTest extends TestCase {
	/**
	 * A session with or without a user.
	 *
	 * @param string|null $uid The uid, or null for no user
	 *
	 * @return IUserSession
	 */
	private function session(?string $uid): IUserSession {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		return $session;
	}//end session()

	/**
	 * Advisory lock acquisition against every stored state.
	 *
	 * @return void
	 */
	public function testAdvisoryLockAcquire(): void {
		$future = (new DateTime('+1 hour'))->format(DateTime::ATOM);
		$past = (new DateTime('-1 hour'))->format(DateTime::ATOM);
		$states = [
			'free'       => ['', 'ok'],
			'held'       => [json_encode(['expiration' => $future]), 'held'],
			'expired'    => [json_encode(['expiration' => $past]), 'ok'],
			'garbage'    => ['not json', 'ok'],
			'no expiry'  => [json_encode(['user' => 'x']), 'ok'],
			'scalar'     => ['"x"', 'ok'],
		];
		foreach ($states as $label => [$stored, $expected]) {
			$written = [];
			$config = $this->createMock(IAppConfig::class);
			$config->method('getValueString')->willReturn($stored);
			$config->method('setValueString')->willReturnCallback(
				function (string $app, string $key, string $value) use (&$written): bool {
					$written[] = [$app, $key, json_decode($value, true)];
					return true;
				}
			);
			$store = new AdvisoryLockStore(appConfig: $config, userSession: $this->session('anna'), logger: $this->createMock(LoggerInterface::class));

			try {
				$answer = $store->acquire(request: new LockRequest(identifier: 'id-1', process: 'proc', duration: 60, runStep: new LockRunStep(null, null)));
				$this->assertSame('ok', $expected, $label);
				$this->assertSame('id-1', $answer['uuid']);
				$this->assertSame(['anna', 'proc', 60, true], [$answer['locked']['user'], $answer['locked']['process'], $answer['locked']['duration'], $answer['locked']['advisory']]);
				$this->assertCount(1, $written, $label);
				$this->assertSame($answer['locked'], $written[0][2]);
			} catch (LockedException $e) {
				$this->assertSame('held', $expected, $label);
				$this->assertSame("Advisory lock 'id-1' is already held", $e->getMessage());
				$this->assertSame([], $written, 'A held lock writes nothing.');
			}
		}
	}//end testAdvisoryLockAcquire()

	/**
	 * The run-lock record: skipped without a run, recorded with one, a failure swallowed.
	 *
	 * @return void
	 */
	public function testRunLockRecord(): void {
		$rows = [];
		$mapper = $this->createMock(RunObjectLockMapper::class);
		$mapper->method('record')->willReturnCallback(
			function (RunObjectLock $lock) use (&$rows): RunObjectLock {
				$rows[] = $lock;
				return $lock;
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$registry = new RunLockRegistry(registry: $mapper, recording: new \OCA\OpenRegister\Service\Object\ObjectChangeRecording($this->createMock(MagicMapper::class), $this->createMock(AuditTrailMapper::class), $logger, $this->createStub(\OCP\EventDispatcher\IEventDispatcher::class)));
		$object = new ObjectEntity();
		$object->setUuid('o-1');
		$object->setRegister('3');
		$object->setSchema('4');
		$object->setLocked(['expiration' => '2030-01-02T03:04:05+00:00']);

		$registry->record(object: $object, step: new LockRunStep(null, 'n'));
		$registry->record(object: $object, step: new LockRunStep('  ', 'n'));
		$this->assertSame([], $rows);

		$registry->record(object: $object, step: new LockRunStep(' run-1 ', 'n-1'));
		$this->assertCount(1, $rows);
		$this->assertSame(
			['run-1', 'o-1', '3', '4', 'n-1', '2030-01-02T03:04:05+00:00'],
			[$rows[0]->getRunUuid(), $rows[0]->getObjectUuid(), $rows[0]->getRegisterId(), $rows[0]->getSchemaId(), $rows[0]->getNodeId(), $rows[0]->getExpiresAt()?->format(DATE_ATOM)]
		);

		$object->setLocked([]);
		$registry->record(object: $object, step: new LockRunStep('run-2', null));
		$this->assertNull($rows[1]->getExpiresAt());

		$object->setLocked(['expiration' => 'not a date at all']);
		$logger->expects($this->once())->method('warning')->with(
			'[RunLockRegistry] Could not record a run lock; the lock itself stands'
		);
		$registry->record(object: $object, step: new LockRunStep('run-3', null));
		$this->assertCount(2, $rows);
	}//end testRunLockRecord()

	/**
	 * The orphan-delete guard: every refusing arm and the allowing path.
	 *
	 * @return void
	 */
	public function testOrphanDeletionGuard(): void {
		$register = new Register();
		$register->setId(1);
		$plain = new Schema();
		$plain->setId(2);
		$appendOnly = new Schema();
		$appendOnly->setId(2);
		$appendOnly->setAppendOnly(true);
		$row = new ObjectEntity();
		$row->setUuid('r-1');
		$row->setOwner('anna');
		$transferred = new ObjectEntity();
		$transferred->setUuid('r-2');
		$transferred->setRetention(['archiefstatus' => 'overgebracht']);

		$cases = [
			'no session user'        => ['user' => null, 'schema' => $plain, 'row' => $row, 'expected' => false],
			'append-only schema'     => ['schema' => $appendOnly, 'expected' => false],
			'transferred record'     => ['row' => $transferred, 'expected' => false],
			'other organisation'     => ['holds' => false, 'expected' => false],
			'shared data refused'    => ['shared' => false, 'expected' => false],
			'no delete permission'   => ['permission' => false, 'expected' => false],
			'not deletable'          => ['deletable' => false, 'expected' => false],
			'has dependants'         => ['dependants' => true, 'expected' => false],
			'mapper refuses'         => ['assertDeletable' => false, 'expected' => false],
			'mapper read fails'      => ['holds' => 'throw', 'expected' => false],
			'allowed'                => ['expected' => true],
		];
		foreach ($cases as $label => $case) {
			$case += ['user' => 'anna', 'schema' => $plain, 'row' => $row, 'holds' => true, 'shared' => true, 'permission' => true, 'deletable' => true, 'dependants' => false, 'assertDeletable' => true];
			$this->assertSame($case['expected'], $this->orphanGuard($case)->allows(row: $case['row'], register: $register, schema: $case['schema']), $label);
		}
	}//end testOrphanDeletionGuard()

	/**
	 * Build an orphan-delete guard over one case.
	 *
	 * @param array $case The case
	 *
	 * @return OrphanDeletionGuard
	 */
	private function orphanGuard(array $case): OrphanDeletionGuard {
		$mapper = $this->createMock(MagicMapper::class);
		if ($case['holds'] === 'throw') {
			$mapper->method('callerOrganisationsHold')->willThrowException(new \RuntimeException('down'));
		} else {
			$mapper->method('callerOrganisationsHold')->willReturn($case['holds']);
		}

		if ($case['assertDeletable'] === false) {
			$mapper->method('assertDeletable')->willThrowException(new \RuntimeException('locked'));
		}

		$permissions = $this->createMock(PermissionHandler::class);
		$permissions->method('hasPermission')->willReturn($case['permission']);

		$shared = $this->createMock(\OCA\OpenRegister\Service\SharedMasterDataService::class);
		if ($case['shared'] === false) {
			$shared->method('assertWritable')->willThrowException(new \RuntimeException('shared'));
		}

		$organisations = $this->createMock(\OCA\OpenRegister\Service\OrganisationServiceInterface::class);
		$organisations->method('getUserActiveOrganisations')->willReturn([]);

		$analysis = new \OCA\OpenRegister\Dto\DeletionAnalysis(
			deletable: $case['deletable'],
			effects: new \OCA\OpenRegister\Dto\DeletionEffects(cascadeTargets: ($case['dependants'] === true ? ['x'] : []))
		);
		$deleter = $this->createMock(\OCA\OpenRegister\Service\Object\DeleteObject::class);
		$deleter->method('canDelete')->willReturn($analysis);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id) => match ($id) {
				\OCA\OpenRegister\Service\SharedMasterDataService::class => $shared,
				\OCA\OpenRegister\Service\OrganisationServiceInterface::class => $organisations,
				\OCA\OpenRegister\Service\Object\DeleteObject::class => $deleter,
			}
		);

		return new OrphanDeletionGuard(
			objectMapper: $mapper,
			permissionHandler: $permissions,
			userSession: $this->session($case['user']),
			container: $container
		);
	}//end orphanGuard()

	/**
	 * The group override clause and the draft caller rule.
	 *
	 * @return void
	 */
	public function testOverrideClauseAndDraftCallerRule(): void {
		$matcher = $this->createMock(ConditionMatcher::class);
		$matcher->method('objectMatchesConditions')->willReturnCallback(
			static fn (array $object, array $match): bool => ($match['ok'] ?? false) === true
		);
		$matcher->method('filterOrganisationMatchForCreate')->willReturn([]);
		$entries = new GroupRuleEntries(conditionMatcher: $matcher);
		$clause = new ReflectionMethod(GroupRuleEntries::class, 'overrideClausePasses');
		$update = new GroupRuleRequest(authorization: [], action: 'update');
		$create = new GroupRuleRequest(authorization: [], action: 'create');

		$this->assertTrue($clause->invoke($entries, null, $update));
		$this->assertTrue($clause->invoke($entries, [], $update));
		$this->assertFalse($clause->invoke($entries, 'x', $update));
		$this->assertFalse($clause->invoke($entries, 0, $update));
		$this->assertTrue($clause->invoke($entries, ['ok' => true], $update));
		$this->assertFalse($clause->invoke($entries, ['ok' => false], $update));
		$this->assertTrue($clause->invoke($entries, ['ok' => false], $create), 'A create drops the organisation match, leaving nothing to fail.');

		$rule = new DraftCallerRule();
		$schema = new Schema();
		$draft = new ObjectEntity();
		$draft->setStatus(ObjectEntity::STATUS_DRAFT);
		$draft->setOwner('anna');
		$active = new ObjectEntity();
		$active->setStatus(ObjectEntity::STATUS_ACTIVE);

		$this->assertFalse($rule->keptFromCaller(subject: new DraftSubject(object: $active, schema: $schema), caller: new DraftCaller(userId: 'bob', isAdmin: false)));
		$this->assertFalse($rule->keptFromCaller(subject: new DraftSubject(object: $draft, schema: $schema), caller: new DraftCaller(userId: 'anna', isAdmin: false)));
		$this->assertFalse($rule->keptFromCaller(subject: new DraftSubject(object: $draft, schema: $schema), caller: new DraftCaller(userId: 'bob', isAdmin: true)));
		$this->assertTrue($rule->keptFromCaller(subject: new DraftSubject(object: $draft, schema: $schema), caller: new DraftCaller(userId: 'bob', isAdmin: false)));
		$this->assertTrue($rule->keptFromCaller(
			subject: new DraftSubject(object: $draft, schema: $schema),
			caller: new DraftCaller(userId: null, isAdmin: false, commandLine: false)
		));
		$this->assertFalse($rule->keptFromCaller(
			subject: new DraftSubject(object: $draft, schema: $schema),
			caller: new DraftCaller(userId: null, isAdmin: false, commandLine: true)
		));
	}//end testOverrideClauseAndDraftCallerRule()

	/**
	 * The conflict refusal wording and its cause.
	 *
	 * @return void
	 */
	public function testConflictReportWording(): void {
		$properties = $this->createMock(\OCA\OpenRegister\Service\PropertyRbacHandlerInterface::class);
		$properties->method('canReadProperty')->willReturn(true);
		$report = new ConflictReport(properties: $properties);
		$stored = new ObjectEntity();
		$stored->setObject(['a' => 1, 'b' => 2, 'c' => 3]);
		$schema = new Schema();

		$entry = new AuditTrail();
		$entry->setChanged(['a' => ['old' => 0, 'new' => 1], 'b' => ['old' => 1, 'new' => 2], 'z' => 'odd']);
		$entry->setUser('u-9');
		$entry->setUserName('  ');
		$entry->setCreated(new DateTime('2030-01-02T03:04:05+00:00'));

		$two = $report->build(stored: $stored, schema: $schema, sent: ['a' => 5, 'b' => 6, 'c' => 7], intervening: [$entry]);
		$this->assertSame('This object changed since you read it, and somebody else wrote the same fields.', $two['message']);
		$this->assertSame(['a', 'b'], array_keys($two['conflicts']));
		$this->assertSame(['status' => 'conflict', 'sent' => 5, 'read' => 0, 'stored' => 1], $two['conflicts']['a']);
		$this->assertSame(['u-9', '2030-01-02T03:04:05+00:00'], [$two['changedBy'], $two['changedAt']]);

		$one = $report->build(stored: $stored, schema: $schema, sent: ['a' => 5, 'b' => 2], intervening: [$entry]);
		$this->assertSame('This object changed since you read it, and somebody else wrote the same field.', $one['message']);

		$none = $report->build(stored: $stored, schema: $schema, sent: ['c' => 7], intervening: []);
		$this->assertSame('This object changed since you read it. Re-read it and try again.', $none['message']);
		$this->assertSame([[], null, null], [$none['conflicts'], $none['changedBy'], $none['changedAt']]);
	}//end testConflictReportWording()
}//end class
