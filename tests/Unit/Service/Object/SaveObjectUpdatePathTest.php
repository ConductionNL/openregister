<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

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

		$nested = $handler->saveObject(register: $register, schema: $schema, data: ['title' => 'nested']);
		$created = $handler->saveObject(
			register: $register,
			schema: $schema,
			data: ['title' => 'new', '@self' => [WriteCause::CLIENT_ATTEMPT_KEY => true]]
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
			register: $register,
			schema: $schema,
			data: ['title' => 'new', '@self' => [WriteCause::CLIENT_ATTEMPT_KEY => true]]
		);

		$this->assertArrayNotHasKey(WriteCause::CLIENT_ATTEMPT_KEY, $created->getObject());
	}

	public function testOnlyTheBooleanTheDoorSetsCounts(): void {
		[$register, $schema] = $this->target();

		$created = $this->handler(case: [], logger: new NullLogger())->saveObject(
			register: $register,
			schema: $schema,
			data: ['title' => 'new', '@self' => [WriteCause::CLIENT_ATTEMPT_KEY => 'true']]
		);

		$this->assertFalse(WriteCause::clientAttemptedFor(objectUuid: (string)$created->getUuid()));
	}

	/**
	 * A POST whose identifier is taken updates that object: the mark goes to it.
	 */
	public function testASaveThatLandsOnAStoredObjectMarksThatObject(): void {
		[$register, $schema] = $this->target();

		$this->handler(case: [], logger: new NullLogger())->saveObject(
			register: $register,
			schema: $schema,
			data: ['title' => 'new', '@self' => [WriteCause::CLIENT_ATTEMPT_KEY => true]],
			uuid: self::UUID
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
			register: $register,
			schema: $schema,
			data: array_merge(['title' => 'new'], ($case['data'] ?? [])),
			uuid: self::UUID,
			folderId: ($args['folderId'] ?? null),
			_rbac: ($args['_rbac'] ?? true),
			persist: ($args['persist'] ?? true),
			silent: ($args['silent'] ?? false),
			failIfExists: ($args['failIfExists'] ?? false),
			_unowned: ($args['_unowned'] ?? false),
			_owner: ($args['_owner'] ?? null)
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
		$files->method('isFileProperty')->willReturnCallback(static fn ($value, $schema = null, $propertyName = null): bool => $propertyName === 'doc');
		$files->method('handleFileProperty')->willReturnCallback(function (ObjectEntity $objectEntity, array &$object, string $propertyName) use ($case): void {
			$this->trace[] = ['file', $propertyName, $object[$propertyName]];
			if (($case['fileFails'] ?? false) === true) {
				throw new \RuntimeException('disk full');
			}

			$object[$propertyName] = 42;
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
		$cache->method('setObjectName')->willReturnCallback(function (string $identifier, string $name, int $ttl = 0, ?string $organisation = null): void {
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
		$retention->method('applyClassificationOnUpdate')->willReturnCallback(function (ObjectEntity $object, Schema $schema, array $previousData): ObjectEntity {
			$this->trace[] = ['classification', $previousData];
			return $object;
		});
		$retention->method('applyArchivalMetadata')->willReturnCallback(function (ObjectEntity $object): ObjectEntity {
			$this->trace[] = ['archival'];
			$object->setDescription('archived');
			return $object;
		});
		$container = $this->createMock(ContainerInterface::class);
		$hints = $this->createMock(\OCA\OpenRegister\Service\Quality\UniqueHintChecker::class);
		$hints->method('check')->willReturnCallback(function (array $configuration, $register, $schema, array $data, ?string $selfUuid = null): void {
			$this->trace[] = ['hints', $register, $schema, $selfUuid];
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
			$mapper,
			$unified,
			$this->createMock(MetadataHydrationHandler::class),
			$files,
			$this->createMock(LinkedEntityPropertyHandler::class),
			$session,
			$audit,
			$this->createMock(SchemaMapper::class),
			$this->createMock(RegisterMapper::class),
			$this->createMock(IURLGenerator::class),
			$organisations,
			$cache,
			$settings,
			$this->createMock(PropertyRbacHandler::class),
			$this->createMock(ComputedFieldHandler::class),
			$translations,
			$this->createMock(TranslationProjectionService::class),
			$this->createMock(TranslationStatusService::class),
			$logger,
			$this->createMock(TmloService::class),
			$folders,
			$this->createMock(PermissionHandler::class),
			container: $container
		);
	}
}
