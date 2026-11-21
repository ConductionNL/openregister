<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Exception\ObjectLookupFailedException;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\CircularReferenceException;
use OCA\OpenRegister\Exception\ReferenceValidationException;
use OCA\OpenRegister\Service\File\FolderManagementHandler;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObject\SaveCallFrame;
use OCA\OpenRegister\Service\Object\SaveObject\ComputedFieldHandler;
use OCA\OpenRegister\Service\Object\SaveObject\FilePropertyHandler;
use OCA\OpenRegister\Service\Object\SaveObject\LinkedEntityPropertyHandler;
use OCA\OpenRegister\Service\Object\SaveObject\MetadataHydrationHandler;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\TmloService;
use OCA\OpenRegister\Service\TranslationProjectionService;
use OCA\OpenRegister\Service\TranslationStatusService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IURLGenerator;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use RuntimeException;

/**
 * SaveObject::validateReferenceExists(): what it looks up, what it caches,
 * which events it sends, and when it refuses.
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
 * @uses \OCA\OpenRegister\Service\Schemas\PropertyVocabularyException
 * @uses \OCA\OpenRegister\Service\Schemas\ReferenceFilterDeclaration
 * @uses \OCA\OpenRegister\Service\Schemas\ReferenceFilterException
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Event\AbstractReferenceValidationEvent
 * @uses \OCA\OpenRegister\Event\ReferenceValidatedEvent
 * @uses \OCA\OpenRegister\Event\ReferenceValidationFailedEvent
 * @uses \OCA\OpenRegister\Exception\CircularReferenceException
 * @uses \OCA\OpenRegister\Exception\ReferenceValidationException
 * @uses \OCA\OpenRegister\Exception\ValidationException
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugBatch
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugClientValue
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugHandler
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugLog
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugSource
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugStore
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ReferenceAdminBypass
 * @uses \OCA\OpenRegister\Service\Rbac\AdminGate
 * @uses   \OCA\OpenRegister\Db\SchemaCatalog
 * @uses   \OCA\OpenRegister\Exception\BrokenReference
 * @uses   \OCA\OpenRegister\Exception\ReferenceCycle
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\NestedSaveTransaction
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
 * @uses   \OCA\OpenRegister\Db\MagicMapper\StatisticsScope
 * @uses   \OCA\OpenRegister\Kernel\Contracts\ListQuery
 * @uses   \OCA\OpenRegister\Service\Object\PermissionCheckOptions
 * @uses \OCA\OpenRegister\Db\SchemaCatalog
 * @uses \OCA\OpenRegister\Exception\BrokenReference
 * @uses \OCA\OpenRegister\Exception\ReferenceCycle
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
 */
final class SaveObjectReferenceExistsTest extends TestCase {

	private const UUID = '33333333-3333-3333-3333-333333333333';

	/** @var list<mixed> */
	private array $trace = [];

	/**
	 * A handler whose object lookup answers as the case says: found, missing or failing.
	 *
	 * @param string $lookup `found`, `missing` or `fails`.
	 * @param bool   $registerKnown Whether register 5 resolves.
	 */
	private function handler(string $lookup, bool $registerKnown = true): SaveObject {
		$schema = new Schema();
		$schema->setId(2);
		$schema->setSlug('note');
		$register = new Register();
		$register->setId(5);

		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturn($schema);
		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willReturnCallback(function ($id) use ($register, $registerKnown): Register {
			$this->trace[] = ['findRegister', $id];
			if ($registerKnown === false) {
				throw new DoesNotExistException('no register');
			}

			return $register;
		});
		$unified = $this->createMock(MagicMapper::class);
		$unified->method('find')->willReturnCallback(function (...$args) use ($lookup): ObjectEntity {
			$this->trace[] = ['find', $args[0], $args[1]?->getId(), $args[2]?->getId(), $args[3], $args[4], $args[5]];
			if ($lookup === 'missing') {
				throw new DoesNotExistException('gone');
			}

			if ($lookup === 'fails') {
				throw new RuntimeException('database down');
			}

			if ($lookup === 'lookup failed') {
				throw new ObjectLookupFailedException('database down');
			}

			return new ObjectEntity();
		});
		$events = $this->createMock(IEventDispatcher::class);
		$events->method('dispatchTyped')->willReturnCallback(function (object $event): void {
			$this->trace[] = ['event', (new ReflectionClass($event))->getShortName(), $event->getPropertyName(), $event->getReferencedUuid(), $event->getTargetSchemaSlug(), $event->getTargetRegister()];
		});

		return new SaveObject(
			services: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectServices(
				persistence: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectPersistence(
					mappers: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectMappers(
						catalog: new \OCA\OpenRegister\Db\SchemaCatalog(registers: $registers, schemas: $schemas, tables: $this->createMock(MagicMapper::class)),
						unifiedObjectMapper: $unified
					),
					records: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectRecords(
						auditTrailMapper: $this->createMock(AuditTrailMapper::class),
						settingsService: $this->createMock(SettingsService::class),
						tmloService: $this->createMock(TmloService::class)
					),
					runtime: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectRuntime(
						cacheHandler: $this->createMock(CacheHandler::class)
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
						translationHandler: $this->createMock(TranslationHandler::class),
						translationProjectionService: $this->createMock(TranslationProjectionService::class),
						translationStatusService: $this->createMock(TranslationStatusService::class)
					)
				),
				platform: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectPlatform(
					userSession: $this->createMock(IUserSession::class),
					urlGenerator: $this->createMock(IURLGenerator::class),
					logger: $this->createMock(LoggerInterface::class),
					eventDispatcher: $events
				),
				tenancy: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectTenancy(
					organisationService: $this->createMock(OrganisationService::class)
				)
			),
			propertyRbacHandler: $this->createMock(PropertyRbacHandler::class),
			permissionHandler: $this->createMock(PermissionHandler::class),
			folderManagementHandler: $this->createMock(FolderManagementHandler::class)
		);
	}

	/**
	 * Check one reference, twice, and return the trace and the outcome of each call.
	 */
	private function checkTwice(SaveObject $handler, ?string $register): array {
		$outcomes = [];
		for ($i = 0; $i < 2; $i++) {
			try {
				ReferenceValidationCalls::validateReferenceExists($handler, ['author', self::UUID, '2', $register]);
				$outcomes[] = 'ok';
			} catch (ReferenceValidationException $e) {
				$outcomes[] = 'refused ' . $e->getTargetSchemaSlug();
			}
		}

		return ['outcomes' => $outcomes, 'trace' => $this->trace];
	}

	public function testAFoundReferenceIsLookedUpOnceInItsRegisterAndSchemaAndAnnounced(): void {
		$this->assertSame(
			[
				'outcomes' => ['ok', 'ok'],
				'trace' => [
					['findRegister', '5'],
					['find', self::UUID, 5, 2, false, false, false],
					['event', 'ReferenceValidatedEvent', 'author', self::UUID, 'note', '5'],
				],
			],
			$this->checkTwice($this->handler(lookup: 'found'), '5')
		);
	}

	public function testAMissingReferenceIsRefusedOnceAnnouncedAndThenReplayed(): void {
		$this->assertSame(
			[
				'outcomes' => ['refused note', 'refused 2'],
				'trace' => [
					['find', self::UUID, null, 2, false, false, false],
					['event', 'ReferenceValidationFailedEvent', 'author', self::UUID, 'note', null],
				],
			],
			$this->checkTwice($this->handler(lookup: 'missing'), null)
		);
	}

	public function testAFailingLookupLetsTheReferencePassUncached(): void {
		$this->assertSame(
			[
				'outcomes' => ['ok', 'ok'],
				'trace' => [
					['find', self::UUID, null, 2, false, false, false],
					['find', self::UUID, null, 2, false, false, false],
				],
			],
			$this->checkTwice($this->handler(lookup: 'fails'), null)
		);
	}

	public function testAFailedStoreLookupRefusesTheSave(): void {
		try {
			$this->checkTwice($this->handler(lookup: 'lookup failed'), null);
			$this->fail('A failed store lookup must not let the reference pass.');
		} catch (ObjectLookupFailedException $e) {
			$this->assertSame('database down', $e->getMessage());
		}

		$this->assertSame([['find', self::UUID, null, 2, false, false, false]], $this->trace);
	}

	public function testAnUnknownRegisterSkipsTheLookup(): void {
		$this->assertSame(
			['outcomes' => ['ok', 'ok'], 'trace' => [['findRegister', '5'], ['findRegister', '5']]],
			$this->checkTwice($this->handler(lookup: 'found', registerKnown: false), '5')
		);
	}

	public function testAReferenceOntoAnObjectBeingSavedIsACycle(): void {
		$handler = $this->handler(lookup: 'found');
		$calls = (new ReflectionClass(SaveObject::class))->getMethod('saveCalls')->invoke($handler);
		$calls->push(new SaveCallFrame('note', self::UUID, 'r1'));

		try {
			ReferenceValidationCalls::validateReferenceExists($handler, ['author', self::UUID, '2', null]);
			$this->fail('no cycle refused');
		} catch (CircularReferenceException $e) {
			$this->assertSame(self::UUID, $e->getReferencedUuid());
			$this->assertSame('2', $e->getTargetSchemaSlug());
			$this->assertSame([['schema' => 'note', 'uuid' => self::UUID, 'register' => 'r1']], $e->getCycle());
			$this->assertSame(422, $e->getCode());
		}

		$this->assertSame([], $this->trace);
	}

	/**
	 * Run validateReferences() over one schema and record the lookups, or the refusal.
	 */
	private function validateAll(string $lookup, array $properties, array $data, ?array $oldData = null): array {
		$this->trace = [];
		$schema = new Schema();
		$schema->setId(7);
		$schema->setProperties($properties);
		try {
			ReferenceValidationCalls::validateReferences($this->handler(lookup: $lookup), [$schema, $data, '5', $oldData]);
			$outcome = 'ok';
		} catch (\Throwable $e) {
			$outcome = (new ReflectionClass($e))->getShortName();
		}

		return ['outcome' => $outcome, 'finds' => array_values(array_filter($this->trace, static fn (array $t): bool => $t[0] !== 'event'))];
	}

	public function testValidateReferencesChecksOnlyTheValuesThatNeedIt(): void {
		$ref = ['$ref' => '2', 'validateReference' => true];
		$properties = [
			'a' => $ref,
			'b' => $ref + ['register' => '9'],
			'c' => ['type' => 'array', 'items' => ['$ref' => '2'], 'validateReference' => true],
			'd' => $ref,
			'e' => $ref,
			'f' => $ref,
			'g' => ['$ref' => '2'],
		];
		$data = [
			'a' => 'a1111111-1111-1111-1111-111111111111',
			'b' => 'b1111111-1111-1111-1111-111111111111',
			'c' => ['c1111111-1111-1111-1111-111111111111', '', 'c2222222-2222-2222-2222-222222222222'],
			'd' => '',
			'e' => 'e1111111-1111-1111-1111-111111111111',
			'f' => 'https://elsewhere.example/objects/1',
			'g' => 'g1111111-1111-1111-1111-111111111111',
		];

		$find = static fn (string $uuid): array => ['find', $uuid, 5, 2, false, false, false];
		$this->assertSame(
			[
				'outcome' => 'ok',
				'finds' => [
					['findRegister', '5'], $find('a1111111-1111-1111-1111-111111111111'),
					['findRegister', '9'], $find('b1111111-1111-1111-1111-111111111111'),
					$find('c1111111-1111-1111-1111-111111111111'), $find('c2222222-2222-2222-2222-222222222222'),
				],
			],
			$this->validateAll(lookup: 'found', properties: $properties, data: $data, oldData: ['e' => 'e1111111-1111-1111-1111-111111111111'])
		);
	}

	public function testAWarnOnlyReferenceLetsTheSaveGoOnAndAStrictOneRefuses(): void {
		$ref = ['$ref' => '2', 'validateReference' => true];
		$this->assertSame(
			[
				'outcome' => 'ReferenceValidationException',
				'finds' => [
					['findRegister', '5'],
					['find', 'a1111111-1111-1111-1111-111111111111', 5, 2, false, false, false],
					['find', 'b1111111-1111-1111-1111-111111111111', 5, 2, false, false, false],
				],
			],
			$this->validateAll(
				lookup: 'missing',
				properties: ['a' => $ref + ['validationStrictness' => 'warn'], 'b' => $ref],
				data: ['a' => 'a1111111-1111-1111-1111-111111111111', 'b' => 'b1111111-1111-1111-1111-111111111111']
			)
		);
	}

	public function testAFoundReferenceMustAlsoPassThePickerFilter(): void {
		$this->assertSame(
			[
				'outcome' => 'ReferenceFilterException',
				'finds' => [['findRegister', '5'], ['find', 'a1111111-1111-1111-1111-111111111111', 5, 2, false, false, false]],
			],
			$this->validateAll(
				lookup: 'found',
				properties: ['a' => ['$ref' => '2', 'validateReference' => true, 'x-openregister-reference-filter' => 'not-a-list']],
				data: ['a' => 'a1111111-1111-1111-1111-111111111111']
			)
		);
	}
}

