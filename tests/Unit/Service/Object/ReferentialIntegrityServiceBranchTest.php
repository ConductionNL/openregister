<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Service\Object\RefusedDeletion;
use OCA\OpenRegister\Service\Object\CascadeDeleteOrigin;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Dto\DeletionAnalysis;
use OCA\OpenRegister\Dto\DeletionEffects;
use OCA\OpenRegister\Service\Object\IntegrityCascadeControls;
use OCA\OpenRegister\Service\Object\IntegrityRelationIndex;
use OCA\OpenRegister\Service\Object\IntegrityStorage;
use OCA\OpenRegister\Service\Object\ReferentialIntegrityService;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Archival\ArchivalRetentionGuard;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\MagicMapperInterface;

/**
 * Branch coverage tests for ReferentialIntegrityService — targets uncovered
 * branches in canDelete, logRestrictBlock,
 * hasIncomingOnDeleteReferences, applyDeletionActions.
 */
class ReferentialIntegrityServiceBranchTest extends TestCase {
	private ReferentialIntegrityService $service;
	private SchemaMapper&MockObject $schemaMapper;
	private RegisterMapper&MockObject $registerMapper;
	private MagicMapper&MockObject $objectMapper;
	private AuditTrailMapper&MockObject $auditTrailMapper;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();

		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->objectMapper = $this->createMock(MagicMapper::class);
		$this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->service = new ReferentialIntegrityService(
			index: new IntegrityRelationIndex(catalog: new SchemaCatalog(registers: $this->registerMapper, schemas: $this->schemaMapper, tables: $this->createMock(MagicMapperInterface::class)), cacheFactory: $this->createNullCacheFactory()),
			storage: new IntegrityStorage(objectEntityMapper: $this->objectMapper, db: $this->createMock(IDBConnection::class)),
			controls: new IntegrityCascadeControls(auditTrailMapper: $this->auditTrailMapper, archivalGuard: $this->makeArchivalGuard()),
			logger: $this->logger
		);
	}

	/**
	 * Build a cache factory whose cache never reports a hit.
	 *
	 * @return \OCP\ICacheFactory
	 */
	private function createNullCacheFactory(): \OCP\ICacheFactory {
		$factory = $this->createMock(\OCP\ICacheFactory::class);
		$factory->method('createDistributed')
			->willReturn($this->createMock(\OCP\ICache::class));

		return $factory;
	}

	private function createObjectMock(string $uuid, ?string $schemaId = null): ObjectEntity&MockObject {
		$mock = $this->getMockBuilder(ObjectEntity::class)
			->onlyMethods(['getUuid', 'getSchema', 'getRegister'])
			->addMethods(['getDeleted'])
			->getMock();
		$mock->method('getUuid')->willReturn($uuid);
		$mock->method('getSchema')->willReturn($schemaId);
		$mock->method('getDeleted')->willReturn(null);
		return $mock;
	}

	// =========================================================================
	// canDelete — null schema
	// =========================================================================

	public function testCanDeleteNullSchemaReturnsDeletable(): void {
		$this->schemaMapper->method('findAll')->willReturn([]);
		$this->registerMapper->method('findAll')->willReturn([]);

		$object = $this->createObjectMock('uuid-1', null);

		$result = $this->service->canDelete($object);
		$this->assertTrue($result->deletable);
		$this->assertSame([], $result->blockers);
	}

	public function testCanDeleteNoIncomingReferences(): void {
		$this->schemaMapper->method('findAll')->willReturn([]);
		$this->registerMapper->method('findAll')->willReturn([]);

		$object = $this->createObjectMock('uuid-2', '999');

		$result = $this->service->canDelete($object);
		$this->assertTrue($result->deletable);
	}

	// =========================================================================
	// hasIncomingOnDeleteReferences
	// =========================================================================

	public function testHasIncomingOnDeleteReferencesNoReferences(): void {
		$this->schemaMapper->method('findAll')->willReturn([]);
		$this->registerMapper->method('findAll')->willReturn([]);

		$result = $this->service->hasIncomingOnDeleteReferences('999');
		$this->assertFalse($result);
	}

	// =========================================================================
	// logRestrictBlock
	// =========================================================================

	public function testLogRestrictBlockLogsAuditTrail(): void {
		$analysis = new DeletionAnalysis(
			deletable: false,
			blockers: [
				[
					'objectUuid' => 'blocker-uuid-1',
					'schema' => '10',
					'property' => 'parentRef',
					'action' => 'RESTRICT',
				],
				[
					'objectUuid' => 'blocker-uuid-2',
					'schema' => '10',
					'property' => 'parentRef',
					'action' => 'RESTRICT',
				],
			]
		);

		$this->auditTrailMapper->expects($this->once())
			->method('insert');

		$this->service->logRestrictBlock(new RefusedDeletion(objectUuid: 'target-uuid', schemaId: '5', userId: 'admin'), $analysis);
	}

	public function testLogRestrictBlockEmptyBlockers(): void {
		$analysis = new DeletionAnalysis(
			deletable: false,
			blockers: []
		);

		$this->auditTrailMapper->expects($this->once())
			->method('insert');

		$this->service->logRestrictBlock(new RefusedDeletion(objectUuid: 'target-uuid', schemaId: null, userId: 'admin'), $analysis);
	}

	// =========================================================================
	// applyDeletionActions — empty analysis
	// =========================================================================

	public function testApplyDeletionActionsEmptyAnalysis(): void {
		$analysis = DeletionAnalysis::empty();

		$result = $this->service->applyDeletionActions(
			$analysis,
			new CascadeDeleteOrigin(userId: 'admin', cascadeSource: 'root-uuid', triggerSchemaSlug: 'my-schema', organisationId: 'org-1')
		);

		$this->assertSame(['retained' => []], $result);
	}

	// =========================================================================
	// DeletionAnalysis
	// =========================================================================

	public function testDeletionAnalysisToArray(): void {
		$analysis = new DeletionAnalysis(
			deletable: true,
			effects: new DeletionEffects(cascadeTargets: [['objectUuid' => 'a']], nullifyTargets: [['objectUuid' => 'b']], defaultTargets: []),
			blockers: [],
			chainPaths: ['a -> b']
		);

		$arr = $analysis->toArray();
		$this->assertTrue($arr['deletable']);
		$this->assertCount(1, $arr['cascadeTargets']);
		$this->assertCount(1, $arr['nullifyTargets']);
	}

	public function testDeletionAnalysisEmpty(): void {
		$analysis = DeletionAnalysis::empty();
		$this->assertTrue($analysis->deletable);
		$this->assertSame([], $analysis->cascadeTargets);
	}

	// =========================================================================
	// ensureRelationIndex — schema load failure
	// =========================================================================

	public function testCanDeleteHandlesSchemaLoadFailure(): void {
		$this->schemaMapper->method('findAll')
			->willThrowException(new \Exception('DB error'));

		$object = $this->createObjectMock('uuid-3', '5');

		$result = $this->service->canDelete($object);
		$this->assertTrue($result->deletable);
	}
	/**
	 * A real ArchivalRetentionGuard over schemas that declare no archival annotation.
	 *
	 * REAL, not a mock: the cascade's retention decision must come from the
	 * production predicate Schema::hasArchivalAnnotation(). These fixtures are
	 * ordinary schemas, so the guard lets every cascade through and these tests
	 * keep asserting the behaviour they were written for.
	 *
	 * @param array<int, string> $archivalSchemas Schema identifiers that ARE archival.
	 *
	 * @return ArchivalRetentionGuard
	 */
	private function makeArchivalGuard(array $archivalSchemas = []): ArchivalRetentionGuard {
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturnCallback(
			function ($id) use ($archivalSchemas): Schema {
				$schema = new Schema();
				$schema->setSlug((string)$id);
				$configuration = [];
				if (in_array((string)$id, $archivalSchemas, true) === true) {
					$configuration['x-openregister-archival'] = ['retention' => ['default' => 'P10Y']];
				}

				$schema->setConfiguration($configuration);

				return $schema;
			}
		);

		return new ArchivalRetentionGuard(new \OCA\OpenRegister\Service\Archival\ArchivalSchemaLookup($schemaMapper, $this->createMock(LoggerInterface::class)));
	}//end makeArchivalGuard()
}
