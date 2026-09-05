<?php

/**
 * ObjectService register/schema context restoration.
 *
 * Pins the contract that an entry point which resolves its OWN scope from its
 * arguments must not leave that scope on the shared service for the next
 * caller. See openregister#3408 for the defect the missing contract produced.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\ViewMapper;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\Object\AuditHandler;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\CascadingHandler;
use OCA\OpenRegister\Service\Object\DataManipulationHandler;
use OCA\OpenRegister\Service\Object\DeleteObject;
use OCA\OpenRegister\Service\Object\FacetHandler;
use OCA\OpenRegister\Service\Object\GetObject;
use OCA\OpenRegister\Service\Object\LockHandler;
use OCA\OpenRegister\Service\Object\MergeHandler;
use OCA\OpenRegister\Service\Object\MetadataHandler;
use OCA\OpenRegister\Service\Object\MigrationHandler;
use OCA\OpenRegister\Service\Object\PerformanceOptimizationHandler;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\QueryHandler;
use OCA\OpenRegister\Service\Object\RelationHandler;
use OCA\OpenRegister\Service\Object\RenderObject;
use OCA\OpenRegister\Service\Object\RevertHandler;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObjects;
use OCA\OpenRegister\Service\Object\SearchQueryHandler;
use OCA\OpenRegister\Service\Object\UtilityHandler;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Service\Object\ValidationHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\ObjectSource\ObjectSourceRegistry;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\SearchTrailService;
use OCA\OpenRegister\Service\SettingsService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\IAppContainer;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A WRITE MUST NOT SCOPE THE NEXT READ.
 *
 * `ObjectService` is one shared instance for a whole request — and, under
 * `occ`, for a whole `upgrade` run. `setRegister()` / `setSchema()` are the
 * CALLER's way of anchoring it and are meant to persist. An entry point that
 * takes `register:` / `schema:` ARGUMENTS is scoping itself for one operation,
 * and that scope belongs to the operation, not to whoever calls next.
 *
 * `find()` has restored its context in a `finally` since BUG-OBJ-13
 * (openregister#1520). `saveObject()` did not, and openregister#3408 is what
 * that cost: `ImportCredentialBrokerRegister` wrote its two example objects
 * with `saveObject(register: credential-broker, schema: brokeredcredential)`,
 * and four repair steps later `MigrateRegisterFlowsToTable` — whose own read
 * named its scope in a key nothing reads — inherited that pair and copied two
 * `brokeredcredential` examples into `openregister_flows` as flows.
 * Deterministically, on every install, with no error anywhere.
 *
 * These tests assert what the SERVICE HANDS ITS HANDLER, not what the caller
 * gets back. #3408's finding was that every fixture mocked `findAll()` to
 * answer regardless of what it was asked, and a mock that answers any question
 * cannot report a wrong one.
 */
class ObjectServiceContextRestorationTest extends TestCase {

	private const BROKER_REGISTER = 'credential-broker';

	private const BROKER_SCHEMA = 'brokeredcredential';

	private const FLOW_REGISTER = 'flows';

	private const FLOW_SCHEMA = 'flow';

	/** @var GetObject&MockObject */
	private GetObject $getHandler;

	/** @var SaveObject&MockObject */
	private SaveObject $saveHandler;

	/** @var DeleteObject&MockObject */
	private DeleteObject $deleteHandler;

	/** @var SaveObjects&MockObject */
	private SaveObjects $saveObjectsHandler;

	/** @var MagicMapper&MockObject */
	private MagicMapper $objectMapper;

	private ObjectService $service;

	/**
	 * The register/schema pair handed to the last `getHandler->findAll()` call.
	 *
	 * @var array{register: ?Register, schema: ?Schema}|null
	 */
	private ?array $findAllScope = null;

	/**
	 * The register/schema pair handed to the last `saveHandler->saveObject()`.
	 *
	 * @var array{register: mixed, schema: mixed}|null
	 */
	private ?array $saveScope = null;

	/**
	 * The row `objectMapper->find()` answers with, or null to answer "absent".
	 *
	 * Absent is the default because the save tests are CREATEs. The delete test
	 * sets one, because a delete of nothing never reaches the scope handling
	 * this file is about.
	 *
	 * @var ObjectEntity|null
	 */
	private ?ObjectEntity $existingObject = null;

	/**
	 * Registers this instance can resolve, by slug.
	 *
	 * @var array<string, Register>
	 */
	private array $registers = [];

	/**
	 * Schemas this instance can resolve, by slug.
	 *
	 * @var array<string, Schema>
	 */
	private array $schemas = [];

	/**
	 * Build an ObjectService whose save path completes on mocks alone.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->buildFixtureRegisters();

		$this->getHandler = $this->createMock(GetObject::class);
		$this->saveHandler = $this->createMock(SaveObject::class);
		$this->deleteHandler = $this->createMock(DeleteObject::class);
		$this->saveObjectsHandler = $this->createMock(SaveObjects::class);
		$this->objectMapper = $this->createMock(MagicMapper::class);

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturnCallback(
			function ($id, ...$rest): Register {
				$register = ($this->registers[(string)$id] ?? null);
				if ($register === null) {
					throw new DoesNotExistException('no such register: ' . (string)$id);
				}

				return $register;
			}
		);

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturnCallback(
			function ($id, ...$rest): Schema {
				$schema = ($this->schemas[(string)$id] ?? null);
				if ($schema === null) {
					throw new DoesNotExistException('no such schema: ' . (string)$id);
				}

				return $schema;
			}
		);
		// Register-scoped resolution: only schemas whose id is listed on the
		// register resolve inside it, which is what makes the register a
		// boundary and what these fixtures need to model.
		$schemaMapper->method('findInIds')->willReturnCallback(
			function ($id, array $schemaIds): ?Schema {
				$schema = ($this->schemas[(string)$id] ?? null);
				if ($schema === null || in_array($schema->getId(), $schemaIds, true) === false) {
					return null;
				}

				return $schema;
			}
		);

		$renderHandler = $this->createMock(RenderObject::class);
		$renderHandler->method('renderEntity')->willReturnArgument(0);
		$renderHandler->method('renderEntities')->willReturnArgument(0);

		// Record the scope the service resolves for a LIST read. This is the
		// assertion #3408 found missing everywhere.
		$this->getHandler->method('findAll')->willReturnCallback(
			function (
				?int $limit = null,
				?int $offset = null,
				array $filters = [],
				array $sort = [],
				?string $search = null,
				?array $_extend = [],
				bool $files = false,
				?string $uses = null,
				?Register $register = null,
				?Schema $schema = null,
				?array $ids = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
			): array {
				$this->findAllScope = ['register' => $register, 'schema' => $schema];
				return [];
			}
		);

		$this->saveHandler->method('applyAlwaysDefaults')->willReturnArgument(1);
		$this->saveHandler->method('seedLifecycleFieldOnCreate')->willReturnArgument(1);
		$this->saveHandler->method('saveObject')->willReturnCallback(
			function ($register, $schema, array $data, ...$rest): ObjectEntity {
				$this->saveScope = ['register' => $register, 'schema' => $schema];

				$entity = new ObjectEntity();
				$entity->setUuid('saved-uuid');
				$entity->setObject($data);
				return $entity;
			}
		);

		// New object: no row exists yet, so the save is a CREATE. A test that
		// needs an existing row sets $this->existingObject.
		$this->objectMapper->method('find')->willReturnCallback(
			function (...$args): ObjectEntity {
				if ($this->existingObject === null) {
					throw new DoesNotExistException('not found');
				}

				return $this->existingObject;
			}
		);

		$dateTimeNormalizer = $this->createMock(DateTimeNormalizer::class);
		$dateTimeNormalizer->method('normalize')->willReturn(null);

		$cascadingHandler = $this->createMock(CascadingHandler::class);
		$cascadingHandler->method('handlePreValidationCascading')->willReturnCallback(
			static fn (array $object, mixed $schema, ?string $uuid, ?int $register): array => [$object, $uuid]
		);

		$this->service = new ObjectService(
			dataManipHandler: $this->createMock(DataManipulationHandler::class),
			deleteHandler: $this->deleteHandler,
			getHandler: $this->getHandler,
			permissionHandler: $this->createMock(PermissionHandler::class),
			renderHandler: $renderHandler,
			saveHandler: $this->saveHandler,
			saveObjectsHandler: $this->saveObjectsHandler,
			searchQueryHandler: $this->createMock(SearchQueryHandler::class),
			validateHandler: $this->createMock(ValidateObject::class),
			lockHandler: $this->createMock(LockHandler::class),
			auditHandler: $this->createMock(AuditHandler::class),
			relationHandler: $this->createMock(RelationHandler::class),
			mergeHandler: $this->createMock(MergeHandler::class),
			facetHandler: $this->createMock(FacetHandler::class),
			metadataHandler: $this->createMock(MetadataHandler::class),
			perfOptHandler: $this->createMock(PerformanceOptimizationHandler::class),
			queryHandler: $this->createMock(QueryHandler::class),
			revertHandler: $this->createMock(RevertHandler::class),
			utilityHandler: $this->createMock(UtilityHandler::class),
			validationHandler: $this->createMock(ValidationHandler::class),
			cascadingHandler: $cascadingHandler,
			migrationHandler: $this->createMock(MigrationHandler::class),
			registerMapper: $registerMapper,
			schemaMapper: $schemaMapper,
			viewMapper: $this->createMock(ViewMapper::class),
			objectMapper: $this->objectMapper,
			fileService: $this->createMock(FileService::class),
			userSession: $this->createMock(IUserSession::class),
			searchTrailService: $this->createMock(SearchTrailService::class),
			groupManager: $this->createMock(IGroupManager::class),
			userManager: $this->createMock(IUserManager::class),
			organisationService: $this->createMock(OrganisationService::class),
			logger: $this->createMock(LoggerInterface::class),
			cacheHandler: $this->createMock(CacheHandler::class),
			settingsService: $this->createMock(SettingsService::class),
			dateTimeNormalizer: $dateTimeNormalizer,
			container: $this->createMock(IAppContainer::class),
			objectSourceRegistry: $this->createMock(ObjectSourceRegistry::class)
		);
	}//end setUp()

	/**
	 * Two register/schema pairs, each carrying only its own schema.
	 *
	 * The pairs are the real ones from openregister#3408 so the test names the
	 * defect it pins rather than an abstraction of it.
	 *
	 * @return void
	 */
	private function buildFixtureRegisters(): void {
		$brokerSchema = new Schema();
		$brokerSchema->setId(9101);
		$brokerSchema->setSlug(self::BROKER_SCHEMA);
		$brokerSchema->setHardValidation(false);

		$flowSchema = new Schema();
		$flowSchema->setId(9102);
		$flowSchema->setSlug(self::FLOW_SCHEMA);
		$flowSchema->setHardValidation(false);

		$brokerRegister = new Register();
		$brokerRegister->setId(701);
		$brokerRegister->setSlug(self::BROKER_REGISTER);
		$brokerRegister->setSchemas([$brokerSchema->getId()]);

		$flowRegister = new Register();
		$flowRegister->setId(702);
		$flowRegister->setSlug(self::FLOW_REGISTER);
		$flowRegister->setSchemas([$flowSchema->getId()]);

		$this->registers = [
			self::BROKER_REGISTER => $brokerRegister,
			self::FLOW_REGISTER => $flowRegister,
		];
		$this->schemas = [
			self::BROKER_SCHEMA => $brokerSchema,
			self::FLOW_SCHEMA => $flowSchema,
		];
	}//end buildFixtureRegisters()

	/**
	 * Write one object into the credential-broker register/schema.
	 *
	 * @return void
	 */
	private function saveIntoBrokerScope(): void {
		$this->service->saveObject(
			object: ['name' => 'example credential'],
			register: self::BROKER_REGISTER,
			schema: self::BROKER_SCHEMA,
			_rbac: false,
			_multitenancy: false
		);
	}//end saveIntoBrokerScope()

	// =====================================================================
	// THE CONTRACT
	// =====================================================================

	/**
	 * THE DEFECT, REPRODUCED: a save must not scope the next unscoped read.
	 *
	 * This is openregister#3408 in miniature. A repair step writes with an
	 * explicit register/schema; a later, unrelated step reads without naming
	 * one. Before the fix the reader was handed the WRITER's pair and read the
	 * writer's table — which is how two `brokeredcredential` example objects
	 * became two rows in `openregister_flows`.
	 *
	 * The assertion is on the arguments handed to the GetObject handler, which
	 * is the only place the resolved scope is observable.
	 *
	 * @return void
	 */
	public function testASaveDoesNotScopeATheNextUnscopedRead(): void {
		$this->saveIntoBrokerScope();

		// A later caller reads without naming a scope. Nothing anchored this
		// service before the save, so there is nothing for it to inherit.
		$this->service->findAll(config: [], _rbac: false, _multitenancy: false);

		$this->assertNotNull($this->findAllScope, 'The read never reached the handler.');
		$this->assertNull(
			$this->findAllScope['register'],
			'The unscoped read inherited the save\'s REGISTER. This is openregister#3408.'
		);
		$this->assertNull(
			$this->findAllScope['schema'],
			'The unscoped read inherited the save\'s SCHEMA. This is openregister#3408.'
		);
	}//end testASaveDoesNotScopeATheNextUnscopedRead()

	/**
	 * A save restores the CALLER's context rather than clearing it.
	 *
	 * The fluent pattern — `setRegister($r)->setSchema($s)` and then a read —
	 * is how a dozen controllers scope their work, and it must survive a write
	 * that names a different scope in between. Restoring, not clearing, is what
	 * makes that true; a `clearCurrents()` at the end of a save would fix the
	 * leak and break this.
	 *
	 * @return void
	 */
	public function testASaveRestoresTheCallersOwnContextRatherThanClearingIt(): void {
		$this->service->setRegister(self::FLOW_REGISTER);
		$this->service->setSchema(self::FLOW_SCHEMA);

		$this->saveIntoBrokerScope();

		$this->service->findAll(config: [], _rbac: false, _multitenancy: false);

		$this->assertNotNull($this->findAllScope, 'The read never reached the handler.');
		$this->assertSame(
			self::FLOW_REGISTER,
			$this->findAllScope['register']?->getSlug(),
			'The caller anchored this service on `flows` and must still be on it after an unrelated save.'
		);
		$this->assertSame(
			self::FLOW_SCHEMA,
			$this->findAllScope['schema']?->getSlug(),
			'The caller anchored this service on `flow` and must still be on it after an unrelated save.'
		);
	}//end testASaveRestoresTheCallersOwnContextRatherThanClearingIt()

	/**
	 * NEGATIVE CONTROL: the save itself is still scoped by its own arguments.
	 *
	 * Restoring afterwards must not mean the write ran unscoped. Without this
	 * the leak could be "fixed" by never resolving the scope at all, and the
	 * two tests above would still pass while every write went to the wrong
	 * table.
	 *
	 * @return void
	 */
	public function testTheSaveItselfStillRunsInTheScopeItsArgumentsName(): void {
		$this->service->setRegister(self::FLOW_REGISTER);
		$this->service->setSchema(self::FLOW_SCHEMA);

		$this->saveIntoBrokerScope();

		$this->assertNotNull($this->saveScope, 'The save never reached the handler.');
		$this->assertInstanceOf(Register::class, $this->saveScope['register']);
		$this->assertInstanceOf(Schema::class, $this->saveScope['schema']);
		$this->assertSame(self::BROKER_REGISTER, $this->saveScope['register']->getSlug());
		$this->assertSame(self::BROKER_SCHEMA, $this->saveScope['schema']->getSlug());
	}//end testTheSaveItselfStillRunsInTheScopeItsArgumentsName()

	/**
	 * NEGATIVE CONTROL: a save that names NO scope changes nothing.
	 *
	 * `createObject()` and `updateObject()` both delegate to
	 * `saveObject(object: $data)` with no register or schema, relying on the
	 * caller having anchored the service first. For them the snapshot and the
	 * restore are the same context, so the fix is a no-op — and that has to
	 * stay true or every one of those callers writes into nothing.
	 *
	 * @return void
	 */
	public function testASaveThatNamesNoScopeLeavesTheCallersContextUntouched(): void {
		$this->service->setRegister(self::FLOW_REGISTER);
		$this->service->setSchema(self::FLOW_SCHEMA);

		$this->service->saveObject(
			object: ['name' => 'anchored by the caller'],
			_rbac: false,
			_multitenancy: false
		);

		$this->assertNotNull($this->saveScope, 'The save never reached the handler.');
		$this->assertSame(
			self::FLOW_REGISTER,
			$this->saveScope['register']?->getSlug(),
			'A save with no scope arguments must still write into the caller\'s anchored register.'
		);

		$this->service->findAll(config: [], _rbac: false, _multitenancy: false);
		$this->assertSame(self::FLOW_REGISTER, $this->findAllScope['register']?->getSlug());
		$this->assertSame(self::FLOW_SCHEMA, $this->findAllScope['schema']?->getSlug());
	}//end testASaveThatNamesNoScopeLeavesTheCallersContextUntouched()

	/**
	 * A save that THROWS must not leave its half-resolved scope behind either.
	 *
	 * A failed operation's leftovers are the least meaningful of all — nothing
	 * downstream asked for that scope and nothing succeeded in it. `find()`
	 * restores in a `finally` for this reason; so does the write path now.
	 *
	 * @return void
	 */
	public function testAFailedSaveDoesNotLeaveItsScopeBehind(): void {
		$this->service->setRegister(self::FLOW_REGISTER);
		$this->service->setSchema(self::FLOW_SCHEMA);

		$this->saveHandler->method('saveObject')->willThrowException(new \RuntimeException('write failed'));

		try {
			$this->service->saveObject(
				object: ['name' => 'doomed'],
				register: self::BROKER_REGISTER,
				schema: self::BROKER_SCHEMA,
				_rbac: false,
				_multitenancy: false
			);
			$this->fail('The save was expected to throw.');
		} catch (\RuntimeException $e) {
			$this->assertSame('write failed', $e->getMessage());
		}

		$this->service->findAll(config: [], _rbac: false, _multitenancy: false);
		$this->assertSame(
			self::FLOW_REGISTER,
			$this->findAllScope['register']?->getSlug(),
			'A throw mid-save left the failed operation\'s register on the shared service.'
		);
		$this->assertSame(self::FLOW_SCHEMA, $this->findAllScope['schema']?->getSlug());
	}//end testAFailedSaveDoesNotLeaveItsScopeBehind()

	/**
	 * The same contract on the delete path.
	 *
	 * `deleteObject()` resolves its scope from its own arguments exactly as
	 * `saveObject()` does, and left it behind exactly as `saveObject()` did.
	 *
	 * @return void
	 */
	public function testADeleteDoesNotScopeTheNextUnscopedRead(): void {
		$this->service->setRegister(self::FLOW_REGISTER);
		$this->service->setSchema(self::FLOW_SCHEMA);

		$existing = new ObjectEntity();
		$existing->setUuid('a2e21c1c-4b71-4a5a-9f0e-2f7b4a1c1f11');
		$existing->setObject([]);
		$this->existingObject = $existing;

		$this->deleteHandler->method('deleteObject')->willReturn(true);

		$this->service->deleteObject(
			uuid: 'a2e21c1c-4b71-4a5a-9f0e-2f7b4a1c1f11',
			register: self::BROKER_REGISTER,
			schema: self::BROKER_SCHEMA,
			_rbac: false,
			_multitenancy: false
		);

		$this->service->findAll(config: [], _rbac: false, _multitenancy: false);
		$this->assertSame(self::FLOW_REGISTER, $this->findAllScope['register']?->getSlug());
		$this->assertSame(self::FLOW_SCHEMA, $this->findAllScope['schema']?->getSlug());
	}//end testADeleteDoesNotScopeTheNextUnscopedRead()

	/**
	 * The same contract on the bulk-save path.
	 *
	 * `saveObjects()` is the import path — the one most likely to be followed
	 * by an unscoped read in the same repair or migration run.
	 *
	 * @return void
	 */
	public function testABulkSaveDoesNotScopeTheNextUnscopedRead(): void {
		$this->service->setRegister(self::FLOW_REGISTER);
		$this->service->setSchema(self::FLOW_SCHEMA);

		$this->saveObjectsHandler->method('saveObjects')->willReturn(['statistics' => []]);

		$this->service->saveObjects(
			objects: [['name' => 'bulk row']],
			register: self::BROKER_REGISTER,
			schema: self::BROKER_SCHEMA,
			_rbac: false,
			_multitenancy: false
		);

		$this->service->findAll(config: [], _rbac: false, _multitenancy: false);
		$this->assertSame(self::FLOW_REGISTER, $this->findAllScope['register']?->getSlug());
		$this->assertSame(self::FLOW_SCHEMA, $this->findAllScope['schema']?->getSlug());
	}//end testABulkSaveDoesNotScopeTheNextUnscopedRead()

	/**
	 * The same contract on `findSilent()`.
	 *
	 * `find()` has restored since BUG-OBJ-13. `findSilent()` differs from it
	 * only in skipping the audit row, so it had no business differing in what
	 * it leaves behind — and it did.
	 *
	 * @return void
	 */
	public function testFindSilentDoesNotScopeTheNextUnscopedRead(): void {
		$this->service->setRegister(self::FLOW_REGISTER);
		$this->service->setSchema(self::FLOW_SCHEMA);

		$this->getHandler->method('findSilent')->willReturn(new ObjectEntity());

		$this->service->findSilent(
			id: 'a2e21c1c-4b71-4a5a-9f0e-2f7b4a1c1f11',
			register: self::BROKER_REGISTER,
			schema: self::BROKER_SCHEMA,
			_rbac: false,
			_multitenancy: false
		);

		$this->service->findAll(config: [], _rbac: false, _multitenancy: false);
		$this->assertSame(self::FLOW_REGISTER, $this->findAllScope['register']?->getSlug());
		$this->assertSame(self::FLOW_SCHEMA, $this->findAllScope['schema']?->getSlug());
	}//end testFindSilentDoesNotScopeTheNextUnscopedRead()

	/**
	 * NEGATIVE CONTROL: a read that DOES name its scope still gets it.
	 *
	 * `prepareFindAllConfig()` reads `filters.register` / `filters.schema`, and
	 * that path is what every correct caller uses. Restoring the context after
	 * a write must not disturb it — otherwise the fix would trade a silent
	 * wrong answer for a silent empty one.
	 *
	 * @return void
	 */
	public function testAReadThatNamesItsScopeUnderFiltersStillGetsIt(): void {
		$this->saveIntoBrokerScope();

		$this->service->findAll(
			config: [
				'filters' => [
					'register' => self::FLOW_REGISTER,
					'schema' => self::FLOW_SCHEMA,
				],
			],
			_rbac: false,
			_multitenancy: false
		);

		$this->assertSame(self::FLOW_REGISTER, $this->findAllScope['register']?->getSlug());
		$this->assertSame(self::FLOW_SCHEMA, $this->findAllScope['schema']?->getSlug());
	}//end testAReadThatNamesItsScopeUnderFiltersStillGetsIt()
}//end class
