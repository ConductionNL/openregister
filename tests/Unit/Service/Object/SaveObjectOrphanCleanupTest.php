<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Orphan cleanup soft-deletes only a row the stored parent owned and the caller may delete.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @license  EUPL-1.2
 * @link     https://github.com/ConductionNL/openregister
 *
 * @spec openspec/specs/referential-integrity/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\File\FolderManagementHandler;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\OrphanDeletionGuard;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObject\ComputedFieldHandler;
use OCA\OpenRegister\Service\Object\SaveObject\FilePropertyHandler;
use OCA\OpenRegister\Service\Object\SaveObject\LinkedEntityPropertyHandler;
use OCA\OpenRegister\Service\Object\SaveObject\MetadataHydrationHandler;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\OrganisationServiceInterface;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\TmloService;
use OCA\OpenRegister\Service\TranslationProjectionService;
use OCA\OpenRegister\Service\TranslationStatusService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCA\OpenRegister\Dto\DeletionAnalysis;
use OCA\OpenRegister\Dto\DeletionEffects;
use OCA\OpenRegister\Exception\HookStoppedException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Exception\SharedMasterDataWriteException;
use OCA\OpenRegister\Service\Object\DeleteObject;
use OCA\OpenRegister\Service\SharedMasterDataService;
use OCP\IUser;
use Psr\Container\ContainerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject\SaveLookupsAccess;

/**
 * Every case runs the real SaveObject::saveObject. The mapper finds a row by uuid or slug, as
 * the real one does, and the test reads which rows were soft-deleted.
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
 * @covers \OCA\OpenRegister\Service\Object\OrphanDeletionGuard
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Dto\DeletionAnalysis
 * @uses   \OCA\OpenRegister\Exception\HookStoppedException
 * @uses   \OCA\OpenRegister\Exception\NotAuthorizedException
 * @uses   \OCA\OpenRegister\Exception\SharedMasterDataWriteException
 * @uses   \OCA\OpenRegister\Db\ObjectHandling
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\Object\DraftStatusPolicy
 * @uses   \OCA\OpenRegister\Service\Object\ObjectVersionHandler
 * @uses   \OCA\OpenRegister\Service\OrganisationContext
 * @uses   \OCA\OpenRegister\Service\WritePhaseProbe
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugBatch
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugClientValue
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugHandler
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugLog
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugSource
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugStore
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ReferenceAdminBypass
 * @uses \OCA\OpenRegister\Service\Rbac\AdminGate
 */
class SaveObjectOrphanCleanupTest extends TestCase {

	private const PARENT = '11111111-1111-1111-1111-111111111111';

	private const ROW = '99999999-9999-9999-9999-999999999999';

	/**
	 * The parent's stored data on an update.
	 */
	private array $stored = [];

	/**
	 * The row the mapper finds, by uuid or by `row-slug`.
	 */
	private ObjectEntity $row;

	/**
	 * Whether the caller's organisations hold the row.
	 */
	private bool $held = true;

	/**
	 * Whether the permission handler grants the delete.
	 */
	private bool $mayDelete = true;

	/**
	 * Every delete check, as [action, owner, rbac, row uuid, user].
	 *
	 * @var list<array>
	 */
	private array $asked = [];

	/**
	 * Every identifier the mapper was asked for.
	 *
	 * @var list<string>
	 */
	private array $finds = [];

	/**
	 * What the child schema's rule set declares, applied to the `note` schema.
	 */
	private array $noteConfig = [];

	/**
	 * Whether the `note` schema is append-only.
	 */
	private bool $appendOnly = false;

	/**
	 * Whether a delete listener stops the delete.
	 */
	private bool $listenerStops = false;

	/**
	 * Whether the caller's organisation only consumes the child schema.
	 */
	private bool $onlyConsumes = false;

	/**
	 * The integrity analysis of a delete.
	 */
	private ?DeletionAnalysis $analysis = null;

	/**
	 * Whether a user is signed in.
	 */
	private bool $signedIn = true;

	/**
	 * Whether the parent write reached the mapper when the row was deleted.
	 *
	 * @var list<string>
	 */
	private array $writes = [];

	/**
	 * Properties and data beside `notes`.
	 */
	private array $extra = ['properties' => [], 'data' => []];

	/**
	 * Whether the save was refused.
	 */
	private bool $refused = false;

	/**
	 * Whether the permission handler refuses a nested create.
	 */
	private bool $refuseCreate = false;

	/**
	 * Whether the save persists.
	 */
	private bool $persist = true;

	/**
	 * Whether the inverse relation sits on the property, not on its items.
	 */
	private bool $inverseOnProperty = false;

	protected function setUp(): void {
		$this->row = new ObjectEntity();
		$this->row->setUuid(self::ROW);
		$this->row->setOwner('carol');
		$this->row->setOrganisation('org-a');
		$this->row->setObject(['incident' => self::PARENT]);
	}//end setUp()

	/**
	 * The save handler over the recording mapper and permission handler.
	 *
	 * @return SaveObject
	 */
	private function handler(): SaveObject {
		$mapper = $this->createMock(MagicMapper::class);
		$mapper->method('find')->willReturnCallback(
			function (string|int $identifier): ObjectEntity {
				$this->finds[] = (string)$identifier;
				if ((string)$identifier === self::PARENT) {
					$parent = new ObjectEntity();
					$parent->setId(5);
					$parent->setUuid(self::PARENT);
					$parent->setRegister('10');
					$parent->setObject($this->stored);
					return $parent;
				}

				if ((string)$identifier === $this->row->getUuid() || (string)$identifier === 'row-slug') {
					return $this->row;
				}

				throw new DoesNotExistException('none');
			}
		);
		$mapper->method('callerOrganisationsHold')->willReturnCallback(fn (): bool => $this->held);
		$mapper->method('assertDeletable')->willReturnCallback(
			function (): void {
				if ($this->listenerStops === true) {
					throw new HookStoppedException(message: 'frozen');
				}
			}
		);
		$mapper->method('insert')->willReturnArgument(0);
		$mapper->method('update')->willReturnCallback(
			function (...$args): ObjectEntity {
				$entity = ($args['entity'] ?? $args[0]);
				$this->writes[] = (string)$entity->getUuid();
				return $entity;
			}
		);

		$shared = $this->createMock(SharedMasterDataService::class);
		$shared->method('assertWritable')->willReturnCallback(
			function (string $table, ?int $id): void {
				if ($this->onlyConsumes === true && $table === SharedMasterDataService::SCHEMAS && $id === 2) {
					throw new SharedMasterDataWriteException(holderUuid: 'org-holder', holderName: null, resourceType: 'schema', resourceTitle: 'note');
				}
			}
		);
		$organisations = $this->createMock(OrganisationService::class);
		$organisations->method('getUserActiveOrganisations')->willReturn(['org-a']);
		$deletes = $this->createMock(DeleteObject::class);
		$deletes->method('canDelete')->willReturnCallback(
			fn (): DeletionAnalysis => $this->analysis ?? new DeletionAnalysis(deletable: true)
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap(
			[
				[SharedMasterDataService::class, $shared],
				[OrganisationServiceInterface::class, $organisations],
				[DeleteObject::class, $deletes],
			]
		);
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($this->signedIn === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('alice');
		}

		$session->method('getUser')->willReturn($user);

		$permissions = $this->createMock(PermissionHandler::class);
		$permissions->method('checkPermission')->willReturnCallback(
			function (): void {
				if ($this->refuseCreate === true) {
					throw new NotAuthorizedException(message: 'no');
				}
			}
		);
		$permissions->method('hasPermission')->willReturnCallback(PermissionAskArgs::spread(
			function (Schema $schema, string $action, ?string $userId = null, ?string $objectOwner = null, bool $_rbac = true, ?ObjectEntity $object = null): bool {
				$this->asked[] = [$schema->getSlug(), $action, $userId, $objectOwner, $_rbac, $object?->getUuid()];
				return $this->mayDelete;
			}
		));

		$translations = $this->createMock(TranslationHandler::class);
		$translations->method('normalizeTranslationsForSave')->willReturnArgument(0);

		$handler = new SaveObject(
			services: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectServices(
				persistence: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectPersistence(
					mappers: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectMappers(
						catalog: new \OCA\OpenRegister\Db\SchemaCatalog(registers: $this->createMock(RegisterMapper::class), schemas: $this->createMock(SchemaMapper::class), tables: $mapper),
						unifiedObjectMapper: $mapper
					),
					records: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectRecords(
						auditTrailMapper: $this->createMock(AuditTrailMapper::class),
						settingsService: $this->createMock(SettingsService::class),
						tmloService: $this->createMock(TmloService::class)
					),
					runtime: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectRuntime(
						cacheHandler: $this->createMock(CacheHandler::class),
						objectSourceRegistry: null,
						runContext: null,
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
						translationStatusService: $this->createMock(TranslationStatusService::class),
						fieldEncryptionHandler: null
					)
				),
				platform: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectPlatform(
					userSession: $session,
					urlGenerator: $this->createMock(IURLGenerator::class),
					logger: new NullLogger(),
					eventDispatcher: null
				),
				tenancy: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectTenancy(
					organisationService: $organisations,
					groupManager: null,
					appConfig: null
				)
			),
			propertyRbacHandler: $this->createMock(PropertyRbacHandler::class),
			permissionHandler: $permissions,
			folderManagementHandler: $this->createMock(FolderManagementHandler::class)
		);

		return $handler;
	}//end handler()

	/**
	 * Save an incident with this `notes` list, and answer whether the row was soft-deleted.
	 *
	 * @param array       $notes      The incoming list.
	 * @param array       $items      The extra `items` keys of the property.
	 * @param string|null $uuid       The incident to update, null to create one.
	 * @param bool        $rbac       The RBAC mode of the save.
	 * @param array       $config     The incident schema's configuration.
	 * @param SaveObject|null $handler A handler that already has state.
	 *
	 * @return bool
	 */
	private function saveRemovesRow(
		array $notes,
		array $items = ['inversedBy' => 'incident'],
		?string $uuid = self::PARENT,
		bool $rbac = true,
		array $config = ['orphanCleanup' => true],
		?SaveObject $handler = null
	): bool {
		$handler ??= $this->handler();
		$note = new Schema();
		$note->setId(2);
		$note->setSlug('note');
		$note->setConfiguration($this->noteConfig);
		$note->setAppendOnly($this->appendOnly);
		$incident = new Schema();
		$incident->setId(1);
		$incident->setSlug('incident');
		$incident->setConfiguration($config);
		$incident->setProperties(
			['notes' => ['type' => 'array', 'items' => ['$ref' => 'note'] + $items, 'objectConfiguration' => ['handling' => 'related-object']] + ($this->inverseOnProperty === true ? ['inversedBy' => 'incident'] : [])] + $this->extra['properties']
		);
		$register = new Register();
		$register->setId(10);

		$reflection = new ReflectionClass(SaveObject::class);
		SaveLookupsAccess::set($handler, 'schemaReferenceCache', ['note' => '2']);
		SaveLookupsAccess::set($handler, 'schemaCache', ['2' => $note, '1' => $incident]);
		SaveLookupsAccess::set($handler, 'registerCache', ['10' => $register]);

		try {
			$handler->saveObject(register: $register, schema: $incident, data: ['notes' => $notes] + $this->extra['data'], uuid: $uuid, _rbac: $rbac, persist: $this->persist);
		} catch (NotAuthorizedException $e) {
			$this->refused = true;
		}

		return $this->row->isSoftDeleted();
	}//end saveRemovesRow()

	/**
	 * A scope that lacks what the bounded lookup or the ownership test needs.
	 *
	 * @return array<string, array{0: array}>
	 */
	public static function incompleteScopes(): array {
		$whole = ['register' => new Register(), 'schema' => new Schema(), 'inverse' => 'incident', 'parent' => self::PARENT];

		return [
			'no register' => [['register' => null] + $whole],
			'no schema' => [['schema' => null] + $whole],
			'no inverse relation' => [['inverse' => null] + $whole],
		];
	}//end incompleteScopes()

	/**
	 * @dataProvider incompleteScopes
	 *
	 * @param array $scope The orphan scope.
	 */
	public function testAnIncompleteScopeLooksNothingUp(array $scope): void {
		(new ReflectionClass(SaveObject::class))->getMethod('deleteOrphanedRelatedObjects')->invoke($this->handler(), [self::ROW], $scope);

		$this->assertSame([], $this->finds);
		$this->assertFalse($this->row->isSoftDeleted());
	}

	public function testACreateNamingARowBySlugDeletesNothing(): void {
		$this->assertFalse($this->saveRemovesRow(notes: ['row-slug'], uuid: null));
		$this->assertSame([], $this->asked);
	}

	public function testAnUpdateNamingARowBySlugDeletesNothing(): void {
		$this->assertFalse($this->saveRemovesRow(notes: ['row-slug']));
		$this->assertSame([], $this->asked);
	}

	public function testAStoredReferenceToAnOwnedRowIsDeletedWhenRemoved(): void {
		$this->stored = ['notes' => [self::ROW]];

		$this->assertTrue($this->saveRemovesRow(notes: ['22222222-2222-2222-2222-222222222222']));
		$this->assertSame([['note', 'delete', null, 'carol', true, self::ROW]], $this->asked);
	}

	public function testEmptyingTheListDeletesAnOwnedRow(): void {
		$this->stored = ['notes' => [self::ROW]];

		$this->assertTrue($this->saveRemovesRow(notes: []));
	}

	public function testARowThatNamesTheParentInAListIsOwned(): void {
		$this->stored = ['notes' => [self::ROW]];
		$this->row->setObject(['incident' => ['other', self::PARENT]]);

		$this->assertTrue($this->saveRemovesRow(notes: []));
	}

	public function testAKeptReferenceIsNotDeleted(): void {
		$this->stored = ['notes' => [self::ROW]];

		$this->assertFalse($this->saveRemovesRow(notes: [self::ROW]));
	}

	public function testAPlainPointerWithoutAnInverseRelationIsNotDeleted(): void {
		$this->stored = ['notes' => [self::ROW]];

		$this->assertFalse($this->saveRemovesRow(notes: [], items: []));
		$this->assertSame([], $this->asked);
	}

	public function testARowNamingAnotherParentIsNotDeleted(): void {
		$this->stored = ['notes' => [self::ROW]];
		$this->row->setObject(['incident' => 'another-parent']);

		$this->assertFalse($this->saveRemovesRow(notes: []));
	}

	public function testAStoredSlugThatFindsARowIsNotDeleted(): void {
		$this->stored = ['notes' => ['row-slug']];

		$this->assertFalse($this->saveRemovesRow(notes: []));
	}

	public function testARowOutsideTheCallersOrganisationsIsNotDeleted(): void {
		$this->stored = ['notes' => [self::ROW]];
		$this->held = false;

		$this->assertFalse($this->saveRemovesRow(notes: []));
		$this->assertSame([], $this->asked);
	}

	public function testARowTheCallerMayNotDeleteOnlyLosesTheReference(): void {
		$this->stored = ['notes' => [self::ROW]];
		$this->mayDelete = false;

		$this->assertFalse($this->saveRemovesRow(notes: []));
	}

	public function testASaveWithRbacOffDeletesAnOwnedRowOnlyWithTheCallersRights(): void {
		$this->stored = ['notes' => [self::ROW]];

		$this->assertTrue($this->saveRemovesRow(notes: [], rbac: false));
		$this->assertSame([['note', 'delete', null, 'carol', true, self::ROW]], $this->asked);
	}

	public function testASaveWithRbacOffKeepsToTheCallersOrganisations(): void {
		$this->stored = ['notes' => [self::ROW]];
		$this->held = false;

		$this->assertFalse($this->saveRemovesRow(notes: [], rbac: false));
	}

	public function testASaveWithRbacOffKeepsToTheCallersDeleteRights(): void {
		$this->stored = ['notes' => [self::ROW]];
		$this->mayDelete = false;

		$this->assertFalse($this->saveRemovesRow(notes: [], rbac: false));
	}

	/**
	 * The configurations that leave the cleanup off.
	 *
	 * @return array<string, array{0: array}>
	 */
	public static function cleanupOff(): array {
		return ['no flag' => [[]], 'flag off' => [['orphanCleanup' => false]]];
	}//end cleanupOff()

	/**
	 * @dataProvider cleanupOff
	 *
	 * @param array $config The incident schema's configuration.
	 */
	public function testWithoutTheOptInARemovedReferenceOnlyLosesTheReference(array $config): void {
		$this->stored = ['notes' => [self::ROW]];

		$this->assertFalse($this->saveRemovesRow(notes: [], config: $config));
		$this->assertNotContains(self::ROW, $this->finds);
		$this->assertSame([], $this->asked);
	}

	/**
	 * @dataProvider cleanupOff
	 *
	 * @param array $config The incident schema's configuration.
	 */
	public function testWithoutTheOptInASlugNamedInASaveTouchesNothing(array $config): void {
		$this->assertFalse($this->saveRemovesRow(notes: ['row-slug'], config: $config));
		$this->assertNotContains('row-slug', $this->finds);
	}

	public function testAConsumerOfASharedChildSchemaDeletesNothing(): void {
		$this->stored = ['notes' => [self::ROW]];
		$this->onlyConsumes = true;

		$this->assertFalse($this->saveRemovesRow(notes: []));
	}

	public function testAnAppendOnlyChildIsNotDeleted(): void {
		$this->stored = ['notes' => [self::ROW]];
		$this->appendOnly = true;

		$this->assertFalse($this->saveRemovesRow(notes: []));
	}

	public function testAViewBackedChildIsNotDeleted(): void {
		$this->stored = ['notes' => [self::ROW]];
		$this->noteConfig = ['x-openregister-view' => ['view' => 7]];

		$this->assertFalse($this->saveRemovesRow(notes: []));
	}

	public function testAnArchivalChildIsNotDeleted(): void {
		$this->stored = ['notes' => [self::ROW]];
		$this->noteConfig = ['x-openregister-archival' => ['bewaartermijn' => 'P1Y']];

		$this->assertFalse($this->saveRemovesRow(notes: []));
	}

	public function testATransferredRowIsNotDeleted(): void {
		$this->stored = ['notes' => [self::ROW]];
		$this->row->setRetention(['archiefstatus' => 'overgebracht']);

		$this->assertFalse($this->saveRemovesRow(notes: []));
	}

	public function testADeleteListenerKeepsTheRow(): void {
		$this->stored = ['notes' => [self::ROW]];
		$this->listenerStops = true;

		$this->assertFalse($this->saveRemovesRow(notes: []));
	}

	public function testARestrictedRowIsNotDeleted(): void {
		$this->stored = ['notes' => [self::ROW]];
		$this->analysis = new DeletionAnalysis(deletable: false);

		$this->assertFalse($this->saveRemovesRow(notes: []));
	}

	/**
	 * The kinds of row that depend on the row.
	 *
	 * @return array<string, array{0: DeletionAnalysis}>
	 */
	public static function dependants(): array {
		$row = [['objectUuid' => 'x']];

		return [
			'cascade' => [new DeletionAnalysis(deletable: true, effects: new DeletionEffects(cascadeTargets: $row))],
			'nullify' => [new DeletionAnalysis(deletable: true, effects: new DeletionEffects(nullifyTargets: $row))],
			'default' => [new DeletionAnalysis(deletable: true, effects: new DeletionEffects(defaultTargets: $row))],
		];
	}//end dependants()

	/**
	 * @dataProvider dependants
	 *
	 * @param DeletionAnalysis $analysis The integrity analysis.
	 */
	public function testARowOthersDependOnIsNotDeleted(DeletionAnalysis $analysis): void {
		$this->stored = ['notes' => [self::ROW]];
		$this->analysis = $analysis;

		$this->assertFalse($this->saveRemovesRow(notes: []));
	}

	public function testTheDeletionNamesTheCaller(): void {
		$this->stored = ['notes' => [self::ROW]];

		$this->assertTrue($this->saveRemovesRow(notes: []));
		$this->assertSame('alice', $this->row->getDeleted()['deletedBy']);
	}

	public function testASaveWithoutASignedInUserDeletesNothing(): void {
		$this->stored = ['notes' => [self::ROW]];
		$this->signedIn = false;

		$this->assertFalse($this->saveRemovesRow(notes: []));
		$this->assertSame([], $this->asked);
	}

	public function testARefusalOnALaterPropertyLeavesTheRowAlone(): void {
		$this->stored = ['notes' => [self::ROW]];
		$this->extra = [
			'properties' => ['secrets' => ['type' => 'array', 'items' => ['$ref' => 'note'], 'objectConfiguration' => ['handling' => 'cascade']]],
			'data' => ['secrets' => [['name' => 'x']]],
		];
		$this->refuseCreate = true;

		$this->assertFalse($this->saveRemovesRow(notes: []));
		$this->assertTrue($this->refused);
	}

	public function testTheRowIsDeletedAfterTheParentIsWritten(): void {
		$this->stored = ['notes' => [self::ROW]];

		$this->assertTrue($this->saveRemovesRow(notes: []));
		$this->assertSame([self::PARENT, self::ROW], array_slice($this->writes, 0, 2));
	}

	public function testAPendingOrphanIsDroppedWhenTheSaveDoesNotPersist(): void {
		$this->stored = ['notes' => [self::ROW]];
		$this->persist = false;

		$this->assertFalse($this->saveRemovesRow(notes: []));
	}

	/**
	 * Other spellings of the kept uuid.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function sameRow(): array {
		return [
			'no dashes' => [str_replace('-', '', self::ROW)],
			'prefixed' => ['id-' . self::ROW],
			'upper case' => [strtoupper('ABCDEF12-1234-1234-1234-123456789ABC')],
		];
	}//end sameRow()

	/**
	 * @dataProvider sameRow
	 *
	 * @param string $spelling The kept reference.
	 */
	public function testAnotherSpellingOfAKeptReferenceIsKept(string $spelling): void {
		$lower = strtolower($spelling);
		$stored = str_contains($lower, 'abcdef12') === true ? $lower : self::ROW;
		$this->stored = ['notes' => [$stored]];
		$this->row->setUuid($stored);

		$this->assertFalse($this->saveRemovesRow(notes: [$spelling]));
	}

	public function testAStaleOrphanOfARefusedSaveIsNotDeletedByTheNextSave(): void {
		$handler = $this->handler();
		$this->stored = ['notes' => [self::ROW]];
		(new ReflectionClass(SaveObject::class))->getProperty('pendingOrphans')->setValue(
			$handler,
			[self::PARENT => [[[self::ROW], ['register' => new Register(), 'schema' => new Schema(), 'inverse' => 'incident', 'parent' => self::PARENT]]]]
		);

		$this->assertFalse($this->saveRemovesRow(notes: [self::ROW], handler: $handler));
	}

	public function testASettledOrphanIsNotDeletedAgain(): void {
		$handler = $this->handler();
		$settle = (new ReflectionClass(SaveObject::class))->getMethod('settleOrphans');
		$pending = (new ReflectionClass(SaveObject::class))->getProperty('pendingOrphans');
		$pending->setValue(
			$handler,
			[self::PARENT => [[[self::ROW], ['register' => new Register(), 'schema' => new Schema(), 'inverse' => 'incident', 'parent' => self::PARENT]]]]
		);

		$settle->invoke($handler, self::PARENT);

		$this->assertSame([], $pending->getValue($handler));
	}

	public function testAConsumerOfASharedRegisterDeletesNothing(): void {
		$shared = $this->createMock(SharedMasterDataService::class);
		$shared->method('assertWritable')->willReturnCallback(
			static function (string $table): void {
				if ($table === SharedMasterDataService::REGISTERS) {
					throw new SharedMasterDataWriteException(holderUuid: 'org-holder');
				}
			}
		);
		$organisations = $this->createMock(OrganisationService::class);
		$organisations->method('getUserActiveOrganisations')->willReturn(['org-a']);
		$container = $this->createMock(ContainerInterface::class);
		$deletes = $this->createMock(DeleteObject::class);
		$deletes->method('canDelete')->willReturn(new DeletionAnalysis(deletable: true));
		$container->method('get')->willReturnMap([[SharedMasterDataService::class, $shared], [OrganisationServiceInterface::class, $organisations], [DeleteObject::class, $deletes]]);
		$permissions = $this->createMock(PermissionHandler::class);
		$permissions->method('hasPermission')->willReturn(true);
		$mapper = $this->createMock(MagicMapper::class);
		$mapper->method('callerOrganisationsHold')->willReturn(true);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->createMock(IUser::class));
		$guard = new OrphanDeletionGuard(objectMapper: $mapper, permissionHandler: $permissions, userSession: $session, container: $container);

		$this->assertFalse($guard->allows(row: $this->row, register: new Register(), schema: new Schema()));
	}

	public function testAnInverseRelationOnThePropertyCounts(): void {
		$this->stored = ['notes' => [self::ROW]];
		$this->inverseOnProperty = true;

		$this->assertTrue($this->saveRemovesRow(notes: [], items: []));
	}

	public function testTheFlagSurvivesAnExportAndImport(): void {
		$schema = new Schema();
		$schema->setConfiguration(['orphanCleanup' => true, 'allowFiles' => true]);
		$exported = json_decode((string)json_encode($schema->jsonSerialize()), true);

		$imported = new Schema();
		$imported->hydrate(['configuration' => $exported['configuration']]);

		$this->assertTrue($imported->getConfiguration()['orphanCleanup']);
		$this->assertTrue($imported->getConfiguration()['allowFiles']);
	}

	public function testANonBooleanFlagIsNotStored(): void {
		$schema = new Schema();
		$schema->setConfiguration(['orphanCleanup' => 'true', 'allowFiles' => true]);

		$this->assertArrayNotHasKey('orphanCleanup', $schema->getConfiguration());
	}

	public function testTheOptInSurvivesTheSchemasConfigurationCheck(): void {
		$schema = new Schema();
		$schema->setConfiguration(['orphanCleanup' => true]);

		$this->assertTrue($schema->getConfiguration()['orphanCleanup']);
	}
}//end class
