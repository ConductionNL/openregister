<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A bulk save refuses a locked, archived or frozen stored row, as a single save does.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @license  EUPL-1.2
 * @link     https://github.com/ConductionNL/openregister
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Flow\FlowRunContext;
use OCA\OpenRegister\Service\Object\BulkRowChecks;
use OCA\OpenRegister\Service\Object\BulkRowRules;
use OCA\OpenRegister\Service\Object\BulkSaveReporting;
use OCA\OpenRegister\Service\Object\BulkSaveSession;
use OCA\OpenRegister\Service\Object\BulkSaveStores;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\SafeguardBatchRun;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObjects;
use OCA\OpenRegister\Service\Object\BulkBatchDefaults;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;
use OCA\OpenRegister\Service\Flow\FlowRunFrame;

/**
 * Caller `alice` sends one row naming a stored row in a given state. The permission handler
 * admits or refuses as the test says; the row's state decides the rest.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObjects
 * @covers \OCA\OpenRegister\Service\Object\BulkRowRules
 * @covers \OCA\OpenRegister\Service\Object\BulkSafeguardRun
 * @uses   \OCA\OpenRegister\Service\Flow\FlowRunContext
 * @uses   \OCA\OpenRegister\Service\Object\DraftCallerRule
 * @uses   \OCA\OpenRegister\Service\Object\DraftStatusPolicy
 * @uses   \OCA\OpenRegister\Service\Rbac\ExplicitReveal
 * @uses   \OCA\OpenRegister\Exception\LockedException
 * @uses   \OCA\OpenRegister\Exception\ObjectStateWriteException
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Service\AnonymousEvaluationContext
 * @uses \OCA\OpenRegister\Service\SystemOperationContext
 */
class SaveObjectsStoredStateGuardTest extends TestCase {

	private const UUID = '11111111-1111-4111-8111-111111111111';

	private const RUN = '22222222-2222-4222-8222-222222222222';

	private const BOB_LOCK = ['kind' => ObjectEntity::LOCK_KIND_USER, 'user' => 'bob'];

	private const ALICE_LOCK = ['kind' => ObjectEntity::LOCK_KIND_USER, 'user' => 'alice'];

	private const RUN_LOCK = ['kind' => ObjectEntity::LOCK_KIND_RUN, 'runUuid' => self::RUN, 'user' => 'bob'];

	private const STATE = ['by' => 'bob', 'at' => '2026-01-01T00:00:00+00:00', 'reason' => 'kept'];

	/**
	 * The stored row the batch names.
	 */
	private ObjectEntity $stored;

	/**
	 * What the batch lookup finds, by uuid.
	 *
	 * @var array<string, ObjectEntity>
	 */
	private array $prefetched;

	/**
	 * Whether the permission handler grants the row's action.
	 */
	private bool $granted = true;

	/**
	 * The flow-run stack the write happens under.
	 */
	private FlowRunContext $flow;

	protected function setUp(): void {
		$this->flow = new FlowRunContext();
		$this->stored = new ObjectEntity();
		$this->stored->setUuid(self::UUID);
		$this->stored->setOwner('bob');
		$this->stored->setOrganisation('org-a');
		$this->stored->setObject(['title' => 'stored']);
		$this->prefetched = [self::UUID => $this->stored];
	}//end setUp()

	/**
	 * Screen one row and answer its refusal, or null when it passes.
	 *
	 * @param array $flags The rbac and multitenancy flags sent.
	 *
	 * @return string|null
	 */
	private function screen(array $flags = ['rbac' => true, 'multitenancy' => true]): ?string {
		$schema = new Schema();
		$schema->setId(681);
		$schema->setSlug('planned');
		$register = new Register();
		$register->setId(10);

		$result = ['invalid' => [], 'statistics' => ['invalid' => 0, 'errors' => 0]];
		$rows = [['id' => self::UUID, 'title' => 'overwritten']];
		$batch = new SafeguardBatchRun(objects: $rows, defaults: new BulkBatchDefaults($register, $schema), flags: $flags + ['validation' => false], result: $result);
		$passed = (new ReflectionMethod(SaveObjects::class, 'applyBulkSafeguards'))->invokeArgs($this->handler(), [$batch]);
		$result = $batch->result;
		if ($passed !== []) {
			return null;
		}

		return $result['invalid'][0]['error'];
	}//end screen()

	/**
	 * The bulk save handler over a mapper that finds the stored row.
	 *
	 * @return SaveObjects
	 */
	private function handler(): SaveObjects {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$mapper = $this->createMock(MagicMapper::class);
		$mapper->method('findObjectsByUuidsInRegisterSchema')->willReturnCallback(fn (): array => $this->prefetched);
		$mapper->method('callerOrganisationsHold')->willReturn(true);
		$permissions = $this->createMock(PermissionHandler::class);
		$permissions->method('hasPermission')->willReturnCallback(PermissionAskArgs::spread(fn (): bool => $this->granted));

		return new SaveObjects(
			stores: new BulkSaveStores(catalog: new \OCA\OpenRegister\Db\SchemaCatalog(registers: $this->createMock(RegisterMapper::class), schemas: $this->createMock(SchemaMapper::class), tables: $mapper), saveHandler: $this->createMock(SaveObject::class)),
			session: new BulkSaveSession(userSession: $session, organisationService: $this->createMock(OrganisationService::class), groupManager: $this->createMock(IGroupManager::class)),
			reporting: new BulkSaveReporting(logger: new NullLogger()),
			checks: new BulkRowChecks(permissionHandler: $permissions, rowRules: new BulkRowRules(runContext: $this->flow))
		);
	}//end handler()

	public function testARowLockedByAnotherUserIsRefused(): void {
		$this->stored->setLocked(self::BOB_LOCK);

		$this->assertSame(
			expected: 'Cannot update object: Object is locked by bob. Please unlock the object before attempting to update it.',
			actual: $this->screen()
		);
	}

	public function testALockHoldsWithRbacSwitchedOff(): void {
		$this->stored->setLocked(self::BOB_LOCK);

		$this->assertStringContainsString(
			needle: 'locked by bob',
			haystack: (string)$this->screen(flags: ['rbac' => false, 'multitenancy' => false])
		);
	}

	public function testTheLockHolderPasses(): void {
		$this->stored->setLocked(self::ALICE_LOCK);

		$this->assertNull($this->screen());
	}

	public function testTheRunHoldingALockPasses(): void {
		$this->stored->setLocked(self::RUN_LOCK);
		$this->flow->push(frame: new FlowRunFrame(runUuid: self::RUN, nodeId: 'write', sequence: 1));

		$this->assertNull($this->screen());
	}

	public function testARunLockRefusesAPerson(): void {
		$this->stored->setLocked(self::RUN_LOCK);

		$this->assertStringContainsString(needle: 'Object is locked by', haystack: (string)$this->screen());
	}

	public function testALockIsNotAnnouncedToACallerWithoutTheUpdateRight(): void {
		$this->stored->setLocked(self::BOB_LOCK);
		$this->granted = false;

		$this->assertSame(
			expected: 'Permission denied for action update on schema planned',
			actual: $this->screen()
		);
	}

	public function testAnArchivedRowIsRefused(): void {
		$this->stored->setArchived(self::STATE);

		$this->assertStringContainsString(
			needle: 'Cannot write to this object: it was archived by',
			haystack: (string)$this->screen()
		);
	}

	public function testAFrozenRowIsRefused(): void {
		$this->stored->setFrozen(self::STATE);

		$this->assertStringContainsString(
			needle: 'Cannot write to this object: it was frozen by',
			haystack: (string)$this->screen()
		);
	}

	public function testAnOrdinaryStoredRowPasses(): void {
		$this->assertNull($this->screen());
	}

	public function testACreateIsNotAskedAboutState(): void {
		$this->stored->setLocked(self::BOB_LOCK);
		$this->prefetched = [];

		$this->assertNull($this->screen());
	}
}//end class
