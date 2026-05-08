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
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\SaveObjects;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObjects\BulkValidationHandler;
use OCA\OpenRegister\Service\Object\SaveObjects\BulkRelationHandler;
use OCA\OpenRegister\Service\Object\SaveObjects\TransformationHandler;
use OCA\OpenRegister\Service\Object\SaveObjects\PreparationHandler;
use OCA\OpenRegister\Service\Object\SaveObjects\ChunkProcessingHandler;
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
 * 3. initializeResult()
 * 4. mergeChunkResult()
 * 5. calculatePerformanceMetrics()
 */
class SaveObjectsRefactoredMethodsTest extends TestCase
{
    private SaveObjects $saveObjects;
    private ReflectionClass $reflection;

    /** @var MockObject|MagicMapper */
    private $objectMapper;

    /** @var MockObject|SchemaMapper */
    private $schemaMapper;

    /** @var MockObject|RegisterMapper */
    private $registerMapper;

    /** @var MockObject|SaveObject */
    private $saveHandler;

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
    private Register $mockRegister;

    /** @var Schema */
    private Schema $mockSchema;

    /**
     * Set up test environment before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Create mocks for all dependencies.
        $this->objectMapper = $this->createMock(MagicMapper::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->saveHandler = $this->createMock(SaveObject::class);
        $this->bulkValidHandler = $this->createMock(BulkValidationHandler::class);
        $this->bulkRelationHandler = $this->createMock(BulkRelationHandler::class);
        $this->transformHandler = $this->createMock(TransformationHandler::class);
        $this->preparationHandler = $this->createMock(PreparationHandler::class);
        $this->chunkProcHandler = $this->createMock(ChunkProcessingHandler::class);
        $this->organisationService = $this->createMock(OrganisationService::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        // Create real entity instances (Entity __call methods cannot be mocked in PHPUnit 10+).
        $this->mockRegister = new Register();
        $this->mockRegister->setId(1);
        $this->mockRegister->setSlug('test-register');

        $this->mockSchema = new Schema();
        $this->mockSchema->setId(1);
        $this->mockSchema->setSlug('test-schema');

        // Create SaveObjects instance with positional params (correct constructor order).
        $this->saveObjects = new SaveObjects(
            $this->objectMapper,
            $this->schemaMapper,
            $this->registerMapper,
            $this->saveHandler,
            $this->bulkValidHandler,
            $this->bulkRelationHandler,
            $this->transformHandler,
            $this->preparationHandler,
            $this->chunkProcHandler,
            $this->organisationService,
            $this->userSession,
            $this->logger
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
        // Method signature: createEmptyResult(int $totalObjects)
        $result = $this->invokePrivateMethod('createEmptyResult', [0]);

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
        $result = $this->invokePrivateMethod('createEmptyResult', [0]);

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
    public function testCreateEmptyResultInitializesStatistics(): void
    {
        $result = $this->invokePrivateMethod('createEmptyResult', [5]);

        $this->assertIsArray($result['statistics'], 'Statistics should be an array.');
        $this->assertArrayHasKey('totalProcessed', $result['statistics'], 'Stats should have totalProcessed.');
        $this->assertArrayHasKey('saved', $result['statistics'], 'Stats should have saved.');
        $this->assertArrayHasKey('updated', $result['statistics'], 'Stats should have updated.');
        $this->assertArrayHasKey('unchanged', $result['statistics'], 'Stats should have unchanged.');
        $this->assertArrayHasKey('invalid', $result['statistics'], 'Stats should have invalid.');
        $this->assertArrayHasKey('errors', $result['statistics'], 'Stats should have errors.');
        $this->assertEquals(5, $result['statistics']['totalProcessed'], 'TotalProcessed should be 5.');
        $this->assertEquals(0, $result['statistics']['saved'], 'Saved should be 0.');
        $this->assertEquals(0, $result['statistics']['updated'], 'Updated should be 0.');
        $this->assertEquals(0, $result['statistics']['unchanged'], 'Unchanged should be 0.');
        $this->assertEquals(0, $result['statistics']['invalid'], 'Invalid should be 0.');
        $this->assertEquals(0, $result['statistics']['errors'], 'Errors should be 0.');
    }

    // ==================== logBulkOperationStart() Tests ====================

    /**
     * Test logBulkOperationStart does not log for small operations.
     *
     * @return void
     */
    public function testLogBulkOperationStartSmallOperationNoLog(): void
    {
        // Method signature: logBulkOperationStart(int $totalObjects, bool $isMixedSchema)
        // For single-schema, threshold is 10000, so 3 objects should NOT log.
        $this->logger
            ->expects($this->never())
            ->method('info');

        $this->invokePrivateMethod(
            'logBulkOperationStart',
            [3, false]
        );
    }

    /**
     * Test logBulkOperationStart logs for large single-schema operations.
     *
     * @return void
     */
    public function testLogBulkOperationStartLargeOperation(): void
    {
        // Threshold for single-schema is 10000.
        $this->logger
            ->expects($this->once())
            ->method('info')
            ->with($this->stringContains('bulk save operation'));

        $this->invokePrivateMethod(
            'logBulkOperationStart',
            [15000, false]
        );
    }

    /**
     * Test logBulkOperationStart logs for large mixed-schema operations.
     *
     * @return void
     */
    public function testLogBulkOperationStartLargeMixedSchema(): void
    {
        // Threshold for mixed-schema is 1000.
        $this->logger
            ->expects($this->once())
            ->method('info')
            ->with($this->stringContains('mixed-schema'));

        $this->invokePrivateMethod(
            'logBulkOperationStart',
            [1500, true]
        );
    }

    // ==================== initializeResult() Tests ====================

    /**
     * Test initializeResult with no invalid objects.
     *
     * @return void
     */
    public function testInitializeResultNoInvalidObjects(): void
    {
        // Method signature: initializeResult(int $totalObjects, array $invalidObjects)
        $result = $this->invokePrivateMethod(
            'initializeResult',
            [3, []]
        );

        $this->assertEquals(3, $result['statistics']['totalProcessed'], 'TotalProcessed should be 3.');
        $this->assertEquals(0, $result['statistics']['invalid'], 'Invalid should be 0.');
        $this->assertEmpty($result['invalid'], 'Invalid array should be empty.');
    }

    /**
     * Test initializeResult with invalid objects.
     *
     * @return void
     */
    public function testInitializeResultWithInvalidObjects(): void
    {
        $invalidObjects = [
            ['data' => ['name' => 'Bad Object'], 'error' => 'Missing schema'],
        ];

        $result = $this->invokePrivateMethod(
            'initializeResult',
            [5, $invalidObjects]
        );

        $this->assertEquals(5, $result['statistics']['totalProcessed'], 'TotalProcessed should be 5.');
        $this->assertEquals(1, $result['statistics']['invalid'], 'Invalid should be 1.');
        $this->assertEquals(1, $result['statistics']['errors'], 'Errors should be 1.');
        $this->assertCount(1, $result['invalid'], 'Invalid array should have 1 item.');
    }

    /**
     * Test initializeResult with empty array.
     *
     * @return void
     */
    public function testInitializeResultWithEmptyArray(): void
    {
        $result = $this->invokePrivateMethod(
            'initializeResult',
            [0, []]
        );

        $this->assertEquals(0, $result['statistics']['totalProcessed'], 'TotalProcessed should be 0 for empty.');
    }

    // ==================== mergeChunkResult() Tests ====================

    /**
     * Test mergeChunkResult merges saved objects.
     *
     * @return void
     */
    public function testMergeChunkResultMergesSaved(): void
    {
        // Method signature: mergeChunkResult(array $result, array $chunkResult, int $chunkIndex, int $chunkCount)
        $result = [
            'saved'      => ['obj-1'],
            'updated'    => [],
            'unchanged'  => [],
            'invalid'    => [],
            'errors'     => [],
            'statistics' => [
                'totalProcessed' => 5,
                'saved'          => 1,
                'updated'        => 0,
                'unchanged'      => 0,
                'invalid'        => 0,
                'errors'         => 0,
                'processingTimeMs' => 0,
            ]
        ];

        $chunkResult = [
            'saved'      => ['obj-2', 'obj-3'],
            'updated'    => [],
            'unchanged'  => [],
            'invalid'    => [],
            'errors'     => [],
            'statistics' => [
                'saved'     => 2,
                'updated'   => 0,
                'unchanged' => 0,
                'invalid'   => 0,
                'errors'    => 0,
            ]
        ];

        $mergedResult = $this->invokePrivateMethod(
            'mergeChunkResult',
            [$result, $chunkResult, 0, 2]
        );

        $this->assertCount(3, $mergedResult['saved'], 'Saved should have 3 items.');
        $this->assertEquals(3, $mergedResult['statistics']['saved'], 'Saved stat should be 3.');
    }

    /**
     * Test mergeChunkResult merges errors.
     *
     * @return void
     */
    public function testMergeChunkResultMergesErrors(): void
    {
        $result = [
            'saved'      => [],
            'updated'    => [],
            'unchanged'  => [],
            'invalid'    => ['obj-1'],
            'errors'     => ['error-1'],
            'statistics' => [
                'totalProcessed' => 5,
                'saved'          => 0,
                'updated'        => 0,
                'unchanged'      => 0,
                'invalid'        => 1,
                'errors'         => 1,
                'processingTimeMs' => 0,
            ]
        ];

        $chunkResult = [
            'saved'      => [],
            'updated'    => [],
            'unchanged'  => [],
            'invalid'    => ['obj-2'],
            'errors'     => ['error-2'],
            'statistics' => [
                'saved'     => 0,
                'updated'   => 0,
                'unchanged' => 0,
                'invalid'   => 1,
                'errors'    => 1,
            ]
        ];

        $mergedResult = $this->invokePrivateMethod(
            'mergeChunkResult',
            [$result, $chunkResult, 0, 1]
        );

        $this->assertCount(2, $mergedResult['invalid'], 'Invalid should have 2 items.');
        $this->assertCount(2, $mergedResult['errors'], 'Errors should have 2 items.');
        $this->assertEquals(2, $mergedResult['statistics']['invalid'], 'Invalid stat should be 2.');
        $this->assertEquals(2, $mergedResult['statistics']['errors'], 'Errors stat should be 2.');
    }

    /**
     * Test mergeChunkResult with mixed results.
     *
     * @return void
     */
    public function testMergeChunkResultWithMixedResults(): void
    {
        $result = [
            'saved'      => ['obj-1'],
            'updated'    => ['obj-2'],
            'unchanged'  => [],
            'invalid'    => [],
            'errors'     => [],
            'statistics' => [
                'totalProcessed' => 10,
                'saved'          => 1,
                'updated'        => 1,
                'unchanged'      => 0,
                'invalid'        => 0,
                'errors'         => 0,
                'processingTimeMs' => 0,
            ]
        ];

        $chunkResult = [
            'saved'      => ['obj-3'],
            'updated'    => ['obj-4'],
            'unchanged'  => ['obj-5'],
            'invalid'    => ['obj-6'],
            'errors'     => ['error-1'],
            'statistics' => [
                'saved'     => 1,
                'updated'   => 1,
                'unchanged' => 1,
                'invalid'   => 1,
                'errors'    => 1,
            ]
        ];

        $mergedResult = $this->invokePrivateMethod(
            'mergeChunkResult',
            [$result, $chunkResult, 1, 4]
        );

        $this->assertCount(2, $mergedResult['saved'], 'Saved should have 2 items.');
        $this->assertCount(2, $mergedResult['updated'], 'Updated should have 2 items.');
        $this->assertCount(1, $mergedResult['unchanged'], 'Unchanged should have 1 item.');
        $this->assertCount(1, $mergedResult['invalid'], 'Invalid should have 1 item.');
        $this->assertEquals(2, $mergedResult['statistics']['saved'], 'Saved stat should be 2.');
        $this->assertEquals(2, $mergedResult['statistics']['updated'], 'Updated stat should be 2.');
        $this->assertEquals(1, $mergedResult['statistics']['unchanged'], 'Unchanged stat should be 1.');
    }

    // ==================== calculatePerformanceMetrics() Tests ====================

    /**
     * Test calculatePerformanceMetrics adds timing information.
     *
     * @return void
     */
    public function testCalculatePerformanceMetricsAddsTimingInfo(): void
    {
        // Method signature: calculatePerformanceMetrics(float $startTime, int $processedCount, int $totalRequested, int $unchangedCount)
        $startTime = microtime(true) - 2.5; // 2.5 seconds ago.

        $performance = $this->invokePrivateMethod(
            'calculatePerformanceMetrics',
            [$startTime, 4, 4, 0]
        );

        $this->assertIsArray($performance, 'Performance should be an array.');
        $this->assertArrayHasKey('totalTime', $performance, 'Should have totalTime.');
        $this->assertArrayHasKey('totalTimeMs', $performance, 'Should have totalTimeMs.');
        $this->assertArrayHasKey('objectsPerSecond', $performance, 'Should have objectsPerSecond.');
        $this->assertArrayHasKey('totalProcessed', $performance, 'Should have totalProcessed.');
        $this->assertArrayHasKey('totalRequested', $performance, 'Should have totalRequested.');
        $this->assertArrayHasKey('efficiency', $performance, 'Should have efficiency.');

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
            'calculatePerformanceMetrics',
            [$startTime, 3, 4, 0]
        );

        $this->assertEquals(75.0, $performance['efficiency'], 'Efficiency should be 75%.');
        $this->assertEquals(3, $performance['totalProcessed'], 'Processed should be 3.');
        $this->assertEquals(4, $performance['totalRequested'], 'Requested should be 4.');
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
            'calculatePerformanceMetrics',
            [$startTime, 0, 0, 0]
        );

        $this->assertEquals(0, $performance['efficiency'], 'Efficiency should be 0.');
        $this->assertEquals(0, $performance['totalProcessed'], 'Processed should be 0.');
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
            'calculatePerformanceMetrics',
            [$startTime, 1, 1, 0]
        );

        // Check that values are numeric.
        $this->assertIsNumeric($performance['totalTime'], 'Total time should be numeric.');
        $this->assertIsNumeric($performance['totalTimeMs'], 'Total time ms should be numeric.');
        $this->assertIsNumeric($performance['objectsPerSecond'], 'Throughput should be numeric.');
    }

    /**
     * Test calculatePerformanceMetrics with unchanged objects.
     *
     * @return void
     */
    public function testCalculatePerformanceMetricsWithUnchanged(): void
    {
        $startTime = microtime(true);

        $performance = $this->invokePrivateMethod(
            'calculatePerformanceMetrics',
            [$startTime, 3, 5, 2]
        );

        $this->assertArrayHasKey('deduplicationEfficiency', $performance, 'Should have deduplication efficiency.');
        $this->assertStringContainsString('operations avoided', $performance['deduplicationEfficiency']);
    }
}
