<?php

/**
 * RelationHandler Deep Coverage Tests
 *
 * Tests targeting uncovered lines in RelationHandler:
 * - extractRelatedData delegation
 * - bulkLoadRelationshipsBatched (empty, cap at 200, batch exception)
 * - loadRelationshipChunkOptimized (empty, exception)
 * - getContracts (success, pagination, exception)
 * - filterByRbac (admin, non-object, null schema, permission check)
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\RelationHandler;
use OCA\OpenRegister\Service\Object\RelationHandlerStores;
use OCA\OpenRegister\Service\Object\RelatedReadFilter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Deep coverage tests for RelationHandler
 */
class RelationHandlerDeepTest extends TestCase {

	private RelationHandler $handler;

	private MockObject|MagicMapper $objectMapper;

	private MockObject|SchemaMapper $schemaMapper;

	private MockObject|MagicRbacHandler $rbacHandler;

	private MockObject|LoggerInterface $logger;

	private MockObject|RegisterMapper $registerMapper;

	/**
	 * Set up test fixtures
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objectMapper = $this->createMock(MagicMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->rbacHandler = $this->createMock(MagicRbacHandler::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);

		$this->handler = new RelationHandler(
			new RelationHandlerStores($this->objectMapper, $this->schemaMapper, $this->registerMapper),
			$this->rbacHandler,
			$this->logger
		);

	}//end setUp()

	// =========================================================================
	// extractRelatedData
	// =========================================================================

	// =========================================================================
	// bulkLoadRelationshipsBatched
	// =========================================================================

	/**
	 * Test bulkLoadRelationshipsBatched with empty input
	 *
	 * @return void
	 */
	public function testBulkLoadRelationshipsBatchedEmpty(): void {
		$result = $this->handler->bulkLoadRelationshipsBatched([]);
		$this->assertEquals([], $result);

	}//end testBulkLoadRelationshipsBatchedEmpty()

	/**
	 * Test bulkLoadRelationshipsBatched caps at 200
	 *
	 * @return void
	 */
	public function testBulkLoadRelationshipsBatchedCap(): void {
		$ids = [];
		for ($i = 0; $i < 300; $i++) {
			$ids[] = 'uuid-' . $i;
		}

		// The method will cap at 200 and then batch load.
		// 300 ids are capped at 200, loaded in four batches of 50.
		$this->objectMapper->expects($this->exactly(4))->method('findAllListed')->willReturn([]);

		$result = $this->handler->bulkLoadRelationshipsBatched($ids);
		// Should still work (returns empty because mock returns []).
		$this->assertSame([], $result);

	}//end testBulkLoadRelationshipsBatchedCap()

	/**
	 * Test bulkLoadRelationshipsBatched loads objects
	 *
	 * @return void
	 */
	public function testBulkLoadRelationshipsBatchedLoads(): void {
		$obj = new ObjectEntity();
		$obj->setUuid('uuid-1');
		// Set id via reflection since Entity id is set by mapper.
		$ref = new \ReflectionClass($obj);
		$idProp = $ref->getProperty('id');
		$idProp->setAccessible(true);
		$idProp->setValue($obj, 1);

		$this->objectMapper->method('findAllListed')->willReturn([$obj]);

		$result = $this->handler->bulkLoadRelationshipsBatched(['uuid-1']);
		$this->assertArrayHasKey('uuid-1', $result);
		$this->assertArrayHasKey(1, $result);

	}//end testBulkLoadRelationshipsBatchedLoads()

	/**
	 * Test bulkLoadRelationshipsBatched continues on batch exception
	 *
	 * @return void
	 */
	public function testBulkLoadRelationshipsBatchedBatchException(): void {
		$this->objectMapper->method('findAllListed')
			->willThrowException(new \Exception('DB error'));

		// Should not throw — continues past failed batch.
		$result = $this->handler->bulkLoadRelationshipsBatched(['uuid-1']);
		$this->assertSame([], $result);

	}//end testBulkLoadRelationshipsBatchedBatchException()

	// =========================================================================
	// loadRelationshipChunkOptimized
	// =========================================================================

	/**
	 * Test loadRelationshipChunkOptimized with empty input
	 *
	 * @return void
	 */
	public function testLoadRelationshipChunkOptimizedEmpty(): void {
		$result = $this->handler->loadRelationshipChunkOptimized([]);
		$this->assertEquals([], $result);

	}//end testLoadRelationshipChunkOptimizedEmpty()

	/**
	 * Test loadRelationshipChunkOptimized with exception
	 *
	 * @return void
	 */
	public function testLoadRelationshipChunkOptimizedException(): void {
		$this->objectMapper->method('findAllListed')
			->willThrowException(new \Exception('Query failed'));

		$result = $this->handler->loadRelationshipChunkOptimized(['uuid-1']);
		$this->assertEquals([], $result);

	}//end testLoadRelationshipChunkOptimizedException()

	/**
	 * Test loadRelationshipChunkOptimized returns objects
	 *
	 * @return void
	 */
	public function testLoadRelationshipChunkOptimizedReturns(): void {
		$obj = $this->createMock(ObjectEntity::class);
		$this->objectMapper->method('findAllListed')->willReturn([$obj]);

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
	public function testGetContractsWithContracts(): void {
		$obj = $this->createMock(ObjectEntity::class);
		$obj->method('getObject')->willReturn([
			'contracts' => ['c1', 'c2', 'c3'],
		]);

		$this->objectMapper->method('find')->willReturn($obj);

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
	public function testGetContractsWithPagination(): void {
		$obj = $this->createMock(ObjectEntity::class);
		$obj->method('getObject')->willReturn([
			'contracts' => ['c1', 'c2', 'c3', 'c4', 'c5'],
		]);

		$this->objectMapper->method('find')->willReturn($obj);

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
	public function testGetContractsEmpty(): void {
		$obj = $this->createMock(ObjectEntity::class);
		$obj->method('getObject')->willReturn([]);

		$this->objectMapper->method('find')->willReturn($obj);

		$result = $this->handler->getContracts('obj-1');
		$this->assertEquals(0, $result['total']);
		$this->assertEmpty($result['results']);

	}//end testGetContractsEmpty()

	/**
	 * Test getContracts with exception
	 *
	 * @return void
	 */
	public function testGetContractsException(): void {
		$this->objectMapper->method('find')
			->willThrowException(new \Exception('Not found'));

		$result = $this->handler->getContracts('obj-1');
		$this->assertEquals(0, $result['total']);
		$this->assertEmpty($result['results']);

	}//end testGetContractsException()

	// =========================================================================
	// RelatedReadFilter::admitted
	// =========================================================================

	/**
	 * Test filterByRbac passes all for admin
	 *
	 * @return void
	 */
	public function testFilterByRbacAdmin(): void {
		$filter = new RelatedReadFilter(rbac: $this->rbacHandler, schemas: $this->schemaMapper);

		$this->rbacHandler->method('isAdmin')->willReturn(true);

		$obj1 = $this->createMock(ObjectEntity::class);
		$obj2 = $this->createMock(ObjectEntity::class);

		$result = $filter->admitted(objects: [$obj1, $obj2]);
		$this->assertCount(2, $result);

	}//end testFilterByRbacAdmin()

	/**
	 * Test filterByRbac passes non-ObjectEntity items
	 *
	 * @return void
	 */
	public function testFilterByRbacNonObjectEntity(): void {
		$filter = new RelatedReadFilter(rbac: $this->rbacHandler, schemas: $this->schemaMapper);

		$this->rbacHandler->method('isAdmin')->willReturn(false);

		$result = $filter->admitted(objects: ['not-an-object']);
		$this->assertCount(1, $result);

	}//end testFilterByRbacNonObjectEntity()

	/**
	 * Test filterByRbac passes objects with null schema
	 *
	 * @return void
	 */
	public function testFilterByRbacNullSchema(): void {
		$filter = new RelatedReadFilter(rbac: $this->rbacHandler, schemas: $this->schemaMapper);

		$this->rbacHandler->method('isAdmin')->willReturn(false);

		$obj = new ObjectEntity();
		// Schema is null by default.

		$result = $filter->admitted(objects: [$obj]);
		$this->assertCount(1, $result);

	}//end testFilterByRbacNullSchema()

	/**
	 * Test filterByRbac skips objects when schema not found
	 *
	 * @return void
	 */
	public function testFilterByRbacSchemaNotFound(): void {
		$filter = new RelatedReadFilter(rbac: $this->rbacHandler, schemas: $this->schemaMapper);

		$this->rbacHandler->method('isAdmin')->willReturn(false);

		$obj = new ObjectEntity();
		$obj->setSchema('5');

		$this->schemaMapper->method('find')
			->willThrowException(new \Exception('Not found'));

		$result = $filter->admitted(objects: [$obj]);
		$this->assertCount(0, $result);

	}//end testFilterByRbacSchemaNotFound()

	/**
	 * Test filterByRbac filters based on permission
	 *
	 * @return void
	 */
	public function testFilterByRbacFiltersOnPermission(): void {
		$filter = new RelatedReadFilter(rbac: $this->rbacHandler, schemas: $this->schemaMapper);

		$this->rbacHandler->method('isAdmin')->willReturn(false);

		$schema = new Schema();
		$this->schemaMapper->method('find')->willReturn($schema);

		$obj = new ObjectEntity();
		$obj->setSchema('1');
		$obj->setObject([]);
		$obj->setOrganisation('org-1');
		$obj->setOwner('admin');

		// First call returns true (has permission), second returns false.
		$this->rbacHandler->method('hasRowPermission')->willReturn(true);

		$result = $filter->admitted(objects: [$obj]);
		$this->assertCount(1, $result);

	}//end testFilterByRbacFiltersOnPermission()

	/**
	 * Test filterByRbac denies when no permission
	 *
	 * @return void
	 */
	public function testFilterByRbacDeniesNoPermission(): void {
		$filter = new RelatedReadFilter(rbac: $this->rbacHandler, schemas: $this->schemaMapper);

		$this->rbacHandler->method('isAdmin')->willReturn(false);

		$schema = new Schema();
		$this->schemaMapper->method('find')->willReturn($schema);

		$obj = new ObjectEntity();
		$obj->setSchema('1');
		$obj->setObject([]);
		$obj->setOrganisation('org-1');
		$obj->setOwner('admin');

		$this->rbacHandler->method('hasRowPermission')->willReturn(false);

		$result = $filter->admitted(objects: [$obj]);
		$this->assertCount(0, $result);

	}//end testFilterByRbacDeniesNoPermission()

}//end class
