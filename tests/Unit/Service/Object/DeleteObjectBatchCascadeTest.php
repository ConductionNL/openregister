<?php

declare(strict_types=1);

/**
 * DeleteObject batched cascade + pre-resolved context unit tests.
 *
 * Covers the delete-path performance changes:
 * - legacy `cascade: true` children are resolved with ONE batched cross-table
 *   lookup and soft-deleted via MagicMapper::softDeleteMultipleObjectEntities
 *   (one UPDATE per magic table) with ONE multi-row audit INSERT — instead of
 *   the full per-id delete pipeline;
 * - ids the batch lookup cannot resolve fall back to the per-id pipeline;
 * - delete() skips its cross-table re-find when the caller passes the
 *   already-resolved register/schema context;
 * - deleteObject() skips its lookup when a pre-resolved entity is passed;
 * - cascade-tagged audit rows are written with a single createAuditTrail()
 *   INSERT (no post-insert update()).
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\DeleteObject;
use OCA\OpenRegister\Service\Object\ReferentialIntegrityService;
use OCA\OpenRegister\Service\SettingsService;
use OCP\IDBConnection;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for the batched legacy-cascade delete path on DeleteObject.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class DeleteObjectBatchCascadeTest extends TestCase {

	/**
	 * Subject under test.
	 *
	 * @var DeleteObject
	 */
	private DeleteObject $handler;

	/**
	 * Object entity mapper mock.
	 *
	 * @var MagicMapper&MockObject
	 */
	private MagicMapper $objectMapper;

	/**
	 * Cache handler mock.
	 *
	 * @var CacheHandler&MockObject
	 */
	private CacheHandler $cacheHandler;

	/**
	 * User session mock.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession $userSession;

	/**
	 * Audit-trail mapper mock.
	 *
	 * @var AuditTrailMapper&MockObject
	 */
	private AuditTrailMapper $auditTrailMapper;

	/**
	 * Settings-service mock.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService $settingsService;

	/**
	 * Referential-integrity service mock.
	 *
	 * @var ReferentialIntegrityService&MockObject
	 */
	private ReferentialIntegrityService $integrityService;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objectMapper = $this->createMock(MagicMapper::class);
		$this->cacheHandler = $this->createMock(CacheHandler::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->integrityService = $this->createMock(ReferentialIntegrityService::class);

		$this->handler = new DeleteObject(
			$this->objectMapper,
			$this->cacheHandler,
			$this->userSession,
			$this->auditTrailMapper,
			$this->settingsService,
			$this->createMock(LoggerInterface::class),
			$this->integrityService,
			$this->createMock(IDBConnection::class)
		);

		$this->userSession->method('getUser')->willReturn(null);
	}//end setUp()

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
	}//end withAuditTrailsEnabled()

	/**
	 * Create an ObjectEntity with common fields pre-filled.
	 *
	 * @param string $uuid Entity UUID.
	 * @param array|null $object Raw object data payload.
	 *
	 * @return ObjectEntity
	 */
	private function makeEntity(string $uuid, ?array $object = null): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setRegister('1');
		$entity->setSchema('10');
		if ($object !== null) {
			$entity->setObject($object);
		}

		return $entity;
	}//end makeEntity()

	/**
	 * Create a Register entity with the given id.
	 *
	 * @param int $id Register id.
	 *
	 * @return Register
	 */
	private function makeRegister(int $id = 1): Register {
		$register = new Register();
		$register->setId($id);
		return $register;
	}//end makeRegister()

	/**
	 * Create a Schema entity with cascade properties.
	 *
	 * @param array $properties Schema property map.
	 * @param int $id Schema id.
	 *
	 * @return Schema
	 */
	private function makeSchema(array $properties = [], int $id = 10): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setProperties($properties);
		return $schema;
	}//end makeSchema()

	// =========================================================================
	// Batched legacy cascade
	// =========================================================================

	/**
	 * The legacy cascade resolves all children with one batched lookup, soft
	 * deletes them via the batched mapper call, and writes ONE multi-row audit
	 * insert — the per-id pipeline is never entered for resolved children.
	 */
	public function testCascadeUsesBatchedPipelineForResolvedChildren(): void {
		$register = $this->makeRegister();
		$schema = $this->makeSchema(['children' => ['cascade' => true, 'type' => 'array']]);

		$parent = $this->makeEntity('parent-uuid', ['children' => ['child-1', 'child-2']]);
		$childA = $this->makeEntity('child-1');
		$childB = $this->makeEntity('child-2');

		// The ONLY cross-table scan is the parent's root lookup.
		$this->objectMapper
			->expects($this->once())
			->method('findAcrossAllSources')
			->with('parent-uuid', true, true, true)
			->willReturn(['object' => $parent, 'register' => $register, 'schema' => $schema]);

		$this->objectMapper
			->expects($this->once())
			->method('findMultipleAcrossAllMagicTables')
			->with(['child-1', 'child-2'], true)
			->willReturn([$childA, $childB]);

		$batchedEntities = null;
		$this->objectMapper
			->expects($this->once())
			->method('softDeleteMultipleObjectEntities')
			->willReturnCallback(
				function (array $entities, array $oldEntities = []) use (&$batchedEntities): array {
					$batchedEntities = $entities;
					return $entities;
				}
			);

		// Parent soft delete still goes through update() — exactly once.
		$this->objectMapper
			->expects($this->once())
			->method('update')
			->willReturn($parent);

		$this->integrityService->method('hasIncomingOnDeleteReferences')->willReturn(false);
		$this->withAuditTrailsEnabled(true);

		// Children: one built audit row each, persisted with ONE bulk insert.
		$this->auditTrailMapper
			->expects($this->exactly(2))
			->method('buildAuditTrail')
			->willReturn(new AuditTrail());
		$this->auditTrailMapper
			->expects($this->once())
			->method('insertAuditTrails')
			->with($this->countOf(2))
			->willReturnArgument(0);

		// Parent: one single-row insert; the per-row INSERT+UPDATE pair is gone.
		$this->auditTrailMapper
			->expects($this->once())
			->method('createAuditTrail')
			->willReturn(new AuditTrail());

		$result = $this->handler->deleteObject(register: 1, schema: 10, uuid: 'parent-uuid');

		$this->assertTrue($result);
		$this->assertCount(2, $batchedEntities);
		// Deletion metadata was applied per child before the batched write.
		$this->assertSame('child-1', $batchedEntities[0]->getDeleted()['objectId']);
		$this->assertSame('child-2', $batchedEntities[1]->getDeleted()['objectId']);
		$this->assertSame('system', $batchedEntities[0]->getDeleted()['deletedBy']);
	}//end testCascadeUsesBatchedPipelineForResolvedChildren()

	/**
	 * Ids the uuid-based batch lookup cannot resolve (slugs, numeric ids,
	 * vanished rows) keep the legacy per-id delete pipeline.
	 */
	public function testCascadeFallsBackToPerIdPipelineForUnresolvedIds(): void {
		$register = $this->makeRegister();
		$schema = $this->makeSchema(['children' => ['cascade' => true, 'type' => 'array']]);

		$parent = $this->makeEntity('parent-uuid', ['children' => ['child-1', 'child-2']]);
		$childA = $this->makeEntity('child-1');
		$childB = $this->makeEntity('child-2');

		// Root lookup for the parent + legacy per-id lookup for child-2 only.
		$this->objectMapper
			->method('findAcrossAllSources')
			->willReturnCallback(
				function (string $identifier) use ($parent, $childB, $register, $schema): array {
					$this->assertContains($identifier, ['parent-uuid', 'child-2']);
					$object = $parent;
					if ($identifier === 'child-2') {
						$object = $childB;
					}

					return ['object' => $object, 'register' => $register, 'schema' => $schema];
				}
			);

		// Batch lookup only resolves child-1.
		$this->objectMapper
			->method('findMultipleAcrossAllMagicTables')
			->willReturn([$childA]);

		$this->objectMapper
			->expects($this->once())
			->method('softDeleteMultipleObjectEntities')
			->with($this->countOf(1))
			->willReturnArgument(0);

		// update() runs for the parent AND the legacy-pipeline child-2.
		$this->objectMapper
			->expects($this->exactly(2))
			->method('update')
			->willReturn($parent);

		$this->integrityService->method('hasIncomingOnDeleteReferences')->willReturn(false);
		$this->withAuditTrailsEnabled(false);

		$result = $this->handler->deleteObject(register: 1, schema: 10, uuid: 'parent-uuid');

		$this->assertTrue($result);
	}//end testCascadeFallsBackToPerIdPipelineForUnresolvedIds()

	/**
	 * A total batched-write failure hands every child back to the per-id
	 * pipeline (no child is silently dropped).
	 */
	public function testCascadeBatchWriteFailureFallsBackToPerIdPipeline(): void {
		$register = $this->makeRegister();
		$schema = $this->makeSchema(['childRef' => ['cascade' => true, 'type' => 'string']]);

		$parent = $this->makeEntity('parent-uuid', ['childRef' => 'child-1']);
		$childA = $this->makeEntity('child-1');

		$this->objectMapper
			->method('findAcrossAllSources')
			->willReturnCallback(
				function (string $identifier) use ($parent, $childA, $register, $schema): array {
					$object = $parent;
					if ($identifier === 'child-1') {
						$object = $childA;
					}

					return ['object' => $object, 'register' => $register, 'schema' => $schema];
				}
			);

		$this->objectMapper
			->method('findMultipleAcrossAllMagicTables')
			->willReturn([$childA]);

		$this->objectMapper
			->method('softDeleteMultipleObjectEntities')
			->willThrowException(new \Exception('batched UPDATE failed'));

		// Parent + fallback child both soft-delete via update().
		$this->objectMapper
			->expects($this->exactly(2))
			->method('update')
			->willReturn($parent);

		$this->integrityService->method('hasIncomingOnDeleteReferences')->willReturn(false);
		$this->withAuditTrailsEnabled(false);

		$result = $this->handler->deleteObject(register: 1, schema: 10, uuid: 'parent-uuid');

		$this->assertTrue($result);
	}//end testCascadeBatchWriteFailureFallsBackToPerIdPipeline()

	// =========================================================================
	// Pre-resolved context short-circuits
	// =========================================================================

	/**
	 * delete() with an ObjectEntity plus concrete register/schema context skips
	 * the cross-table re-find entirely.
	 */
	public function testDeleteSkipsRefindWhenContextProvided(): void {
		$entity = $this->makeEntity('uuid-ctx');

		$this->objectMapper
			->expects($this->never())
			->method('findAcrossAllSources');

		$this->objectMapper
			->expects($this->once())
			->method('update')
			->willReturn($entity);

		$this->withAuditTrailsEnabled(false);

		$result = $this->handler->delete(
			object: $entity,
			register: $this->makeRegister(),
			schema: $this->makeSchema()
		);

		$this->assertTrue($result);
	}//end testDeleteSkipsRefindWhenContextProvided()

	/**
	 * The mapper update receives the pre-delete snapshot as its old entity, so
	 * it does not re-find the row for the update event.
	 */
	public function testDeletePassesPreDeleteSnapshotAsOldEntity(): void {
		$entity = $this->makeEntity('uuid-snapshot');

		$capturedOld = null;
		$this->objectMapper
			->method('update')
			->willReturnCallback(
				function ($updated, $register = null, $schema = null, $oldEntity = null) use (&$capturedOld) {
					$capturedOld = $oldEntity;
					return $updated;
				}
			);

		$this->withAuditTrailsEnabled(false);

		$this->handler->delete(
			object: $entity,
			register: $this->makeRegister(),
			schema: $this->makeSchema()
		);

		$this->assertInstanceOf(ObjectEntity::class, $capturedOld);
		$this->assertSame('uuid-snapshot', $capturedOld->getUuid());
		// The snapshot is the PRE-delete state: no deleted marker.
		$this->assertEmpty($capturedOld->getDeleted());
		$this->assertNotEmpty($entity->getDeleted());
	}//end testDeletePassesPreDeleteSnapshotAsOldEntity()

	/**
	 * A cascade-tagged delete writes its audit row with ONE createAuditTrail()
	 * call carrying the context — never a post-insert update().
	 */
	public function testDeleteWithCascadeContextWritesSingleAuditInsert(): void {
		$entity = $this->makeEntity('uuid-cascade-audit');

		$this->objectMapper->method('update')->willReturn($entity);
		$this->withAuditTrailsEnabled(true);

		$cascadeContext = [
			'triggerObject' => 'root-uuid',
			'triggerSchema' => 'my-schema',
			'action_type' => 'referential_integrity.cascade_delete',
			'property' => 'children',
		];

		$this->auditTrailMapper
			->expects($this->once())
			->method('createAuditTrail')
			->with($entity, null, 'referential_integrity.cascade_delete', $cascadeContext)
			->willReturn(new AuditTrail());

		$this->auditTrailMapper
			->expects($this->never())
			->method('update');

		$result = $this->handler->delete(
			object: $entity,
			cascadeContext: $cascadeContext,
			register: $this->makeRegister(),
			schema: $this->makeSchema()
		);

		$this->assertTrue($result);
	}//end testDeleteWithCascadeContextWritesSingleAuditInsert()

	/**
	 * deleteObject() with a pre-resolved entity and concrete scope entities
	 * performs no lookup at all.
	 */
	public function testDeleteObjectSkipsLookupWithPreResolvedEntity(): void {
		$entity = $this->makeEntity('uuid-pre');

		$this->objectMapper->expects($this->never())->method('find');
		$this->objectMapper->expects($this->never())->method('findAcrossAllSources');

		$this->objectMapper
			->expects($this->once())
			->method('update')
			->willReturn($entity);

		$this->integrityService->method('hasIncomingOnDeleteReferences')->willReturn(false);
		$this->withAuditTrailsEnabled(false);

		$result = $this->handler->deleteObject(
			register: $this->makeRegister(),
			schema: $this->makeSchema(),
			uuid: 'uuid-pre',
			preResolved: $entity
		);

		$this->assertTrue($result);
	}//end testDeleteObjectSkipsLookupWithPreResolvedEntity()

	/**
	 * A pre-resolved entity whose uuid does not match the requested uuid is
	 * ignored (defensive) — the normal lookup runs instead.
	 */
	public function testDeleteObjectIgnoresMismatchedPreResolvedEntity(): void {
		$requested = $this->makeEntity('uuid-wanted');
		$mismatch = $this->makeEntity('uuid-other');
		$register = $this->makeRegister();
		$schema = $this->makeSchema();

		$this->objectMapper
			->expects($this->once())
			->method('findAcrossAllSources')
			->with('uuid-wanted', true, true, true)
			->willReturn(['object' => $requested, 'register' => $register, 'schema' => $schema]);

		$this->objectMapper->method('update')->willReturn($requested);

		$this->integrityService->method('hasIncomingOnDeleteReferences')->willReturn(false);
		$this->withAuditTrailsEnabled(false);

		$result = $this->handler->deleteObject(
			register: $register,
			schema: $schema,
			uuid: 'uuid-wanted',
			preResolved: $mismatch
		);

		$this->assertTrue($result);
	}//end testDeleteObjectIgnoresMismatchedPreResolvedEntity()
}//end class
