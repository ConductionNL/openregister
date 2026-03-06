<?php

/**
 * SaveObjects Refactored Methods Unit Tests
 *
 * Comprehensive tests for the private methods extracted during Phase 1 refactoring.
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
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\SaveObjects;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObjects\BulkRelationHandler;
use OCA\OpenRegister\Service\Object\SaveObjects\BulkValidationHandler;
use OCA\OpenRegister\Service\Object\SaveObjects\PreparationHandler;
use OCA\OpenRegister\Service\Object\SaveObjects\ChunkProcessingHandler;
use OCA\OpenRegister\Service\Object\SaveObjects\TransformationHandler;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use ReflectionClass;

/**
 * Unit tests for SaveObjects refactored methods.
 *
 * Tests the extracted private methods using reflection:
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

	/** @var MockObject|ObjectEntityMapper */
	private $objectEntityMapper;

	/** @var MockObject|SchemaMapper */
	private $schemaMapper;

	/** @var MockObject|RegisterMapper */
	private $registerMapper;

	/** @var MockObject|SaveObject */
	private $saveObject;

	/** @var MockObject|BulkValidationHandler */
	private $bulkValidHandler;

	/** @var MockObject|BulkRelationHandler */
	private $bulkRelationHandler;

	/** @var MockObject|TransformationHandler */
	private $transformHandler;

	/** @var MockObject|PreparationHandler */
	private $preparationHandler;

	/** @var MockObject|ChunkProcessingHandler */
	private $chunkProcHandler;

	/** @var MockObject|OrganisationService */
	private $organisationService;

	/** @var MockObject|IUserSession */
	private $userSession;

	/** @var MockObject|LoggerInterface */
	private $logger;

	/** @var Register */
	private $mockRegister;

	/** @var Schema */
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
		$this->objectEntityMapper = $this->createMock(ObjectEntityMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->saveObject = $this->createMock(SaveObject::class);
		$this->bulkValidHandler = $this->createMock(BulkValidationHandler::class);
		$this->bulkRelationHandler = $this->createMock(BulkRelationHandler::class);
		$this->transformHandler = $this->createMock(TransformationHandler::class);
		$this->preparationHandler = $this->createMock(PreparationHandler::class);
		$this->chunkProcHandler = $this->createMock(ChunkProcessingHandler::class);
		$this->organisationService = $this->createMock(OrganisationService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		// Create real entities (getId is a magic method, cannot be mocked).
		$this->mockRegister = new Register();
		$this->mockRegister->setId(1);

		$this->mockSchema = new Schema();
		$this->mockSchema->setId(1);

		// Create SaveObjects instance.
		$this->saveObjects = new SaveObjects(
			objectEntityMapper: $this->objectEntityMapper,
			schemaMapper: $this->schemaMapper,
			registerMapper: $this->registerMapper,
			saveHandler: $this->saveObject,
			bulkValidHandler: $this->bulkValidHandler,
			bulkRelationHandler: $this->bulkRelationHandler,
			transformHandler: $this->transformHandler,
			preparationHandler: $this->preparationHandler,
			chunkProcHandler: $this->chunkProcHandler,
			organisationService: $this->organisationService,
			userSession: $this->userSession,
			logger: $this->logger,
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
		$result = $this->invokePrivateMethod(methodName: 'createEmptyResult', parameters: [0]);

		$this->assertIsArray($result, 'Result should be an array.');
		$this->assertArrayHasKey('saved', $result, 'Result should have saved key.');
		$this->assertArrayHasKey('updated', $result, 'Result should have updated key.');
		$this->assertArrayHasKey('unchanged', $result, 'Result should have unchanged key.');
		$this->assertArrayHasKey('invalid', $result, 'Result should have invalid key.');
		$this->assertArrayHasKey('errors', $result, 'Result should have errors key.');
		$this->assertArrayHasKey('statistics', $result, 'Result should have statistics key.');
	}

	/**
	 * Test createEmptyResult initializes empty arrays.
	 *
	 * @return void
	 */
	public function testCreateEmptyResultInitializesArrays(): void
	{
		$result = $this->invokePrivateMethod(methodName: 'createEmptyResult', parameters: [0]);

		$this->assertIsArray($result['saved'], 'Saved should be an array.');
		$this->assertIsArray($result['updated'], 'Updated should be an array.');
		$this->assertIsArray($result['unchanged'], 'Unchanged should be an array.');
		$this->assertIsArray($result['invalid'], 'Invalid should be an array.');
		$this->assertIsArray($result['errors'], 'Errors should be an array.');
		$this->assertEmpty($result['saved'], 'Saved should be empty.');
		$this->assertEmpty($result['updated'], 'Updated should be empty.');
		$this->assertEmpty($result['unchanged'], 'Unchanged should be empty.');
		$this->assertEmpty($result['invalid'], 'Invalid should be empty.');
		$this->assertEmpty($result['errors'], 'Errors should be empty.');
	}

	/**
	 * Test createEmptyResult initializes statistics with zeros.
	 *
	 * @return void
	 */
	public function testCreateEmptyResultInitializesStats(): void
	{
		$result = $this->invokePrivateMethod(methodName: 'createEmptyResult', parameters: [5]);

		$this->assertIsArray($result['statistics'], 'Statistics should be an array.');
		$this->assertArrayHasKey('totalProcessed', $result['statistics'], 'Statistics should have totalProcessed.');
		$this->assertArrayHasKey('saved', $result['statistics'], 'Statistics should have saved.');
		$this->assertArrayHasKey('updated', $result['statistics'], 'Statistics should have updated.');
		$this->assertArrayHasKey('unchanged', $result['statistics'], 'Statistics should have unchanged.');
		$this->assertArrayHasKey('invalid', $result['statistics'], 'Statistics should have invalid.');
		$this->assertArrayHasKey('errors', $result['statistics'], 'Statistics should have errors.');
		$this->assertEquals(5, $result['statistics']['totalProcessed'], 'totalProcessed should be 5.');
		$this->assertEquals(0, $result['statistics']['saved'], 'Saved should be 0.');
		$this->assertEquals(0, $result['statistics']['updated'], 'Updated should be 0.');
		$this->assertEquals(0, $result['statistics']['unchanged'], 'Unchanged should be 0.');
		$this->assertEquals(0, $result['statistics']['invalid'], 'Invalid should be 0.');
		$this->assertEquals(0, $result['statistics']['errors'], 'Errors should be 0.');
	}

	// ==================== logBulkOperationStart() Tests ====================

	/**
	 * Test logBulkOperationStart logs single-schema operation above threshold.
	 *
	 * The method only logs when totalObjects exceeds the threshold
	 * (10000 for single-schema, 1000 for mixed-schema).
	 *
	 * @return void
	 */
	public function testLogBulkOperationStartSingleSchema(): void
	{
		$this->logger
			->expects($this->once())
			->method('info')
			->with($this->stringContains('single-schema'));

		$this->invokePrivateMethod(
			methodName: 'logBulkOperationStart',
			parameters: [10001, false]
		);
	}

	/**
	 * Test logBulkOperationStart logs mixed-schema operation above threshold.
	 *
	 * @return void
	 */
	public function testLogBulkOperationStartMixedSchema(): void
	{
		$this->logger
			->expects($this->once())
			->method('info')
			->with($this->stringContains('mixed-schema'));

		$this->invokePrivateMethod(
			methodName: 'logBulkOperationStart',
			parameters: [1001, true]
		);
	}

	/**
	 * Test logBulkOperationStart does not log below threshold.
	 *
	 * @return void
	 */
	public function testLogBulkOperationStartBelowThreshold(): void
	{
		$this->logger
			->expects($this->never())
			->method('info');

		$this->invokePrivateMethod(
			methodName: 'logBulkOperationStart',
			parameters: [0, false]
		);
	}

	// ==================== prepareObjectsForSave() Tests ====================

	/**
	 * Test prepareObjectsForSave delegates to preparationHandler for mixed-schema.
	 *
	 * @return void
	 */
	public function testPrepareObjectsForSaveMixedSchema(): void
	{
		$objects = [
			['id' => 'uuid-1', 'name' => 'Object 1'],
			['id' => 'uuid-2', 'name' => 'Object 2'],
			['name' => 'Object 3']
		];

		$expectedResult = [
			[['prepared' => true], [], []],
		];

		$this->preparationHandler
			->expects($this->once())
			->method('prepareObjectsForBulkSave')
			->with($objects)
			->willReturn([['prepared' => true], [], []]);

		$result = $this->invokePrivateMethod(
			methodName: 'prepareObjectsForSave',
			parameters: [$objects, null, null, true, true]
		);

		$this->assertIsArray($result, 'Result should be an array.');
	}

	/**
	 * Test prepareObjectsForSave with empty array.
	 *
	 * @return void
	 */
	public function testPrepareObjectsForSaveWithEmptyArray(): void
	{
		$this->preparationHandler
			->expects($this->once())
			->method('prepareObjectsForBulkSave')
			->with([])
			->willReturn([[], [], []]);

		$result = $this->invokePrivateMethod(
			methodName: 'prepareObjectsForSave',
			parameters: [[], null, null, true, true]
		);

		$this->assertIsArray($result, 'Result should be an array.');
	}

	// ==================== initializeResult() Tests ====================

	/**
	 * Test initializeResult sets total count.
	 *
	 * @return void
	 */
	public function testInitializeResultSetsTotalCount(): void
	{
		$result = $this->invokePrivateMethod(
			methodName: 'initializeResult',
			parameters: [3, []]
		);

		$this->assertEquals(3, $result['statistics']['totalProcessed'], 'totalProcessed should be 3.');
		$this->assertEquals(0, $result['statistics']['saved'], 'Saved should be 0.');
		$this->assertEquals(0, $result['statistics']['invalid'], 'Invalid should be 0.');
	}

	/**
	 * Test initializeResult with empty array (zero objects).
	 *
	 * @return void
	 */
	public function testInitializeResultWithEmptyArray(): void
	{
		$result = $this->invokePrivateMethod(
			methodName: 'initializeResult',
			parameters: [0, []]
		);

		$this->assertEquals(0, $result['statistics']['totalProcessed'], 'totalProcessed should be 0 for empty.');
	}

	/**
	 * Test initializeResult with invalid objects.
	 *
	 * @return void
	 */
	public function testInitializeResultWithInvalidObjects(): void
	{
		$invalidObjects = [
			['error' => 'Missing required field', 'object' => ['name' => 'Bad Object']],
		];

		$result = $this->invokePrivateMethod(
			methodName: 'initializeResult',
			parameters: [3, $invalidObjects]
		);

		$this->assertEquals(3, $result['statistics']['totalProcessed'], 'totalProcessed should be 3.');
		$this->assertCount(1, $result['invalid'], 'Invalid should have 1 item.');
		$this->assertEquals(1, $result['statistics']['invalid'], 'Invalid count should be 1.');
		$this->assertEquals(1, $result['statistics']['errors'], 'Errors count should be 1.');
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
			'saved'     => ['obj-1'],
			'updated'   => [],
			'unchanged' => [],
			'invalid'   => [],
			'errors'    => [],
			'statistics' => [
				'totalProcessed' => 5,
				'saved'          => 1,
				'updated'        => 0,
				'unchanged'      => 0,
				'invalid'        => 0,
				'errors'         => 0,
			]
		];

		$chunkResult = [
			'saved'     => ['obj-2', 'obj-3'],
			'updated'   => [],
			'unchanged' => [],
			'invalid'   => [],
			'errors'    => [],
			'statistics' => [
				'saved'     => 2,
				'updated'   => 0,
				'unchanged' => 0,
				'invalid'   => 0,
				'errors'    => 0,
			]
		];

		$merged = $this->invokePrivateMethod(
			methodName: 'mergeChunkResult',
			parameters: [$result, $chunkResult, 0, 2]
		);

		$this->assertCount(3, $merged['saved'], 'Saved should have 3 items.');
		$this->assertEquals(3, $merged['statistics']['saved'], 'Saved count should be 3.');
	}

	/**
	 * Test mergeChunkResult merges failures.
	 *
	 * @return void
	 */
	public function testMergeChunkResultMergesFailures(): void
	{
		$result = [
			'saved'     => [],
			'updated'   => [],
			'unchanged' => [],
			'invalid'   => ['obj-1'],
			'errors'    => ['error-1'],
			'statistics' => [
				'totalProcessed' => 5,
				'saved'          => 0,
				'updated'        => 0,
				'unchanged'      => 0,
				'invalid'        => 1,
				'errors'         => 1,
			]
		];

		$chunkResult = [
			'saved'     => [],
			'updated'   => [],
			'unchanged' => [],
			'invalid'   => ['obj-2'],
			'errors'    => ['error-2'],
			'statistics' => [
				'saved'     => 0,
				'updated'   => 0,
				'unchanged' => 0,
				'invalid'   => 1,
				'errors'    => 1,
			]
		];

		$merged = $this->invokePrivateMethod(
			methodName: 'mergeChunkResult',
			parameters: [$result, $chunkResult, 0, 1]
		);

		$this->assertCount(2, $merged['invalid'], 'Invalid should have 2 items.');
		$this->assertCount(2, $merged['errors'], 'Errors should have 2 items.');
		$this->assertEquals(2, $merged['statistics']['invalid'], 'Invalid count should be 2.');
		$this->assertEquals(2, $merged['statistics']['errors'], 'Errors count should be 2.');
	}

	/**
	 * Test mergeChunkResult with mixed results.
	 *
	 * @return void
	 */
	public function testMergeChunkResultWithMixedResults(): void
	{
		$result = [
			'saved'     => ['obj-1'],
			'updated'   => [],
			'unchanged' => [],
			'invalid'   => ['obj-2'],
			'errors'    => ['error-1'],
			'statistics' => [
				'totalProcessed' => 10,
				'saved'          => 1,
				'updated'        => 0,
				'unchanged'      => 0,
				'invalid'        => 1,
				'errors'         => 1,
			]
		];

		$chunkResult = [
			'saved'     => ['obj-3', 'obj-4'],
			'updated'   => [],
			'unchanged' => [],
			'invalid'   => ['obj-5'],
			'errors'    => ['error-2'],
			'statistics' => [
				'saved'     => 2,
				'updated'   => 0,
				'unchanged' => 0,
				'invalid'   => 1,
				'errors'    => 1,
			]
		];

		$merged = $this->invokePrivateMethod(
			methodName: 'mergeChunkResult',
			parameters: [$result, $chunkResult, 1, 3]
		);

		$this->assertCount(3, $merged['saved'], 'Saved should have 3 items.');
		$this->assertCount(2, $merged['invalid'], 'Invalid should have 2 items.');
		$this->assertEquals(3, $merged['statistics']['saved'], 'Saved count should be 3.');
		$this->assertEquals(2, $merged['statistics']['invalid'], 'Invalid count should be 2.');
		$this->assertEquals(2, $merged['statistics']['errors'], 'Errors count should be 2.');
	}

	// ==================== calculatePerformanceMetrics() Tests ====================

	/**
	 * Test calculatePerformanceMetrics adds timing information.
	 *
	 * @return void
	 */
	public function testCalculatePerformanceMetricsAddsTimingInfo(): void
	{
		$startTime = microtime(true) - 2.5; // 2.5 seconds ago.

		$performance = $this->invokePrivateMethod(
			methodName: 'calculatePerformanceMetrics',
			parameters: [$startTime, 4, 4, 0]
		);

		$this->assertIsArray($performance, 'Result should be an array.');
		$this->assertArrayHasKey('totalTime', $performance, 'Should have totalTime.');
		$this->assertArrayHasKey('totalTimeMs', $performance, 'Should have totalTimeMs.');
		$this->assertArrayHasKey('objectsPerSecond', $performance, 'Should have objectsPerSecond.');
		$this->assertArrayHasKey('totalProcessed', $performance, 'Should have totalProcessed.');
		$this->assertArrayHasKey('totalRequested', $performance, 'Should have totalRequested.');

		$this->assertGreaterThan(2, $performance['totalTime'], 'Total time should be > 2 seconds.');
		$this->assertGreaterThan(0, $performance['objectsPerSecond'], 'Throughput should be positive.');
	}

	/**
	 * Test calculatePerformanceMetrics calculates efficiency.
	 *
	 * @return void
	 */
	public function testCalculatePerformanceMetricsCalculatesEfficiency(): void
	{
		$startTime = microtime(true);

		$performance = $this->invokePrivateMethod(
			methodName: 'calculatePerformanceMetrics',
			parameters: [$startTime, 3, 4, 0]
		);

		$this->assertArrayHasKey('efficiency', $performance, 'Should have efficiency.');
		$this->assertEquals(75.0, $performance['efficiency'], 'Efficiency should be 75%.');
	}

	/**
	 * Test calculatePerformanceMetrics with zero processed objects.
	 *
	 * @return void
	 */
	public function testCalculatePerformanceMetricsWithZeroProcessed(): void
	{
		$startTime = microtime(true);

		$performance = $this->invokePrivateMethod(
			methodName: 'calculatePerformanceMetrics',
			parameters: [$startTime, 0, 0, 0]
		);

		$this->assertEquals(0, $performance['objectsPerSecond'], 'Throughput should be 0.');
		$this->assertEquals(0, $performance['efficiency'], 'Efficiency should be 0.');
	}

	/**
	 * Test calculatePerformanceMetrics formats values correctly.
	 *
	 * @return void
	 */
	public function testCalculatePerformanceMetricsFormatsValues(): void
	{
		$startTime = microtime(true) - 0.123456; // ~123ms ago.

		$performance = $this->invokePrivateMethod(
			methodName: 'calculatePerformanceMetrics',
			parameters: [$startTime, 1, 1, 0]
		);

		// Check that values are numeric.
		$this->assertIsNumeric($performance['totalTime'], 'Total time should be numeric.');
		$this->assertIsNumeric($performance['totalTimeMs'], 'Total time ms should be numeric.');
		$this->assertIsNumeric($performance['objectsPerSecond'], 'Throughput should be numeric.');
	}

	/**
	 * Test calculatePerformanceMetrics includes deduplication info when unchanged > 0.
	 *
	 * @return void
	 */
	public function testCalculatePerformanceMetricsWithUnchanged(): void
	{
		$startTime = microtime(true);

		$performance = $this->invokePrivateMethod(
			methodName: 'calculatePerformanceMetrics',
			parameters: [$startTime, 3, 5, 2]
		);

		$this->assertArrayHasKey('deduplicationEfficiency', $performance, 'Should have deduplicationEfficiency.');
		$this->assertStringContainsString('operations avoided', $performance['deduplicationEfficiency']);
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
			['name' => 'Object 3']
		];

		// Mock the preparationHandler for single-schema path (schema is provided).
		// prepareObjectsForSave will call prepareSingleSchemaObjectsOptimized
		// which is a private method - we need to mock the chunkProcHandler instead.
		// The preparationHandler is only called for mixed-schema (schema=null).

		// For single-schema path, prepareObjectsForSave calls prepareSingleSchemaObjectsOptimized
		// which uses preparationHandler internally. We need to mock that.
		$this->preparationHandler
			->method('prepareObjectsForBulkSave')
			->willReturn([$objects, [], []]);

		// Mock chunk processing handler to return successful results.
		$this->chunkProcHandler
			->method('processObjectsChunk')
			->willReturn([
				'saved'     => [new ObjectEntity(), new ObjectEntity(), new ObjectEntity()],
				'updated'   => [],
				'unchanged' => [],
				'invalid'   => [],
				'errors'    => [],
				'statistics' => [
					'saved'     => 3,
					'updated'   => 0,
					'unchanged' => 0,
					'invalid'   => 0,
					'errors'    => 0,
				],
			]);

		// Execute bulk save (no $async parameter - it does not exist).
		$result = $this->saveObjects->saveObjects(
			objects: $objects,
			register: $this->mockRegister,
			schema: $this->mockSchema
		);

		// Assertions.
		$this->assertIsArray($result, 'Result should be an array.');
		$this->assertArrayHasKey('statistics', $result, 'Result should have statistics.');
		$this->assertArrayHasKey('performance', $result, 'Result should have performance metrics.');
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

		// Mock chunk processing handler to return mixed results.
		$this->chunkProcHandler
			->method('processObjectsChunk')
			->willReturn([
				'saved'     => [new ObjectEntity(), new ObjectEntity()],
				'updated'   => [],
				'unchanged' => [],
				'invalid'   => [['error' => 'Save failed for object 2.', 'object' => ['name' => 'Object 2']]],
				'errors'    => [['error' => 'Save failed for object 2.', 'type' => 'SaveException']],
				'statistics' => [
					'saved'     => 2,
					'updated'   => 0,
					'unchanged' => 0,
					'invalid'   => 1,
					'errors'    => 1,
				],
			]);

		// Execute bulk save (no $async parameter).
		$result = $this->saveObjects->saveObjects(
			objects: $objects,
			register: $this->mockRegister,
			schema: $this->mockSchema
		);

		// Assertions.
		$this->assertIsArray($result, 'Result should be an array.');
		$this->assertArrayHasKey('statistics', $result, 'Result should have statistics.');
		$this->assertEquals(2, $result['statistics']['saved'], 'Two should be saved.');
		$this->assertEquals(1, $result['statistics']['invalid'], 'One should be invalid.');
		$this->assertCount(2, $result['saved'], 'Saved array should have 2 items.');
		$this->assertCount(1, $result['invalid'], 'Invalid array should have 1 item.');
		$this->assertCount(1, $result['errors'], 'Errors array should have 1 item.');
	}
}
