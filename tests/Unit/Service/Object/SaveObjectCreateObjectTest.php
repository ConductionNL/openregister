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
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IURLGenerator;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use Stringable;
use OCA\OpenRegister\Service\Object\ObjectSaveOptions;
use OCA\OpenRegister\Service\Object\ObjectFindTarget;
use OCA\OpenRegister\Service\Object\SavePolicy;
use OCA\OpenRegister\Service\Object\SaveActor;

/**
 * What a create through SaveObject::saveObject() does around the insert: the
 * entity it prepares, the retention calls, the insert flags, the name cache,
 * the audit trail, the inverse relations and the outcome it reports.
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
 * @uses \OCA\OpenRegister\Service\WritePhaseProbe
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ReferenceAdminBypass
 * @uses \OCA\OpenRegister\Service\Rbac\AdminGate
 */
final class SaveObjectCreateObjectTest extends TestCase {

	private const UUID = '44444444-4444-4444-4444-444444444444';

	/** @var list<mixed> */
	private array $trace = [];

	/**
	 * The cases.
	 *
	 * @return array<string, array>
	 */
	private static function cases(): array {
		return [
			'create' => ['args' => ['failIfExists' => true, '_owner' => 'alice']],
			'mapper took its update branch' => ['action' => 'update', 'session' => true, 'args' => ['_unowned' => true]],
			'session user owns the create' => ['session' => true, 'args' => []],
			'silent' => ['args' => ['silent' => true]],
			'audit off' => ['audit' => false, 'args' => []],
			'not persisted' => ['args' => ['persist' => false]],
			'file property stored' => ['data' => ['doc' => 'raw'], 'image' => 'file', 'args' => []],
			'file property, image not a file' => ['data' => ['doc' => 'raw'], 'image' => 'string', 'args' => []],
			'file write fails' => ['data' => ['doc' => 'raw'], 'fileFails' => true, 'args' => []],
		];
	}

	public function testEveryCaseMatchesTheGoldenRecordedOnTheOldCode(): void {
		$fixture = __DIR__ . '/fixtures/save-object-create-object-golden.json';
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
			_rbac: false,
			persist: ($args['persist'] ?? true)
		);
		} catch (\RuntimeException $e) {
			return ['trace' => $this->trace, 'thrown' => $e->getMessage()];
		}

		$outcome = (new \ReflectionClass(SaveObject::class))->getProperty('lastSaveOutcome')->getValue($handler);
		return [
			'trace' => $this->trace,
			'entity' => [
				'uuid' => $saved->getUuid(), 'register' => $saved->getRegister(), 'schema' => $saved->getSchema(),
				'folder' => $saved->getFolder(), 'owner' => $saved->getOwner(), 'organisation' => $saved->getOrganisation(),
				'createdSet' => $saved->getCreated() !== null, 'lastLog' => $saved->getLastLog(), 'description' => $saved->getDescription(),
			],
			'outcome' => $outcome,
			'log' => array_values(array_filter($logger->lines, static fn (string $l): bool => str_contains($l, 'updateInverseRelations'))),
		];
	}

	/**
	 * The handler with tracing doubles.
	 */
	private function handler(array $case, AbstractLogger $logger): SaveObject {
		$mapper = $this->createMock(MagicMapper::class);
		$mapper->method('find')->willThrowException(new DoesNotExistException('none'));
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
		$files->method('handleFileProperty')->willReturnCallback(function (ObjectEntity $objectEntity, array &$object, string $propertyName) use ($case): void {
			$this->trace[] = ['file', $propertyName, $object[$propertyName]];
			if (($case['fileFails'] ?? false) === true) {
				throw new \RuntimeException('disk full');
			}

			$object[$propertyName] = 42;
		});
		$unified = $this->createMock(MagicMapper::class);
		$unified->method('find')->willThrowException(new DoesNotExistException('none'));
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
			$log = $this->createMock(AuditTrail::class);
			$log->method('jsonSerialize')->willReturn(['log' => 1]);
			return $log;
		});
		$retention = $this->createMock(RetentionServiceInterface::class);
		$retention->method('guardClassificationOverride')->willReturnCallback(function (Schema $schema, array $data): void {
			$this->trace[] = ['retentionGuard', $schema->getId(), array_key_exists('title', $data)];
		});
		$retention->method('applyArchivalMetadata')->willReturnCallback(function (ObjectEntity $object): ObjectEntity {
			$this->trace[] = ['archival'];
			$object->setDescription('archived');
			return $object;
		});
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (string $id): object => ($id === RetentionServiceInterface::class) ? $retention : throw new \RuntimeException('no ' . $id));
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

	public function testTheFileStepWritesTheFileIdsBackIntoTheCallersData(): void {
		$register = new Register();
		$register->setId(3);
		$schema = new Schema();
		$schema->setId(7);
		$schema->setProperties(['doc' => ['type' => 'file']]);
		$saved = new ObjectEntity();
		$saved->setUuid(self::UUID);
		$data = ['title' => 'new', 'doc' => 'raw'];

		FilePropertyCalls::processFilePropertiesWithRollback($this->handler(case: [], logger: new \Psr\Log\NullLogger()), [$saved, &$data, $register, $schema]);

		$this->assertSame(['title' => 'new', 'doc' => 42], $data);
	}
}

