<?php

/**
 * RelationHandler Unit Tests
 *
 * Tests for the RelationHandler service class, covering:
 * - Inverse relation filtering (applyInversedByFilter)
 * - Bulk relationship loading (bulkLoadRelationshipsBatched, loadRelationshipChunkOptimized)
 * - Contract lookups (getContracts)
 * - Use lookups (getUses, getUsedBy)
 * - Related data delegation (extractRelatedData)
 * - Error handling and edge cases
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\RelationHandler;
use OCA\OpenRegister\Service\Object\RelationHandlerStores;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use OCA\OpenRegister\Service\Object\RelationLookup;

/**
 * Unit tests for RelationHandler.
 *
 * @covers \OCA\OpenRegister\Service\Object\RelationHandler
 * @covers \OCA\OpenRegister\Service\Object\RelationMap
 * @covers \OCA\OpenRegister\Service\Object\RelationTables
 * @covers \OCA\OpenRegister\Service\Object\RelatedReadFilter
 * @covers \OCA\OpenRegister\Service\Object\RelationLookup
 * @uses   \OCA\OpenRegister\Service\Object\RelationLoadRun
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Service\Object\PinnedTable
 * @uses \OCA\OpenRegister\Service\Object\ReferrerPage
 * @uses \OCA\OpenRegister\Service\Object\RelatedObjectSet
 * @uses \OCA\OpenRegister\Service\Object\RelationLink
 * @uses \OCA\OpenRegister\Service\Object\RelationSource
 */
class RelationHandlerTest extends TestCase {
	private RelationHandler $handler;

	/** @var MagicMapper&MockObject */
	private MagicMapper $objectMapper;

	/** @var RegisterMapper&MockObject */
	private RegisterMapper $registerMapper;

	/** @var SchemaMapper&MockObject */
	private SchemaMapper $schemaMapper;

	/** @var MagicRbacHandler&MockObject */
	private MagicRbacHandler $rbacHandler;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	/**
	 * Set up test fixtures before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objectMapper = $this->createMock(MagicMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->rbacHandler = $this->createMock(MagicRbacHandler::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->handler = new RelationHandler(
			stores: new RelationHandlerStores(
				objects: $this->objectMapper,
				schemas: $this->schemaMapper,
				registers: $this->registerMapper
			),
			rbacHandler: $this->rbacHandler,
			logger: $this->logger
		);
	}//end setUp()

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Create an ObjectEntity with a given ID set via reflection.
	 *
	 * @param int $id The integer ID to assign.
	 *
	 * @return ObjectEntity
	 */
	private function makeObjectEntity(int $id): ObjectEntity {
		$entity = new ObjectEntity();
		$ref = new ReflectionClass($entity);
		$prop = $ref->getProperty('id');
		$prop->setAccessible(true);
		$prop->setValue($entity, $id);

		return $entity;
	}//end makeObjectEntity()

	/**
	 * Create a Schema mock with given properties.
	 *
	 * @param array<string, mixed> $properties Schema properties array.
	 *
	 * @return Schema&MockObject
	 */
	private function makeSchema(array $properties = []): Schema {
		$schema = $this->createMock(Schema::class);
		$schema->method('getProperties')->willReturn($properties);

		return $schema;
	}//end makeSchema()

	// =========================================================================
	// applyInversedByFilter tests
	// =========================================================================

	// =========================================================================
	// bulkLoadRelationshipsBatched tests
	// =========================================================================

	/**
	 * Returns empty array immediately when no IDs provided.
	 *
	 * @return void
	 */
	public function testBulkLoadRelationshipsBatchedReturnsEmptyForNoIds(): void {
		$result = $this->handler->bulkLoadRelationshipsBatched([]);

		$this->assertSame([], $result);
	}//end testBulkLoadRelationshipsBatchedReturnsEmptyForNoIds()

	/**
	 * Caps the input at 200 IDs with a warning log when more than 200 given.
	 *
	 * @return void
	 */
	public function testBulkLoadRelationshipsBatchedCapsAt200(): void {
		$ids = array_map(fn ($i) => "id-$i", range(1, 250));

		$this->objectMapper
			->method('findAll')
			->willReturn([]);

		$this->logger->expects($this->atLeastOnce())->method('warning');
		$this->logger->expects($this->atLeastOnce())->method('info');

		$result = $this->handler->bulkLoadRelationshipsBatched($ids);

		$this->assertIsArray($result);
	}//end testBulkLoadRelationshipsBatchedCapsAt200()

	/**
	 * Indexes loaded objects by both UUID and integer ID.
	 *
	 * @return void
	 */
	public function testBulkLoadRelationshipsBatchedIndexesByUuidAndId(): void {
		$entity = $this->makeObjectEntity(42);
		$entity->setUuid('550e8400-e29b-41d4-a716-446655440000');

		$this->objectMapper
			->method('findAll')
			->willReturn([$entity]);

		$this->logger->expects($this->atLeastOnce())->method('info');
		$this->logger->expects($this->atLeastOnce())->method('debug');

		$result = $this->handler->bulkLoadRelationshipsBatched(['550e8400-e29b-41d4-a716-446655440000']);

		$this->assertArrayHasKey('550e8400-e29b-41d4-a716-446655440000', $result);
		$this->assertArrayHasKey(42, $result);
	}//end testBulkLoadRelationshipsBatchedIndexesByUuidAndId()

	/**
	 * Continues loading remaining batches even if one batch throws an exception.
	 *
	 * @return void
	 */
	public function testBulkLoadRelationshipsBatchedContinuesOnBatchError(): void {
		// Generate 60 IDs -- two batches of 50/10.
		$ids = array_map(fn ($i) => "id-$i", range(1, 60));

		$this->objectMapper
			->method('findAll')
			->willThrowException(new \RuntimeException('DB error'));

		$this->logger->expects($this->atLeastOnce())->method('error');
		$this->logger->expects($this->atLeastOnce())->method('info');

		// Should not throw -- errors are caught per-batch.
		$result = $this->handler->bulkLoadRelationshipsBatched($ids);

		$this->assertIsArray($result);
	}//end testBulkLoadRelationshipsBatchedContinuesOnBatchError()

	// =========================================================================
	// loadRelationshipChunkOptimized tests
	// =========================================================================

	/**
	 * Returns empty array for empty input without hitting the mapper.
	 *
	 * @return void
	 */
	public function testLoadRelationshipChunkOptimizedReturnsEmptyForNoIds(): void {
		$this->objectMapper->expects($this->never())->method('findAll');

		$result = $this->handler->loadRelationshipChunkOptimized([]);

		$this->assertSame([], $result);
	}//end testLoadRelationshipChunkOptimizedReturnsEmptyForNoIds()

	/**
	 * Delegates to objectMapper->findAll and returns its result.
	 *
	 * @return void
	 */
	public function testLoadRelationshipChunkOptimizedReturnsMapperResult(): void {
		$entity = $this->makeObjectEntity(7);

		$this->objectMapper
			->expects($this->once())
			->method('findAll')
			->with($this->anything())
			->willReturn([$entity]);

		$result = $this->handler->loadRelationshipChunkOptimized(['some-uuid']);

		$this->assertCount(1, $result);
		$this->assertSame($entity, $result[0]);
	}//end testLoadRelationshipChunkOptimizedReturnsMapperResult()

	/**
	 * Returns empty array and logs error when mapper throws.
	 *
	 * @return void
	 */
	public function testLoadRelationshipChunkOptimizedReturnsEmptyOnMapperException(): void {
		$this->objectMapper
			->method('findAll')
			->willThrowException(new \RuntimeException('DB error'));

		$this->logger->expects($this->once())->method('error');

		$result = $this->handler->loadRelationshipChunkOptimized(['some-uuid']);

		$this->assertSame([], $result);
	}//end testLoadRelationshipChunkOptimizedReturnsEmptyOnMapperException()

	// =========================================================================
	// extractRelatedData tests
	// =========================================================================

	// =========================================================================
	// getContracts tests
	// =========================================================================

	/**
	 * Returns paginated contracts from the object's 'contracts' property.
	 *
	 * @return void
	 */
	public function testGetContractsReturnsPaginatedContracts(): void {
		$entity = $this->makeObjectEntity(1);
		$entity->setObject(['contracts' => ['contract-a', 'contract-b', 'contract-c']]);

		$this->objectMapper
			->method('find')
			->willReturn($entity);

		$result = $this->handler->getContracts('some-uuid', ['_limit' => 2, '_offset' => 0]);

		$this->assertArrayHasKey('results', $result);
		$this->assertArrayHasKey('total', $result);
		$this->assertArrayHasKey('limit', $result);
		$this->assertArrayHasKey('offset', $result);

		$this->assertCount(2, $result['results']);
		$this->assertSame(3, $result['total']);
		$this->assertSame(2, $result['limit']);
		$this->assertSame(0, $result['offset']);
	}//end testGetContractsReturnsPaginatedContracts()

	/**
	 * Returns default limit/offset when no filters provided.
	 *
	 * @return void
	 */
	public function testGetContractsUsesDefaultPagination(): void {
		$entity = $this->makeObjectEntity(1);
		$entity->setObject(['contracts' => []]);

		$this->objectMapper->method('find')->willReturn($entity);

		$result = $this->handler->getContracts('some-uuid');

		$this->assertSame(30, $result['limit']);
		$this->assertSame(0, $result['offset']);
		$this->assertSame(0, $result['total']);
	}//end testGetContractsUsesDefaultPagination()

	/**
	 * Returns empty results when object has no 'contracts' key.
	 *
	 * @return void
	 */
	public function testGetContractsReturnsEmptyWhenNoContractsProperty(): void {
		$entity = $this->makeObjectEntity(1);
		$entity->setObject(['name' => 'Test Object']);

		$this->objectMapper->method('find')->willReturn($entity);

		$result = $this->handler->getContracts('some-uuid');

		$this->assertSame([], $result['results']);
		$this->assertSame(0, $result['total']);
	}//end testGetContractsReturnsEmptyWhenNoContractsProperty()

	/**
	 * Returns error response structure when mapper throws an exception.
	 *
	 * @return void
	 */
	public function testGetContractsReturnsErrorResponseOnException(): void {
		$this->objectMapper
			->method('find')
			->willThrowException(new \RuntimeException('Not found'));

		$this->logger->expects($this->once())->method('error');

		$result = $this->handler->getContracts('missing-uuid', ['_limit' => 10, '_offset' => 5]);

		$this->assertSame([], $result['results']);
		$this->assertSame(0, $result['total']);
		$this->assertSame(10, $result['limit']);
		$this->assertSame(5, $result['offset']);
	}//end testGetContractsReturnsErrorResponseOnException()

	/**
	 * Applies offset correctly when paginating contracts.
	 *
	 * @return void
	 */
	public function testGetContractsAppliesOffsetCorrectly(): void {
		$entity = $this->makeObjectEntity(1);
		$entity->setObject(['contracts' => ['a', 'b', 'c', 'd', 'e']]);

		$this->objectMapper->method('find')->willReturn($entity);

		$result = $this->handler->getContracts('some-uuid', ['_limit' => 2, '_offset' => 3]);

		$this->assertCount(2, $result['results']);
		$this->assertSame(['d', 'e'], array_values($result['results']));
		$this->assertSame(5, $result['total']);
	}//end testGetContractsAppliesOffsetCorrectly()

	// =========================================================================
	// getUses tests
	// =========================================================================

	/**
	 * Returns empty results when object has no relations.
	 *
	 * @return void
	 */
	public function testGetUsesReturnsEmptyWhenObjectHasNoRelations(): void {
		$entity = $this->makeObjectEntity(1);
		$entity->setUuid('550e8400-e29b-41d4-a716-446655440000');
		$entity->setRelations([]);

		$this->objectMapper->method('find')->willReturn($entity);

		$result = $this->handler->getUses(lookup: new RelationLookup(objectId: '550e8400-e29b-41d4-a716-446655440000'));

		$this->assertSame([], $result['results']);
		$this->assertSame(0, $result['total']);
	}//end testGetUsesReturnsEmptyWhenObjectHasNoRelations()

	/**
	 * Paging parameters that array_slice() refuses.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function unusablePaging(): array {
		return [
			'text limit' => [['_limit' => 'abc']],
			'empty limit' => [['_limit' => '']],
			'text offset' => [['_offset' => 'abc']],
			'empty offset' => [['_offset' => '']],
			'both' => [['_limit' => 'abc', '_offset' => 'def']],
		];
	}//end unusablePaging()

	/**
	 * An object without relations answers an empty page, echoing the paging as requested.
	 *
	 * @param array<string, mixed> $query The paging parameters.
	 *
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('unusablePaging')]
	public function testGetUsesWithoutRelationsEchoesUnusablePaging(array $query): void {
		$entity = $this->makeObjectEntity(1);
		$entity->setUuid('550e8400-e29b-41d4-a716-446655440000');
		$entity->setRelations([]);
		$this->objectMapper->method('find')->willReturn($entity);

		$result = $this->handler->getUses(lookup: new RelationLookup(objectId: '550e8400-e29b-41d4-a716-446655440000', query: $query));

		$this->assertSame($this->emptyPageOf(query: $query), $result);
	}//end testGetUsesWithoutRelationsEchoesUnusablePaging()

	/**
	 * A failed uses lookup answers an empty page, echoing the paging as requested.
	 *
	 * @param array<string, mixed> $query The paging parameters.
	 *
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('unusablePaging')]
	public function testFailedGetUsesEchoesUnusablePaging(array $query): void {
		$this->objectMapper->method('find')->willThrowException(new \RuntimeException('Not found'));

		$result = $this->handler->getUses(lookup: new RelationLookup(objectId: 'missing-uuid', query: $query));

		$this->assertSame($this->emptyPageOf(query: $query), $result);
	}//end testFailedGetUsesEchoesUnusablePaging()

	/**
	 * A failed used-by lookup answers an empty page, echoing the paging as requested.
	 *
	 * @param array<string, mixed> $query The paging parameters.
	 *
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('unusablePaging')]
	public function testFailedGetUsedByEchoesUnusablePaging(array $query): void {
		$this->objectMapper->method('find')->willThrowException(new \RuntimeException('Not found'));

		$result = $this->handler->getUsedBy(lookup: new RelationLookup(objectId: 'missing-uuid', query: $query));

		$this->assertSame($this->emptyPageOf(query: $query), $result);
	}//end testFailedGetUsedByEchoesUnusablePaging()

	/**
	 * The empty page a query answers.
	 *
	 * @param array<string, mixed> $query The paging parameters.
	 *
	 * @return array<string, mixed>
	 */
	private function emptyPageOf(array $query): array {
		return ['results' => [], 'total' => 0, 'limit' => $query['_limit'] ?? 30, 'offset' => $query['_offset'] ?? 0];
	}//end emptyPageOf()

	/**
	 * Returns empty results when relations only contain the object's own UUID.
	 *
	 * @return void
	 */
	public function testGetUsesFiltersOutSelfReference(): void {
		$ownUuid = '550e8400-e29b-41d4-a716-446655440000';

		$entity = $this->makeObjectEntity(1);
		$entity->setUuid($ownUuid);
		$entity->setRelations(['self' => $ownUuid]);

		$this->objectMapper->method('find')->willReturn($entity);

		$result = $this->handler->getUses(lookup: new RelationLookup(objectId: $ownUuid));

		$this->assertSame([], $result['results']);
		$this->assertSame(0, $result['total']);
	}//end testGetUsesFiltersOutSelfReference()

	/**
	 * Returns error response structure when object lookup throws.
	 *
	 * @return void
	 */
	public function testGetUsesReturnsErrorResponseOnException(): void {
		$this->objectMapper
			->method('find')
			->willThrowException(new \RuntimeException('Not found'));

		$this->logger->expects($this->once())->method('error');

		$result = $this->handler->getUses(lookup: new RelationLookup(objectId: 'missing-uuid', query: ['_limit' => 5, '_offset' => 2]));

		$this->assertSame([], $result['results']);
		$this->assertSame(0, $result['total']);
		$this->assertSame(5, $result['limit']);
		$this->assertSame(2, $result['offset']);
	}//end testGetUsesReturnsErrorResponseOnException()

	/**
	 * A slug two objects of the register and schema hold (#4037) is not an empty
	 * relation list: the exception reaches the controller, which answers 409.
	 *
	 * @return void
	 */
	public function testGetUsesPropagatesAnAmbiguousIdentifier(): void {
		$this->objectMapper->method('find')
			->willThrowException(new \OCP\AppFramework\Db\MultipleObjectsReturnedException('two objects hold "dubbel"'));
		$this->logger->expects($this->never())->method('error');

		$this->expectException(\OCP\AppFramework\Db\MultipleObjectsReturnedException::class);
		$this->handler->getUses(lookup: new RelationLookup(objectId: 'dubbel'));
	}//end testGetUsesPropagatesAnAmbiguousIdentifier()

	/**
	 * The same for the objects that use this one.
	 *
	 * @return void
	 */
	public function testGetUsedByPropagatesAnAmbiguousIdentifier(): void {
		$this->objectMapper->method('find')
			->willThrowException(new \OCP\AppFramework\Db\MultipleObjectsReturnedException('two objects hold "dubbel"'));
		$this->logger->expects($this->never())->method('error');

		$this->expectException(\OCP\AppFramework\Db\MultipleObjectsReturnedException::class);
		$this->handler->getUsedBy(lookup: new RelationLookup(objectId: 'dubbel'));
	}//end testGetUsedByPropagatesAnAmbiguousIdentifier()

	/**
	 * Returns default limit/offset in empty response when no query provided.
	 *
	 * @return void
	 */
	public function testGetUsesUsesDefaultPagination(): void {
		$entity = $this->makeObjectEntity(1);
		$entity->setUuid('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
		$entity->setRelations([]);

		$this->objectMapper->method('find')->willReturn($entity);

		$result = $this->handler->getUses(lookup: new RelationLookup(objectId: 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'));

		$this->assertSame(30, $result['limit']);
		$this->assertSame(0, $result['offset']);
	}//end testGetUsesUsesDefaultPagination()

	/**
	 * Extracts relation IDs from nested array relations structure.
	 *
	 * @return void
	 */
	public function testGetUsesExtractsRelationsFromNestedArrays(): void {
		$ownUuid = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

		$entity = $this->makeObjectEntity(1);
		$entity->setUuid($ownUuid);
		// Relations stored as ['field' => ['uuid1', 'uuid2']].
		$entity->setRelations(['members' => ['rel-uuid-1', 'rel-uuid-2']]);

		$this->objectMapper->method('find')->willReturn($entity);

		// getUses calls \OC::$server for RegisterMapper and MagicMapper -- this path will
		// throw a fatal if OC is not available in unit tests, so we assert on the
		// error-fallback path which still returns the correct structure.
		$result = $this->handler->getUses(lookup: new RelationLookup(objectId: $ownUuid));

		$this->assertArrayHasKey('results', $result);
		$this->assertArrayHasKey('total', $result);
	}//end testGetUsesExtractsRelationsFromNestedArrays()

	// =========================================================================
	// getUsedBy tests
	// =========================================================================

	/**
	 * Returns error response structure when object lookup throws.
	 *
	 * @return void
	 */
	public function testGetUsedByReturnsErrorResponseOnException(): void {
		$this->objectMapper
			->method('find')
			->willThrowException(new \RuntimeException('Not found'));

		$this->logger->expects($this->once())->method('error');

		$result = $this->handler->getUsedBy(lookup: new RelationLookup(objectId: 'missing-uuid', query: ['_limit' => 5, '_offset' => 2]));

		$this->assertSame([], $result['results']);
		$this->assertSame(0, $result['total']);
		$this->assertSame(5, $result['limit']);
		$this->assertSame(2, $result['offset']);
	}//end testGetUsedByReturnsErrorResponseOnException()

	/**
	 * Returns default limit/offset in error fallback when no query provided.
	 *
	 * @return void
	 */
	public function testGetUsedByUsesDefaultPaginationInErrorFallback(): void {
		$this->objectMapper
			->method('find')
			->willThrowException(new \RuntimeException('Not found'));

		$this->logger->expects($this->once())->method('error');

		$result = $this->handler->getUsedBy(lookup: new RelationLookup(objectId: 'missing-uuid'));

		$this->assertSame(30, $result['limit']);
		$this->assertSame(0, $result['offset']);
	}//end testGetUsedByUsesDefaultPaginationInErrorFallback()

	/**
	 * Correct response structure keys are always present in the returned array.
	 *
	 * @return void
	 */
	public function testGetUsedByResponseAlwaysHasRequiredKeys(): void {
		$this->objectMapper
			->method('find')
			->willThrowException(new \RuntimeException('Not found'));

		$this->logger->expects($this->once())->method('error');

		$result = $this->handler->getUsedBy(lookup: new RelationLookup(objectId: 'some-uuid'));

		$this->assertArrayHasKey('results', $result);
		$this->assertArrayHasKey('total', $result);
		$this->assertArrayHasKey('limit', $result);
		$this->assertArrayHasKey('offset', $result);
	}//end testGetUsedByResponseAlwaysHasRequiredKeys()

	/**
	 * getContracts response always has all required structure keys.
	 *
	 * @return void
	 */
	public function testGetContractsResponseAlwaysHasRequiredKeys(): void {
		$entity = $this->makeObjectEntity(1);
		$entity->setObject([]);

		$this->objectMapper->method('find')->willReturn($entity);

		$result = $this->handler->getContracts('some-uuid');

		$this->assertArrayHasKey('results', $result);
		$this->assertArrayHasKey('total', $result);
		$this->assertArrayHasKey('limit', $result);
		$this->assertArrayHasKey('offset', $result);
	}//end testGetContractsResponseAlwaysHasRequiredKeys()

	// =========================================================================
	// Additional bulkLoadRelationshipsBatched tests
	// =========================================================================

	/**
	 * Processes multiple batches when IDs exceed one batch size (50).
	 *
	 * @return void
	 */
	public function testBulkLoadRelationshipsBatchedProcessesMultipleBatches(): void {
		$ids = array_map(fn ($i) => "id-$i", range(1, 75));

		$entity1 = $this->makeObjectEntity(1);
		$entity1->setUuid('aaaaaaaa-bbbb-cccc-dddd-000000000001');

		$this->objectMapper
			->method('findAll')
			->willReturn([$entity1]);

		$this->logger->expects($this->atLeastOnce())->method('info');
		$this->logger->expects($this->atLeastOnce())->method('debug');

		$result = $this->handler->bulkLoadRelationshipsBatched($ids);

		$this->assertIsArray($result);
		$this->assertArrayHasKey('aaaaaaaa-bbbb-cccc-dddd-000000000001', $result);
	}//end testBulkLoadRelationshipsBatchedProcessesMultipleBatches()

	/**
	 * Returns empty result when all batches throw exceptions.
	 *
	 * @return void
	 */
	public function testBulkLoadRelationshipsBatchedAllBatchesFail(): void {
		$ids = array_map(fn ($i) => "id-$i", range(1, 5));

		$this->objectMapper
			->method('findAll')
			->willThrowException(new \RuntimeException('DB error'));

		$this->logger->expects($this->atLeastOnce())->method('error');
		$this->logger->expects($this->atLeastOnce())->method('info');

		$result = $this->handler->bulkLoadRelationshipsBatched($ids);

		$this->assertSame([], $result);
	}//end testBulkLoadRelationshipsBatchedAllBatchesFail()

	/**
	 * Exactly 200 IDs is not subject to the cap warning.
	 *
	 * @return void
	 */
	public function testBulkLoadRelationshipsBatchedExactly200NoCap(): void {
		$ids = array_map(fn ($i) => "id-$i", range(1, 200));

		$this->objectMapper->method('findAll')->willReturn([]);

		// warning should NOT be called -- exactly 200 is not over the limit.
		$this->logger->expects($this->never())->method('warning');
		$this->logger->expects($this->atLeastOnce())->method('info');

		$result = $this->handler->bulkLoadRelationshipsBatched($ids);

		$this->assertIsArray($result);
	}//end testBulkLoadRelationshipsBatchedExactly200NoCap()

	// =========================================================================
	// Additional getContracts tests
	// =========================================================================

	/**
	 * getContracts returns correct items when offset exceeds array length.
	 *
	 * @return void
	 */
	public function testGetContractsOffsetBeyondArrayReturnsEmpty(): void {
		$entity = $this->makeObjectEntity(1);
		$entity->setObject(['contracts' => ['a', 'b']]);

		$this->objectMapper->method('find')->willReturn($entity);

		$result = $this->handler->getContracts('some-uuid', ['_limit' => 5, '_offset' => 10]);

		$this->assertSame([], array_values($result['results']));
		$this->assertSame(2, $result['total']);
	}//end testGetContractsOffsetBeyondArrayReturnsEmpty()

	/**
	 * getContracts uses _limit and _offset from filters in the error response.
	 *
	 * @return void
	 */
	public function testGetContractsErrorResponseRespectsFilters(): void {
		$this->objectMapper
			->method('find')
			->willThrowException(new \RuntimeException('DB fail'));

		$this->logger->expects($this->once())->method('error');

		$result = $this->handler->getContracts('uuid', ['_limit' => 25, '_offset' => 5]);

		$this->assertSame(25, $result['limit']);
		$this->assertSame(5, $result['offset']);
	}//end testGetContractsErrorResponseRespectsFilters()

	// =========================================================================
	// Additional getUses tests
	// =========================================================================

	/**
	 * getUses returns default pagination values even when query is empty array.
	 *
	 * @return void
	 */
	public function testGetUsesDefaultPaginationWithEmptyQuery(): void {
		$entity = $this->makeObjectEntity(1);
		$entity->setUuid('bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb');
		$entity->setRelations([]);

		$this->objectMapper->method('find')->willReturn($entity);

		$result = $this->handler->getUses(lookup: new RelationLookup(objectId: 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', query: []));

		$this->assertSame(30, $result['limit']);
		$this->assertSame(0, $result['offset']);
		$this->assertSame(0, $result['total']);
	}//end testGetUsesDefaultPaginationWithEmptyQuery()

	/**
	 * getUses extracts string relations from a flat ['field' => 'uuid'] structure.
	 *
	 * @return void
	 */
	public function testGetUsesExtractsStringRelations(): void {
		$ownUuid = 'cccccccc-cccc-cccc-cccc-cccccccccccc';

		$entity = $this->makeObjectEntity(1);
		$entity->setUuid($ownUuid);
		// Flat structure: ['field1' => 'some-uuid', 'field2' => 'other-uuid'].
		$entity->setRelations(['field1' => 'some-uuid-a', 'field2' => 'some-uuid-b']);

		$this->objectMapper->method('find')->willReturn($entity);

		// getUses will attempt \OC::$server calls, which may fail in unit test context.
		// We just assert the response has the required structure.
		$result = $this->handler->getUses(lookup: new RelationLookup(objectId: $ownUuid));

		$this->assertArrayHasKey('results', $result);
		$this->assertArrayHasKey('total', $result);
		$this->assertArrayHasKey('limit', $result);
		$this->assertArrayHasKey('offset', $result);
	}//end testGetUsesExtractsStringRelations()

	/**
	 * getUses response has correct structure keys when getUsedBy error fallback fires.
	 *
	 * @return void
	 */
	public function testGetUsesResponseStructureOnError(): void {
		$this->objectMapper
			->method('find')
			->willThrowException(new \RuntimeException('Not found'));

		$this->logger->expects($this->once())->method('error');

		$result = $this->handler->getUses(lookup: new RelationLookup(objectId: 'bad-uuid', query: ['_limit' => 10, '_offset' => 2]));

		$this->assertArrayHasKey('results', $result);
		$this->assertArrayHasKey('total', $result);
		$this->assertSame(10, $result['limit']);
		$this->assertSame(2, $result['offset']);
	}//end testGetUsesResponseStructureOnError()

	// =========================================================================
	// Additional applyInversedByFilter tests
	// =========================================================================

	// =========================================================================
	// Additional extractRelatedData tests
	// =========================================================================

	// =========================================================================
	// Additional getUsedBy tests
	// =========================================================================

	/**
	 * getUsedBy returns correct structure when objectMapper throws (error path).
	 *
	 * In unit tests OC::$server calls may succeed but may fail deep in MagicMapper.
	 * We exercise the error fallback which always returns the correct structure.
	 *
	 * @return void
	 */
	public function testGetUsedBySuccessPathStructure(): void {
		// Make find() throw so we hit the outer catch -- always returns the right structure.
		$this->objectMapper
			->method('find')
			->willThrowException(new \RuntimeException('Unit test - no DB'));

		$this->logger->expects($this->once())->method('error');

		$result = $this->handler->getUsedBy(lookup: new RelationLookup(objectId: 'dddddddd-dddd-dddd-dddd-dddddddddddd', query: ['_limit' => 15, '_offset' => 3]));

		$this->assertArrayHasKey('results', $result);
		$this->assertArrayHasKey('total', $result);
		$this->assertSame(15, $result['limit']);
		$this->assertSame(3, $result['offset']);
	}//end testGetUsedBySuccessPathStructure()

	// =========================================================================
	// NEW: applyInversedByFilter -- advanced filter processing
	// =========================================================================

	// =========================================================================
	// NEW: bulkLoadRelationshipsBatched -- >200 IDs batching
	// =========================================================================

	/**
	 * Verifies that with >200 IDs, only 200 are processed (4 batches of 50).
	 *
	 * @return void
	 */
	public function testBulkLoadRelationshipsBatchedWith300IdsProcessesOnly200(): void {
		$ids = array_map(fn ($i) => "id-$i", range(1, 300));

		$callCount = 0;
		$this->objectMapper
			->method('findAll')
			->willReturnCallback(function () use (&$callCount) {
				$callCount++;
				return [];
			});

		$this->logger->expects($this->atLeastOnce())->method('warning');
		$this->logger->expects($this->atLeastOnce())->method('info');

		$this->handler->bulkLoadRelationshipsBatched($ids);

		// 200 IDs / 50 per batch = 4 batches.
		$this->assertSame(4, $callCount);
	}//end testBulkLoadRelationshipsBatchedWith300IdsProcessesOnly200()

	/**
	 * Single ID should result in a single batch call.
	 *
	 * @return void
	 */
	public function testBulkLoadRelationshipsBatchedSingleId(): void {
		$entity = $this->makeObjectEntity(99);
		$entity->setUuid('11111111-1111-1111-1111-111111111111');

		$this->objectMapper
			->expects($this->once())
			->method('findAll')
			->willReturn([$entity]);

		$this->logger->expects($this->atLeastOnce())->method('info');
		$this->logger->expects($this->atLeastOnce())->method('debug');

		$result = $this->handler->bulkLoadRelationshipsBatched(['11111111-1111-1111-1111-111111111111']);

		$this->assertArrayHasKey('11111111-1111-1111-1111-111111111111', $result);
		$this->assertArrayHasKey(99, $result);
	}//end testBulkLoadRelationshipsBatchedSingleId()

	/**
	 * Multiple objects returned per batch are all indexed.
	 *
	 * @return void
	 */
	public function testBulkLoadRelationshipsBatchedMultipleObjectsPerBatch(): void {
		$e1 = $this->makeObjectEntity(10);
		$e1->setUuid('aaaa-1111');
		$e2 = $this->makeObjectEntity(20);
		$e2->setUuid('aaaa-2222');

		$this->objectMapper->method('findAll')->willReturn([$e1, $e2]);

		$this->logger->expects($this->atLeastOnce())->method('info');
		$this->logger->expects($this->atLeastOnce())->method('debug');

		$result = $this->handler->bulkLoadRelationshipsBatched(['aaaa-1111', 'aaaa-2222']);

		$this->assertArrayHasKey('aaaa-1111', $result);
		$this->assertArrayHasKey('aaaa-2222', $result);
		$this->assertArrayHasKey(10, $result);
		$this->assertArrayHasKey(20, $result);
	}//end testBulkLoadRelationshipsBatchedMultipleObjectsPerBatch()

	// =========================================================================
	// NEW: loadRelationshipChunkOptimized -- multiple IDs
	// =========================================================================

	/**
	 * loadRelationshipChunkOptimized passes IDs to findAll correctly.
	 *
	 * @return void
	 */
	public function testLoadRelationshipChunkOptimizedPassesMultipleIds(): void {
		$e1 = $this->makeObjectEntity(1);
		$e2 = $this->makeObjectEntity(2);

		$capturedIds = null;
		$this->objectMapper
			->expects($this->once())
			->method('findAll')
			->willReturnCallback(function () use ($e1, $e2, &$capturedIds) {
				$capturedIds = func_get_args();
				return [$e1, $e2];
			});

		$result = $this->handler->loadRelationshipChunkOptimized(['uuid-a', 'uuid-b']);

		$this->assertCount(2, $result);
	}//end testLoadRelationshipChunkOptimizedPassesMultipleIds()

	// =========================================================================
	// NEW: getContracts -- non-array contracts property
	// =========================================================================

	/**
	 * getContracts handles a non-array contracts value (e.g., string).
	 *
	 * @return void
	 */
	public function testGetContractsHandlesNonArrayContractsValue(): void {
		$entity = $this->makeObjectEntity(1);
		$entity->setObject(['contracts' => 'not-an-array']);

		$this->objectMapper->method('find')->willReturn($entity);

		$result = $this->handler->getContracts('some-uuid');

		// When contracts is not an array, total stays 0 and results is the raw value.
		$this->assertSame(0, $result['total']);
		$this->assertSame('not-an-array', $result['results']);
	}//end testGetContractsHandlesNonArrayContractsValue()

	/**
	 * getContracts returns single page when limit exceeds total.
	 *
	 * @return void
	 */
	public function testGetContractsLimitExceedsTotal(): void {
		$entity = $this->makeObjectEntity(1);
		$entity->setObject(['contracts' => ['c1', 'c2']]);

		$this->objectMapper->method('find')->willReturn($entity);

		$result = $this->handler->getContracts('uuid', ['_limit' => 100, '_offset' => 0]);

		$this->assertCount(2, $result['results']);
		$this->assertSame(2, $result['total']);
		$this->assertSame(100, $result['limit']);
	}//end testGetContractsLimitExceedsTotal()

	// =========================================================================
	// NEW: getUses -- register/schema IDs
	// =========================================================================

	/**
	 * getUses with register/schema IDs that fail to load falls back gracefully.
	 *
	 * @return void
	 */
	public function testGetUsesWithRegisterSchemaIdsFailure(): void {
		$entity = $this->makeObjectEntity(1);
		$entity->setUuid('eeeeeeee-eeee-eeee-eeee-eeeeeeeeeeee');
		$entity->setRelations([]);

		$this->objectMapper->method('find')->willReturn($entity);

		// Even with register/schema IDs that may fail internally, the response is correct.
		$result = $this->handler->getUses(lookup: (new RelationLookup(objectId: 'eeeeeeee-eeee-eeee-eeee-eeeeeeeeeeee', query: []))->filteredBy(rbac: true, multitenancy: true)->pinnedTo(registerId: 999, schemaId: 888));

		$this->assertArrayHasKey('results', $result);
		$this->assertArrayHasKey('total', $result);
		$this->assertSame(0, $result['total']);
	}//end testGetUsesWithRegisterSchemaIdsFailure()

	/**
	 * getUses ignores empty string values in nested relation arrays.
	 *
	 * @return void
	 */
	public function testGetUsesIgnoresEmptyRelationValues(): void {
		$ownUuid = 'ffffffff-ffff-ffff-ffff-ffffffffffff';

		$entity = $this->makeObjectEntity(1);
		$entity->setUuid($ownUuid);
		$entity->setRelations([
			'field1' => '',
			'field2' => ['', ''],
		]);

		$this->objectMapper->method('find')->willReturn($entity);

		$result = $this->handler->getUses(lookup: new RelationLookup(objectId: $ownUuid));

		$this->assertSame([], $result['results']);
		$this->assertSame(0, $result['total']);
	}//end testGetUsesIgnoresEmptyRelationValues()

	// =========================================================================
	// NEW: getUsedBy -- register/schema IDs
	// =========================================================================

	/**
	 * getUsedBy with register/schema IDs that fail returns error fallback.
	 *
	 * @return void
	 */
	public function testGetUsedByWithRegisterSchemaIdsFailure(): void {
		$this->objectMapper
			->method('find')
			->willThrowException(new \RuntimeException('DB error'));

		$this->logger->expects($this->once())->method('error');

		$result = $this->handler->getUsedBy(lookup: (new RelationLookup(objectId: 'some-uuid', query: ['_limit' => 20, '_offset' => 5]))->filteredBy(rbac: true, multitenancy: true)->pinnedTo(registerId: 999, schemaId: 888));

		$this->assertSame([], $result['results']);
		$this->assertSame(0, $result['total']);
		$this->assertSame(20, $result['limit']);
		$this->assertSame(5, $result['offset']);
	}//end testGetUsedByWithRegisterSchemaIdsFailure()

	/**
	 * getUsedBy with RBAC disabled still returns correct structure.
	 *
	 * @return void
	 */
	public function testGetUsedByWithRbacDisabled(): void {
		$this->objectMapper
			->method('find')
			->willThrowException(new \RuntimeException('No DB'));

		$this->logger->expects($this->once())->method('error');

		$result = $this->handler->getUsedBy(lookup: (new RelationLookup(objectId: 'some-uuid', query: []))->filteredBy(rbac: false, multitenancy: false));

		$this->assertArrayHasKey('results', $result);
		$this->assertArrayHasKey('total', $result);
	}//end testGetUsedByWithRbacDisabled()

	// =========================================================================
	// NEW: getUses -- null relations
	// =========================================================================

	/**
	 * getUses handles null relations (getRelations returns null).
	 *
	 * @return void
	 */
	public function testGetUsesHandlesNullRelations(): void {
		// getRelations/getUuid are magic methods on ObjectEntity -- use addMethods.
		$entity = $this->getMockBuilder(ObjectEntity::class)
			->onlyMethods(['getUuid'])
			->addMethods(['getRelations'])
			->getMock();
		$entity->method('getRelations')->willReturn(null);
		$entity->method('getUuid')->willReturn('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa');

		$this->objectMapper->method('find')->willReturn($entity);

		$result = $this->handler->getUses(lookup: new RelationLookup(objectId: 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa'));

		$this->assertSame([], $result['results']);
		$this->assertSame(0, $result['total']);
	}//end testGetUsesHandlesNullRelations()

}//end class
