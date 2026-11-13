<?php

declare(strict_types=1);

/**
 * DeleteObject Unit Tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @author   OpenRegister Team
 * @license  EUPL-1.2
 * @link     https://github.com/OpenRegister/OpenRegister
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Service\Object\RefusedDeletion;
use OCA\OpenRegister\Service\Object\CascadeDeleteOrigin;
use Exception;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Dto\DeletionAnalysis;
use OCA\OpenRegister\Dto\DeletionEffects;
use OCA\OpenRegister\Exception\HookStoppedException;
use OCA\OpenRegister\Exception\ReferentialIntegrityException;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\DeleteObject;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Service\Object\DeletionRequest;
use OCA\OpenRegister\Service\Object\DeletionOptions;
use OCA\OpenRegister\Service\Object\RemovalOptions;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Service\Object\ReferentialIntegrityService;
use OCA\OpenRegister\Service\SettingsService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Unit tests for DeleteObject service.
 *
 * Covers:
 * - canDelete() delegation to ReferentialIntegrityService
 * - delete() soft-delete path (with/without user, audit trail on/off, cache invalidation)
 * - delete() called with ObjectEntity directly vs array input
 * - deleteObject() referential integrity gating (RESTRICT block, actions applied)
 * - deleteObject() legacy cascade (cascade: true properties)
 * - deleteObject() error handling (exception caught, returns false)
 * - deleteObject() sub-deletion (originalObjectId set — skips integrity checks)
 * - isAuditTrailsEnabled() fallback when settings throw
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 */
class DeleteObjectTest extends TestCase {
	/** @var DeleteObject */
	private DeleteObject $handler;

	/** @var MagicMapper&MockObject */
	private MagicMapper $objectMapper;

	/** @var CacheHandler&MockObject */
	private CacheHandler $cacheHandler;

	/** @var IUserSession&MockObject */
	private IUserSession $userSession;

	/** @var AuditTrailMapper&MockObject */
	private AuditTrailMapper $auditTrailMapper;

	/** @var SettingsService&MockObject */
	private SettingsService $settingsService;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	/** @var ReferentialIntegrityService&MockObject */
	private ReferentialIntegrityService $integrityService;

	/** @var OrganisationMapper&MockObject */
	private OrganisationMapper $organisationMapper;

	// =========================================================================
	// Set-up
	// =========================================================================

	protected function setUp(): void {
		parent::setUp();

		$this->objectMapper = $this->createMock(MagicMapper::class);
		$this->cacheHandler = $this->createMock(CacheHandler::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->integrityService = $this->createMock(ReferentialIntegrityService::class);
		$this->organisationMapper = $this->createMock(OrganisationMapper::class);

		$this->handler = new DeleteObject(
			$this->objectMapper,
			$this->cacheHandler,
			new \OCA\OpenRegister\Service\Object\AuditRecording($this->auditTrailMapper, $this->logger),
			$this->settingsService,
			new \OCA\OpenRegister\Service\Object\DeletionIntegrity($this->integrityService, $this->createMock(\OCP\IDBConnection::class)),
			new \OCA\OpenRegister\Service\Object\DeletionAttribution($this->userSession, $this->organisationMapper, $this->logger)
		);
	}

	// =========================================================================
	// Helper methods
	// =========================================================================

	/**
	 * A handler with a file service, for the folder half of a permanent delete.
	 *
	 * @param \OCA\OpenRegister\Service\FileService $files The file service.
	 *
	 * @return DeleteObject The handler.
	 */
	private function handlerWithFiles(\OCA\OpenRegister\Service\FileService $files): DeleteObject {
		return new DeleteObject(
			$this->objectMapper,
			$this->cacheHandler,
			new \OCA\OpenRegister\Service\Object\AuditRecording($this->auditTrailMapper, $this->logger),
			$this->settingsService,
			new \OCA\OpenRegister\Service\Object\DeletionIntegrity($this->integrityService, $this->createMock(\OCP\IDBConnection::class)),
			new \OCA\OpenRegister\Service\Object\DeletionAttribution($this->userSession, $this->organisationMapper, $this->logger),
			$files
		);
	}


	/**
	 * Create an ObjectEntity with common fields pre-filled.
	 *
	 * @param string $uuid Entity UUID.
	 * @param string|null $register Register ID (as string).
	 * @param string|null $schema Schema ID (as string).
	 * @param array|null $object Raw object data payload.
	 *
	 * @return ObjectEntity
	 */
	private function createObjectEntity(
		string $uuid,
		?string $register = '1',
		?string $schema = '1',
		?array $object = null,
	): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		if ($register !== null) {
			$entity->setRegister($register);
		}
		if ($schema !== null) {
			$entity->setSchema($schema);
		}
		if ($object !== null) {
			$entity->setObject($object);
		}

		return $entity;
	}

	/**
	 * Set the id property on an Entity via reflection (the field is managed by QBMapper).
	 *
	 * @param object $entity Entity to modify.
	 * @param int $id Value to set.
	 *
	 * @return void
	 */
	private function setEntityId(object $entity, int $id): void {
		$reflection = new ReflectionClass($entity);
		$class = $reflection;
		while ($class !== false) {
			if ($class->hasProperty('id')) {
				$prop = $class->getProperty('id');
				$prop->setAccessible(true);
				$prop->setValue($entity, $id);
				return;
			}

			$class = $class->getParentClass();
		}
	}

	/**
	 * Create a Register entity with the given ID.
	 *
	 * @param int $id Register ID.
	 *
	 * @return Register
	 */
	private function createRegister(int $id): Register {
		$register = new Register();
		$this->setEntityId($register, $id);
		return $register;
	}

	/**
	 * Create a Schema entity with the given ID and optional properties.
	 *
	 * @param int $id Schema ID.
	 * @param array $properties Schema properties map.
	 * @param string|null $slug Schema slug.
	 *
	 * @return Schema
	 */
	private function createSchema(int $id, array $properties = [], ?string $slug = null): Schema {
		$schema = new Schema();
		$this->setEntityId($schema, $id);
		$schema->setProperties($properties);
		if ($slug !== null) {
			$schema->setSlug($slug);
		}

		return $schema;
	}

	/**
	 * Wire the userSession mock to return no logged-in user (system context).
	 *
	 * @return void
	 */
	private function withNoUser(): void {
		$this->userSession->method('getUser')->willReturn(null);
	}

	/**
	 * Wire the userSession mock to return a user with the given UID.
	 *
	 * @param string $uid User ID.
	 *
	 * @return IUser&MockObject
	 */
	private function withUser(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		return $user;
	}

	/**
	 * Wire the settingsService to return the given auditTrailsEnabled flag.
	 *
	 * @param bool $enabled Whether audit trails are enabled.
	 *
	 * @return void
	 */
	private function withAuditTrailsEnabled(bool $enabled): void {
		$this->settingsService
			->method('getRetentionSettingsOnly')
			->willReturn(['auditTrailsEnabled' => $enabled]);
	}

	/**
	 * Build a minimal findAcrossAllSources return array.
	 *
	 * @param ObjectEntity $object The object entity.
	 * @param Register|null $register The register entity.
	 * @param Schema|null $schema The schema entity.
	 *
	 * @return array{object: ObjectEntity, register: Register|null, schema: Schema|null}
	 */
	private function makeContext(
		ObjectEntity $object,
		?Register $register = null,
		?Schema $schema = null,
	): array {
		return [
			'object' => $object,
			'register' => $register,
			'schema' => $schema,
		];
	}

	// =========================================================================
	// canDelete() tests
	// =========================================================================

	/**
	 * canDelete() delegates to integrityService and returns DeletionAnalysis as-is.
	 */
	public function testCanDeleteDelegatesToIntegrityService(): void {
		$object = $this->createObjectEntity('uuid-1');
		$analysis = new DeletionAnalysis(deletable: true);

		$this->integrityService
			->expects($this->once())
			->method('canDelete')
			->with($object)
			->willReturn($analysis);

		$result = $this->handler->canDelete($object);

		$this->assertSame($analysis, $result);
	}

	/**
	 * canDelete() returns non-deletable analysis when blockers are present.
	 */
	public function testCanDeleteReturnsNonDeletableAnalysis(): void {
		$object = $this->createObjectEntity('uuid-2');
		$analysis = new DeletionAnalysis(
			deletable: false,
			blockers: [['objectUuid' => 'ref-uuid', 'property' => 'parentId']]
		);

		$this->integrityService
			->method('canDelete')
			->willReturn($analysis);

		$result = $this->handler->canDelete($object);

		$this->assertFalse($result->deletable);
		$this->assertCount(1, $result->blockers);
	}

	// =========================================================================
	// delete() — ObjectEntity input path
	// =========================================================================

	/**
	 * delete() with ObjectEntity performs a soft delete and returns true.
	 */
	public function testDeleteWithObjectEntityReturnsTrueOnSuccess(): void {
		$object = $this->createObjectEntity('uuid-3', '2', '5');
		$register = $this->createRegister(2);
		$schema = $this->createSchema(5);
		$context = $this->makeContext($object, $register, $schema);

		$this->objectMapper
			->expects($this->once())
			->method('findAcrossAllSources')
			->with('uuid-3', true, false, false)
			->willReturn($context);

		$this->objectMapper
			->expects($this->once())
			->method('update')
			->willReturn($object);

		$this->withNoUser();
		$this->withAuditTrailsEnabled(false);

		$result = $this->handler->delete($object);

		$this->assertTrue($result);
	}

	/**
	 * A soft delete asks the delete guards about the object it is deleting, before it writes.
	 */
	public function testSoftDeleteAsksTheDeleteGuardsFirst(): void {
		$object = $this->createObjectEntity('uuid-guarded', '2', '5');
		$order = [];

		$this->objectMapper
			->expects($this->once())
			->method('assertDeletable')
			->with($this->identicalTo($object))
			->willReturnCallback(function () use (&$order, $object): void {
				$order[] = 'guard';
				$this->assertEmpty($object->getDeleted(), 'the guards see the object before it is marked deleted');
			});
		$this->objectMapper
			->expects($this->once())
			->method('update')
			->willReturnCallback(function () use (&$order, $object): ObjectEntity {
				$order[] = 'write';
				return $object;
			});

		$this->withNoUser();
		$this->withAuditTrailsEnabled(false);

		$this->assertTrue($this->handler->delete($object, new RemovalOptions(table: new RegisterSchema(register: $this->createRegister(2), schema: $this->createSchema(5)))));
		$this->assertSame(['guard', 'write'], $order);
	}

	/**
	 * A guard that refuses (a frozen object) stops the soft delete: nothing is written or audited.
	 */
	public function testARefusingGuardStopsTheSoftDelete(): void {
		$object = $this->createObjectEntity('uuid-frozen', '2', '5');
		$refusal = new HookStoppedException(message: 'This record is locked', errors: ['status' => 409]);

		$this->objectMapper->method('assertDeletable')->willThrowException($refusal);
		$this->objectMapper->expects($this->never())->method('update');
		$this->auditTrailMapper->expects($this->never())->method('createAuditTrail');
		$this->withAuditTrailsEnabled(true);

		try {
			$this->handler->delete($object, new RemovalOptions(table: new RegisterSchema(register: $this->createRegister(2), schema: $this->createSchema(5))));
			$this->fail('a refused soft delete must throw');
		} catch (HookStoppedException $exception) {
			$this->assertSame($refusal, $exception);
		}

		$this->assertEmpty($object->getDeleted());
	}

	/**
	 * A permanent delete asks the guards once, itself, and tells the mapper not to ask again.
	 */
	public function testAPermanentDeleteAsksTheGuardsOnce(): void {
		$object = $this->createObjectEntity('uuid-permanent', '2', '5');

		$this->objectMapper->expects($this->once())->method('assertDeletable')->with($this->identicalTo($object));
		$this->objectMapper->expects($this->once())->method('deleteObjectEntity')
			->with($this->identicalTo($object), $this->anything(), $this->anything(), true, false)
			->willReturn($object);
		$this->withAuditTrailsEnabled(false);

		$this->assertTrue($this->handler->delete($object, new RemovalOptions(permanent: true, table: new RegisterSchema(register: $this->createRegister(2), schema: $this->createSchema(5)))));
	}

	/**
	 * A permanent delete announces the removed object, after the row is gone.
	 */
	public function testAPermanentDeleteAnnouncesTheDeletedObject(): void {
		$object = $this->createObjectEntity('uuid-permanent-event', '2', '5');
		$events = $this->createMock(IEventDispatcher::class);
		$handler = new DeleteObject(
			$this->objectMapper,
			$this->cacheHandler,
			new \OCA\OpenRegister\Service\Object\AuditRecording($this->auditTrailMapper, $this->logger),
			$this->settingsService,
			new \OCA\OpenRegister\Service\Object\DeletionIntegrity($this->integrityService, $this->createMock(\OCP\IDBConnection::class)),
			new \OCA\OpenRegister\Service\Object\DeletionAttribution($this->userSession, $this->organisationMapper, $this->logger),
			eventDispatcher: $events
		);

		$order = [];
		$this->objectMapper->method('deleteObjectEntity')->willReturnCallback(
			function () use (&$order, $object) {
				$order[] = 'row';
				return $object;
			}
		);
		$events->expects($this->once())->method('dispatchTyped')
			->with($this->callback(fn ($event): bool => $event instanceof ObjectDeletedEvent && $event->getObject() === $object))
			->willReturnCallback(function () use (&$order): void {
				$order[] = 'event';
			});
		$this->withAuditTrailsEnabled(false);

		$this->assertTrue($handler->delete($object, new RemovalOptions(permanent: true, table: new RegisterSchema(register: $this->createRegister(2), schema: $this->createSchema(5)))));
		$this->assertSame(['row', 'event'], $order);
	}

	/**
	 * A soft delete leaves the announcement to the mapper's update.
	 */
	public function testASoftDeleteDoesNotAnnounceADeletion(): void {
		$object = $this->createObjectEntity('uuid-soft-event', '2', '5');
		$events = $this->createMock(IEventDispatcher::class);
		$events->expects($this->never())->method('dispatchTyped');
		$handler = new DeleteObject(
			$this->objectMapper,
			$this->cacheHandler,
			new \OCA\OpenRegister\Service\Object\AuditRecording($this->auditTrailMapper, $this->logger),
			$this->settingsService,
			new \OCA\OpenRegister\Service\Object\DeletionIntegrity($this->integrityService, $this->createMock(\OCP\IDBConnection::class)),
			new \OCA\OpenRegister\Service\Object\DeletionAttribution($this->userSession, $this->organisationMapper, $this->logger),
			eventDispatcher: $events
		);
		$this->objectMapper->method('update')->willReturn($object);
		$this->withNoUser();
		$this->withAuditTrailsEnabled(false);

		$this->assertTrue($handler->delete($object, new RemovalOptions(permanent: false, table: new RegisterSchema(register: $this->createRegister(2), schema: $this->createSchema(5)))));
	}

	/**
	 * A refused permanent delete leaves the object's folder where it is.
	 */
	public function testARefusedPermanentDeleteKeepsTheFolder(): void {
		$object = $this->createObjectEntity('uuid-frozen-folder', '2', '5');
		$object->setFolder('42');
		$files = $this->createMock(\OCA\OpenRegister\Service\FileService::class);
		$files->expects($this->never())->method('getObjectFolder');
		$handler = $this->handlerWithFiles(files: $files);

		$this->objectMapper->method('assertDeletable')->willThrowException(new HookStoppedException(message: 'locked'));
		$this->objectMapper->expects($this->never())->method('deleteObjectEntity');

		$this->expectException(HookStoppedException::class);
		$handler->delete($object, new RemovalOptions(permanent: true, table: new RegisterSchema(register: $this->createRegister(2), schema: $this->createSchema(5))));
	}

	/**
	 * The root's guards refuse before a cascade:true child is deleted.
	 */
	public function testARefusedRootDeletesNoCascadeChild(): void {
		$parent = $this->createObjectEntity('parent-locked', '1', '10', ['childRef' => 'child-1']);
		$child = $this->createObjectEntity('child-1', '1', '10');
		$register = $this->createRegister(1);
		$schema = $this->createSchema(10, ['childRef' => ['cascade' => true, 'type' => 'string']]);
		$this->objectMapper->method('findAcrossAllSources')->willReturnCallback(
			fn (string $identifier) => $this->makeContext($identifier === 'child-1' ? $child : $parent, $register, $schema)
		);
		$this->integrityService->method('hasIncomingOnDeleteReferences')->willReturn(false);
		$this->withNoUser();
		$this->withAuditTrailsEnabled(false);
		// Only the root is locked; the child on its own may go.
		$this->objectMapper->method('assertDeletable')->willReturnCallback(
			static function (ObjectEntity $object): void {
				if ($object->getUuid() === 'parent-locked') {
					throw new HookStoppedException(message: 'locked');
				}
			}
		);
		$this->objectMapper->expects($this->never())->method('update');

		$this->expectException(HookStoppedException::class);
		$this->handler->deleteObject(new DeletionRequest(register: 1, schema: 10, uuid: 'parent-locked'));
	}

	/**
	 * A root delete asks its guards exactly once, before the cascade, not again when it writes.
	 */
	public function testARootDeleteAsksItsGuardsOnce(): void {
		$object = $this->createObjectEntity('uuid-root', '1', '10');
		$this->objectMapper->method('findAcrossAllSources')->willReturn($this->makeContext($object));
		$this->objectMapper->method('update')->willReturn($object);
		$this->integrityService->method('hasIncomingOnDeleteReferences')->willReturn(false);
		$this->withNoUser();
		$this->withAuditTrailsEnabled(false);
		$this->objectMapper->expects($this->once())->method('assertDeletable');

		$this->assertTrue($this->handler->deleteObject(new DeletionRequest(register: 1, schema: 10, uuid: 'uuid-root')));
	}

	/**
	 * A guard answer kept for a root whose delete failed does not carry over to the next root delete.
	 */
	public function testANewRootDeleteForgetsTheGuardsAnEarlierFailedRootAsked(): void {
		$failed = $this->createObjectEntity('uuid-failed', '1', '10');
		$other = $this->createObjectEntity('uuid-other', '1', '20');
		$this->objectMapper->method('findAcrossAllSources')->willReturnCallback(
			fn (string $identifier): array => $this->makeContext($identifier === 'uuid-failed' ? $failed : $other)
		);
		$this->objectMapper->method('update')->willReturnArgument(0);
		$this->integrityService->method('hasIncomingOnDeleteReferences')
			->willReturnCallback(static fn (string $schemaId): bool => $schemaId === '10');
		$this->integrityService->method('canDelete')->willReturn(new DeletionAnalysis(deletable: true));
		$this->integrityService->method('applyDeletionActions')->willThrowException(new \RuntimeException('cascade failed'));
		$this->withNoUser();
		$this->withAuditTrailsEnabled(false);

		$asked = [];
		$this->objectMapper->method('assertDeletable')->willReturnCallback(
			static function (ObjectEntity $entity) use (&$asked): void {
				$asked[] = $entity->getUuid();
			}
		);

		try {
			$this->handler->deleteObject(new DeletionRequest(register: 1, schema: 10, uuid: 'uuid-failed'));
			$this->fail('The failing cascade was expected to throw.');
		} catch (\RuntimeException $e) {
			$this->assertSame('cascade failed', $e->getMessage());
		}

		$this->handler->deleteObject(new DeletionRequest(register: 1, schema: 20, uuid: 'uuid-other'));
		$asked = [];
		$this->handler->delete(object: $failed, options: new RemovalOptions(table: new RegisterSchema(register: $this->createRegister(1), schema: $this->createSchema(10, [], 'my-schema'))));

		$this->assertSame(['uuid-failed'], $asked);
	}

	/**
	 * delete() sets deleted metadata with the current user UID when a user is logged in.
	 */
	public function testDeleteSetsDeletedByCurrentUser(): void {
		$object = $this->createObjectEntity('uuid-4');
		$context = $this->makeContext($object);

		$this->objectMapper
			->method('findAcrossAllSources')
			->willReturn($context);

		$capturedEntity = null;
		$this->objectMapper
			->method('update')
			->willReturnCallback(function ($entity) use (&$capturedEntity, $object) {
				$capturedEntity = $entity;
				return $object;
			});

		$this->withUser('alice');
		$this->withAuditTrailsEnabled(false);
		$this->organisationMapper->expects($this->once())
			->method('getActiveOrganisationWithFallback')
			->with('alice')
			->willReturn('org-uuid-1');
		$this->logger->expects($this->never())->method('warning');

		$this->handler->delete($object);

		$this->assertNotNull($capturedEntity);
		$deleted = $capturedEntity->getDeleted();
		$this->assertIsArray($deleted);
		$this->assertSame('alice', $deleted['deletedBy']);
		$this->assertSame('uuid-4', $deleted['objectId']);
		$this->assertSame('org-uuid-1', $deleted['organisation']);
		$this->assertArrayHasKey('deletedAt', $deleted);
	}

	/**
	 * delete() sets deletedBy to "system" when no user is logged in.
	 */
	public function testDeleteSetsDeletedBySystemWhenNoUser(): void {
		$object = $this->createObjectEntity('uuid-5');
		$context = $this->makeContext($object);

		$this->objectMapper->method('findAcrossAllSources')->willReturn($context);

		$capturedEntity = null;
		$this->objectMapper
			->method('update')
			->willReturnCallback(function ($entity) use (&$capturedEntity, $object) {
				$capturedEntity = $entity;
				return $object;
			});

		$this->withNoUser();
		$this->withAuditTrailsEnabled(false);

		$this->handler->delete($object);

		$deleted = $capturedEntity->getDeleted();
		$this->assertSame('system', $deleted['deletedBy']);
	}

	/**
	 * delete() creates an audit trail entry when audit trails are enabled.
	 */
	public function testDeleteCreatesAuditTrailWhenEnabled(): void {
		$object = $this->createObjectEntity('uuid-6');
		$context = $this->makeContext($object);

		$this->objectMapper->method('findAcrossAllSources')->willReturn($context);
		$this->objectMapper->method('update')->willReturn($object);

		$this->withNoUser();
		$this->withAuditTrailsEnabled(true);

		$this->auditTrailMapper
			->expects($this->once())
			->method('createAuditTrail')
			->with($object, null, 'delete');

		$this->handler->delete($object);
	}

	/**
	 * delete() does not create an audit trail when audit trails are disabled.
	 */
	public function testDeleteSkipsAuditTrailWhenDisabled(): void {
		$object = $this->createObjectEntity('uuid-7');
		$context = $this->makeContext($object);

		$this->objectMapper->method('findAcrossAllSources')->willReturn($context);
		$this->objectMapper->method('update')->willReturn($object);

		$this->withNoUser();
		$this->withAuditTrailsEnabled(false);

		$this->auditTrailMapper
			->expects($this->never())
			->method('createAuditTrail');

		$this->handler->delete($object);
	}

	/**
	 * delete() invokes cache invalidation after a successful update.
	 */
	public function testDeleteInvalidatesCacheOnSuccess(): void {
		$object = $this->createObjectEntity('uuid-8', '3', '7');
		$context = $this->makeContext($object);

		$this->objectMapper->method('findAcrossAllSources')->willReturn($context);
		$this->objectMapper->method('update')->willReturn($object);

		$this->withNoUser();
		$this->withAuditTrailsEnabled(false);

		$this->cacheHandler
			->expects($this->once())
			->method('invalidateForObjectChange')
			->with($object, 'soft_delete', 3, 7);

		$this->handler->delete($object);
	}

	/**
	 * delete() still returns true even when cache invalidation throws.
	 */
	public function testDeleteReturnsTrueWhenCacheInvalidationThrows(): void {
		$object = $this->createObjectEntity('uuid-9');
		$context = $this->makeContext($object);

		$this->objectMapper->method('findAcrossAllSources')->willReturn($context);
		$this->objectMapper->method('update')->willReturn($object);

		$this->cacheHandler
			->method('invalidateForObjectChange')
			->willThrowException(new Exception('Solr not available'));

		$this->withNoUser();
		$this->withAuditTrailsEnabled(false);

		$result = $this->handler->delete($object);

		$this->assertTrue($result);
	}

	/**
	 * delete() returns true when objectMapper->update() returns the entity
	 * (the only valid return type — update() is non-nullable).
	 */
	public function testDeleteReturnsTrueWhenUpdateSucceeds(): void {
		$object = $this->createObjectEntity('uuid-10');
		$context = $this->makeContext($object);

		$this->objectMapper->method('findAcrossAllSources')->willReturn($context);
		$this->objectMapper->method('update')->willReturn($object);

		$this->withNoUser();
		$this->withAuditTrailsEnabled(false);

		$result = $this->handler->delete($object);

		$this->assertTrue($result);
	}

	/**
	 * delete() defaults audit trails to enabled when settingsService throws.
	 */
	public function testDeleteDefaultsAuditTrailToEnabledOnSettingsException(): void {
		$object = $this->createObjectEntity('uuid-11');
		$context = $this->makeContext($object);

		$this->objectMapper->method('findAcrossAllSources')->willReturn($context);
		$this->objectMapper->method('update')->willReturn($object);

		$this->settingsService
			->method('getRetentionSettingsOnly')
			->willThrowException(new Exception('settings unavailable'));

		$this->withNoUser();
		$this->logger->method('warning');

		// Audit trail should still be created (default-enabled fallback).
		$this->auditTrailMapper
			->expects($this->once())
			->method('createAuditTrail');

		$this->handler->delete($object);
	}

	// =========================================================================
	// delete() — array input path
	// =========================================================================

	/**
	 * delete() accepts an array with 'id' key and looks up the object.
	 */
	public function testDeleteWithArrayInputLoadsObjectFromMapper(): void {
		$object = $this->createObjectEntity('uuid-arr-1');
		$context = $this->makeContext($object);

		$this->objectMapper
			->expects($this->once())
			->method('findAcrossAllSources')
			->with('uuid-arr-1', false, false, false)
			->willReturn($context);

		$this->objectMapper->method('update')->willReturn($object);

		$this->withNoUser();
		$this->withAuditTrailsEnabled(false);

		$result = $this->handler->delete(['id' => 'uuid-arr-1']);

		$this->assertTrue($result);
	}

	// =========================================================================
	// deleteObject() — referential integrity
	// =========================================================================

	/**
	 * deleteObject() skips integrity check when schema has no incoming onDelete refs.
	 */
	public function testDeleteObjectSkipsIntegrityCheckWhenNoIncomingRefs(): void {
		$object = $this->createObjectEntity('uuid-do-1', '1', '10');
		$register = $this->createRegister(1);
		$schema = $this->createSchema(10);
		$context = $this->makeContext($object, $register, $schema);

		$this->objectMapper
			->expects($this->atLeastOnce())
			->method('findAcrossAllSources')
			->willReturn($context);

		$this->objectMapper->method('update')->willReturn($object);

		$this->integrityService
			->expects($this->once())
			->method('hasIncomingOnDeleteReferences')
			->with('10')
			->willReturn(false);

		// canDelete must NOT be called.
		$this->integrityService
			->expects($this->never())
			->method('canDelete');

		$this->withNoUser();
		$this->withAuditTrailsEnabled(false);

		$result = $this->handler->deleteObject(new DeletionRequest(register: 1, schema: 10, uuid: 'uuid-do-1'));

		$this->assertTrue($result);
	}

	/**
	 * deleteObject() throws ReferentialIntegrityException when deletion is blocked.
	 */
	public function testDeleteObjectThrowsWhenDeletionIsBlocked(): void {
		$object = $this->createObjectEntity('uuid-do-2', '1', '10');
		$register = $this->createRegister(1);
		$schema = $this->createSchema(10);
		$context = $this->makeContext($object, $register, $schema);

		$this->objectMapper->method('findAcrossAllSources')->willReturn($context);

		$this->integrityService->method('hasIncomingOnDeleteReferences')->willReturn(true);

		$analysis = new DeletionAnalysis(
			deletable: false,
			blockers: [['objectUuid' => 'other-uuid', 'property' => 'relatedId']]
		);
		$this->integrityService->method('canDelete')->willReturn($analysis);

		$this->integrityService
			->expects($this->once())
			->method('logRestrictBlock')
			->with(new RefusedDeletion(objectUuid: 'uuid-do-2', schemaId: '10', userId: 'system'), $analysis);

		$this->withNoUser();

		$this->expectException(ReferentialIntegrityException::class);
		$this->expectExceptionMessage('Cannot delete object: 1 dependent object(s) block deletion');

		$this->handler->deleteObject(new DeletionRequest(register: 1, schema: 10, uuid: 'uuid-do-2'));
	}

	/**
	 * deleteObject() applies integrity actions when deletable and has incoming refs.
	 */
	public function testDeleteObjectAppliesDeletionActionsWhenDeletable(): void {
		$object = $this->createObjectEntity('uuid-do-3', '1', '10');
		$register = $this->createRegister(1);
		$schema = $this->createSchema(10, [], 'my-schema');
		$context = $this->makeContext($object, $register, $schema);

		$this->objectMapper
			->method('findAcrossAllSources')
			->willReturn($context);

		$this->objectMapper->method('update')->willReturn($object);

		$this->integrityService->method('hasIncomingOnDeleteReferences')->willReturn(true);

		$analysis = new DeletionAnalysis(
			deletable: true,
			effects: new DeletionEffects(nullifyTargets: [['objectUuid' => 'ref-1', 'property' => 'owner']])
		);
		$this->integrityService->method('canDelete')->willReturn($analysis);

		// applyDeletionActions reports the cascade targets the archival obligation
		// kept live. Nothing is retained here, so the mock answers with the empty
		// report rather than an unshaped array.
		$this->integrityService
			->expects($this->once())
			->method('applyDeletionActions')
			->with($analysis, new CascadeDeleteOrigin(userId: 'system', cascadeSource: 'uuid-do-3', triggerSchemaSlug: 'my-schema', organisationId: null))
			->willReturn(['retained' => []]);

		$this->withNoUser();
		$this->withAuditTrailsEnabled(false);

		$result = $this->handler->deleteObject(new DeletionRequest(register: 1, schema: 10, uuid: 'uuid-do-3'));

		$this->assertTrue($result);
	}

	/**
	 * A permanent delete of an object with referrers destroys the root, after the integrity actions.
	 */
	public function testAPermanentDeleteWithReferrersDestroysTheRoot(): void {
		$object = $this->createObjectEntity('uuid-do-4', '1', '10');
		$this->objectMapper->method('findAcrossAllSources')
			->willReturn($this->makeContext($object, $this->createRegister(1), $this->createSchema(10, [], 'my-schema')));
		$this->integrityService->method('hasIncomingOnDeleteReferences')->willReturn(true);
		$this->integrityService->method('canDelete')->willReturn(
			new DeletionAnalysis(deletable: true, effects: new DeletionEffects(nullifyTargets: [['objectUuid' => 'ref-1', 'property' => 'owner']]))
		);
		$this->integrityService->expects($this->once())->method('applyDeletionActions')->willReturn(['retained' => []]);
		$this->withNoUser();
		$this->withAuditTrailsEnabled(false);

		$this->objectMapper->expects($this->once())->method('deleteObjectEntity');
		$this->objectMapper->expects($this->never())->method('update');

		$this->assertTrue(
			$this->handler->deleteObject(
				new DeletionRequest(register: 1, schema: 10, uuid: 'uuid-do-4', options: new DeletionOptions(permanent: true))
			)
		);
	}

	/**
	 * deleteObject() with non-null originalObjectId skips all integrity checks (sub-delete).
	 */
	public function testDeleteObjectSkipsIntegrityOnSubDeletion(): void {
		$object = $this->createObjectEntity('uuid-do-child', '1', '10');
		$register = $this->createRegister(1);
		$schema = $this->createSchema(10);
		$context = $this->makeContext($object, $register, $schema);

		$this->objectMapper->method('findAcrossAllSources')->willReturn($context);
		$this->objectMapper->method('update')->willReturn($object);

		// Neither hasIncomingOnDeleteReferences nor canDelete must be called.
		$this->integrityService
			->expects($this->never())
			->method('hasIncomingOnDeleteReferences');

		$this->integrityService
			->expects($this->never())
			->method('canDelete');

		$this->withNoUser();
		$this->withAuditTrailsEnabled(false);

		$result = $this->handler->deleteObject(
			new DeletionRequest(register: 1, schema: 10, uuid: 'uuid-do-child', options: new DeletionOptions(originalObjectId: 'uuid-do-parent'))
		);

		$this->assertTrue($result);
	}

	// =========================================================================
	// deleteObject() — legacy cascade
	// =========================================================================

	// The checked `cascade: true` children are covered by DeleteObjectCascadeChildChecksTest.

	/**
	 * deleteObject() does not cascade when cascade property is absent.
	 */
	public function testDeleteObjectDoesNotCascadeWithoutCascadeFlag(): void {
		$parentUuid = 'no-cascade-parent';
		$properties = [
			'childRef' => ['type' => 'string'], // no cascade key
		];

		$parentObject = $this->createObjectEntity($parentUuid, '1', '10', ['childRef' => 'some-child-uuid']);

		$register = $this->createRegister(1);
		$schema = $this->createSchema(10, $properties);

		$context = $this->makeContext($parentObject, $register, $schema);

		$this->objectMapper->method('findAcrossAllSources')->willReturn($context);
		$this->objectMapper->method('update')->willReturn($parentObject);

		$this->integrityService->method('hasIncomingOnDeleteReferences')->willReturn(false);

		$this->withNoUser();
		$this->withAuditTrailsEnabled(false);

		// Only one update: the parent itself.
		$this->objectMapper
			->expects($this->once())
			->method('update');

		$this->handler->deleteObject(new DeletionRequest(register: 1, schema: 10, uuid: $parentUuid));
	}

	/**
	 * deleteObject() does not cascade when property value is null.
	 */
	public function testDeleteObjectSkipsCascadeWhenPropertyValueIsNull(): void {
		$parentUuid = 'null-cascade-parent';
		$properties = [
			'childRef' => ['cascade' => true, 'type' => 'string'],
		];

		$parentObject = $this->createObjectEntity($parentUuid, '1', '10', ['childRef' => null]);

		$register = $this->createRegister(1);
		$schema = $this->createSchema(10, $properties);

		$context = $this->makeContext($parentObject, $register, $schema);

		$this->objectMapper->method('findAcrossAllSources')->willReturn($context);
		$this->objectMapper->method('update')->willReturn($parentObject);

		$this->integrityService->method('hasIncomingOnDeleteReferences')->willReturn(false);

		$this->withNoUser();
		$this->withAuditTrailsEnabled(false);

		$this->objectMapper
			->expects($this->once())
			->method('update');

		$this->handler->deleteObject(new DeletionRequest(register: 1, schema: 10, uuid: $parentUuid));
	}

	// =========================================================================
	// deleteObject() — error handling
	// =========================================================================

	/**
	 * deleteObject() returns false when the underlying delete() throws.
	 */
	public function testDeleteObjectReturnsFalseWhenDeleteThrows(): void {
		$object = $this->createObjectEntity('uuid-err-1', '1', '10');
		$context = $this->makeContext($object);

		$this->objectMapper
			->method('findAcrossAllSources')
			->willReturn($context);

		$this->objectMapper
			->method('update')
			->willThrowException(new Exception('DB connection lost'));

		$this->integrityService->method('hasIncomingOnDeleteReferences')->willReturn(false);

		$this->withNoUser();
		$this->withAuditTrailsEnabled(false);

		$this->logger->expects($this->once())->method('warning');

		$result = $this->handler->deleteObject(new DeletionRequest(register: 1, schema: 10, uuid: 'uuid-err-1'));

		$this->assertFalse($result);
	}

	/**
	 * deleteObject() hands a guard's refusal to its caller instead of reporting a failed delete.
	 */
	public function testDeleteObjectPassesAGuardRefusalOn(): void {
		$object = $this->createObjectEntity('uuid-locked', '1', '10');
		$refusal = new HookStoppedException(message: 'This record is locked', errors: ['status' => 409]);

		$this->objectMapper->method('findAcrossAllSources')->willReturn($this->makeContext($object));
		$this->objectMapper->method('assertDeletable')->willThrowException($refusal);
		$this->integrityService->method('hasIncomingOnDeleteReferences')->willReturn(false);
		$this->withNoUser();
		$this->withAuditTrailsEnabled(false);

		$this->expectExceptionObject($refusal);

		$this->handler->deleteObject(new DeletionRequest(register: 1, schema: 10, uuid: 'uuid-locked'));
	}

	// =========================================================================
	// deleteObject() — multitenancy / RBAC flags
	// =========================================================================

	/**
	 * deleteObject() passes _rbac=false correctly to findAcrossAllSources.
	 */
	public function testDeleteObjectPassesRbacFalse(): void {
		$object = $this->createObjectEntity('uuid-rbac-1', '1', '10');
		$register = $this->createRegister(1);
		$schema = $this->createSchema(10);
		$context = $this->makeContext($object, $register, $schema);

		$this->objectMapper
			->expects($this->atLeastOnce())
			->method('findAcrossAllSources')
			->willReturnCallback(function (string $id, bool $incDel, bool $rbac, bool $mt) use ($context) {
				// Root deleteObject call must have the flags we passed.
				if ($id === 'uuid-rbac-1') {
					$this->assertFalse($rbac);
					$this->assertTrue($mt);
				}

				return $context;
			});

		$this->objectMapper->method('update')->willReturn($object);
		$this->integrityService->method('hasIncomingOnDeleteReferences')->willReturn(false);
		$this->withNoUser();
		$this->withAuditTrailsEnabled(false);

		$this->handler->deleteObject(
			new DeletionRequest(register: 1, schema: 10, uuid: 'uuid-rbac-1'), _rbac: false, _multitenancy: true
		);
	}

	/**
	 * deleteObject() passes _multitenancy=false correctly to findAcrossAllSources.
	 */
	public function testDeleteObjectPassesMultitenancyFalse(): void {
		$object = $this->createObjectEntity('uuid-mt-1', '1', '10');
		$register = $this->createRegister(1);
		$schema = $this->createSchema(10);
		$context = $this->makeContext($object, $register, $schema);

		$this->objectMapper
			->expects($this->atLeastOnce())
			->method('findAcrossAllSources')
			->willReturnCallback(function (string $id, bool $incDel, bool $rbac, bool $mt) use ($context) {
				if ($id === 'uuid-mt-1') {
					$this->assertTrue($rbac);
					$this->assertFalse($mt);
				}

				return $context;
			});

		$this->objectMapper->method('update')->willReturn($object);
		$this->integrityService->method('hasIncomingOnDeleteReferences')->willReturn(false);
		$this->withNoUser();
		$this->withAuditTrailsEnabled(false);

		$this->handler->deleteObject(
			new DeletionRequest(register: 1, schema: 10, uuid: 'uuid-mt-1'), _rbac: true, _multitenancy: false
		);
	}

	// =========================================================================
	// deleteObject() — schema has null schemaId
	// =========================================================================

	/**
	 * deleteObject() skips integrity check when object has no schema set.
	 */
	public function testDeleteObjectSkipsIntegrityCheckWhenSchemaIdIsNull(): void {
		$object = new ObjectEntity();
		$object->setUuid('uuid-noschema');
		// Do NOT set a schema.

		$register = $this->createRegister(1);
		$context = $this->makeContext($object, $register, null);

		$this->objectMapper->method('findAcrossAllSources')->willReturn($context);
		$this->objectMapper->method('update')->willReturn($object);

		// hasIncomingOnDeleteReferences must NOT be called when schemaId is null.
		$this->integrityService
			->expects($this->never())
			->method('hasIncomingOnDeleteReferences');

		$this->withNoUser();
		$this->withAuditTrailsEnabled(false);

		$result = $this->handler->deleteObject(new DeletionRequest(register: 1, schema: 10, uuid: 'uuid-noschema'));

		$this->assertTrue($result);
	}

	// =========================================================================
	// ReferentialIntegrityException — toResponseBody
	// =========================================================================

	/**
	 * ReferentialIntegrityException carries the DeletionAnalysis and formats a response body.
	 */
	public function testReferentialIntegrityExceptionCarriesAnalysis(): void {
		$blocker = ['objectUuid' => 'blocker-uuid', 'property' => 'parentId'];
		$analysis = new DeletionAnalysis(deletable: false, blockers: [$blocker]);

		$exception = new ReferentialIntegrityException($analysis);

		$this->assertSame($analysis, $exception->getAnalysis());
		$this->assertStringContainsString('1 dependent object(s)', $exception->getMessage());

		$body = $exception->toResponseBody();
		$this->assertSame('DELETION_BLOCKED', $body['error']);
		$this->assertCount(1, $body['blockers']);
	}

	// =========================================================================
	// DeletionAnalysis DTO
	// =========================================================================

	/**
	 * DeletionAnalysis::empty() returns a deletable analysis with no targets.
	 */
	public function testDeletionAnalysisEmptyIsFullyDeletable(): void {
		$analysis = DeletionAnalysis::empty();

		$this->assertTrue($analysis->deletable);
		$this->assertEmpty($analysis->cascadeTargets);
		$this->assertEmpty($analysis->nullifyTargets);
		$this->assertEmpty($analysis->defaultTargets);
		$this->assertEmpty($analysis->blockers);
	}

	/**
	 * DeletionAnalysis::toArray() returns all fields correctly serialised.
	 */
	public function testDeletionAnalysisToArray(): void {
		$analysis = new DeletionAnalysis(
			deletable: false,
			effects: new DeletionEffects(cascadeTargets: [['objectUuid' => 'c1']], nullifyTargets: [['objectUuid' => 'n1']], defaultTargets: [['objectUuid' => 'd1']]),
			blockers: [['objectUuid' => 'b1']],
			chainPaths: [['path' => 'A->B']]
		);

		$arr = $analysis->toArray();

		$this->assertFalse($arr['deletable']);
		$this->assertCount(1, $arr['cascadeTargets']);
		$this->assertCount(1, $arr['nullifyTargets']);
		$this->assertCount(1, $arr['defaultTargets']);
		$this->assertCount(1, $arr['blockers']);
		$this->assertCount(1, $arr['chainPaths']);
	}

	// =========================================================================
	// delete() — numeric register/schema ID conversion for cache invalidation
	// =========================================================================

	/**
	 * delete() converts non-numeric register/schema IDs to null for cache invalidation.
	 */
	public function testDeleteConvertsNonNumericIdsToNullForCache(): void {
		$object = $this->createObjectEntity('uuid-nonnumeric', 'reg-slug', 'schema-slug');
		$context = $this->makeContext($object);

		$this->objectMapper->method('findAcrossAllSources')->willReturn($context);
		$this->objectMapper->method('update')->willReturn($object);

		$this->withNoUser();
		$this->withAuditTrailsEnabled(false);

		// Should be called with null for both IDs (non-numeric slugs).
		$this->cacheHandler
			->expects($this->once())
			->method('invalidateForObjectChange')
			->with($object, 'soft_delete', null, null);

		$this->handler->delete($object);
	}

	/**
	 * delete() converts numeric string register/schema IDs to int for cache invalidation.
	 */
	public function testDeleteConvertsNumericStringIdsToIntForCache(): void {
		$object = $this->createObjectEntity('uuid-numeric', '42', '99');
		$context = $this->makeContext($object);

		$this->objectMapper->method('findAcrossAllSources')->willReturn($context);
		$this->objectMapper->method('update')->willReturn($object);

		$this->withNoUser();
		$this->withAuditTrailsEnabled(false);

		$this->cacheHandler
			->expects($this->once())
			->method('invalidateForObjectChange')
			->with($object, 'soft_delete', 42, 99);

		$this->handler->delete($object);
	}
}//end class
