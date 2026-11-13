<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Service\RecordUpdate;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\File\FolderManagementHandler;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObject\ComputedFieldHandler;
use OCA\OpenRegister\Service\Object\SaveObject\FilePropertyHandler;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectFileWrite;
use OCA\OpenRegister\Service\Object\SaveObject\LinkedEntityPropertyHandler;
use OCA\OpenRegister\Service\Object\SaveObject\MetadataHydrationHandler;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\RetentionServiceInterface;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\TmloService;
use OCA\OpenRegister\Service\TranslationProjectionService;
use OCA\OpenRegister\Service\TranslationStatusService;
use OCA\OpenRegister\Service\WriteCause;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IURLGenerator;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Stringable;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\SavePolicy;
use OCA\OpenRegister\Kernel\Contracts\Value\SaveActor;

/**
 * What an update through SaveObject::saveObject() does before and around the
 * write: the archival refusal, the soft uniqueness hints, the classification
 * re-derivation, the version it keeps or drops, persistence and the outcome.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObject
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\SaveLookups
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\SaveCallFrame
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\SaveCallStack
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ReferenceStrictness
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ReferenceEvents
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ReferenceExistence
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ReferenceFilterGuard
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ReferenceValidator
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ReferencePropertyRun
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ReferenceText
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\RegisterReferenceLookup
 * @uses \OCA\OpenRegister\Formats\UuidFormat
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\ObjectHandling
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Service\Object\DraftStatusPolicy
 * @uses \OCA\OpenRegister\Service\Object\ObjectVersionHandler
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugBatch
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugClientValue
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugHandler
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugLog
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugSource
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugStore
 * @uses \OCA\OpenRegister\Service\OrganisationContext
 * @uses \OCA\OpenRegister\Service\WriteCause
 * @uses \OCA\OpenRegister\Service\WritePhaseProbe
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ReferenceAdminBypass
 * @uses \OCA\OpenRegister\Service\Rbac\AdminGate
 * @uses   \OCA\OpenRegister\Db\SchemaCatalog
 * @uses   \OCA\OpenRegister\Db\Schema\ObjectSourceDeclaration
 * @uses   \OCA\OpenRegister\Db\Schema\PropertyAuthorization
 * @uses   \OCA\OpenRegister\Db\Schema\SchemaPropertyDocument
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\SaveActor
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\SavePolicy
 * @uses   \OCA\OpenRegister\Service\AnonymousEvaluationContext
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\CascadeRules
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\CascadeRun
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\DefaultValueContext
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\EmptyValues
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\FolderBind
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\InverseRelationWrite
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\InverseRelations
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\InverseWriteBack
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\NestedSaveTransaction
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ObjectFileProperties
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ObjectPayload
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ObjectWriteTarget
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\PropertyDefaults
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\RelationScanLevel
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\RelationScanner
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectHandlers
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectMappers
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectPersistence
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectPlatform
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectPropertyHandlers
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectRecords
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectRuntime
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectServices
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectTenancy
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectValueHandlers
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\SelfSubmission
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\SlugRequest
 * @uses   \OCA\OpenRegister\Service\Object\TranslationScope
 * @uses   \OCA\OpenRegister\Service\Quality\UniqueHintCandidate
 * @uses   \OCA\OpenRegister\Service\Quality\UniqueHintScope
 * @uses   \OCA\OpenRegister\Service\SystemOperationContext
 * @uses   \OCA\OpenRegister\Db\LockTerms
 * @uses   \OCA\OpenRegister\Db\MagicMapper\StatisticsScope
 * @uses   \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses   \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 * @uses   \OCA\OpenRegister\Db\Schema\ConfigurationValueRules
 * @uses   \OCA\OpenRegister\Db\SoftDeletion
 * @uses   \OCA\OpenRegister\Kernel\Contracts\ListQuery
 * @uses   \OCA\OpenRegister\Service\Object\ObjectUpdateOptions
 * @uses   \OCA\OpenRegister\Service\Object\PermissionCheckOptions
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ObjectImage
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\OutdatedTranslations
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\UpdatePreparation
 * @uses   \OCA\OpenRegister\Service\Object\StoredObjectUpdate
 * @uses   \OCA\OpenRegister\Service\ProtectedCarryForward
 * @uses   \OCA\OpenRegister\Service\RecordUpdate
 * @uses \OCA\OpenRegister\Db\SchemaCatalog
 * @uses \OCA\OpenRegister\Db\Schema\ObjectSourceDeclaration
 * @uses \OCA\OpenRegister\Db\Schema\PropertyAuthorization
 * @uses \OCA\OpenRegister\Db\Schema\SchemaPropertyDocument
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\SaveActor
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\SavePolicy
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\CascadeRules
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\CascadeRun
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\DefaultValueContext
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\EmptyValues
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\FolderBind
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\InverseRelationWrite
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\InverseRelations
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\InverseWriteBack
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ObjectFileProperties
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ObjectPayload
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ObjectWriteTarget
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\PropertyDefaults
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\RelationScanLevel
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\RelationScanner
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectHandlers
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectMappers
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectPersistence
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectPlatform
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectPropertyHandlers
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectRecords
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectRuntime
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectServices
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectTenancy
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectValueHandlers
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\SelfSubmission
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\SlugRequest
 * @uses \OCA\OpenRegister\Service\Object\TranslationScope
 * @uses \OCA\OpenRegister\Service\Quality\UniqueHintCandidate
 * @uses \OCA\OpenRegister\Service\Quality\UniqueHintScope
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationValueRules
 * @uses \OCA\OpenRegister\Service\Object\ObjectUpdateOptions
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ObjectImage
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\OutdatedTranslations
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\UpdatePreparation
 * @uses \OCA\OpenRegister\Service\Object\StoredObjectUpdate
 * @uses \OCA\OpenRegister\Service\ProtectedCarryForward
 * @uses \OCA\OpenRegister\Service\RecordUpdate
 */
final class SaveObjectUpdatePathTest extends TestCase {

	private const UUID = '44444444-4444-4444-4444-444444444444';

	/** @var list<mixed> */
	private array $trace = [];

	/** @var array<string, bool> Whether each audited object was marked at the moment its row was built. */
	private array $auditedMarks = [];

	protected function tearDown(): void {
		WriteCause::reset();
		parent::tearDown();
	}

	/**
	 * The cases.
	 *
	 * @return array<string, array>
	 */
	private static function cases(): array {
		return [
			'update' => ['data' => ['@self' => ['version' => '9.9.9']], 'args' => []],
			'update without rbac keeps the carried version' => ['data' => ['@self' => ['version' => '9.9.9']], 'args' => ['_rbac' => false]],
			'destroyed in the Dutch vocabulary' => ['archive' => 'vernietigd', 'args' => []],
			'destroyed' => ['archive' => 'destroyed', 'args' => []],
			'transferred in the Dutch vocabulary' => ['archive' => 'overgebracht', 'args' => []],
			'transferred' => ['archive' => 'transferred', 'args' => []],
			'not persisted' => ['data' => ['@self' => ['version' => '9.9.9']], 'args' => ['persist' => false]],
			'not persisted without rbac keeps the carried version' => ['data' => ['@self' => ['version' => '9.9.9']], 'args' => ['persist' => false, '_rbac' => false]],
			'silent' => ['args' => ['silent' => true]],
		];
	}

	public function testEveryCaseMatchesTheGoldenRecordedOnTheOldCode(): void {
		$fixture = __DIR__ . '/fixtures/save-object-update-path-golden.json';
		$actual = [];
		foreach (self::cases() as $name => $case) {
			$actual[$name] = json_decode(json_encode($this->runCreate($case)), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents($fixture, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$expected = json_decode((string)file_get_contents($fixture), true);
		$this->assertSame(array_keys($expected), array_keys($actual));
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, $actual[$name], $name);
		}
	}

	/**
	 * A nested object is saved and audited before its parent, with no uuid of
	 * the parent known yet. Only the save that carried the mark notes it, for
	 * the uuid it wrote.
	 */
	public function testACreateMarksTheRowOfItsOwnObjectAndNotOfOneSavedBeforeIt(): void {
		[$register, $schema] = $this->target();
		$handler = $this->handler(case: [], logger: new NullLogger());

		$nested = $handler->saveObject(data: ['title' => 'nested'], options: new ObjectSaveOptions(target: new ObjectFindTarget(register: $register, schema: $schema)));
		$created = $handler->saveObject(
			data: ['title' => 'new', '@self' => [WriteCause::CLIENT_ATTEMPT_KEY => true]],
			options: new ObjectSaveOptions(target: new ObjectFindTarget(register: $register, schema: $schema))
		);

		$this->assertNotSame($nested->getUuid(), $created->getUuid());
		$this->assertSame(
			[$nested->getUuid() => false, $created->getUuid() => true],
			$this->auditedMarks
		);
	}

	public function testTheMarkIsNotStoredOnTheObject(): void {
		[$register, $schema] = $this->target();

		$created = $this->handler(case: [], logger: new NullLogger())->saveObject(
			data: ['title' => 'new', '@self' => [WriteCause::CLIENT_ATTEMPT_KEY => true]],
			options: new ObjectSaveOptions(target: new ObjectFindTarget(register: $register, schema: $schema))
		);

		$this->assertArrayNotHasKey(WriteCause::CLIENT_ATTEMPT_KEY, $created->getObject());
	}

	public function testOnlyTheBooleanTheDoorSetsCounts(): void {
		[$register, $schema] = $this->target();

		$created = $this->handler(case: [], logger: new NullLogger())->saveObject(
			data: ['title' => 'new', '@self' => [WriteCause::CLIENT_ATTEMPT_KEY => 'true']],
			options: new ObjectSaveOptions(target: new ObjectFindTarget(register: $register, schema: $schema))
		);

		$this->assertFalse(WriteCause::clientAttemptedFor(objectUuid: (string)$created->getUuid()));
	}

	/**
	 * A POST whose identifier is taken updates that object: the mark goes to it.
	 */
	public function testASaveThatLandsOnAStoredObjectMarksThatObject(): void {
		[$register, $schema] = $this->target();

		$this->handler(case: [], logger: new NullLogger())->saveObject(
			data: ['title' => 'new', '@self' => [WriteCause::CLIENT_ATTEMPT_KEY => true]],
			options: new ObjectSaveOptions(target: new ObjectFindTarget(register: $register, schema: $schema), uuid: self::UUID)
		);

		$this->assertSame([self::UUID => true], $this->auditedMarks);
	}

	/**
	 * The register and schema every case writes to.
	 *
	 * @return array{0: Register, 1: Schema}
	 */
	private function target(): array {
		$register = new Register();
		$register->setId(3);
		$schema = new Schema();
		$schema->setId(7);
		$schema->setSlug('things');
		$schema->setProperties(['title' => ['type' => 'string']]);

		return [$register, $schema];
	}

	/**
	 * Run one create and return what happened.
	 */
	private function runCreate(array $case): array {
		$this->trace = [];
		$register = new Register();
		$register->setId(3);
		$schema = new Schema();
		$schema->setId(7);
		$schema->setSlug('things');
		$schema->setProperties(['title' => ['type' => 'string'], 'doc' => ['type' => ($case['image'] ?? 'file')]]);
		$schema->setConfiguration(['objectImageField' => 'doc']);

		$logger = new class extends AbstractLogger {
			/** @var list<string> */
			public array $lines = [];

			public function log($level, string|Stringable $message, array $context = []): void {
				$this->lines[] = $level . ': ' . $message;
			}
		};

		$handler = $this->handler(case: $case, logger: $logger);
		$args = $case['args'];
		try {
			$saved = $handler->saveObject(
			data: array_merge(['title' => 'new'], ($case['data'] ?? [])),
			options: new ObjectSaveOptions(target: new ObjectFindTarget(register: $register, schema: $schema), uuid: self::UUID, policy: new SavePolicy(silent: ($args['silent'] ?? false), failIfExists: ($args['failIfExists'] ?? false)), actor: new SaveActor(owner: ($args['_owner'] ?? null), unowned: ($args['_unowned'] ?? false))),
			_rbac: ($args['_rbac'] ?? true),
			persist: ($args['persist'] ?? true)
		);
		} catch (\Exception $e) {
			return ['trace' => $this->trace, 'thrown' => [$e->getMessage(), $e->getCode()]];
		}

		$outcome = (new \ReflectionClass(SaveObject::class))->getProperty('lastSaveOutcome')->getValue($handler);
		return [
			'trace' => $this->trace,
			'entity' => [
				'uuid' => $saved->getUuid(), 'register' => $saved->getRegister(), 'schema' => $saved->getSchema(),
				'folder' => $saved->getFolder(), 'version' => $saved->getVersion(), 'owner' => $saved->getOwner(), 'organisation' => $saved->getOrganisation(),
				'createdSet' => $saved->getCreated() !== null, 'lastLog' => $saved->getLastLog(), 'description' => $saved->getDescription(),
			],
			'outcome' => $outcome,
			'log' => array_values(array_filter($logger->lines, static fn (string $l): bool => str_contains($l, 'updateInverseRelations'))),
		];
	}

	/**
	 * The handler with tracing doubles.
	 */
	private function handler(array $case, LoggerInterface $logger): SaveObject {
		$stored = new ObjectEntity();
		$stored->setId(11);
		$stored->setUuid(self::UUID);
		$stored->setRegister('3');
		$stored->setSchema('7');
		$stored->setVersion('1.0.0');
		$stored->setObject(['title' => 'old']);
		if (isset($case['archive']) === true) {
			$stored->setRetention(['archiefstatus' => $case['archive']]);
		}

		$mapper = $this->createMock(MagicMapper::class);
		$mapper->method('find')->willReturn($stored);
		$mapper->method('update')->willReturnCallback(function (ObjectEntity $entity): ObjectEntity {
			$this->trace[] = ['store', $entity->getObject(), $entity->getImage()];
			$stored = clone $entity;
			$stored->setDescription('stored again');
			return $stored;
		});
		$mapper->method('delete')->willReturnCallback(function (ObjectEntity $entity): ObjectEntity {
			$this->trace[] = ['delete', $entity->getUuid()];
			return $entity;
		});
		$files = $this->createMock(FilePropertyHandler::class);
		$files->method('isFileInSchema')->willReturnCallback(static fn ($schema, $propertyName): bool => $propertyName === 'doc');
		$files->method('handleFileProperty')->willReturnCallback(function (ObjectFileWrite $write, string $propertyName) use ($case): void {
			$this->trace[] = ['file', $propertyName, $write->data[$propertyName]];
			if (($case['fileFails'] ?? false) === true) {
				throw new \RuntimeException('disk full');
			}

			$write->data[$propertyName] = 42;
		});
		$unified = $this->createMock(MagicMapper::class);
		$unified->method('find')->willReturn($stored);
		$unified->method('update')->willReturnCallback(function (ObjectEntity $entity, $register = null, $schema = null, ?ObjectEntity $oldEntity = null): ObjectEntity {
			$this->trace[] = ['update', $entity->getObject(), $entity->getVersion(), $oldEntity?->getObject()];
			return $entity;
		});
		$unified->method('insert')->willReturnCallback(function (ObjectEntity $entity, $register = null, $schema = null, bool $failIfExists = false): ObjectEntity {
			$this->trace[] = ['insert', $entity->getRegister(), $entity->getSchema(), $entity->getFolder(), $entity->getOwner(), $failIfExists];
			$entity->setName('Saved name');
			$entity->setOrganisation('org-9');
			$entity->setImage('img.png');
			return $entity;
		});
		$unified->method('getLastWriteAction')->willReturn($case['action'] ?? 'insert');
		$cache = $this->createMock(CacheHandler::class);
		$cache->method('setObjectName')->willReturnCallback(function (string $identifier, string $name, ?string $organisation = null): void {
			$this->trace[] = ['cacheName', $identifier, $name, $organisation];
		});
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRetentionSettingsOnly')->willReturn(['auditTrailsEnabled' => ($case['audit'] ?? true)]);
		$audit = $this->createMock(AuditTrailMapper::class);
		$audit->method('createAuditTrail')->willReturnCallback(function (?ObjectEntity $old = null, ?ObjectEntity $new = null, ?string $action = null): AuditTrail {
			$this->trace[] = ['audit', $old?->getUuid(), $new?->getUuid(), $action];
			$uuid = (string)($new?->getUuid() ?? $old?->getUuid());
			$this->auditedMarks[$uuid] = WriteCause::clientAttemptedFor(objectUuid: $uuid);
			$log = $this->createMock(AuditTrail::class);
			$log->method('jsonSerialize')->willReturn(['log' => 1]);
			return $log;
		});
		$retention = $this->createMock(RetentionServiceInterface::class);
		$retention->method('guardClassificationOverride')->willReturnCallback(function (Schema $schema, array $data): void {
			$this->trace[] = ['retentionGuard', $schema->getId(), array_key_exists('title', $data)];
		});
		$retention->method('applyClassification')->willReturnCallback(function (RecordUpdate $update): ObjectEntity {
			$this->trace[] = ['classification', $update->previousData];
			return $update->object;
		});
		$retention->method('applyArchivalMetadata')->willReturnCallback(function (ObjectEntity $object): ObjectEntity {
			$this->trace[] = ['archival'];
			$object->setDescription('archived');
			return $object;
		});
		$container = $this->createMock(ContainerInterface::class);
		$hints = $this->createMock(\OCA\OpenRegister\Service\Quality\UniqueHintChecker::class);
		$hints->method('check')->willReturnCallback(function (array $configuration, \OCA\OpenRegister\Service\Quality\UniqueHintCandidate $candidate): void {
			$this->trace[] = ['hints', $candidate->scope->register, $candidate->scope->schema, $candidate->selfUuid];
		});
		$services = [RetentionServiceInterface::class => $retention, \OCA\OpenRegister\Service\Quality\UniqueHintChecker::class => $hints];
		$container->method('get')->willReturnCallback(static fn (string $id): object => $services[$id] ?? throw new \RuntimeException('no ' . $id));
		$folders = $this->createMock(FolderManagementHandler::class);
		$translations = $this->createMock(TranslationHandler::class);
		$translations->method('normalizeTranslationsForSave')->willReturnCallback(static fn (array $objectData): array => $objectData);
		$session = $this->createMock(IUserSession::class);
		if (($case['session'] ?? false) === true) {
			$bob = $this->createMock(\OCP\IUser::class);
			$bob->method('getUID')->willReturn('bob');
			$session->method('getUser')->willReturn($bob);
		}
		$organisations = $this->createMock(OrganisationService::class);
		$organisations->method('getOrganisationForNewEntity')->willReturn('org-1');

		return new SaveObject(
			services: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectServices(
				persistence: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectPersistence(
					mappers: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectMappers(
						catalog: new \OCA\OpenRegister\Db\SchemaCatalog(registers: $this->createMock(RegisterMapper::class), schemas: $this->createMock(SchemaMapper::class), tables: $mapper),
						unifiedObjectMapper: $unified
					),
					records: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectRecords(
						auditTrailMapper: $audit,
						settingsService: $settings,
						tmloService: $this->createMock(TmloService::class)
					),
					runtime: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectRuntime(
						cacheHandler: $cache,
						container: $container
					)
				),
				handlers: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectHandlers(
					properties: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectPropertyHandlers(
						metaHydrationHandler: $this->createMock(MetadataHydrationHandler::class),
						filePropertyHandler: $files,
						linkedEntityHandler: $this->createMock(LinkedEntityPropertyHandler::class),
						computedFieldHandler: $this->createMock(ComputedFieldHandler::class)
					),
					values: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectValueHandlers(
						translationHandler: $translations,
						translationProjectionService: $this->createMock(TranslationProjectionService::class),
						translationStatusService: $this->createMock(TranslationStatusService::class)
					)
				),
				platform: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectPlatform(
					userSession: $session,
					urlGenerator: $this->createMock(IURLGenerator::class),
					logger: $logger
				),
				tenancy: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectTenancy(
					organisationService: $organisations
				)
			),
			propertyRbacHandler: $this->createMock(PropertyRbacHandler::class),
			permissionHandler: $this->createMock(PermissionHandler::class),
			folderManagementHandler: $folders
		);
	}
}
