<?php

/**
 * SaveObjects Refactored Methods Unit Tests
 *
 * Comprehensive tests for the 8 private methods extracted during Phase 1 refactoring.
 * Tests cover bulk object save operations and performance metrics.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectHandlers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectHandlers;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\SaveObjects;
use OCA\OpenRegister\Service\Object\SaveObject;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use ReflectionClass;

/**
 * Unit tests for SaveObjects refactored methods.
 *
 * Tests the 8 extracted private methods using reflection:
 * 1. createEmptyResult()
 * 2. logBulkOperationStart()
 * 3. prepareObjectsForSave()
 * 4. initializeResult()
 * 5. processObjectsInChunks()
 * 6. mergeChunkResult()
 * 7. calculatePerformanceMetrics()
 * 8. processChunk()
 */
class SaveObjectsRefactoredMethodsTest extends TestCase
{
	private SaveObjects $saveObjects;
	private ReflectionClass $reflection;

	/** @var MockObject|SaveObject */
	private $saveObject;

	/** @var MockObject|LoggerInterface */
	private $logger;

	/** @var MockObject|Register */
	private $mockRegister;

	/** @var MockObject|Schema */
	private $mockSchema;

	/**
	 * Set up test environment before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void
	{
		parent::setUp();

		// Create mocks for all dependencies.
		$this->saveObject = $this->createMock(SaveObject::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		// Create mock entities.
		$this->mockRegister = $this->createMock(Register::class);
		$this->mockSchema = $this->createMock(Schema::class);

		// Set up basic mock returns.
		$this->mockRegister->method('getId')->willReturn(1);
		$this->mockRegister->method('getSlug')->willReturn('test-register');
		$this->mockSchema->method('getId')->willReturn(1);
		$this->mockSchema->method('getSlug')->willReturn('test-schema');

		// Create SaveObjects instance.
		$this->saveObjects = new SaveObjects(
			saveObject: $this->saveObject,
			logger: $this->logger
		);

		// Set up reflection for accessing private methods.
		$this->reflection = new ReflectionClass(SaveObjects::class);
	}

	/**
	 * Helper method to invoke private methods using reflection.
	 *
	 * @param string $methodName The name of the private method.
	 * @param array  $parameters The parameters to pass to the method.
	 *
	 * @return mixed The result of the method invocation.
	 */
	private function invokePrivateMethod(string $methodName, array $parameters = []): mixed
	{
		$method = $this->reflection->getMethod($methodName);
		$method->setAccessible(true);

		return $method->invokeArgs($this->saveObjects, $parameters);
	}

	// ==================== createEmptyResult() Tests ====================

	/**
	 * Test createEmptyResult returns properly structured array.
	 *
	 * @return void
	 */
	public function testCreateEmptyResultStructure(): void
	{
		$result = $this->invokePrivateMethod(methodName: 'createEmptyResult');

		$this->assertIsArray($result, 'Result should be an array.');
		$this->assertArrayHasKey('success', $result, 'Result should have success key.');
		$this->assertArrayHasKey('failed', $result, 'Result should have failed key.');
		$this->assertArrayHasKey('errors', $result, 'Result should have errors key.');
		$this->assertArrayHasKey('stats', $result, 'Result should have stats key.');
	}

	/**
	 * Test createEmptyResult initializes empty arrays.
	 *
	 * @return void
	 */
	public function testCreateEmptyResultInitializesArrays(): void
	{
		$result = $this->invokePrivateMethod(methodName: 'createEmptyResult');

		$this->assertIsArray($result['success'], 'Success should be an array.');
		$this->assertIsArray($result['failed'], 'Failed should be an array.');
		$this->assertIsArray($result['errors'], 'Errors should be an array.');
		$this->assertEmpty($result['success'], 'Success should be empty.');
		$this->assertEmpty($result['failed'], 'Failed should be empty.');
		$this->assertEmpty($result['errors'], 'Errors should be empty.');
	}

	/**
	 * Test createEmptyResult initializes stats with zeros.
	 *
	 * @return void
	 */
	public function testCreateEmptyResultInitializesStats(): void
	{
		$result = $this->invokePrivateMethod(methodName: 'createEmptyResult');

		$this->assertIsArray($result['stats'], 'Stats should be an array.');
		$this->assertArrayHasKey('total', $result['stats'], 'Stats should have total.');
		$this->assertArrayHasKey('processed', $result['stats'], 'Stats should have processed.');
		$this->assertArrayHasKey('successful', $result['stats'], 'Stats should have successful.');
		$this->assertArrayHasKey('failed', $result['stats'], 'Stats should have failed.');
		$this->assertEquals(0, $result['stats']['total'], 'Total should be 0.');
		$this->assertEquals(0, $result['stats']['processed'], 'Processed should be 0.');
		$this->assertEquals(0, $result['stats']['successful'], 'Successful should be 0.');
		$this->assertEquals(0, $result['stats']['failed'], 'Failed should be 0.');
	}

	// ==================== logBulkOperationStart() Tests ====================

	/**
	 * Test logBulkOperationStart logs synchronous operation.
	 *
	 * @return void
	 */
	public function testLogBulkOperationStartSync(): void
	{
		$objects = [
			['name' => 'Object 1'],
			['name' => 'Object 2'],
			['name' => 'Object 3']
		];

		$this->logger
			->expects($this->once())
			->method('info')
			->with($this->stringContains('Starting bulk save operation'));

		$this->invokePrivateMethod(
			methodName: 'logBulkOperationStart',
			parameters: [$objects, false]
		);
	}

	/**
	 * Test logBulkOperationStart logs async operation.
	 *
	 * @return void
	 */
	public function testLogBulkOperationStartAsync(): void
	{
		$objects = [
			['name' => 'Object 1'],
			['name' => 'Object 2']
		];

		$this->logger
			->expects($this->once())
			->method('info')
			->with($this->stringContains('async'));

		$this->invokePrivateMethod(
			methodName: 'logBulkOperationStart',
			parameters: [$objects, true]
		);
	}

	/**
	 * Test logBulkOperationStart with empty array.
	 *
	 * @return void
	 */
	public function testLogBulkOperationStartWithEmptyArray(): void
	{
		$this->logger
			->expects($this->once())
			->method('info')
			->with($this->stringContains('0 objects'));

		$this->invokePrivateMethod(
			methodName: 'logBulkOperationStart',
			parameters: [[], false]
		);
	}

	// ==================== prepareObjectsForSave() Tests ====================

	/**
	 * Test prepareObjectsForSave extracts UUIDs and normalizes objects.
	 *
	 * @return void
	 */
	public function testPrepareObjectsForSaveExtractsUuids(): void
	{
		$objects = [
			['id' => 'uuid-1', 'name' => 'Object 1'],
			['id' => 'uuid-2', 'name' => 'Object 2'],
			['name' => 'Object 3'] // No ID.
		];

		$result = $this->invokePrivateMethod(
			methodName: 'prepareObjectsForSave',
			parameters: [$objects]
		);

		$this->assertIsArray($result, 'Result should be an array.');
		$this->assertCount(3, $result, 'Should have 3 prepared objects.');

		// Check first object has UUID extracted.
		$this->assertArrayHasKey('uuid', $result[0], 'First object should have uuid key.');
		$this->assertEquals('uuid-1', $result[0]['uuid'], 'UUID should be extracted.');
		$this->assertArrayHasKey('data', $result[0], 'First object should have data key.');
		$this->assertArrayNotHasKey('id', $result[0]['data'], 'ID should be removed from data.');

		// Check third object without ID.
		$this->assertArrayHasKey('uuid', $result[2], 'Third object should have uuid key.');
		$this->assertNull($result[2]['uuid'], 'UUID should be null when not provided.');
	}

	/**
	 * Test prepareObjectsForSave with empty array.
	 *
	 * @return void
	 */
	public function testPrepareObjectsForSaveWithEmptyArray(): void
	{
		$result = $this->invokePrivateMethod(
			methodName: 'prepareObjectsForSave',
			parameters: [[]]
		);

		$this->assertIsArray($result, 'Result should be an array.');
		$this->assertEmpty($result, 'Result should be empty.');
	}

	// ==================== initializeResult() Tests ====================

	/**
	 * Test initializeResult sets total count.
	 *
	 * @return void
	 */
	public function testInitializeResultSetsTotalCount(): void
	{
		$preparedObjects = [
			['uuid' => 'uuid-1', 'data' => []],
			['uuid' => 'uuid-2', 'data' => []],
			['uuid' => 'uuid-3', 'data' => []]
		];

		$result = $this->invokePrivateMethod(
			methodName: 'initializeResult',
			parameters: [$preparedObjects]
		);

		$this->assertEquals(3, $result['stats']['total'], 'Total should be 3.');
		$this->assertEquals(0, $result['stats']['processed'], 'Processed should be 0.');
	}

	/**
	 * Test initializeResult with empty array.
	 *
	 * @return void
	 */
	public function testInitializeResultWithEmptyArray(): void
	{
		$result = $this->invokePrivateMethod(
			methodName: 'initializeResult',
			parameters: [[]]
		);

		$this->assertEquals(0, $result['stats']['total'], 'Total should be 0 for empty array.');
	}

	// ==================== mergeChunkResult() Tests ====================

	/**
	 * Test mergeChunkResult merges successful saves.
	 *
	 * @return void
	 */
	public function testMergeChunkResultMergesSuccess(): void
	{
		$result = [
			'success' => ['obj-1'],
			'failed' => [],
			'errors' => [],
			'stats' => [
				'total' => 5,
				'processed' => 2,
				'successful' => 1,
				'failed' => 0
			]
		];

		$chunkResult = [
			'success' => ['obj-2', 'obj-3'],
			'failed' => [],
			'errors' => [],
			'processed' => 2,
			'successful' => 2,
			'failed' => 0
		];

		$this->invokePrivateMethod(
			methodName: 'mergeChunkResult',
			parameters: [&$result, $chunkResult]
		);

		$this->assertCount(3, $result['success'], 'Success should have 3 items.');
		$this->assertEquals(4, $result['stats']['processed'], 'Processed should be 4.');
		$this->assertEquals(3, $result['stats']['successful'], 'Successful should be 3.');
	}

	/**
	 * Test mergeChunkResult merges failures.
	 *
	 * @return void
	 */
	public function testMergeChunkResultMergesFailures(): void
	{
		$result = [
			'success' => [],
			'failed' => ['obj-1'],
			'errors' => ['error-1'],
			'stats' => [
				'total' => 5,
				'processed' => 1,
				'successful' => 0,
				'failed' => 1
			]
		];

		$chunkResult = [
			'success' => [],
			'failed' => ['obj-2'],
			'errors' => ['error-2'],
			'processed' => 1,
			'successful' => 0,
			'failed' => 1
		];

		$this->invokePrivateMethod(
			methodName: 'mergeChunkResult',
			parameters: [&$result, $chunkResult]
		);

		$this->assertCount(2, $result['failed'], 'Failed should have 2 items.');
		$this->assertCount(2, $result['errors'], 'Errors should have 2 items.');
		$this->assertEquals(2, $result['stats']['processed'], 'Processed should be 2.');
		$this->assertEquals(2, $result['stats']['failed'], 'Failed should be 2.');
	}

	/**
	 * Test mergeChunkResult with mixed results.
	 *
	 * @return void
	 */
	public function testMergeChunkResultWithMixedResults(): void
	{
		$result = [
			'success' => ['obj-1'],
			'failed' => ['obj-2'],
			'errors' => ['error-1'],
			'stats' => [
				'total' => 10,
				'processed' => 2,
				'successful' => 1,
				'failed' => 1
			]
		];

		$chunkResult = [
			'success' => ['obj-3', 'obj-4'],
			'failed' => ['obj-5'],
			'errors' => ['error-2'],
			'processed' => 3,
			'successful' => 2,
			'failed' => 1
		];

		$this->invokePrivateMethod(
			methodName: 'mergeChunkResult',
			parameters: [&$result, $chunkResult]
		);

		$this->assertCount(3, $result['success'], 'Success should have 3 items.');
		$this->assertCount(2, $result['failed'], 'Failed should have 2 items.');
		$this->assertEquals(5, $result['stats']['processed'], 'Processed should be 5.');
		$this->assertEquals(3, $result['stats']['successful'], 'Successful should be 3.');
		$this->assertEquals(2, $result['stats']['failed'], 'Failed should be 2.');
	}

	// ==================== calculatePerformanceMetrics() Tests ====================

	/**
	 * Test calculatePerformanceMetrics adds timing information.
	 *
	 * @return void
	 */
	public function testCalculatePerformanceMetricsAddsTimingInfo(): void
	{
		$result = [
			'success' => ['obj-1', 'obj-2', 'obj-3'],
			'failed' => ['obj-4'],
			'errors' => ['error-1'],
			'stats' => [
				'total' => 4,
				'processed' => 4,
				'successful' => 3,
				'failed' => 1
			]
		];

		$startTime = microtime(true) - 2.5; // 2.5 seconds ago.

		$resultWithMetrics = $this->invokePrivateMethod(
			methodName: 'calculatePerformanceMetrics',
			parameters: [$result, $startTime]
		);

		$this->assertArrayHasKey('performance', $resultWithMetrics, 'Result should have performance metrics.');
		$this->assertArrayHasKey('total_time_seconds', $resultWithMetrics['performance'], 'Should have total time.');
		$this->assertArrayHasKey('objects_per_second', $resultWithMetrics['performance'], 'Should have throughput.');
		$this->assertArrayHasKey('average_time_per_object', $resultWithMetrics['performance'], 'Should have average time.');

		$this->assertGreaterThan(2, $resultWithMetrics['performance']['total_time_seconds'], 'Total time should be > 2 seconds.');
		$this->assertGreaterThan(0, $resultWithMetrics['performance']['objects_per_second'], 'Throughput should be positive.');
	}

	/**
	 * Test calculatePerformanceMetrics calculates success rate.
	 *
	 * @return void
	 */
	public function testCalculatePerformanceMetricsCalculatesSuccessRate(): void
	{
		$result = [
			'success' => ['obj-1', 'obj-2', 'obj-3'],
			'failed' => ['obj-4'],
			'stats' => [
				'total' => 4,
				'processed' => 4,
				'successful' => 3,
				'failed' => 1
			]
		];

		$startTime = microtime(true);

		$resultWithMetrics = $this->invokePrivateMethod(
			methodName: 'calculatePerformanceMetrics',
			parameters: [$result, $startTime]
		);

		$this->assertArrayHasKey('success_rate', $resultWithMetrics['stats'], 'Stats should have success rate.');
		$this->assertEquals(75.0, $resultWithMetrics['stats']['success_rate'], 'Success rate should be 75%.');
	}

	/**
	 * Test calculatePerformanceMetrics with zero processed objects.
	 *
	 * @return void
	 */
	public function testCalculatePerformanceMetricsWithZeroProcessed(): void
	{
		$result = [
			'success' => [],
			'failed' => [],
			'stats' => [
				'total' => 0,
				'processed' => 0,
				'successful' => 0,
				'failed' => 0
			]
		];

		$startTime = microtime(true);

		$resultWithMetrics = $this->invokePrivateMethod(
			methodName: 'calculatePerformanceMetrics',
			parameters: [$result, $startTime]
		);

		$this->assertEquals(0, $resultWithMetrics['performance']['objects_per_second'], 'Throughput should be 0.');
		$this->assertEquals(0, $resultWithMetrics['performance']['average_time_per_object'], 'Average should be 0.');
	}

	/**
	 * Test calculatePerformanceMetrics formats values correctly.
	 *
	 * @return void
	 */
	public function testCalculatePerformanceMetricsFormatsValues(): void
	{
		$result = [
			'success' => ['obj-1'],
			'stats' => [
				'total' => 1,
				'processed' => 1,
				'successful' => 1,
				'failed' => 0
			]
		];

		$startTime = microtime(true) - 0.123456; // ~123ms ago.

		$resultWithMetrics = $this->invokePrivateMethod(
			methodName: 'calculatePerformanceMetrics',
			parameters: [$result, $startTime]
		);

		// Check that values are numeric.
		$this->assertIsNumeric($resultWithMetrics['performance']['total_time_seconds'], 'Total time should be numeric.');
		$this->assertIsNumeric($resultWithMetrics['performance']['objects_per_second'], 'Throughput should be numeric.');
		$this->assertIsNumeric($resultWithMetrics['performance']['average_time_per_object'], 'Average should be numeric.');
	}

	// ==================== Integration Test ====================

	/**
	 * Test that all refactored methods work together in saveObjects().
	 *
	 * This test verifies the complete bulk save operation flow.
	 *
	 * @return void
	 */
	public function testRefactoredSaveObjectsIntegration(): void
	{
		$objects = [
			['id' => 'uuid-1', 'name' => 'Object 1'],
			['id' => 'uuid-2', 'name' => 'Object 2'],
			['name' => 'Object 3'] // Will be created with new UUID.
		];

		// Mock successful saves.
		$this->saveObject
			->method('saveObject')
			->willReturnCallback(function () {
				$entity = new ObjectEntity();
				$entity->setId(rand(1, 1000));
				return $entity;
			});

		// Execute bulk save.
		$result = $this->saveObjects->saveObjects(
			register: $this->mockRegister,
			schema: $this->mockSchema,
			objects: $objects,
			async: false
		);

		// Assertions.
		$this->assertIsArray($result, 'Result should be an array.');
		$this->assertArrayHasKey('stats', $result, 'Result should have stats.');
		$this->assertArrayHasKey('performance', $result, 'Result should have performance metrics.');
		$this->assertEquals(3, $result['stats']['total'], 'Total should be 3.');
		$this->assertEquals(3, $result['stats']['successful'], 'All should succeed.');
		$this->assertEquals(0, $result['stats']['failed'], 'None should fail.');
		$this->assertCount(3, $result['success'], 'Success array should have 3 items.');
	}

	/**
	 * Test bulk save with partial failures.
	 *
	 * @return void
	 */
	public function testRefactoredSaveObjectsWithPartialFailures(): void
	{
		$objects = [
			['name' => 'Object 1'],
			['name' => 'Object 2'],
			['name' => 'Object 3']
		];

		// Mock saves with some failures.
		$callCount = 0;
		$this->saveObject
			->method('saveObject')
			->willReturnCallback(function () use (&$callCount) {
				$callCount++;
				if ($callCount === 2) {
					throw new \Exception('Save failed for object 2.');
				}
				$entity = new ObjectEntity();
				$entity->setId($callCount);
				return $entity;
			});

		// Execute bulk save.
		$result = $this->saveObjects->saveObjects(
			register: $this->mockRegister,
			schema: $this->mockSchema,
			objects: $objects,
			async: false
		);

		// Assertions.
		$this->assertEquals(3, $result['stats']['total'], 'Total should be 3.');
		$this->assertEquals(2, $result['stats']['successful'], 'Two should succeed.');
		$this->assertEquals(1, $result['stats']['failed'], 'One should fail.');
		$this->assertCount(2, $result['success'], 'Success array should have 2 items.');
		$this->assertCount(1, $result['failed'], 'Failed array should have 1 item.');
		$this->assertCount(1, $result['errors'], 'Errors array should have 1 item.');
	}
}




