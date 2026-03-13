<?php

/**
 * RelationHandler Deep Coverage Tests
 *
 * Tests targeting uncovered lines in RelationHandler:
 * - applyInversedByFilter (schema false, empty sub-filters, inversedBy, URL extraction)
 * - extractRelatedData delegation
 * - extractAllRelationshipIds (circuit breaker, array slicing, string values)
 * - bulkLoadRelationshipsBatched (empty, cap at 200, batch exception)
 * - loadRelationshipChunkOptimized (empty, exception)
 * - getContracts (success, pagination, exception)
 * - filterByRbac (admin, non-object, null schema, permission check)
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

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

/**
 * Deep coverage tests for RelationHandler
 */
class RelationHandlerDeepTest extends TestCase
{

    private RelationHandler $handler;

    private MockObject|ObjectEntityMapper $objectEntityMapper;

    private MockObject|SchemaMapper $schemaMapper;

    private MockObject|PerformanceHandler $performanceHandler;

    private MockObject|MagicRbacHandler $rbacHandler;

    private MockObject|LoggerInterface $logger;


    /**
     * Set up test fixtures
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->objectEntityMapper = $this->createMock(ObjectEntityMapper::class);
        $this->schemaMapper       = $this->createMock(SchemaMapper::class);
        $this->performanceHandler = $this->createMock(PerformanceHandler::class);
        $this->rbacHandler        = $this->createMock(MagicRbacHandler::class);
        $this->logger             = $this->createMock(LoggerInterface::class);

        $this->handler = new RelationHandler(
            $this->objectEntityMapper,
            $this->schemaMapper,
            $this->performanceHandler,
            $this->rbacHandler,
            $this->logger
        );

    }//end setUp()


    // =========================================================================
    // applyInversedByFilter
    // =========================================================================

    /**
     * Test applyInversedByFilter returns empty when schema is false
     *
     * @return void
     */
    public function testApplyInversedByFilterSchemaFalse(): void
    {
        $filters = ['schema' => false];
        $result  = $this->handler->applyInversedByFilter($filters, fn($f) => []);

        $this->assertEquals([], $result);

    }//end testApplyInversedByFilterSchemaFalse()


    /**
     * Test applyInversedByFilter returns empty when no sub-filters
     *
     * @return void
     */
    public function testApplyInversedByFilterNoSubFilters(): void
    {
        $schema = $this->createMock(Schema::class);
        $schema->method('getProperties')->willReturn([]);

        $this->schemaMapper->method('find')->willReturn($schema);

        $filters = ['schema' => 1, 'name' => 'test'];
        $result  = $this->handler->applyInversedByFilter($filters, fn($f) => []);

        $this->assertEquals([], $result);

    }//end testApplyInversedByFilterNoSubFilters()


    // =========================================================================
    // extractRelatedData
    // =========================================================================

    /**
     * Test extractRelatedData delegates to PerformanceHandler
     *
     * @return void
     */
    public function testExtractRelatedDataDelegates(): void
    {
        $expected = ['related' => ['uuid1', 'uuid2']];
        $this->performanceHandler->expects($this->once())
            ->method('extractRelatedData')
            ->with(['result1'], true, false)
            ->willReturn($expected);

        $result = $this->handler->extractRelatedData(['result1'], true, false);
        $this->assertEquals($expected, $result);

    }//end testExtractRelatedDataDelegates()


    // =========================================================================
    // extractAllRelationshipIds
    // =========================================================================

    /**
     * Test extractAllRelationshipIds with empty objects
     *
     * @return void
     */
    public function testExtractAllRelationshipIdsEmpty(): void
    {
        $result = $this->handler->extractAllRelationshipIds([], ['field1']);
        $this->assertEquals([], $result);

    }//end testExtractAllRelationshipIdsEmpty()


    /**
     * Test extractAllRelationshipIds with string value
     *
     * @return void
     */
    public function testExtractAllRelationshipIdsStringValue(): void
    {
        $obj = $this->createMock(ObjectEntity::class);
        $obj->method('getObject')->willReturn(['author' => 'uuid-123']);

        $result = $this->handler->extractAllRelationshipIds([$obj], ['author']);
        $this->assertContains('uuid-123', $result);

    }//end testExtractAllRelationshipIdsStringValue()


    /**
     * Test extractAllRelationshipIds with array value
     *
     * @return void
     */
    public function testExtractAllRelationshipIdsArrayValue(): void
    {
        $obj = $this->createMock(ObjectEntity::class);
        $obj->method('getObject')->willReturn([
            'tags' => ['uuid-1', 'uuid-2', 'uuid-3'],
        ]);

        $result = $this->handler->extractAllRelationshipIds([$obj], ['tags']);
        $this->assertContains('uuid-1', $result);
        $this->assertContains('uuid-2', $result);
        $this->assertContains('uuid-3', $result);

    }//end testExtractAllRelationshipIdsArrayValue()


    /**
     * Test extractAllRelationshipIds with more than 10 array items (limit)
     *
     * @return void
     */
    public function testExtractAllRelationshipIdsArrayLimit(): void
    {
        $ids = [];
        for ($i = 0; $i < 15; $i++) {
            $ids[] = 'uuid-'.$i;
        }

        $obj = $this->createMock(ObjectEntity::class);
        $obj->method('getObject')->willReturn(['refs' => $ids]);

        $result = $this->handler->extractAllRelationshipIds([$obj], ['refs']);
        // Max 10 per array.
        $this->assertCount(10, $result);

    }//end testExtractAllRelationshipIdsArrayLimit()


    /**
     * Test extractAllRelationshipIds respects circuit breaker (200 max)
     *
     * @return void
     */
    public function testExtractAllRelationshipIdsCircuitBreaker(): void
    {
        $objects = [];
        for ($i = 0; $i < 50; $i++) {
            $obj = $this->createMock(ObjectEntity::class);
            $ids = [];
            for ($j = 0; $j < 10; $j++) {
                $ids[] = 'uuid-'.$i.'-'.$j;
            }

            $obj->method('getObject')->willReturn(['refs' => $ids]);
            $objects[] = $obj;
        }

        $result = $this->handler->extractAllRelationshipIds($objects, ['refs']);
        // Should be capped at 200.
        $this->assertLessThanOrEqual(200, count($result));

    }//end testExtractAllRelationshipIdsCircuitBreaker()


    /**
     * Test extractAllRelationshipIds skips empty strings and non-strings
     *
     * @return void
     */
    public function testExtractAllRelationshipIdsSkipsInvalid(): void
    {
        $obj = $this->createMock(ObjectEntity::class);
        $obj->method('getObject')->willReturn([
            'refs' => ['', null, 123, 'valid-uuid'],
        ]);

        $result = $this->handler->extractAllRelationshipIds([$obj], ['refs']);
        $this->assertContains('valid-uuid', $result);
        // Empty string and non-strings should be excluded.
        $this->assertNotContains('', $result);

    }//end testExtractAllRelationshipIdsSkipsInvalid()


    /**
     * Test extractAllRelationshipIds deduplicates
     *
     * @return void
     */
    public function testExtractAllRelationshipIdsDeduplicates(): void
    {
        $obj1 = $this->createMock(ObjectEntity::class);
        $obj1->method('getObject')->willReturn(['author' => 'same-uuid']);

        $obj2 = $this->createMock(ObjectEntity::class);
        $obj2->method('getObject')->willReturn(['author' => 'same-uuid']);

        $result = $this->handler->extractAllRelationshipIds([$obj1, $obj2], ['author']);
        $this->assertCount(1, $result);

    }//end testExtractAllRelationshipIdsDeduplicates()


    /**
     * Test extractAllRelationshipIds skips missing properties
     *
     * @return void
     */
    public function testExtractAllRelationshipIdsSkipsMissing(): void
    {
        $obj = $this->createMock(ObjectEntity::class);
        $obj->method('getObject')->willReturn(['title' => 'test']);

        $result = $this->handler->extractAllRelationshipIds([$obj], ['nonexistent']);
        $this->assertEmpty($result);

    }//end testExtractAllRelationshipIdsSkipsMissing()


    // =========================================================================
    // bulkLoadRelationshipsBatched
    // =========================================================================

    /**
     * Test bulkLoadRelationshipsBatched with empty input
     *
     * @return void
     */
    public function testBulkLoadRelationshipsBatchedEmpty(): void
    {
        $result = $this->handler->bulkLoadRelationshipsBatched([]);
        $this->assertEquals([], $result);

    }//end testBulkLoadRelationshipsBatchedEmpty()


    /**
     * Test bulkLoadRelationshipsBatched caps at 200
     *
     * @return void
     */
    public function testBulkLoadRelationshipsBatchedCap(): void
    {
        $ids = [];
        for ($i = 0; $i < 300; $i++) {
            $ids[] = 'uuid-'.$i;
        }

        // The method will cap at 200 and then batch load.
        $this->objectEntityMapper->method('findAll')->willReturn([]);

        $result = $this->handler->bulkLoadRelationshipsBatched($ids);
        // Should still work (returns empty because mock returns []).
        $this->assertIsArray($result);

    }//end testBulkLoadRelationshipsBatchedCap()


    /**
     * Test bulkLoadRelationshipsBatched loads objects
     *
     * @return void
     */
    public function testBulkLoadRelationshipsBatchedLoads(): void
    {
        $obj = $this->createMock(ObjectEntity::class);
        $obj->method('getUuid')->willReturn('uuid-1');
        $obj->method('getId')->willReturn(1);

        $this->objectEntityMapper->method('findAll')->willReturn([$obj]);

        $result = $this->handler->bulkLoadRelationshipsBatched(['uuid-1']);
        $this->assertArrayHasKey('uuid-1', $result);
        $this->assertArrayHasKey(1, $result);

    }//end testBulkLoadRelationshipsBatchedLoads()


    /**
     * Test bulkLoadRelationshipsBatched continues on batch exception
     *
     * @return void
     */
    public function testBulkLoadRelationshipsBatchedBatchException(): void
    {
        $this->objectEntityMapper->method('findAll')
            ->willThrowException(new \Exception('DB error'));

        // Should not throw — continues past failed batch.
        $result = $this->handler->bulkLoadRelationshipsBatched(['uuid-1']);
        $this->assertIsArray($result);

    }//end testBulkLoadRelationshipsBatchedBatchException()


    // =========================================================================
    // loadRelationshipChunkOptimized
    // =========================================================================

    /**
     * Test loadRelationshipChunkOptimized with empty input
     *
     * @return void
     */
    public function testLoadRelationshipChunkOptimizedEmpty(): void
    {
        $result = $this->handler->loadRelationshipChunkOptimized([]);
        $this->assertEquals([], $result);

    }//end testLoadRelationshipChunkOptimizedEmpty()


    /**
     * Test loadRelationshipChunkOptimized with exception
     *
     * @return void
     */
    public function testLoadRelationshipChunkOptimizedException(): void
    {
        $this->objectEntityMapper->method('findAll')
            ->willThrowException(new \Exception('Query failed'));

        $result = $this->handler->loadRelationshipChunkOptimized(['uuid-1']);
        $this->assertEquals([], $result);

    }//end testLoadRelationshipChunkOptimizedException()


    /**
     * Test loadRelationshipChunkOptimized returns objects
     *
     * @return void
     */
    public function testLoadRelationshipChunkOptimizedReturns(): void
    {
        $obj = $this->createMock(ObjectEntity::class);
        $this->objectEntityMapper->method('findAll')->willReturn([$obj]);

        $result = $this->handler->loadRelationshipChunkOptimized(['uuid-1']);
        $this->assertCount(1, $result);

    }//end testLoadRelationshipChunkOptimizedReturns()


    // =========================================================================
    // getContracts
    // =========================================================================

    /**
     * Test getContracts with contracts in object data
     *
     * @return void
     */
    public function testGetContractsWithContracts(): void
    {
        $obj = $this->createMock(ObjectEntity::class);
        $obj->method('getObject')->willReturn([
            'contracts' => ['c1', 'c2', 'c3'],
        ]);

        $this->objectEntityMapper->method('find')->willReturn($obj);

        $result = $this->handler->getContracts('obj-1');
        $this->assertEquals(3, $result['total']);
        $this->assertCount(3, $result['results']);
        $this->assertEquals(30, $result['limit']);
        $this->assertEquals(0, $result['offset']);

    }//end testGetContractsWithContracts()


    /**
     * Test getContracts with pagination
     *
     * @return void
     */
    public function testGetContractsWithPagination(): void
    {
        $obj = $this->createMock(ObjectEntity::class);
        $obj->method('getObject')->willReturn([
            'contracts' => ['c1', 'c2', 'c3', 'c4', 'c5'],
        ]);

        $this->objectEntityMapper->method('find')->willReturn($obj);

        $result = $this->handler->getContracts('obj-1', ['_limit' => 2, '_offset' => 1]);
        $this->assertEquals(5, $result['total']);
        $this->assertCount(2, $result['results']);
        $this->assertEquals(2, $result['limit']);
        $this->assertEquals(1, $result['offset']);

    }//end testGetContractsWithPagination()


    /**
     * Test getContracts with no contracts
     *
     * @return void
     */
    public function testGetContractsEmpty(): void
    {
        $obj = $this->createMock(ObjectEntity::class);
        $obj->method('getObject')->willReturn([]);

        $this->objectEntityMapper->method('find')->willReturn($obj);

        $result = $this->handler->getContracts('obj-1');
        $this->assertEquals(0, $result['total']);
        $this->assertEmpty($result['results']);

    }//end testGetContractsEmpty()


    /**
     * Test getContracts with exception
     *
     * @return void
     */
    public function testGetContractsException(): void
    {
        $this->objectEntityMapper->method('find')
            ->willThrowException(new \Exception('Not found'));

        $result = $this->handler->getContracts('obj-1');
        $this->assertEquals(0, $result['total']);
        $this->assertEmpty($result['results']);

    }//end testGetContractsException()


    // =========================================================================
    // filterByRbac (private)
    // =========================================================================

    /**
     * Test filterByRbac passes all for admin
     *
     * @return void
     */
    public function testFilterByRbacAdmin(): void
    {
        $method = new \ReflectionMethod(RelationHandler::class, 'filterByRbac');

        $this->rbacHandler->method('isAdmin')->willReturn(true);

        $obj1 = $this->createMock(ObjectEntity::class);
        $obj2 = $this->createMock(ObjectEntity::class);

        $result = $method->invoke($this->handler, [$obj1, $obj2]);
        $this->assertCount(2, $result);

    }//end testFilterByRbacAdmin()


    /**
     * Test filterByRbac passes non-ObjectEntity items
     *
     * @return void
     */
    public function testFilterByRbacNonObjectEntity(): void
    {
        $method = new \ReflectionMethod(RelationHandler::class, 'filterByRbac');

        $this->rbacHandler->method('isAdmin')->willReturn(false);

        $result = $method->invoke($this->handler, ['not-an-object']);
        $this->assertCount(1, $result);

    }//end testFilterByRbacNonObjectEntity()


    /**
     * Test filterByRbac passes objects with null schema
     *
     * @return void
     */
    public function testFilterByRbacNullSchema(): void
    {
        $method = new \ReflectionMethod(RelationHandler::class, 'filterByRbac');

        $this->rbacHandler->method('isAdmin')->willReturn(false);

        $obj = $this->createMock(ObjectEntity::class);
        $obj->method('getSchema')->willReturn(null);

        $result = $method->invoke($this->handler, [$obj]);
        $this->assertCount(1, $result);

    }//end testFilterByRbacNullSchema()


    /**
     * Test filterByRbac skips objects when schema not found
     *
     * @return void
     */
    public function testFilterByRbacSchemaNotFound(): void
    {
        $method = new \ReflectionMethod(RelationHandler::class, 'filterByRbac');

        $this->rbacHandler->method('isAdmin')->willReturn(false);

        $obj = $this->createMock(ObjectEntity::class);
        $obj->method('getSchema')->willReturn(5);

        $this->schemaMapper->method('find')
            ->willThrowException(new \Exception('Not found'));

        $result = $method->invoke($this->handler, [$obj]);
        $this->assertCount(0, $result);

    }//end testFilterByRbacSchemaNotFound()


    /**
     * Test filterByRbac filters based on permission
     *
     * @return void
     */
    public function testFilterByRbacFiltersOnPermission(): void
    {
        $method = new \ReflectionMethod(RelationHandler::class, 'filterByRbac');

        $this->rbacHandler->method('isAdmin')->willReturn(false);

        $schema = $this->createMock(Schema::class);
        $this->schemaMapper->method('find')->willReturn($schema);

        $obj = $this->createMock(ObjectEntity::class);
        $obj->method('getSchema')->willReturn(1);
        $obj->method('getObject')->willReturn([]);
        $obj->method('getOrganisation')->willReturn('org-1');
        $obj->method('getOwner')->willReturn('admin');

        // First call returns true (has permission), second returns false.
        $this->rbacHandler->method('hasPermission')->willReturn(true);

        $result = $method->invoke($this->handler, [$obj]);
        $this->assertCount(1, $result);

    }//end testFilterByRbacFiltersOnPermission()


    /**
     * Test filterByRbac denies when no permission
     *
     * @return void
     */
    public function testFilterByRbacDeniesNoPermission(): void
    {
        $method = new \ReflectionMethod(RelationHandler::class, 'filterByRbac');

        $this->rbacHandler->method('isAdmin')->willReturn(false);

        $schema = $this->createMock(Schema::class);
        $this->schemaMapper->method('find')->willReturn($schema);

        $obj = $this->createMock(ObjectEntity::class);
        $obj->method('getSchema')->willReturn(1);
        $obj->method('getObject')->willReturn([]);
        $obj->method('getOrganisation')->willReturn('org-1');
        $obj->method('getOwner')->willReturn('admin');

        $this->rbacHandler->method('hasPermission')->willReturn(false);

        $result = $method->invoke($this->handler, [$obj]);
        $this->assertCount(0, $result);

    }//end testFilterByRbacDeniesNoPermission()


}//end class
