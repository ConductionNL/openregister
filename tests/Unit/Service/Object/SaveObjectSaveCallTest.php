<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Service\PropertyWriteCheck;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\ObjectExistsException;
use OCA\OpenRegister\Search\Service\Quality\DedupCreatePolicyInterface;
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
use OCA\OpenRegister\Search\Service\Quality\DedupCreatePolicy;
use OCA\OpenRegister\Service\Quality\UniqueHintChecker;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\TmloService;
use OCA\OpenRegister\Service\TranslationProjectionService;
use OCA\OpenRegister\Service\TranslationStatusService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IURLGenerator;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Search\Service\Quality\CreateCandidate;

/**
 * The branches of SaveObject::saveObject() that decide between update, create
 * and refusal: the insert-only guard, the duplicate-override flag, the soft
 * uniqueness hints, the property authorization lookup and the auto-generated
 * UUID shortcut.
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
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Exception\ObjectExistsException
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugBatch
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugClientValue
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugHandler
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugLog
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugSource
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugStore
 * @uses \OCA\OpenRegister\Service\OrganisationContext
 */
final class SaveObjectSaveCallTest extends TestCase {

	private const UUID = 'dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4';

	/** @var MagicMapper&MockObject */
	private $objectEntityMapper;

	/** @var MagicMapper&MockObject */
	private $unifiedObjectMapper;

	/** @var PropertyRbacHandler&MockObject */
	private $propertyRbacHandler;

	/** @var array<string, object> */
	private array $services = [];

	private Register $register;

	private Schema $schema;

	protected function setUp(): void {
		parent::setUp();
		$this->objectEntityMapper = $this->createMock(MagicMapper::class);
		$this->unifiedObjectMapper = $this->createMock(MagicMapper::class);
		$this->propertyRbacHandler = $this->createMock(PropertyRbacHandler::class);

		$this->register = new Register();
		$this->register->setId(3);
		$this->schema = new Schema();
		$this->schema->setId(7);
		$this->schema->setSlug('things');
		$this->schema->setProperties(['name' => ['type' => 'string']]);
	}

	private function saveObject(): SaveObject {
		$translations = $this->createMock(TranslationHandler::class);
		$translations->method('normalizeTranslationsForSave')->willReturnCallback(
			static fn (array $objectData): array => $objectData
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(function (string $id): object {
			if (isset($this->services[$id]) === false) {
				throw new RuntimeException('no ' . $id);
			}

			return $this->services[$id];
		});

		return new SaveObject(
			services: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectServices(
				persistence: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectPersistence(
					mappers: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectMappers(
						catalog: new \OCA\OpenRegister\Db\SchemaCatalog(registers: $this->createMock(RegisterMapper::class), schemas: $this->createMock(SchemaMapper::class), tables: $this->objectEntityMapper),
						unifiedObjectMapper: $this->unifiedObjectMapper
					),
					records: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectRecords(
						auditTrailMapper: $this->createMock(AuditTrailMapper::class),
						settingsService: $this->createMock(SettingsService::class),
						tmloService: $this->createMock(TmloService::class)
					),
					runtime: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectRuntime(
						cacheHandler: $this->createMock(CacheHandler::class),
						container: $container
					)
				),
				handlers: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectHandlers(
					properties: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectPropertyHandlers(
						metaHydrationHandler: $this->createMock(MetadataHydrationHandler::class),
						filePropertyHandler: $this->createMock(FilePropertyHandler::class),
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
					userSession: $this->createMock(IUserSession::class),
					urlGenerator: $this->createMock(IURLGenerator::class),
					logger: $this->createMock(LoggerInterface::class)
				),
				tenancy: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectTenancy(
					organisationService: $this->createMock(OrganisationService::class)
				)
			),
			propertyRbacHandler: $this->propertyRbacHandler,
			permissionHandler: $this->createMock(PermissionHandler::class),
			folderManagementHandler: $this->createMock(FolderManagementHandler::class)
		);
	}

	private function stored(): ObjectEntity {
		$stored = new ObjectEntity();
		$stored->setId(11);
		$stored->setUuid(self::UUID);
		$stored->setRegister('3');
		$stored->setSchema('7');
		$stored->setObject(['name' => 'Stored']);
		return $stored;
	}

	/**
	 * A dedup policy whose guard records what it was asked and then stops the save.
	 *
	 * @param array $seen Receives the data and the override flag.
	 */
	private function stoppingDedupPolicy(array &$seen): void {
		$policy = $this->createMock(DedupCreatePolicy::class);
		$policy->method('guardCreate')->willReturnCallback(
			static function (CreateCandidate $candidate) use (&$seen): array {
				$seen = ['register' => $candidate->register, 'schema' => $candidate->schema, 'data' => $candidate->data, 'override' => $candidate->overrideRequested];
				throw new RuntimeException('stop');
			}
		);
		$this->services[DedupCreatePolicyInterface::class] = $policy;
	}

	public function testAnInsertOnlySaveOfATakenIdentifierIsRefused(): void {
		$this->objectEntityMapper->method('find')->willReturn($this->stored());

		$this->expectException(ObjectExistsException::class);
		$this->expectExceptionMessage('An object with identifier "' . self::UUID . '" already exists.');

		$this->saveObject()->saveObject(
			register: $this->register,
			schema: $this->schema,
			data: ['name' => 'New'],
			uuid: self::UUID,
			_rbac: false,
			persist: false,
			failIfExists: true
		);
	}

	public function testTheOverrideKeyInTheBodyIsTakenOffTheDataAndRequestsTheOverride(): void {
		$seen = [];
		$this->stoppingDedupPolicy($seen);

		try {
			$this->saveObject()->saveObject(
				register: $this->register,
				schema: $this->schema,
				data: ['name' => 'New', DedupCreatePolicy::OVERRIDE_KEY => 'true'],
				_rbac: false
			);
			$this->fail('the dedup guard was not reached');
		} catch (RuntimeException $e) {
			$this->assertSame('stop', $e->getMessage());
		}

		$this->assertSame(3, $seen['register']);
		$this->assertSame(7, $seen['schema']);
		$this->assertSame(['name' => 'New'], $seen['data']);
		$this->assertTrue($seen['override']);
	}

	public function testAFalseOverrideKeyIsTakenOffWithoutRequestingTheOverride(): void {
		$seen = [];
		$this->stoppingDedupPolicy($seen);

		try {
			$this->saveObject()->saveObject(
				register: $this->register,
				schema: $this->schema,
				data: ['name' => 'New', DedupCreatePolicy::OVERRIDE_KEY => 'no'],
				_rbac: false
			);
		} catch (RuntimeException $e) {
			$this->assertSame('stop', $e->getMessage());
		}

		$this->assertSame(['name' => 'New'], $seen['data']);
		$this->assertFalse($seen['override']);
	}

	public function testTheOverrideParameterRequestsTheOverrideWithoutABodyKey(): void {
		$seen = [];
		$this->stoppingDedupPolicy($seen);

		try {
			$this->saveObject()->saveObject(
				register: $this->register,
				schema: $this->schema,
				data: ['name' => 'New'],
				_rbac: false,
				_dedupOverride: true
			);
		} catch (RuntimeException $e) {
			$this->assertSame('stop', $e->getMessage());
		}

		$this->assertTrue($seen['override']);
	}

	public function testACreateRecordsTheSoftUniquenessHints(): void {
		$this->schema->setConfiguration(['unique' => ['name']]);
		$seen = null;
		$hints = $this->createMock(UniqueHintChecker::class);
		$hints->expects($this->once())->method('check')->willReturnCallback(
			static function (array $configuration, \OCA\OpenRegister\Service\Quality\UniqueHintScope $scope, array $data) use (&$seen): void {
				$seen = [$configuration, $scope->register, $scope->schema, $data];
				throw new RuntimeException('stop');
			}
		);
		$this->services[UniqueHintChecker::class] = $hints;

		try {
			$this->saveObject()->saveObject(register: $this->register, schema: $this->schema, data: ['name' => 'New'], _rbac: false);
		} catch (RuntimeException $e) {
			$this->assertSame('stop', $e->getMessage());
		}

		$this->assertSame([['unique' => ['name']], 3, 7, ['name' => 'New']], $seen);
	}

	public function testThePropertyCheckOfAnUpdateOfAMissingObjectIsACreate(): void {
		$this->schema->setProperties(['name' => ['type' => 'string', 'authorization' => ['update' => ['admin']]]]);
		$this->unifiedObjectMapper->method('find')->willThrowException(new DoesNotExistException('gone'));
		$seen = null;
		$this->propertyRbacHandler->method('getUnauthorizedProperties')->willReturnCallback(
			static function (PropertyWriteCheck $write) use (&$seen): array {
				[$schema, $object, $incomingData, $isCreate] = [$write->schema, $write->request->object, $write->incoming, $write->request->isCreate];
				$seen = [$object, $incomingData, $isCreate];
				return ['name'];
			}
		);

		$this->expectExceptionMessage('You are not authorized to modify the following properties: name');
		try {
			$this->saveObject()->saveObject(register: $this->register, schema: $this->schema, data: ['name' => 'New'], uuid: self::UUID);
		} finally {
			$this->assertSame([[], ['name' => 'New'], true], $seen);
		}
	}

	public function testThePropertyCheckOfAnUpdateReadsTheStoredObject(): void {
		$this->schema->setProperties(['name' => ['type' => 'string', 'authorization' => ['update' => ['admin']]]]);
		$stored = $this->stored();
		$this->unifiedObjectMapper->method('find')->willReturn($stored);
		$seen = null;
		$this->propertyRbacHandler->method('getUnauthorizedProperties')->willReturnCallback(
			static function (PropertyWriteCheck $write) use (&$seen): array {
				[$schema, $object, $incomingData, $isCreate] = [$write->schema, $write->request->object, $write->incoming, $write->request->isCreate];
				$seen = [$object, $isCreate];
				return ['name'];
			}
		);

		try {
			$this->saveObject()->saveObject(register: $this->register, schema: $this->schema, data: ['name' => 'New'], uuid: self::UUID);
			$this->fail('the property check did not refuse');
		} catch (\Exception $e) {
			$this->assertStringContainsString('following properties: name', $e->getMessage());
		}

		$this->assertSame([$stored->getMatchEnvelope(), false], $seen);
	}

	public function testAUuidNothingAnswersToIsACreate(): void {
		$this->objectEntityMapper->expects($this->once())->method('find')->willThrowException(new DoesNotExistException('none'));
		$seen = [];
		$this->stoppingDedupPolicy($seen);

		try {
			$this->saveObject()->saveObject(
				register: $this->register,
				schema: $this->schema,
				data: ['name' => 'New'],
				uuid: self::UUID,
				_rbac: false
			);
		} catch (RuntimeException $e) {
			$this->assertSame('stop', $e->getMessage());
		}

		$this->assertSame(['name' => 'New'], $seen['data']);
	}

	public function testAFlagInTheBodyDoesNotMakeAStoredObjectACreate(): void {
		$stored = $this->stored();
		$stored->setRetention(['archiefstatus' => 'vernietigd']);
		$this->objectEntityMapper->method('find')->willReturn($stored);
		$this->unifiedObjectMapper->expects($this->never())->method('insert');

		$this->expectExceptionCode(409);
		$this->saveObject()->saveObject(
			register: $this->register,
			schema: $this->schema,
			data: ['name' => 'New', '@self' => ['_autoGeneratedUuid' => true]],
			uuid: self::UUID,
			_rbac: false
		);
	}
}
