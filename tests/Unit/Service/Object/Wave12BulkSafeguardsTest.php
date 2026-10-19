<?php

/**
 * Wave-12 Fix 3 regression tests for bulk-save path safeguards.
 *
 * Tests the `SaveObjects::applyBulkSafeguards()` orchestration pipeline added
 * in Wave-12 to close the SHIP-BLOCKING bulk-path bypass documented at
 * `/tmp/wave11-openregister-report.md` SB2 + the @self.organisation injection
 * vector flagged in SB1 (both paths).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObjects;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;

/**
 * @covers \OCA\OpenRegister\Service\Object\SaveObjects::applyBulkSafeguards
 * @covers \OCA\OpenRegister\Service\Object\SaveObjects::stripSelfInjectionFields
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Service\Object\SaveObjects
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Service\Object\DraftCallerRule
 * @uses \OCA\OpenRegister\Service\Object\DraftStatusPolicy
 */
class Wave12BulkSafeguardsTest extends TestCase {

	private MagicMapper&MockObject $objectMapper;

	private SchemaMapper&MockObject $schemaMapper;

	private RegisterMapper&MockObject $registerMapper;

	private SaveObject&MockObject $saveHandler;

	private OrganisationService&MockObject $organisationService;

	private IUserSession&MockObject $userSession;

	private LoggerInterface&MockObject $logger;

	private PermissionHandler&MockObject $permissionHandler;

	private ValidateObject&MockObject $validateHandler;

	private IGroupManager&MockObject $groupManager;

	private SaveObjects $handler;

	protected function setUp(): void {
		parent::setUp();

		$this->objectMapper = $this->createMock(MagicMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->saveHandler = $this->createMock(SaveObject::class);
		$this->organisationService = $this->createMock(OrganisationService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->permissionHandler = $this->createMock(PermissionHandler::class);
		$this->validateHandler = $this->createMock(ValidateObject::class);
		$this->groupManager = $this->createMock(IGroupManager::class);

		// Named arguments: this lineage's SaveObjects orders the optional tail as
		// groupManager, permissionHandler, validateHandler, eventDispatcher,
		// auditTrailMapper — a different order from the one wave-12 was written
		// against (permissionHandler, validateHandler, groupManager). Binding by
		// name keeps this fixture correct regardless of order; the positional list
		// wave-12 shipped would bind permissionHandler into $groupManager here.
		$this->handler = new SaveObjects(
			objectEntityMapper: $this->objectMapper,
			schemaMapper: $this->schemaMapper,
			registerMapper: $this->registerMapper,
			saveHandler: $this->saveHandler,
			userSession: $this->userSession,
			organisationService: $this->organisationService,
			logger: $this->logger,
			groupManager: $this->groupManager,
			permissionHandler: $this->permissionHandler,
			validateHandler: $this->validateHandler
		);

		// Reset static caches between tests.
		$ref = new ReflectionClass(SaveObjects::class);
		foreach (['schemaCache', 'schemaAnalysisCache', 'registerCache'] as $propName) {
			$prop = $ref->getProperty($propName);
			$prop->setAccessible(true);
			$prop->setValue(null, []);
		}
	}//end setUp()

	private function callApplyBulkSafeguards(
		array $objects,
		?Register $register,
		?Schema $schema,
		bool $_rbac,
		bool $_validation,
		array &$result,
	): array {
		$reflection = new ReflectionMethod(SaveObjects::class, 'applyBulkSafeguards');
		$reflection->setAccessible(true);
		return $reflection->invokeArgs(
			$this->handler,
			[$objects, $register, $schema, $_rbac, $_validation, &$result]
		);
	}//end callApplyBulkSafeguards()

	private function initResult(int $totalObjects): array {
		return [
			'saved' => [],
			'updated' => [],
			'unchanged' => [],
			'invalid' => [],
			'errors' => [],
			'statistics' => [
				'totalProcessed' => $totalObjects,
				'saved' => 0,
				'updated' => 0,
				'unchanged' => 0,
				'invalid' => 0,
				'errors' => 0,
				'processingTimeMs' => 0,
			],
		];
	}//end initResult()

	private function mockUser(string $uid, bool $isAdmin): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->with($uid)->willReturn($isAdmin);
		return $user;
	}//end mockUser()

	private function newSchema(int $id, ?array $authorization = null, bool $appendOnly = false): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setTitle('schema-' . $id);
		$schema->setSlug('schema-' . $id);
		$schema->setAuthorization($authorization);
		$schema->setAppendOnly($appendOnly);
		return $schema;
	}//end newSchema()

	private function newRegister(int $id): Register {
		$register = new Register();
		$register->setId($id);
		$register->setConfiguration([]);
		return $register;
	}//end newRegister()

	// === @self injection stripping ===
	public function testBulkSaveAsNonAdminStripsOrganisationInjection(): void {
		// Wave-11 SB1: @self.organisation must be stripped for non-admin callers.
		// The bulk path previously accepted it straight to the _organisation column.
		$this->mockUser('alice', isAdmin: false);

		$schema = $this->newSchema(1);
		$register = $this->newRegister(10);
		$this->permissionHandler->method('hasPermission')->willReturn(true);

		$result = $this->initResult(1);
		$passed = $this->callApplyBulkSafeguards(
			objects: [
				[
					'@self' => [
						'organisation' => 'victim-tenant-uuid',
						'owner' => 'admin',
					],
					'title' => 'attack',
				],
			],
			register: $register,
			schema: $schema,
			_rbac: true,
			_validation: false,
			result: $result
		);

		$this->assertCount(1, $passed, 'Row should pass after safeguard strip, not be rejected');
		$this->assertArrayNotHasKey('organisation', $passed[0]['@self']);
		$this->assertArrayNotHasKey('owner', $passed[0]['@self']);
		// Top-level flat forms must also be stripped (MagicBulkHandler reads both).
		$this->assertArrayNotHasKey('_organisation', $passed[0]);
		$this->assertArrayNotHasKey('_owner', $passed[0]);
		// Business data preserved.
		$this->assertSame('attack', $passed[0]['title']);
	}//end testBulkSaveAsNonAdminStripsOrganisationInjection()

	public function testBulkSaveAsAdminPreservesOrganisationInjection(): void {
		// Admin callers legitimately need to set owner/organisation (e.g.
		// import path attributing rows to original owner). The strip MUST
		// only fire for non-admins.
		$this->mockUser('root', isAdmin: true);

		$schema = $this->newSchema(1);
		$register = $this->newRegister(10);
		$this->permissionHandler->method('hasPermission')->willReturn(true);

		$result = $this->initResult(1);
		$passed = $this->callApplyBulkSafeguards(
			objects: [
				[
					'@self' => [
						'organisation' => 'tenant-uuid-from-source',
						'owner' => 'imported-user',
					],
					'title' => 'import',
				],
			],
			register: $register,
			schema: $schema,
			_rbac: true,
			_validation: false,
			result: $result
		);

		$this->assertCount(1, $passed);
		$this->assertSame('tenant-uuid-from-source', $passed[0]['@self']['organisation']);
		$this->assertSame('imported-user', $passed[0]['@self']['owner']);
	}//end testBulkSaveAsAdminPreservesOrganisationInjection()

	// === Per-row PermissionHandler check ===
	public function testNonAdminBulkSaveDeniedByPermissionHandlerIsRejected(): void {
		// Permission denied → row goes to invalid bucket, statistics updated.
		$this->mockUser('alice', isAdmin: false);

		$schema = $this->newSchema(1, ['create' => ['admin']]);
		$register = $this->newRegister(10);
		$this->permissionHandler
			->expects($this->once())
			->method('hasPermission')
			->with($schema, 'create', 'alice')
			->willReturn(false);

		$result = $this->initResult(1);
		$passed = $this->callApplyBulkSafeguards(
			objects: [['title' => 'rejected']],
			register: $register,
			schema: $schema,
			_rbac: true,
			_validation: false,
			result: $result
		);

		$this->assertSame([], $passed);
		$this->assertSame(1, $result['statistics']['invalid']);
		$this->assertSame(1, $result['statistics']['errors']);
		$this->assertCount(1, $result['invalid']);
		$this->assertStringContainsString('Permission denied', $result['invalid'][0]['error']);
	}//end testNonAdminBulkSaveDeniedByPermissionHandlerIsRejected()

	// === appendOnly enforcement ===
	public function testBulkUpdateRowAgainstAppendOnlySchemaIsRejected(): void {
		// appendOnly: true schemas must not accept UPDATE rows from the
		// bulk path either (single-object path already enforces this at
		// ObjectService::saveObject:1154).
		$this->mockUser('alice', isAdmin: false);

		$schema = $this->newSchema(1, ['create' => ['users'], 'update' => ['users']], appendOnly: true);
		$register = $this->newRegister(10);
		$this->permissionHandler->method('hasPermission')->willReturn(true);

		// Existing object returned → triggers UPDATE classification.
		//
		// 🔑 THE LOOKUP IS THE BATCH PREFETCH, NOT `find()`. This mocked `find()`
		// alone, which is the per-row fallback and is only reached when a group's
		// probe FAILED. `prefetchExistingRows()` runs first and calls
		// `findObjectsByUuidsInRegisterSchema()`; an unmocked mapper answers `[]`,
		// which the classifier reads as a SUCCESSFUL probe that found nothing —
		// "this group was probed and the uuid was not in it" — and returns
		// `create` without ever consulting `find()`.
		//
		// ⚠️ The failure that produced was a row sailing through the appendOnly
		// gate, which is exactly what a real immutability bypass would look like.
		// It was not one: measured with a probe at the gate,
		// `uuid='1111…' action='create' appendOnly=true`. The gate is intact and
		// fires on `update`; the row simply never got classified as one. A test
		// mocking a superseded lookup does not fail loudly — it silently exercises
		// the other branch and reports the safeguard missing.
		$existing = new \OCA\OpenRegister\Db\ObjectEntity();
		$existing->setUuid('11111111-1111-1111-1111-111111111111');
		$this->objectMapper
			->method('find')
			->willReturn($existing);
		$this->objectMapper
			->method('findObjectsByUuidsInRegisterSchema')
			->willReturn(['11111111-1111-1111-1111-111111111111' => $existing]);

		$result = $this->initResult(1);
		$passed = $this->callApplyBulkSafeguards(
			objects: [
				[
					'@self' => ['uuid' => '11111111-1111-1111-1111-111111111111'],
					'title' => 'update-attempt',
				],
			],
			register: $register,
			schema: $schema,
			_rbac: true,
			_validation: false,
			result: $result
		);

		$this->assertSame([], $passed);
		$this->assertSame(1, $result['statistics']['invalid']);
		$this->assertStringContainsString('appendOnly', $result['invalid'][0]['error']);
	}//end testBulkUpdateRowAgainstAppendOnlySchemaIsRejected()

	// === draft ownership ===

	/**
	 * Bulk save of the uuid of somebody else's draft (schema without draftsVisible) is rejected as a denied permission.
	 */
	public function testBulkSaveOverSomebodyElsesDraftIsRejected(): void {
		$this->mockUser('bob', isAdmin: false);
		$schema = $this->newSchema(1);
		$register = $this->newRegister(10);
		// The permission handler would allow it: the hole is that it has no draft rule.
		$this->permissionHandler->method('hasPermission')->willReturn(true);
		$this->storeDraft(owner: 'alice');

		$result = $this->initResult(1);
		$passed = $this->callApplyBulkSafeguards(
			objects: [['@self' => ['uuid' => '22222222-2222-2222-2222-222222222222'], 'title' => 'overwritten']],
			register: $register,
			schema: $schema,
			_rbac: true,
			_validation: false,
			result: $result
		);

		$this->assertSame([], $passed);
		$this->assertSame(1, $result['statistics']['invalid']);
		$this->assertStringContainsString('Permission denied', $result['invalid'][0]['error']);
	}//end testBulkSaveOverSomebodyElsesDraftIsRejected()

	/**
	 * An administrator is not kept from another user's draft by the bulk save, as on the read path.
	 */
	public function testAnAdministratorMayBulkSaveOverSomebodyElsesDraft(): void {
		$this->mockUser('root', isAdmin: true);
		$schema = $this->newSchema(1);
		$register = $this->newRegister(10);
		$this->permissionHandler->method('hasPermission')->willReturn(true);
		$this->storeDraft(owner: 'alice');

		$result = $this->initResult(1);
		$passed = $this->callApplyBulkSafeguards(
			objects: [['@self' => ['uuid' => '22222222-2222-2222-2222-222222222222'], 'title' => 'admin edit']],
			register: $register,
			schema: $schema,
			_rbac: true,
			_validation: false,
			result: $result
		);

		$this->assertCount(1, $passed);
		$this->assertSame(0, $result['statistics']['invalid']);
	}//end testAnAdministratorMayBulkSaveOverSomebodyElsesDraft()

	/**
	 * On an appendOnly schema the draft rule answers first: a non-owner is not told by the appendOnly message that the uuid exists.
	 */
	public function testAppendOnlyDoesNotRevealSomebodyElsesDraft(): void {
		$this->mockUser('bob', isAdmin: false);
		$schema = $this->newSchema(1, null, appendOnly: true);
		$register = $this->newRegister(10);
		$this->permissionHandler->method('hasPermission')->willReturn(true);
		$this->storeDraft(owner: 'alice');

		$result = $this->initResult(1);
		$this->callApplyBulkSafeguards(
			objects: [['@self' => ['uuid' => '22222222-2222-2222-2222-222222222222'], 'title' => 'probe']],
			register: $register,
			schema: $schema,
			_rbac: true,
			_validation: false,
			result: $result
		);

		$this->assertStringContainsString('Permission denied', $result['invalid'][0]['error']);
		$this->assertStringNotContainsString('appendOnly', $result['invalid'][0]['error']);
	}//end testAppendOnlyDoesNotRevealSomebodyElsesDraft()

	/**
	 * The owner's own bulk save of their draft still goes through.
	 */
	public function testBulkSaveOverYourOwnDraftPasses(): void {
		$this->mockUser('alice', isAdmin: false);
		$schema = $this->newSchema(1);
		$register = $this->newRegister(10);
		$this->permissionHandler->method('hasPermission')->willReturn(true);
		$this->storeDraft(owner: 'alice');

		$result = $this->initResult(1);
		$passed = $this->callApplyBulkSafeguards(
			objects: [['@self' => ['uuid' => '22222222-2222-2222-2222-222222222222'], 'title' => 'mine']],
			register: $register,
			schema: $schema,
			_rbac: true,
			_validation: false,
			result: $result
		);

		$this->assertCount(1, $passed);
		$this->assertSame(0, $result['statistics']['invalid']);
	}//end testBulkSaveOverYourOwnDraftPasses()

	/**
	 * A decoy `@self.uuid` next to the victim draft's `id` is refused: the writer takes `id`, so the guard may not take the decoy.
	 */
	public function testDecoyUuidNextToVictimIdIsRefused(): void {
		$this->mockUser('bob', isAdmin: false);
		$schema = $this->newSchema(1);
		$register = $this->newRegister(10);
		$this->permissionHandler->method('hasPermission')->willReturn(true);
		$this->storeDraft(owner: 'alice');

		$result = $this->initResult(2);
		$passed = $this->callApplyBulkSafeguards(
			objects: [
				['id' => '22222222-2222-2222-2222-222222222222', '@self' => ['uuid' => '99999999-9999-9999-9999-999999999999'], 'title' => 'overwritten'],
				['@self' => ['id' => '22222222-2222-2222-2222-222222222222', 'uuid' => '99999999-9999-9999-9999-999999999999'], 'title' => 'overwritten too'],
			],
			register: $register,
			schema: $schema,
			_rbac: true,
			_validation: false,
			result: $result
		);

		$this->assertSame([], $passed);
		$this->assertSame(2, $result['statistics']['invalid']);
		$this->assertStringContainsString('more than one identity', $result['invalid'][0]['error']);
	}//end testDecoyUuidNextToVictimIdIsRefused()

	/**
	 * The same identity in `id` and `@self` is one identity: the owner's own draft still passes, somebody else's is refused.
	 */
	public function testAgreeingIdentitiesAreOneIdentity(): void {
		$this->mockUser('bob', isAdmin: false);
		$schema = $this->newSchema(1);
		$register = $this->newRegister(10);
		$this->permissionHandler->method('hasPermission')->willReturn(true);
		$this->storeDraft(owner: 'alice');

		$result = $this->initResult(1);
		$passed = $this->callApplyBulkSafeguards(
			objects: [['id' => '22222222-2222-2222-2222-222222222222', '@self' => ['uuid' => '22222222-2222-2222-2222-222222222222'], 'title' => 'x']],
			register: $register,
			schema: $schema,
			_rbac: true,
			_validation: false,
			result: $result
		);

		$this->assertSame([], $passed);
		$this->assertStringContainsString('Permission denied', $result['invalid'][0]['error']);
	}//end testAgreeingIdentitiesAreOneIdentity()

	/**
	 * Store a draft of the given owner as the existing row of uuid 2222….
	 */
	private function storeDraft(string $owner): void {
		$draft = new \OCA\OpenRegister\Db\ObjectEntity();
		$draft->setUuid('22222222-2222-2222-2222-222222222222');
		$draft->setStatus('draft');
		$draft->setOwner($owner);
		$this->objectMapper->method('find')->willReturn($draft);
		$this->objectMapper->method('findObjectsByUuidsInRegisterSchema')->willReturn(['22222222-2222-2222-2222-222222222222' => $draft]);
	}//end storeDraft()

	public function testBulkCreateAgainstAppendOnlySchemaIsAllowed(): void {
		// appendOnly blocks UPDATE only; INSERT must still succeed.
		$this->mockUser('alice', isAdmin: false);

		$schema = $this->newSchema(1, ['create' => ['users']], appendOnly: true);
		$register = $this->newRegister(10);
		$this->permissionHandler->method('hasPermission')->willReturn(true);

		$result = $this->initResult(1);
		$passed = $this->callApplyBulkSafeguards(
			objects: [['title' => 'new-record']],
			register: $register,
			schema: $schema,
			_rbac: true,
			_validation: false,
			result: $result
		);

		$this->assertCount(1, $passed);
	}//end testBulkCreateAgainstAppendOnlySchemaIsAllowed()

	// === Reserved keys policy ===
	public function testRbacFalseFromNonAdminPayloadIsIgnored(): void {
		// The reserved-key `_rbac: false` must NOT let non-admins skip
		// permission checks. The flag effectively resets to `true` for
		// non-admin callers — PermissionHandler is still consulted.
		$this->mockUser('alice', isAdmin: false);

		$schema = $this->newSchema(1, ['create' => ['admin']]);
		$register = $this->newRegister(10);

		// Even though the caller passed _rbac=false, PermissionHandler is
		// still called (non-admin can't bypass).
		$this->permissionHandler
			->expects($this->once())
			->method('hasPermission')
			->willReturn(false);

		$result = $this->initResult(1);
		$passed = $this->callApplyBulkSafeguards(
			objects: [['title' => 'attack']],
			register: $register,
			schema: $schema,
			_rbac: false,
			_validation: false,
			result: $result
		);

		$this->assertSame([], $passed);
	}//end testRbacFalseFromNonAdminPayloadIsIgnored()

	// === Per-row Opis validation ===
	public function testValidationFailureRejectsRow(): void {
		// When the caller passes _validation:true, per-row Opis validation
		// runs and failed rows go to invalid.
		$this->mockUser('admin1', isAdmin: true);

		$schema = $this->newSchema(1);
		$register = $this->newRegister(10);
		$this->permissionHandler->method('hasPermission')->willReturn(true);

		$invalidResult = $this->createMock(\Opis\JsonSchema\ValidationResult::class);
		$invalidResult->method('isValid')->willReturn(false);
		$this->validateHandler
			->expects($this->once())
			->method('validateObject')
			->willReturn($invalidResult);
		$this->validateHandler
			->method('generateErrorMessage')
			->willReturn('title must be a string');

		$result = $this->initResult(1);
		$passed = $this->callApplyBulkSafeguards(
			objects: [['title' => 123]],
			register: $register,
			schema: $schema,
			_rbac: true,
			_validation: true,
			result: $result
		);

		$this->assertSame([], $passed);
		$this->assertSame(1, $result['statistics']['invalid']);
		$this->assertStringContainsString('Schema validation failed', $result['invalid'][0]['error']);
	}//end testValidationFailureRejectsRow()
}//end class
