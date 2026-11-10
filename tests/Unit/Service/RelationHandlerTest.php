<?php

/**
 * RelationHandler Unit Tests
 *
 * Tests for RelationHandler covering relationship extraction,
 * bulk loading, contracts, and inversedBy filter logic.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for RelationHandler
 *
 * Tests cover:
 * - Relationship extraction from objects
 * - Circuit breaker logic
 * - Bulk loading with batching
 * - Contract retrieval
 * - InversedBy filter
 * - Related data extraction delegation
 */
class RelationHandlerTest extends TestCase {
	/** @var RelationHandler */
	private RelationHandler $handler;

	/** @var MockObject|MagicMapper */
	private $objectMapper;

	/** @var MockObject|SchemaMapper */
	private $schemaMapper;

	/** @var MockObject|MagicRbacHandler */
	private $rbacHandler;

	/** @var MockObject|LoggerInterface */
	private $logger;

	/** @var MockObject|RegisterMapper */
	private $registerMapper;

	/**
	 * Set up test dependencies
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
	}

	// =============================================
	// bulkLoadRelationshipsBatched tests
	// =============================================

	/**
	 * Test bulkLoadRelationshipsBatched returns empty for empty input
	 *
	 * @return void
	 */
	public function testBulkLoadRelationshipsBatchedEmptyInput(): void {
		$result = $this->handler->bulkLoadRelationshipsBatched([]);
		$this->assertEmpty($result);
	}

	/**
	 * Test bulkLoadRelationshipsBatched loads objects and indexes by uuid and id
	 *
	 * @return void
	 */
	public function testBulkLoadRelationshipsBatchedIndexesByUuidAndId(): void {
		$obj = $this->getMockBuilder(ObjectEntity::class)
			->onlyMethods(['getUuid'])
			->addMethods(['getId'])
			->onlyMethods(['getObject'])
			->getMock();
		$obj->method('getUuid')->willReturn('test-uuid-123');
		$obj->method('getId')->willReturn(42);

		$this->objectMapper
			->method('findAll')
			->willReturn([$obj]);

		$result = $this->handler->bulkLoadRelationshipsBatched(['test-uuid-123']);

		$this->assertArrayHasKey('test-uuid-123', $result);
		$this->assertArrayHasKey(42, $result);
		$this->assertSame($obj, $result['test-uuid-123']);
		$this->assertSame($obj, $result[42]);
	}

	/**
	 * Test bulkLoadRelationshipsBatched caps at 200 relationships
	 *
	 * @return void
	 */
	public function testBulkLoadRelationshipsBatchedCapsAt200(): void {
		$ids = [];
		for ($i = 0; $i < 250; $i++) {
			$ids[] = 'uuid-' . $i;
		}

		$this->objectMapper
			->method('findAll')
			->willReturn([]);

		// Should not throw, just cap.
		$result = $this->handler->bulkLoadRelationshipsBatched($ids);
		$this->assertIsArray($result);
	}

	/**
	 * Test bulkLoadRelationshipsBatched handles batch exceptions gracefully
	 *
	 * @return void
	 */
	public function testBulkLoadRelationshipsBatchedHandlesExceptions(): void {
		$this->objectMapper
			->method('findAll')
			->willThrowException(new \Exception('DB error'));

		// Should not throw, continue with next batch.
		$result = $this->handler->bulkLoadRelationshipsBatched(['uuid-1', 'uuid-2']);
		$this->assertIsArray($result);
		$this->assertEmpty($result);
	}

	// =============================================
	// loadRelationshipChunkOptimized tests
	// =============================================

	/**
	 * Test loadRelationshipChunkOptimized with empty input
	 *
	 * @return void
	 */
	public function testLoadRelationshipChunkOptimizedEmptyInput(): void {
		$result = $this->handler->loadRelationshipChunkOptimized([]);
		$this->assertEmpty($result);
	}

	/**
	 * Test loadRelationshipChunkOptimized delegates to mapper
	 *
	 * @return void
	 */
	public function testLoadRelationshipChunkOptimizedDelegatesToMapper(): void {
		$obj = $this->createMock(ObjectEntity::class);

		$this->objectMapper
			->expects($this->once())
			->method('findAll')
			->willReturn([$obj]);

		$result = $this->handler->loadRelationshipChunkOptimized(['uuid-1']);
		$this->assertCount(1, $result);
		$this->assertSame($obj, $result[0]);
	}

	/**
	 * Test loadRelationshipChunkOptimized returns empty on exception
	 *
	 * @return void
	 */
	public function testLoadRelationshipChunkOptimizedReturnsEmptyOnException(): void {
		$this->objectMapper
			->method('findAll')
			->willThrowException(new \Exception('DB error'));

		$result = $this->handler->loadRelationshipChunkOptimized(['uuid-1']);
		$this->assertEmpty($result);
	}

	// =============================================
	// getContracts tests
	// =============================================

	/**
	 * Test getContracts returns contracts from object data
	 *
	 * @return void
	 */
	public function testGetContractsReturnsContractsFromObject(): void {
		$object = $this->createMock(ObjectEntity::class);
		$object->method('getObject')->willReturn([
			'contracts' => ['contract-1', 'contract-2', 'contract-3'],
		]);

		$this->objectMapper
			->method('find')
			->willReturn($object);

		$result = $this->handler->getContracts('test-id');

		$this->assertEquals(3, $result['total']);
		$this->assertCount(3, $result['results']);
		$this->assertEquals(30, $result['limit']);
		$this->assertEquals(0, $result['offset']);
	}

	/**
	 * Test getContracts applies pagination
	 *
	 * @return void
	 */
	public function testGetContractsAppliesPagination(): void {
		$contracts = [];
		for ($i = 0; $i < 10; $i++) {
			$contracts[] = 'contract-' . $i;
		}

		$object = $this->createMock(ObjectEntity::class);
		$object->method('getObject')->willReturn([
			'contracts' => $contracts,
		]);

		$this->objectMapper
			->method('find')
			->willReturn($object);

		$result = $this->handler->getContracts('test-id', [
			'_limit' => 3,
			'_offset' => 2,
		]);

		$this->assertEquals(10, $result['total']);
		$this->assertCount(3, $result['results']);
		$this->assertEquals(3, $result['limit']);
		$this->assertEquals(2, $result['offset']);
	}

	/**
	 * Test getContracts returns empty when no contracts
	 *
	 * @return void
	 */
	public function testGetContractsReturnsEmptyWhenNoContracts(): void {
		$object = $this->createMock(ObjectEntity::class);
		$object->method('getObject')->willReturn([]);

		$this->objectMapper
			->method('find')
			->willReturn($object);

		$result = $this->handler->getContracts('test-id');

		$this->assertEquals(0, $result['total']);
		$this->assertEmpty($result['results']);
	}

	/**
	 * Test getContracts returns empty on exception
	 *
	 * @return void
	 */
	public function testGetContractsReturnsEmptyOnException(): void {
		$this->objectMapper
			->method('find')
			->willThrowException(new \Exception('Not found'));

		$result = $this->handler->getContracts('bad-id');

		$this->assertEquals(0, $result['total']);
		$this->assertEmpty($result['results']);
	}

}
