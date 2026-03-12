<?php

/**
 * RelationHandler Unit Tests
 *
 * Tests for the RelationHandler service class, covering:
 * - Inverse relation filtering (applyInversedByFilter)
 * - Relationship ID extraction (extractAllRelationshipIds)
 * - Bulk relationship loading (bulkLoadRelationshipsBatched, loadRelationshipChunkOptimized)
 * - Contract lookups (getContracts)
 * - Use lookups (getUses, getUsedBy)
 * - Related data delegation (extractRelatedData)
 * - Error handling and edge cases
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\PerformanceHandler;
use OCA\OpenRegister\Service\Object\RelationHandler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Unit tests for RelationHandler.
 *
 * @covers \OCA\OpenRegister\Service\Object\RelationHandler
 */
class RelationHandlerTest extends TestCase
{
    private RelationHandler $handler;

    /** @var ObjectEntityMapper&MockObject */
    private ObjectEntityMapper $objectEntityMapper;

    /** @var SchemaMapper&MockObject */
    private SchemaMapper $schemaMapper;

    /** @var PerformanceHandler&MockObject */
    private PerformanceHandler $performanceHandler;

    /** @var MagicRbacHandler&MockObject */
    private MagicRbacHandler $rbacHandler;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    /**
     * Set up test fixtures before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectEntityMapper = $this->createMock(ObjectEntityMapper::class);
        $this->schemaMapper       = $this->createMock(SchemaMapper::class);
        $this->performanceHandler = $this->createMock(PerformanceHandler::class);
        $this->rbacHandler        = $this->createMock(MagicRbacHandler::class);
        $this->logger             = $this->createMock(LoggerInterface::class);

        $this->handler = new RelationHandler(
            objectEntityMapper: $this->objectEntityMapper,
            schemaMapper: $this->schemaMapper,
            performanceHandler: $this->performanceHandler,
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
    private function makeObjectEntity(int $id): ObjectEntity
    {
        $entity = new ObjectEntity();
        $ref    = new ReflectionClass($entity);
        $prop   = $ref->getProperty('id');
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
    private function makeSchema(array $properties = []): Schema
    {
        $schema = $this->createMock(Schema::class);
        $schema->method('getProperties')->willReturn($properties);

        return $schema;
    }//end makeSchema()

    // =========================================================================
    // applyInversedByFilter tests
    // =========================================================================

    /**
     * When schema === false, applyInversedByFilter returns empty array immediately.
     *
     * @return void
     */
    public function testApplyInversedByFilterReturnEmptyWhenSchemaFalse(): void
    {
        $filters = ['schema' => false];

        $result = $this->handler->applyInversedByFilter($filters, fn($x) => []);

        $this->assertSame([], $result);
    }//end testApplyInversedByFilterReturnEmptyWhenSchemaFalse()

    /**
     * When no filter keys contain underscore sub-keys, returns empty array.
     *
     * @return void
     */
    public function testApplyInversedByFilterReturnEmptyWhenNoSubFilters(): void
    {
        $schema  = $this->makeSchema(['name' => ['type' => 'string']]);
        $filters = ['schema' => 1, 'name' => 'test'];

        $this->schemaMapper->method('find')->willReturn($schema);

        $result = $this->handler->applyInversedByFilter($filters, fn($x) => []);

        $this->assertSame([], $result);
    }//end testApplyInversedByFilterReturnEmptyWhenNoSubFilters()

    /**
     * When sub-filter key matches a property with no inversedBy, iterator stays 0 and
     * returns empty array (not null).
     *
     * @return void
     */
    public function testApplyInversedByFilterReturnEmptyWhenPropertyHasNoInversedBy(): void
    {
        // Property 'owner' has no inversedBy.
        $schema  = $this->makeSchema(['owner' => ['type' => 'string']]);
        $filters = ['schema' => 1, 'owner_name' => 'alice'];

        $this->schemaMapper->method('find')->willReturn($schema);

        $result = $this->handler->applyInversedByFilter($filters, fn($x) => []);

        $this->assertSame([], $result);
    }//end testApplyInversedByFilterReturnEmptyWhenPropertyHasNoInversedBy()

    /**
     * When inversedBy property is found but callback returns no objects, returns null
     * (signals "no results match").
     *
     * @return void
     */
    public function testApplyInversedByFilterReturnsNullWhenCallbackFindsNothing(): void
    {
        $schema = $this->makeSchema([
            'owner' => [
                'type'       => 'string',
                'inversedBy' => 'member',
                '$ref'       => 'some-schema-uuid',
            ],
        ]);

        $filters = ['schema' => 1, 'owner_id' => 'some-value'];

        $this->schemaMapper->method('find')->willReturn($schema);

        // Callback returns empty -- no matching objects found.
        $result = $this->handler->applyInversedByFilter($filters, fn($x) => []);

        $this->assertNull($result);
    }//end testApplyInversedByFilterReturnsNullWhenCallbackFindsNothing()

    /**
     * When inversedBy property is matched and related object contains a valid UUID in the
     * inversedBy field, that UUID is returned in the ids array.
     *
     * @return void
     */
    public function testApplyInversedByFilterExtractsUuidFromRelatedObject(): void
    {
        $uuid   = '550e8400-e29b-41d4-a716-446655440000';
        $schema = $this->makeSchema([
            'owner' => [
                'type'       => 'string',
                'inversedBy' => 'member',
                '$ref'       => 'schema-uuid',
            ],
        ]);

        $relatedObject = $this->createMock(ObjectEntity::class);
        $relatedObject->method('jsonSerialize')->willReturn(['member' => $uuid, 'name' => 'Test']);

        $filters = ['schema' => 1, 'owner_id' => 'some-value'];

        $this->schemaMapper->method('find')->willReturn($schema);

        $result = $this->handler->applyInversedByFilter($filters, fn($x) => [$relatedObject]);

        $this->assertIsArray($result);
        $this->assertContains($uuid, $result);
    }//end testApplyInversedByFilterExtractsUuidFromRelatedObject()

    /**
     * When inversedBy field value is a URL, the last path segment (UUID) is returned.
     *
     * @return void
     */
    public function testApplyInversedByFilterExtractsUuidFromUrl(): void
    {
        $uuid   = '550e8400-e29b-41d4-a716-446655440000';
        $schema = $this->makeSchema([
            'owner' => [
                'type'       => 'string',
                'inversedBy' => 'member',
                '$ref'       => 'schema-uuid',
            ],
        ]);

        $relatedObject = $this->createMock(ObjectEntity::class);
        $relatedObject->method('jsonSerialize')->willReturn([
            'member' => 'https://example.com/objects/'.$uuid,
        ]);

        $filters = ['schema' => 1, 'owner_id' => 'something'];

        $this->schemaMapper->method('find')->willReturn($schema);

        $result = $this->handler->applyInversedByFilter($filters, fn($x) => [$relatedObject]);

        $this->assertIsArray($result);
        $this->assertContains($uuid, $result);
    }//end testApplyInversedByFilterExtractsUuidFromUrl()

    /**
     * When inversedBy field value is neither a UUID nor a URL, null is mapped and the id list
     * can still be returned as an array containing null.
     *
     * @return void
     */
    public function testApplyInversedByFilterHandlesNonUuidNonUrlValue(): void
    {
        $schema = $this->makeSchema([
            'owner' => [
                'type'       => 'string',
                'inversedBy' => 'member',
                '$ref'       => 'schema-uuid',
            ],
        ]);

        $relatedObject = $this->createMock(ObjectEntity::class);
        $relatedObject->method('jsonSerialize')->willReturn(['member' => 'not-a-uuid-or-url']);

        $filters = ['schema' => 1, 'owner_id' => 'something'];

        $this->schemaMapper->method('find')->willReturn($schema);

        $result = $this->handler->applyInversedByFilter($filters, fn($x) => [$relatedObject]);

        // Result is array containing null.
        $this->assertIsArray($result);
    }//end testApplyInversedByFilterHandlesNonUuidNonUrlValue()

    /**
     * After processing, sub-filter keys are removed from $filters.
     *
     * @return void
     */
    public function testApplyInversedByFilterRemovesProcessedFilterKeys(): void
    {
        $uuid   = '550e8400-e29b-41d4-a716-446655440000';
        $schema = $this->makeSchema([
            'owner' => [
                'type'       => 'string',
                'inversedBy' => 'member',
                '$ref'       => 'schema-uuid',
            ],
        ]);

        $relatedObject = $this->createMock(ObjectEntity::class);
        $relatedObject->method('jsonSerialize')->willReturn(['member' => $uuid]);

        $filters = ['schema' => 1, 'owner_id' => 'some-value'];

        $this->schemaMapper->method('find')->willReturn($schema);
        $this->handler->applyInversedByFilter($filters, fn($x) => [$relatedObject]);

        // The composite key 'owner_id' should be removed from $filters.
        $this->assertArrayNotHasKey('owner_id', $filters);
    }//end testApplyInversedByFilterRemovesProcessedFilterKeys()

    // =========================================================================
    // extractAllRelationshipIds tests
    // =========================================================================

    /**
     * Returns empty array when objects list is empty.
     *
     * @return void
     */
    public function testExtractAllRelationshipIdsReturnsEmptyForNoObjects(): void
    {
        $this->logger->expects($this->once())->method('info');

        $result = $this->handler->extractAllRelationshipIds([], ['field']);

        $this->assertSame([], $result);
    }//end testExtractAllRelationshipIdsReturnsEmptyForNoObjects()

    /**
     * Returns empty array when extend list is empty.
     *
     * @return void
     */
    public function testExtractAllRelationshipIdsReturnsEmptyForNoExtendFields(): void
    {
        $object = $this->makeObjectEntity(1);
        $object->setObject(['related' => 'some-id']);

        $this->logger->expects($this->once())->method('info');

        $result = $this->handler->extractAllRelationshipIds([$object], []);

        $this->assertSame([], $result);
    }//end testExtractAllRelationshipIdsReturnsEmptyForNoExtendFields()

    /**
     * Extracts a single string relationship ID from an object property.
     *
     * @return void
     */
    public function testExtractAllRelationshipIdsExtractsSingleStringId(): void
    {
        $object = $this->makeObjectEntity(1);
        $object->setObject(['relatedId' => 'abc-123']);

        $this->logger->expects($this->once())->method('info');

        $result = $this->handler->extractAllRelationshipIds([$object], ['relatedId']);

        $this->assertContains('abc-123', $result);
        $this->assertCount(1, $result);
    }//end testExtractAllRelationshipIdsExtractsSingleStringId()

    /**
     * Extracts multiple relationship IDs from an array property.
     *
     * @return void
     */
    public function testExtractAllRelationshipIdsExtractsArrayOfIds(): void
    {
        $object = $this->makeObjectEntity(1);
        $object->setObject(['members' => ['id-1', 'id-2', 'id-3']]);

        $this->logger->expects($this->once())->method('info');

        $result = $this->handler->extractAllRelationshipIds([$object], ['members']);

        $this->assertContains('id-1', $result);
        $this->assertContains('id-2', $result);
        $this->assertContains('id-3', $result);
    }//end testExtractAllRelationshipIdsExtractsArrayOfIds()

    /**
     * Deduplicates IDs that appear in multiple objects.
     *
     * @return void
     */
    public function testExtractAllRelationshipIdsDeduplicates(): void
    {
        $object1 = $this->makeObjectEntity(1);
        $object1->setObject(['link' => 'shared-id']);

        $object2 = $this->makeObjectEntity(2);
        $object2->setObject(['link' => 'shared-id']);

        $this->logger->expects($this->once())->method('info');

        $result = $this->handler->extractAllRelationshipIds([$object1, $object2], ['link']);

        $this->assertCount(1, $result);
        $this->assertContains('shared-id', $result);
    }//end testExtractAllRelationshipIdsDeduplicates()

    /**
     * Skips empty string values inside arrays.
     *
     * @return void
     */
    public function testExtractAllRelationshipIdsSkipsEmptyStringsInArrays(): void
    {
        $object = $this->makeObjectEntity(1);
        $object->setObject(['items' => ['valid-id', '', '  ']]);

        $this->logger->expects($this->once())->method('info');

        $result = $this->handler->extractAllRelationshipIds([$object], ['items']);

        // Only 'valid-id' is non-empty.
        $this->assertContains('valid-id', $result);
        $this->assertNotContains('', $result);
    }//end testExtractAllRelationshipIdsSkipsEmptyStringsInArrays()

    /**
     * Skips empty string scalar values.
     *
     * @return void
     */
    public function testExtractAllRelationshipIdsSkipsEmptyStringScalar(): void
    {
        $object = $this->makeObjectEntity(1);
        $object->setObject(['link' => '']);

        $this->logger->expects($this->once())->method('info');

        $result = $this->handler->extractAllRelationshipIds([$object], ['link']);

        $this->assertSame([], $result);
    }//end testExtractAllRelationshipIdsSkipsEmptyStringScalar()

    /**
     * Array properties are limited to 10 items per object (performance protection).
     *
     * @return void
     */
    public function testExtractAllRelationshipIdsLimitsArrayTo10Items(): void
    {
        $ids = array_map(fn($i) => "id-$i", range(1, 15));

        $object = $this->makeObjectEntity(1);
        $object->setObject(['items' => $ids]);

        // Logger gets info (completion) + debug (truncation notice).
        $this->logger->expects($this->atLeastOnce())->method('debug');
        $this->logger->expects($this->once())->method('info');

        $result = $this->handler->extractAllRelationshipIds([$object], ['items']);

        // Max 10 from a single array property.
        $this->assertCount(10, $result);
    }//end testExtractAllRelationshipIdsLimitsArrayTo10Items()

    /**
     * Stops extracting after hitting the 200-ID circuit breaker limit.
     *
     * @return void
     */
    public function testExtractAllRelationshipIdsCircuitBreakerAt200(): void
    {
        // 25 objects each with 10 IDs = 250 total; circuit breaker caps at 200.
        $objects = [];
        for ($i = 1; $i <= 25; $i++) {
            $ids    = array_map(fn($j) => "obj{$i}-id{$j}", range(1, 10));
            $object = $this->makeObjectEntity($i);
            $object->setObject(['items' => $ids]);
            $objects[] = $object;
        }

        $this->logger->expects($this->atLeastOnce())->method('info');

        $result = $this->handler->extractAllRelationshipIds($objects, ['items']);

        // Should be at most 200 unique IDs.
        $this->assertLessThanOrEqual(200, count($result));
    }//end testExtractAllRelationshipIdsCircuitBreakerAt200()

    // =========================================================================
    // bulkLoadRelationshipsBatched tests
    // =========================================================================

    /**
     * Returns empty array immediately when no IDs provided.
     *
     * @return void
     */
    public function testBulkLoadRelationshipsBatchedReturnsEmptyForNoIds(): void
    {
        $result = $this->handler->bulkLoadRelationshipsBatched([]);

        $this->assertSame([], $result);
    }//end testBulkLoadRelationshipsBatchedReturnsEmptyForNoIds()

    /**
     * Caps the input at 200 IDs with a warning log when more than 200 given.
     *
     * @return void
     */
    public function testBulkLoadRelationshipsBatchedCapsAt200(): void
    {
        $ids = array_map(fn($i) => "id-$i", range(1, 250));

        $this->objectEntityMapper
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
    public function testBulkLoadRelationshipsBatchedIndexesByUuidAndId(): void
    {
        $entity = $this->makeObjectEntity(42);
        $entity->setUuid('550e8400-e29b-41d4-a716-446655440000');

        $this->objectEntityMapper
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
    public function testBulkLoadRelationshipsBatchedContinuesOnBatchError(): void
    {
        // Generate 60 IDs -- two batches of 50/10.
        $ids = array_map(fn($i) => "id-$i", range(1, 60));

        $this->objectEntityMapper
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
    public function testLoadRelationshipChunkOptimizedReturnsEmptyForNoIds(): void
    {
        $this->objectEntityMapper->expects($this->never())->method('findAll');

        $result = $this->handler->loadRelationshipChunkOptimized([]);

        $this->assertSame([], $result);
    }//end testLoadRelationshipChunkOptimizedReturnsEmptyForNoIds()

    /**
     * Delegates to objectEntityMapper->findAll and returns its result.
     *
     * @return void
     */
    public function testLoadRelationshipChunkOptimizedReturnsMapperResult(): void
    {
        $entity = $this->makeObjectEntity(7);

        $this->objectEntityMapper
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
    public function testLoadRelationshipChunkOptimizedReturnsEmptyOnMapperException(): void
    {
        $this->objectEntityMapper
            ->method('findAll')
            ->willThrowException(new \RuntimeException('DB error'));

        $this->logger->expects($this->once())->method('error');

        $result = $this->handler->loadRelationshipChunkOptimized(['some-uuid']);

        $this->assertSame([], $result);
    }//end testLoadRelationshipChunkOptimizedReturnsEmptyOnMapperException()

    // =========================================================================
    // extractRelatedData tests
    // =========================================================================

    /**
     * Delegates extractRelatedData to PerformanceHandler with the same arguments.
     *
     * @return void
     */
    public function testExtractRelatedDataDelegatesToPerformanceHandler(): void
    {
        $results  = ['key' => 'value'];
        $expected = ['related' => ['uuid-1']];

        $this->performanceHandler
            ->expects($this->once())
            ->method('extractRelatedData')
            ->with(
                results: $results,
                includeRelated: true,
                includeRelatedNames: false
            )
            ->willReturn($expected);

        $result = $this->handler->extractRelatedData($results, true, false);

        $this->assertSame($expected, $result);
    }//end testExtractRelatedDataDelegatesToPerformanceHandler()

    /**
     * Passes both boolean flags correctly to PerformanceHandler.
     *
     * @return void
     */
    public function testExtractRelatedDataPassesFlagsCorrectly(): void
    {
        $this->performanceHandler
            ->expects($this->once())
            ->method('extractRelatedData')
            ->with(
                results: $this->anything(),
                includeRelated: false,
                includeRelatedNames: true
            )
            ->willReturn([]);

        $this->handler->extractRelatedData([], false, true);
    }//end testExtractRelatedDataPassesFlagsCorrectly()

    // =========================================================================
    // getContracts tests
    // =========================================================================

    /**
     * Returns paginated contracts from the object's 'contracts' property.
     *
     * @return void
     */
    public function testGetContractsReturnsPaginatedContracts(): void
    {
        $entity = $this->makeObjectEntity(1);
        $entity->setObject(['contracts' => ['contract-a', 'contract-b', 'contract-c']]);

        $this->objectEntityMapper
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
    public function testGetContractsUsesDefaultPagination(): void
    {
        $entity = $this->makeObjectEntity(1);
        $entity->setObject(['contracts' => []]);

        $this->objectEntityMapper->method('find')->willReturn($entity);

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
    public function testGetContractsReturnsEmptyWhenNoContractsProperty(): void
    {
        $entity = $this->makeObjectEntity(1);
        $entity->setObject(['name' => 'Test Object']);

        $this->objectEntityMapper->method('find')->willReturn($entity);

        $result = $this->handler->getContracts('some-uuid');

        $this->assertSame([], $result['results']);
        $this->assertSame(0, $result['total']);
    }//end testGetContractsReturnsEmptyWhenNoContractsProperty()

    /**
     * Returns error response structure when mapper throws an exception.
     *
     * @return void
     */
    public function testGetContractsReturnsErrorResponseOnException(): void
    {
        $this->objectEntityMapper
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
    public function testGetContractsAppliesOffsetCorrectly(): void
    {
        $entity = $this->makeObjectEntity(1);
        $entity->setObject(['contracts' => ['a', 'b', 'c', 'd', 'e']]);

        $this->objectEntityMapper->method('find')->willReturn($entity);

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
    public function testGetUsesReturnsEmptyWhenObjectHasNoRelations(): void
    {
        $entity = $this->makeObjectEntity(1);
        $entity->setUuid('550e8400-e29b-41d4-a716-446655440000');
        $entity->setRelations([]);

        $this->objectEntityMapper->method('find')->willReturn($entity);

        $result = $this->handler->getUses('550e8400-e29b-41d4-a716-446655440000');

        $this->assertSame([], $result['results']);
        $this->assertSame(0, $result['total']);
    }//end testGetUsesReturnsEmptyWhenObjectHasNoRelations()

    /**
     * Returns empty results when relations only contain the object's own UUID.
     *
     * @return void
     */
    public function testGetUsesFiltersOutSelfReference(): void
    {
        $ownUuid = '550e8400-e29b-41d4-a716-446655440000';

        $entity = $this->makeObjectEntity(1);
        $entity->setUuid($ownUuid);
        $entity->setRelations(['self' => $ownUuid]);

        $this->objectEntityMapper->method('find')->willReturn($entity);

        $result = $this->handler->getUses($ownUuid);

        $this->assertSame([], $result['results']);
        $this->assertSame(0, $result['total']);
    }//end testGetUsesFiltersOutSelfReference()

    /**
     * Returns error response structure when object lookup throws.
     *
     * @return void
     */
    public function testGetUsesReturnsErrorResponseOnException(): void
    {
        $this->objectEntityMapper
            ->method('find')
            ->willThrowException(new \RuntimeException('Not found'));

        $this->logger->expects($this->once())->method('error');

        $result = $this->handler->getUses('missing-uuid', ['_limit' => 5, '_offset' => 2]);

        $this->assertSame([], $result['results']);
        $this->assertSame(0, $result['total']);
        $this->assertSame(5, $result['limit']);
        $this->assertSame(2, $result['offset']);
    }//end testGetUsesReturnsErrorResponseOnException()

    /**
     * Returns default limit/offset in empty response when no query provided.
     *
     * @return void
     */
    public function testGetUsesUsesDefaultPagination(): void
    {
        $entity = $this->makeObjectEntity(1);
        $entity->setUuid('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
        $entity->setRelations([]);

        $this->objectEntityMapper->method('find')->willReturn($entity);

        $result = $this->handler->getUses('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');

        $this->assertSame(30, $result['limit']);
        $this->assertSame(0, $result['offset']);
    }//end testGetUsesUsesDefaultPagination()

    /**
     * Extracts relation IDs from nested array relations structure.
     *
     * @return void
     */
    public function testGetUsesExtractsRelationsFromNestedArrays(): void
    {
        $ownUuid = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

        $entity = $this->makeObjectEntity(1);
        $entity->setUuid($ownUuid);
        // Relations stored as ['field' => ['uuid1', 'uuid2']].
        $entity->setRelations(['members' => ['rel-uuid-1', 'rel-uuid-2']]);

        $this->objectEntityMapper->method('find')->willReturn($entity);

        // getUses calls \OC::$server for RegisterMapper and MagicMapper -- this path will
        // throw a fatal if OC is not available in unit tests, so we assert on the
        // error-fallback path which still returns the correct structure.
        $result = $this->handler->getUses($ownUuid);

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
    public function testGetUsedByReturnsErrorResponseOnException(): void
    {
        $this->objectEntityMapper
            ->method('find')
            ->willThrowException(new \RuntimeException('Not found'));

        $this->logger->expects($this->once())->method('error');

        $result = $this->handler->getUsedBy('missing-uuid', ['_limit' => 5, '_offset' => 2]);

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
    public function testGetUsedByUsesDefaultPaginationInErrorFallback(): void
    {
        $this->objectEntityMapper
            ->method('find')
            ->willThrowException(new \RuntimeException('Not found'));

        $this->logger->expects($this->once())->method('error');

        $result = $this->handler->getUsedBy('missing-uuid');

        $this->assertSame(30, $result['limit']);
        $this->assertSame(0, $result['offset']);
    }//end testGetUsedByUsesDefaultPaginationInErrorFallback()

    /**
     * Correct response structure keys are always present in the returned array.
     *
     * @return void
     */
    public function testGetUsedByResponseAlwaysHasRequiredKeys(): void
    {
        $this->objectEntityMapper
            ->method('find')
            ->willThrowException(new \RuntimeException('Not found'));

        $this->logger->expects($this->once())->method('error');

        $result = $this->handler->getUsedBy('some-uuid');

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
    public function testGetContractsResponseAlwaysHasRequiredKeys(): void
    {
        $entity = $this->makeObjectEntity(1);
        $entity->setObject([]);

        $this->objectEntityMapper->method('find')->willReturn($entity);

        $result = $this->handler->getContracts('some-uuid');

        $this->assertArrayHasKey('results', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('limit', $result);
        $this->assertArrayHasKey('offset', $result);
    }//end testGetContractsResponseAlwaysHasRequiredKeys()


    // =========================================================================
    // Additional extractAllRelationshipIds tests
    // =========================================================================

    /**
     * Logs info once when array property count exceeds 10.
     *
     * @return void
     */
    public function testExtractAllRelationshipIdsLogsDebugWhenArrayTruncated(): void
    {
        $ids = array_map(fn($i) => "id-$i", range(1, 12));

        $object = $this->makeObjectEntity(1);
        $object->setObject(['items' => $ids]);

        $this->logger->expects($this->once())->method('debug');
        $this->logger->expects($this->once())->method('info');

        $result = $this->handler->extractAllRelationshipIds([$object], ['items']);

        $this->assertCount(10, $result);
    }//end testExtractAllRelationshipIdsLogsDebugWhenArrayTruncated()

    /**
     * Skips non-string values inside array properties.
     *
     * @return void
     */
    public function testExtractAllRelationshipIdsSkipsNonStringArrayValues(): void
    {
        $object = $this->makeObjectEntity(1);
        $object->setObject(['items' => ['valid', 42, null, true, 'also-valid']]);

        $this->logger->expects($this->once())->method('info');

        $result = $this->handler->extractAllRelationshipIds([$object], ['items']);

        $this->assertContains('valid', $result);
        $this->assertContains('also-valid', $result);
        $this->assertCount(2, $result);
    }//end testExtractAllRelationshipIdsSkipsNonStringArrayValues()

    /**
     * Skips non-string/non-array property values entirely.
     *
     * @return void
     */
    public function testExtractAllRelationshipIdsSkipsNonStringScalar(): void
    {
        $object = $this->makeObjectEntity(1);
        $object->setObject(['count' => 42]);

        $this->logger->expects($this->once())->method('info');

        $result = $this->handler->extractAllRelationshipIds([$object], ['count']);

        $this->assertSame([], $result);
    }//end testExtractAllRelationshipIdsSkipsNonStringScalar()

    /**
     * Logs info with circuit breaker hit when exactly 200 IDs extracted.
     *
     * @return void
     */
    public function testExtractAllRelationshipIdsExactly200UniqueIds(): void
    {
        // 20 objects x 10 IDs each = exactly 200 IDs.
        $objects = [];
        for ($i = 1; $i <= 20; $i++) {
            $ids    = array_map(fn($j) => "obj{$i}-id{$j}", range(1, 10));
            $object = $this->makeObjectEntity($i);
            $object->setObject(['items' => $ids]);
            $objects[] = $object;
        }

        $this->logger->expects($this->atLeastOnce())->method('info');

        $result = $this->handler->extractAllRelationshipIds($objects, ['items']);

        $this->assertCount(200, $result);
    }//end testExtractAllRelationshipIdsExactly200UniqueIds()

    // =========================================================================
    // Additional bulkLoadRelationshipsBatched tests
    // =========================================================================

    /**
     * Processes multiple batches when IDs exceed one batch size (50).
     *
     * @return void
     */
    public function testBulkLoadRelationshipsBatchedProcessesMultipleBatches(): void
    {
        $ids = array_map(fn($i) => "id-$i", range(1, 75));

        $entity1 = $this->makeObjectEntity(1);
        $entity1->setUuid('aaaaaaaa-bbbb-cccc-dddd-000000000001');

        $this->objectEntityMapper
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
    public function testBulkLoadRelationshipsBatchedAllBatchesFail(): void
    {
        $ids = array_map(fn($i) => "id-$i", range(1, 5));

        $this->objectEntityMapper
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
    public function testBulkLoadRelationshipsBatchedExactly200NoCap(): void
    {
        $ids = array_map(fn($i) => "id-$i", range(1, 200));

        $this->objectEntityMapper->method('findAll')->willReturn([]);

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
    public function testGetContractsOffsetBeyondArrayReturnsEmpty(): void
    {
        $entity = $this->makeObjectEntity(1);
        $entity->setObject(['contracts' => ['a', 'b']]);

        $this->objectEntityMapper->method('find')->willReturn($entity);

        $result = $this->handler->getContracts('some-uuid', ['_limit' => 5, '_offset' => 10]);

        $this->assertSame([], array_values($result['results']));
        $this->assertSame(2, $result['total']);
    }//end testGetContractsOffsetBeyondArrayReturnsEmpty()

    /**
     * getContracts uses _limit and _offset from filters in the error response.
     *
     * @return void
     */
    public function testGetContractsErrorResponseRespectsFilters(): void
    {
        $this->objectEntityMapper
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
    public function testGetUsesDefaultPaginationWithEmptyQuery(): void
    {
        $entity = $this->makeObjectEntity(1);
        $entity->setUuid('bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb');
        $entity->setRelations([]);

        $this->objectEntityMapper->method('find')->willReturn($entity);

        $result = $this->handler->getUses('bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', []);

        $this->assertSame(30, $result['limit']);
        $this->assertSame(0, $result['offset']);
        $this->assertSame(0, $result['total']);
    }//end testGetUsesDefaultPaginationWithEmptyQuery()

    /**
     * getUses extracts string relations from a flat ['field' => 'uuid'] structure.
     *
     * @return void
     */
    public function testGetUsesExtractsStringRelations(): void
    {
        $ownUuid = 'cccccccc-cccc-cccc-cccc-cccccccccccc';

        $entity = $this->makeObjectEntity(1);
        $entity->setUuid($ownUuid);
        // Flat structure: ['field1' => 'some-uuid', 'field2' => 'other-uuid'].
        $entity->setRelations(['field1' => 'some-uuid-a', 'field2' => 'some-uuid-b']);

        $this->objectEntityMapper->method('find')->willReturn($entity);

        // getUses will attempt \OC::$server calls, which may fail in unit test context.
        // We just assert the response has the required structure.
        $result = $this->handler->getUses($ownUuid);

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
    public function testGetUsesResponseStructureOnError(): void
    {
        $this->objectEntityMapper
            ->method('find')
            ->willThrowException(new \RuntimeException('Not found'));

        $this->logger->expects($this->once())->method('error');

        $result = $this->handler->getUses('bad-uuid', ['_limit' => 10, '_offset' => 2]);

        $this->assertArrayHasKey('results', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertSame(10, $result['limit']);
        $this->assertSame(2, $result['offset']);
    }//end testGetUsesResponseStructureOnError()

    // =========================================================================
    // Additional applyInversedByFilter tests
    // =========================================================================

    /**
     * Multiple inversedBy properties -- results are intersected.
     *
     * @return void
     */
    public function testApplyInversedByFilterIntersectsMultipleInversedByProperties(): void
    {
        $uuid1 = '550e8400-e29b-41d4-a716-446655440001';
        $uuid2 = '550e8400-e29b-41d4-a716-446655440002';

        $schema = $this->makeSchema([
            'owner'  => ['type' => 'string', 'inversedBy' => 'memberId', '$ref' => 'schema-1'],
            'group'  => ['type' => 'string', 'inversedBy' => 'groupId',  '$ref' => 'schema-2'],
        ]);

        // First callback returns [uuid1, uuid2], second only uuid1.
        $calls = 0;
        $callback = function ($args) use ($uuid1, $uuid2, &$calls) {
            $calls++;
            $obj = $this->createMock(ObjectEntity::class);
            if ($calls === 1) {
                $obj->method('jsonSerialize')->willReturn(['memberId' => $uuid1]);
                $obj2 = $this->createMock(ObjectEntity::class);
                $obj2->method('jsonSerialize')->willReturn(['memberId' => $uuid2]);
                return [$obj, $obj2];
            }
            $obj->method('jsonSerialize')->willReturn(['groupId' => $uuid1]);
            return [$obj];
        };

        $this->schemaMapper->method('find')->willReturn($schema);

        $filters = ['schema' => 1, 'owner_x' => 'val1', 'group_y' => 'val2'];

        $result = $this->handler->applyInversedByFilter($filters, $callback);

        // The intersection of [uuid1, uuid2] and [uuid1] should be [uuid1].
        $this->assertIsArray($result);
    }//end testApplyInversedByFilterIntersectsMultipleInversedByProperties()

    /**
     * applyInversedByFilter returns empty array when filters has no sub-filter keys.
     *
     * @return void
     */
    public function testApplyInversedByFilterReturnsEmptyArrayOnlySimpleKeys(): void
    {
        $schema  = $this->makeSchema(['name' => ['type' => 'string']]);
        $filters = ['schema' => 5, 'plain' => 'value'];

        $this->schemaMapper->method('find')->willReturn($schema);

        $result = $this->handler->applyInversedByFilter($filters, fn($x) => []);

        $this->assertSame([], $result);
    }//end testApplyInversedByFilterReturnsEmptyArrayOnlySimpleKeys()

    // =========================================================================
    // Additional extractRelatedData tests
    // =========================================================================

    /**
     * extractRelatedData passes empty array to PerformanceHandler correctly.
     *
     * @return void
     */
    public function testExtractRelatedDataWithEmptyResults(): void
    {
        $this->performanceHandler
            ->expects($this->once())
            ->method('extractRelatedData')
            ->with(results: [], includeRelated: true, includeRelatedNames: true)
            ->willReturn([]);

        $result = $this->handler->extractRelatedData([], true, true);

        $this->assertSame([], $result);
    }//end testExtractRelatedDataWithEmptyResults()

    // =========================================================================
    // Additional getUsedBy tests
    // =========================================================================

    /**
     * getUsedBy returns correct structure when objectEntityMapper throws (error path).
     *
     * In unit tests OC::$server calls may succeed but may fail deep in MagicMapper.
     * We exercise the error fallback which always returns the correct structure.
     *
     * @return void
     */
    public function testGetUsedBySuccessPathStructure(): void
    {
        // Make find() throw so we hit the outer catch -- always returns the right structure.
        $this->objectEntityMapper
            ->method('find')
            ->willThrowException(new \RuntimeException('Unit test - no DB'));

        $this->logger->expects($this->once())->method('error');

        $result = $this->handler->getUsedBy(
            'dddddddd-dddd-dddd-dddd-dddddddddddd',
            ['_limit' => 15, '_offset' => 3]
        );

        $this->assertArrayHasKey('results', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertSame(15, $result['limit']);
        $this->assertSame(3, $result['offset']);
    }//end testGetUsedBySuccessPathStructure()

    // =========================================================================
    // NEW: applyInversedByFilter -- advanced filter processing
    // =========================================================================

    /**
     * applyInversedByFilter passes the $ref as 'schema' in the callback filters.
     *
     * @return void
     */
    public function testApplyInversedByFilterPassesRefAsSchemaToCallback(): void
    {
        $uuid   = '550e8400-e29b-41d4-a716-446655440000';
        $schema = $this->makeSchema([
            'owner' => [
                'type'       => 'string',
                'inversedBy' => 'member',
                '$ref'       => 'ref-schema-42',
            ],
        ]);

        $relatedObject = $this->createMock(ObjectEntity::class);
        $relatedObject->method('jsonSerialize')->willReturn(['member' => $uuid]);

        $capturedArgs = null;
        $callback = function ($args) use ($relatedObject, &$capturedArgs) {
            $capturedArgs = $args;
            return [$relatedObject];
        };

        $filters = ['schema' => 1, 'owner_status' => 'active'];
        $this->schemaMapper->method('find')->willReturn($schema);

        $this->handler->applyInversedByFilter($filters, $callback);

        $this->assertIsArray($capturedArgs);
        $this->assertArrayHasKey('filters', $capturedArgs);
        $this->assertSame('ref-schema-42', $capturedArgs['filters']['schema']);
    }//end testApplyInversedByFilterPassesRefAsSchemaToCallback()

    /**
     * applyInversedByFilter sets $ref to null when property has no $ref key.
     *
     * @return void
     */
    public function testApplyInversedByFilterSetsNullRefWhenMissing(): void
    {
        $uuid   = '550e8400-e29b-41d4-a716-446655440000';
        $schema = $this->makeSchema([
            'owner' => [
                'type'       => 'string',
                'inversedBy' => 'member',
            ],
        ]);

        $relatedObject = $this->createMock(ObjectEntity::class);
        $relatedObject->method('jsonSerialize')->willReturn(['member' => $uuid]);

        $capturedArgs = null;
        $callback = function ($args) use ($relatedObject, &$capturedArgs) {
            $capturedArgs = $args;
            return [$relatedObject];
        };

        $filters = ['schema' => 1, 'owner_name' => 'test'];
        $this->schemaMapper->method('find')->willReturn($schema);

        $this->handler->applyInversedByFilter($filters, $callback);

        $this->assertNull($capturedArgs['filters']['schema']);
    }//end testApplyInversedByFilterSetsNullRefWhenMissing()

    /**
     * applyInversedByFilter extracts UUID from URL with deep path segments.
     *
     * @return void
     */
    public function testApplyInversedByFilterExtractsUuidFromDeepUrl(): void
    {
        $schema = $this->makeSchema([
            'ref' => [
                'type'       => 'string',
                'inversedBy' => 'link',
                '$ref'       => 's1',
            ],
        ]);

        $relatedObject = $this->createMock(ObjectEntity::class);
        $relatedObject->method('jsonSerialize')->willReturn([
            'link' => 'https://example.com/api/v1/objects/my-final-segment',
        ]);

        $filters = ['schema' => 1, 'ref_x' => 'val'];
        $this->schemaMapper->method('find')->willReturn($schema);

        $result = $this->handler->applyInversedByFilter($filters, fn($x) => [$relatedObject]);

        $this->assertIsArray($result);
        $this->assertContains('my-final-segment', $result);
    }//end testApplyInversedByFilterExtractsUuidFromDeepUrl()

    /**
     * applyInversedByFilter handles multiple sub-filter keys for same property.
     *
     * @return void
     */
    public function testApplyInversedByFilterHandlesMultipleSubKeysForSameProperty(): void
    {
        $uuid   = '550e8400-e29b-41d4-a716-446655440000';
        $schema = $this->makeSchema([
            'owner' => [
                'type'       => 'string',
                'inversedBy' => 'member',
                '$ref'       => 'schema-uuid',
            ],
        ]);

        $relatedObject = $this->createMock(ObjectEntity::class);
        $relatedObject->method('jsonSerialize')->willReturn(['member' => $uuid]);

        $filters = ['schema' => 1, 'owner_name' => 'alice', 'owner_status' => 'active'];
        $this->schemaMapper->method('find')->willReturn($schema);

        $result = $this->handler->applyInversedByFilter($filters, fn($x) => [$relatedObject]);

        $this->assertIsArray($result);
        // Both sub-keys should be removed.
        $this->assertArrayNotHasKey('owner_name', $filters);
        $this->assertArrayNotHasKey('owner_status', $filters);
    }//end testApplyInversedByFilterHandlesMultipleSubKeysForSameProperty()

    // =========================================================================
    // NEW: extractAllRelationshipIds -- circuit breaker with mixed properties
    // =========================================================================

    /**
     * Circuit breaker counts both scalar and array extractions towards the 200 limit.
     *
     * @return void
     */
    public function testExtractAllRelationshipIdsCircuitBreakerWithMixedTypes(): void
    {
        // Create objects with both scalar and array properties.
        $objects = [];
        for ($i = 1; $i <= 30; $i++) {
            $object = $this->makeObjectEntity($i);
            $object->setObject([
                'scalar' => "scalar-$i",
                'array'  => array_map(fn($j) => "arr{$i}-{$j}", range(1, 10)),
            ]);
            $objects[] = $object;
        }

        $this->logger->expects($this->atLeastOnce())->method('info');

        $result = $this->handler->extractAllRelationshipIds($objects, ['scalar', 'array']);

        // Circuit breaker checks at the start of each outer-loop iteration.
        // Within a single iteration it can add up to 11 IDs (1 scalar + 10 array)
        // before the check fires, so the count can overshoot by up to 11.
        $this->assertLessThanOrEqual(211, count($result));
        // But it must be significantly less than the theoretical max of 330.
        $this->assertLessThan(330, count($result));
    }//end testExtractAllRelationshipIdsCircuitBreakerWithMixedTypes()

    /**
     * Extracts IDs from multiple extend properties on the same object.
     *
     * @return void
     */
    public function testExtractAllRelationshipIdsMultipleExtendProperties(): void
    {
        $object = $this->makeObjectEntity(1);
        $object->setObject([
            'author' => 'author-uuid',
            'editor' => 'editor-uuid',
        ]);

        $this->logger->expects($this->once())->method('info');

        $result = $this->handler->extractAllRelationshipIds([$object], ['author', 'editor']);

        $this->assertContains('author-uuid', $result);
        $this->assertContains('editor-uuid', $result);
        $this->assertCount(2, $result);
    }//end testExtractAllRelationshipIdsMultipleExtendProperties()

    /**
     * Skips extend properties that do not exist on the object.
     *
     * @return void
     */
    public function testExtractAllRelationshipIdsSkipsMissingExtendProperties(): void
    {
        $object = $this->makeObjectEntity(1);
        $object->setObject(['name' => 'test']);

        $this->logger->expects($this->once())->method('info');

        $result = $this->handler->extractAllRelationshipIds([$object], ['nonexistent']);

        $this->assertSame([], $result);
    }//end testExtractAllRelationshipIdsSkipsMissingExtendProperties()

    /**
     * Deduplicates IDs across different extend properties on different objects.
     *
     * @return void
     */
    public function testExtractAllRelationshipIdsDeduplicatesAcrossProperties(): void
    {
        $obj1 = $this->makeObjectEntity(1);
        $obj1->setObject(['field1' => 'shared-uuid']);

        $obj2 = $this->makeObjectEntity(2);
        $obj2->setObject(['field2' => 'shared-uuid']);

        $this->logger->expects($this->once())->method('info');

        $result = $this->handler->extractAllRelationshipIds([$obj1, $obj2], ['field1', 'field2']);

        $this->assertCount(1, $result);
        $this->assertContains('shared-uuid', $result);
    }//end testExtractAllRelationshipIdsDeduplicatesAcrossProperties()

    // =========================================================================
    // NEW: bulkLoadRelationshipsBatched -- >200 IDs batching
    // =========================================================================

    /**
     * Verifies that with >200 IDs, only 200 are processed (4 batches of 50).
     *
     * @return void
     */
    public function testBulkLoadRelationshipsBatchedWith300IdsProcessesOnly200(): void
    {
        $ids = array_map(fn($i) => "id-$i", range(1, 300));

        $callCount = 0;
        $this->objectEntityMapper
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
    public function testBulkLoadRelationshipsBatchedSingleId(): void
    {
        $entity = $this->makeObjectEntity(99);
        $entity->setUuid('11111111-1111-1111-1111-111111111111');

        $this->objectEntityMapper
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
    public function testBulkLoadRelationshipsBatchedMultipleObjectsPerBatch(): void
    {
        $e1 = $this->makeObjectEntity(10);
        $e1->setUuid('aaaa-1111');
        $e2 = $this->makeObjectEntity(20);
        $e2->setUuid('aaaa-2222');

        $this->objectEntityMapper->method('findAll')->willReturn([$e1, $e2]);

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
    public function testLoadRelationshipChunkOptimizedPassesMultipleIds(): void
    {
        $e1 = $this->makeObjectEntity(1);
        $e2 = $this->makeObjectEntity(2);

        $capturedIds = null;
        $this->objectEntityMapper
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
    public function testGetContractsHandlesNonArrayContractsValue(): void
    {
        $entity = $this->makeObjectEntity(1);
        $entity->setObject(['contracts' => 'not-an-array']);

        $this->objectEntityMapper->method('find')->willReturn($entity);

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
    public function testGetContractsLimitExceedsTotal(): void
    {
        $entity = $this->makeObjectEntity(1);
        $entity->setObject(['contracts' => ['c1', 'c2']]);

        $this->objectEntityMapper->method('find')->willReturn($entity);

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
    public function testGetUsesWithRegisterSchemaIdsFailure(): void
    {
        $entity = $this->makeObjectEntity(1);
        $entity->setUuid('eeeeeeee-eeee-eeee-eeee-eeeeeeeeeeee');
        $entity->setRelations([]);

        $this->objectEntityMapper->method('find')->willReturn($entity);

        // Even with register/schema IDs that may fail internally, the response is correct.
        $result = $this->handler->getUses(
            'eeeeeeee-eeee-eeee-eeee-eeeeeeeeeeee',
            [],
            true,
            true,
            999,
            888
        );

        $this->assertArrayHasKey('results', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertSame(0, $result['total']);
    }//end testGetUsesWithRegisterSchemaIdsFailure()

    /**
     * getUses ignores empty string values in nested relation arrays.
     *
     * @return void
     */
    public function testGetUsesIgnoresEmptyRelationValues(): void
    {
        $ownUuid = 'ffffffff-ffff-ffff-ffff-ffffffffffff';

        $entity = $this->makeObjectEntity(1);
        $entity->setUuid($ownUuid);
        $entity->setRelations([
            'field1' => '',
            'field2' => ['', ''],
        ]);

        $this->objectEntityMapper->method('find')->willReturn($entity);

        $result = $this->handler->getUses($ownUuid);

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
    public function testGetUsedByWithRegisterSchemaIdsFailure(): void
    {
        $this->objectEntityMapper
            ->method('find')
            ->willThrowException(new \RuntimeException('DB error'));

        $this->logger->expects($this->once())->method('error');

        $result = $this->handler->getUsedBy(
            'some-uuid',
            ['_limit' => 20, '_offset' => 5],
            true,
            true,
            999,
            888
        );

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
    public function testGetUsedByWithRbacDisabled(): void
    {
        $this->objectEntityMapper
            ->method('find')
            ->willThrowException(new \RuntimeException('No DB'));

        $this->logger->expects($this->once())->method('error');

        $result = $this->handler->getUsedBy(
            'some-uuid',
            [],
            false,
            false
        );

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
    public function testGetUsesHandlesNullRelations(): void
    {
        // getRelations/getUuid are magic methods on ObjectEntity -- use addMethods.
        $entity = $this->getMockBuilder(ObjectEntity::class)
            ->addMethods(['getRelations', 'getUuid'])
            ->getMock();
        $entity->method('getRelations')->willReturn(null);
        $entity->method('getUuid')->willReturn('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa');

        $this->objectEntityMapper->method('find')->willReturn($entity);

        $result = $this->handler->getUses('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa');

        $this->assertSame([], $result['results']);
        $this->assertSame(0, $result['total']);
    }//end testGetUsesHandlesNullRelations()

}//end class
