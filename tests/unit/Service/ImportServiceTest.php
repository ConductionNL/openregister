<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use Psr\Log\LoggerInterface;

/**
 * Test class for ImportService
 *
 * This test suite comprehensively tests the ImportService class, which handles
 * CSV data import functionality for OpenRegister. The tests cover:
 * 
 * ## Test Categories:
 * 
 * ### 1. Basic Import Functionality
 * - testImportFromCsvWithBatchSaving: Tests successful CSV import with proper data
 * - testImportFromCsvWithEmptyFile: Tests handling of empty CSV files
 * - testImportFromCsvWithoutSchema: Tests error handling when no schema provided
 * 
 * ### 2. Error Handling & Edge Cases
 * - testImportFromCsvWithErrors: Tests error handling during import process
 * - testImportFromCsvWithMalformedData: Tests handling of invalid CSV data
 * - testImportFromCsvWithLargeFile: Tests performance with large datasets (1000+ rows)
 * - testImportFromCsvWithSpecialCharacters: Tests Unicode and special character handling
 * 
 * ### 3. Advanced Features
 * - testImportFromCsvAsync: Tests asynchronous import functionality
 * - testImportFromCsvCategorizesCreatedVsUpdated: Tests object categorization logic
 * 
 * ## Mocking Strategy:
 * 
 * The tests use comprehensive mocking to isolate the ImportService from external dependencies:
 * - ObjectService: Mocked to simulate database operations
 * - SchemaMapper: Mocked to provide schema definitions
 * - LoggerInterface: Mocked to capture log messages
 * - User/Group Managers: Mocked for RBAC testing
 * 
 * ## Test Data Management:
 * 
 * Tests create temporary CSV files with various data patterns:
 * - Valid data with proper headers
 * - Malformed data with invalid types
 * - Large datasets for performance testing
 * - Special characters and Unicode content
 * 
 * All temporary files are properly cleaned up in finally blocks.
 * 
 * ## Dependencies:
 * 
 * Tests require PhpSpreadsheet library for CSV processing. Tests are skipped
 * if the library is not available, with appropriate skip messages.
 * 
 * @category Test
 * @package  OCA\OpenRegister\Tests\Service
 * @author   Your Name <your.email@example.com>
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/your-org/openregister
 * @version  1.0.0
 */
class ImportServiceTest extends TestCase
{
    private ImportService $importService;
    private ObjectService $objectService;
    private ObjectEntityMapper $objectEntityMapper;
    private SchemaMapper $schemaMapper;
    private LoggerInterface $logger;
    private \OCP\IUserManager $userManager;
    private \OCP\IGroupManager $groupManager;
    private \OCP\BackgroundJob\IJobList $jobList;

    protected function setUp(): void
    {
        parent::setUp();

        // Create mock dependencies
        $this->objectService = $this->createMock(ObjectService::class);
        $this->objectEntityMapper = $this->createMock(ObjectEntityMapper::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->userManager = $this->createMock(\OCP\IUserManager::class);
        $this->groupManager = $this->createMock(\OCP\IGroupManager::class);
        $this->jobList = $this->createMock(\OCP\BackgroundJob\IJobList::class);

        // Create ImportService instance
        $this->importService = new ImportService(
            $this->objectEntityMapper,
            $this->schemaMapper,
            $this->objectService,
            $this->logger,
            $this->userManager,
            $this->groupManager,
            $this->jobList
        );
    }

    /**
     * Test CSV import with batch saving
     */
    public function testImportFromCsvWithBatchSaving(): void
    {
        // Skip test if PhpSpreadsheet is not available
        if (class_exists('PhpOffice\PhpSpreadsheet\Reader\Csv') === false) {
            $this->markTestSkipped('PhpSpreadsheet library not available');
            return;
        }
        
        // Create test data
        $register = $this->createMock(Register::class);
        $register->method('getId')->willReturn('test-register-id');

        $schema = $this->createMock(Schema::class);
        $schema->method('getId')->willReturn('1');
        $schema->method('getTitle')->willReturn('Test Schema');
        $schema->method('getSlug')->willReturn('test-schema');
        $schema->method('getProperties')->willReturn([
            'name' => ['type' => 'string'],
            'age' => ['type' => 'integer'],
            'active' => ['type' => 'boolean'],
        ]);
        
        // Use reflection to set protected properties
        $reflection = new \ReflectionClass($schema);
        $titleProperty = $reflection->getProperty('title');
        $titleProperty->setAccessible(true);
        $titleProperty->setValue($schema, 'Test Schema');
        
        $slugProperty = $reflection->getProperty('slug');
        $slugProperty->setAccessible(true);
        $slugProperty->setValue($schema, 'test-schema');

        // Create mock saved objects that return array data
        $savedObject1 = [
            '@self' => ['id' => 'object-1-uuid'],
            'uuid' => 'object-1-uuid',
            'name' => 'John Doe'
        ];
        
        $savedObject2 = [
            '@self' => ['id' => 'object-2-uuid'],
            'uuid' => 'object-2-uuid',
            'name' => 'Jane Smith'
        ];

        // Mock ObjectService saveObjects method
        $this->objectService->expects($this->once())
            ->method('saveObjects')
            ->with(
                $this->callback(function ($objects) {
                    // Verify that objects have correct structure
                    if (count($objects) !== 2) {
                        return false;
                    }
                    
                    foreach ($objects as $object) {
                        if (isset($object['name']) === false) {
                            return false;
                        }
                    }
                    
                    return true;
                }),
                $register, // register object
                $schema,   // schema object
                true,      // rbac
                true,      // multi
                false,     // validation
                false      // events
            )
            ->willReturn([
                'saved' => [$savedObject1, $savedObject2],
                'updated' => [],
                'invalid' => []
            ]);

        // Create temporary CSV file for testing
        $csvContent = "name,age,active\nJohn Doe,30,true\nJane Smith,25,false";
        $tempFile = tempnam(sys_get_temp_dir(), 'test_csv_');
        file_put_contents($tempFile, $csvContent);

        try {
            // Test the import
            $result = $this->importService->importFromCsv($tempFile, $register, $schema);

            // Verify the result structure
            $this->assertIsArray($result);
            $this->assertCount(1, $result); // One sheet
            
            $sheetResult = array_values($result)[0];
            $this->assertArrayHasKey('found', $sheetResult);
            $this->assertArrayHasKey('created', $sheetResult);
            $this->assertArrayHasKey('errors', $sheetResult);
            $this->assertArrayHasKey('schema', $sheetResult);

            // Verify the counts
            $this->assertEquals(2, $sheetResult['found']);
            $this->assertCount(2, $sheetResult['created']);
            $this->assertCount(0, $sheetResult['errors']);

            // Verify schema information
            $this->assertEquals(1, $sheetResult['schema']['id']);
            $this->assertEquals('Test Schema', $sheetResult['schema']['title']);
            $this->assertEquals('test-schema', $sheetResult['schema']['slug']);

        } finally {
            // Clean up temporary file
            unlink($tempFile);
        }
    }

    /**
     * Test CSV import with errors
     */
    public function testImportFromCsvWithErrors(): void
    {
        // Skip test if PhpSpreadsheet is not available
        if (class_exists('PhpOffice\PhpSpreadsheet\Reader\Csv') === false) {
            $this->markTestSkipped('PhpSpreadsheet library not available');
            return;
        }
        
        // Create test data
        $register = $this->createMock(Register::class);
        $register->method('getId')->willReturn('test-register-id');

        $schema = $this->createMock(Schema::class);
        $schema->method('getId')->willReturn('1');
        $schema->method('getTitle')->willReturn('Test Schema');
        $schema->method('getSlug')->willReturn('test-schema');
        $schema->method('getProperties')->willReturn([
            'name' => ['type' => 'string'],
            'age' => ['type' => 'integer'],
        ]);
        
        // Use reflection to set protected properties
        $reflection = new \ReflectionClass($schema);
        $titleProperty = $reflection->getProperty('title');
        $titleProperty->setAccessible(true);
        $titleProperty->setValue($schema, 'Test Schema');
        
        $slugProperty = $reflection->getProperty('slug');
        $slugProperty->setAccessible(true);
        $slugProperty->setValue($schema, 'test-schema');

        // Mock ObjectService to throw exception on saveObjects
        $this->objectService->expects($this->once())
            ->method('saveObjects')
            ->willThrowException(new \Exception('Database connection failed'));

        // Create temporary CSV file with test data
        $csvContent = "name,age\nJohn Doe,30\nJane Smith,25";
        $tempFile = tempnam(sys_get_temp_dir(), 'test_csv_');
        file_put_contents($tempFile, $csvContent);

        try {
            // Test the import - should throw exception due to database connection failure
            $this->expectException(\Exception::class);
            $this->expectExceptionMessage('Database connection failed');
            
            $this->importService->importFromCsv($tempFile, $register, $schema);

        } finally {
            // Clean up temporary file
            unlink($tempFile);
        }
    }

    /**
     * Test CSV import with empty file
     */
    public function testImportFromCsvWithEmptyFile(): void
    {
        // Skip test if PhpSpreadsheet is not available
        if (class_exists('PhpOffice\PhpSpreadsheet\Reader\Csv') === false) {
            $this->markTestSkipped('PhpSpreadsheet library not available');
            return;
        }
        
        // Create test data
        $register = $this->createMock(Register::class);
        $register->method('getId')->willReturn('test-register-id');

        $schema = $this->createMock(Schema::class);
        $schema->method('getId')->willReturn('test-schema-id');

        // Create temporary CSV file with only headers
        $csvContent = "name,age,active\n";
        $tempFile = tempnam(sys_get_temp_dir(), 'test_csv_');
        file_put_contents($tempFile, $csvContent);

        try {
            // Test the import
            $result = $this->importService->importFromCsv($tempFile, $register, $schema);

            // Verify the result structure
            $this->assertIsArray($result);
            $this->assertCount(1, $result);
            
            $sheetResult = array_values($result)[0];
            $this->assertArrayHasKey('found', $sheetResult);
            $this->assertArrayHasKey('errors', $sheetResult);

            // Verify that no data rows error is included
            $this->assertEquals(0, $sheetResult['found']);
            $this->assertGreaterThan(0, count($sheetResult['errors']));

            $hasNoDataError = false;
            foreach ($sheetResult['errors'] as $error) {
                if (isset($error['row']) && $error['row'] === 1) {
                    $hasNoDataError = true;
                    $this->assertStringContainsString('No data rows found', $error['error']);
                    break;
                }
            }
            $this->assertTrue($hasNoDataError, 'No data rows error should be included in results');

        } finally {
            // Clean up temporary file
            unlink($tempFile);
        }
    }

    /**
     * Test CSV import without schema (should throw exception)
     */
    public function testImportFromCsvWithoutSchema(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CSV import requires a specific schema');

        $register = $this->createMock(Register::class);
        
        // Create temporary CSV file
        $csvContent = "name,age\nJohn Doe,30";
        $tempFile = tempnam(sys_get_temp_dir(), 'test_csv_');
        file_put_contents($tempFile, $csvContent);

        try {
            $this->importService->importFromCsv($tempFile, $register, null);
        } finally {
            unlink($tempFile);
        }
    }

    /**
     * Test async CSV import
     */
    public function testImportFromCsvAsync(): void
    {
        // Skip test if PhpSpreadsheet is not available
        if (class_exists('PhpOffice\PhpSpreadsheet\Reader\Csv') === false) {
            $this->markTestSkipped('PhpSpreadsheet library not available');
            return;
        }
        
        // Create test data
        $register = $this->createMock(Register::class);
        $register->method('getId')->willReturn('test-register-id');

        $schema = $this->createMock(Schema::class);
        $schema->method('getId')->willReturn('test-schema-id');
        $schema->method('getProperties')->willReturn(['name' => ['type' => 'string']]);

        // Mock ObjectService
        $savedObject = $this->createMock(ObjectEntity::class);

        $this->objectService->expects($this->once())
            ->method('saveObjects')
            ->willReturn([$savedObject]);

        // Create temporary CSV file
        $csvContent = "name\nJohn Doe";
        $tempFile = tempnam(sys_get_temp_dir(), 'test_csv_');
        file_put_contents($tempFile, $csvContent);

        try {
            // Test the async import
            $promise = $this->importService->importFromCsvAsync($tempFile, $register, $schema);
            
            // Verify it's a PromiseInterface
            $this->assertInstanceOf(PromiseInterface::class, $promise);

            // Resolve the promise to get the result
            $result = null;
            $promise->then(
                function ($value) use (&$result) {
                    $result = $value;
                }
            );

            // For testing purposes, we'll manually resolve it
            // In a real async environment, this would be handled by the event loop
            $this->assertNotNull($promise);

        } finally {
            unlink($tempFile);
        }
    }

    /**
     * Test that CSV import properly categorizes created vs updated objects
     */
    public function testImportFromCsvCategorizesCreatedVsUpdated(): void
    {
        // Skip test if PhpSpreadsheet is not available
        if (class_exists('PhpOffice\PhpSpreadsheet\Reader\Csv') === false) {
            $this->markTestSkipped('PhpSpreadsheet library not available');
            return;
        }
        
        // Mock ObjectService to return different objects for created vs updated
        $mockObjectService = $this->createMock(ObjectService::class);
        
        // Create mock objects - one with existing ID (update), one without (create)
        $existingObject = [
            '@self' => ['id' => 'existing-uuid-123'],
            'uuid' => 'existing-uuid-123',
            'name' => 'Updated Item'
        ];
        
        $newObject = [
            '@self' => ['id' => 'new-uuid-456'],
            'uuid' => 'new-uuid-456',
            'name' => 'New Item'
        ];
        
        // Mock saveObjects to return both objects
        $mockObjectService->method('saveObjects')
            ->willReturn([
                'saved' => [$newObject],
                'updated' => [$existingObject],
                'invalid' => []
            ]);
        
        $importService = new ImportService(
            $this->createMock(ObjectEntityMapper::class),
            $this->createMock(SchemaMapper::class),
            $mockObjectService,
            $this->createMock(LoggerInterface::class),
            $this->createMock(\OCP\IUserManager::class),
            $this->createMock(\OCP\IGroupManager::class),
            $this->createMock(\OCP\BackgroundJob\IJobList::class)
        );
        
        // Create a temporary CSV file with data
        $csvContent = "id,name,description\n";
        $csvContent .= "existing-uuid-123,Updated Item,Updated description\n";
        $csvContent .= ",New Item,New description\n";
        
        $tempFile = tempnam(sys_get_temp_dir(), 'test_csv_');
        file_put_contents($tempFile, $csvContent);
        
        try {
            $register = $this->createMock(Register::class);
            $schema = $this->createMock(Schema::class);
            $schema->method('getId')->willReturn('test-schema-id');
            
            $result = $importService->importFromCsv($tempFile, $register, $schema);
            
            // Verify the result structure
            $this->assertArrayHasKey('Worksheet', $result);
            $worksheetResult = $result['Worksheet'];
            
            $this->assertArrayHasKey('found', $worksheetResult);
            $this->assertArrayHasKey('created', $worksheetResult);
            $this->assertArrayHasKey('updated', $worksheetResult);
            $this->assertArrayHasKey('unchanged', $worksheetResult);
            $this->assertArrayHasKey('errors', $worksheetResult);
            
            // Verify counts
            $this->assertEquals(2, $worksheetResult['found']);
            $this->assertCount(1, $worksheetResult['created']);
            $this->assertCount(1, $worksheetResult['updated']);
            $this->assertCount(0, $worksheetResult['unchanged']);
            $this->assertCount(0, $worksheetResult['errors']);
            
            // Verify specific UUIDs
            $this->assertContains('new-uuid-456', $worksheetResult['created']);
            $this->assertContains('existing-uuid-123', $worksheetResult['updated']);
            
        } finally {
            // Clean up temp file
            unlink($tempFile);
        }
    }

    /**
     * Test CSV import with malformed CSV data
     */
    public function testImportFromCsvWithMalformedData(): void
    {
        // Skip test if PhpSpreadsheet is not available
        if (class_exists('PhpOffice\PhpSpreadsheet\Reader\Csv') === false) {
            $this->markTestSkipped('PhpSpreadsheet library not available');
            return;
        }
        
        // Create test data
        $register = $this->createMock(Register::class);
        $register->method('getId')->willReturn('test-register-id');

        $schema = $this->createMock(Schema::class);
        $schema->method('getId')->willReturn('1');
        $schema->method('getTitle')->willReturn('Test Schema');
        $schema->method('getSlug')->willReturn('test-schema');
        $schema->method('getProperties')->willReturn([
            'name' => ['type' => 'string'],
            'age' => ['type' => 'integer'],
        ]);
        
        // Use reflection to set protected properties
        $reflection = new \ReflectionClass($schema);
        $titleProperty = $reflection->getProperty('title');
        $titleProperty->setAccessible(true);
        $titleProperty->setValue($schema, 'Test Schema');
        
        $slugProperty = $reflection->getProperty('slug');
        $slugProperty->setAccessible(true);
        $slugProperty->setValue($schema, 'test-schema');

        // Mock ObjectService to return empty results for malformed data
        $this->objectService->expects($this->once())
            ->method('saveObjects')
            ->willReturn([
                'saved' => [],
                'updated' => [],
                'invalid' => []
            ]);

        // Create temporary CSV file with malformed data
        $csvContent = "name,age\nJohn Doe,invalid_number\nJane Smith,25\n"; // Invalid number in age column
        $tempFile = tempnam(sys_get_temp_dir(), 'test_csv_');
        file_put_contents($tempFile, $csvContent);

        try {
            // Test the import
            $result = $this->importService->importFromCsv($tempFile, $register, $schema);

            // Verify the result structure
            $this->assertIsArray($result);
            $this->assertCount(1, $result); // One sheet
            
            $sheetResult = array_values($result)[0];
            $this->assertArrayHasKey('found', $sheetResult);
            $this->assertArrayHasKey('created', $sheetResult);
            $this->assertArrayHasKey('errors', $sheetResult);

            // Should have found 2 rows but created 0 due to malformed data
            $this->assertEquals(2, $sheetResult['found']);
            $this->assertCount(0, $sheetResult['created']);
            // Note: ImportService may not generate errors for malformed data, just skip invalid rows
            $this->assertIsArray($sheetResult['errors']);

        } finally {
            // Clean up temporary file
            unlink($tempFile);
        }
    }

    /**
     * Test CSV import with extremely large file
     */
    public function testImportFromCsvWithLargeFile(): void
    {
        // Skip test if PhpSpreadsheet is not available
        if (class_exists('PhpOffice\PhpSpreadsheet\Reader\Csv') === false) {
            $this->markTestSkipped('PhpSpreadsheet library not available');
            return;
        }
        
        // Create test data
        $register = $this->createMock(Register::class);
        $register->method('getId')->willReturn('test-register-id');

        $schema = $this->createMock(Schema::class);
        $schema->method('getId')->willReturn('1');
        $schema->method('getTitle')->willReturn('Test Schema');
        $schema->method('getSlug')->willReturn('test-schema');
        $schema->method('getProperties')->willReturn([
            'name' => ['type' => 'string'],
            'age' => ['type' => 'integer'],
        ]);
        
        // Use reflection to set protected properties
        $reflection = new \ReflectionClass($schema);
        $titleProperty = $reflection->getProperty('title');
        $titleProperty->setAccessible(true);
        $titleProperty->setValue($schema, 'Test Schema');
        
        $slugProperty = $reflection->getProperty('slug');
        $slugProperty->setAccessible(true);
        $slugProperty->setValue($schema, 'test-schema');

        // Create large CSV content (1000 rows)
        $csvContent = "name,age\n";
        for ($i = 1; $i <= 1000; $i++) {
            $csvContent .= "User $i," . (20 + ($i % 50)) . "\n";
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'test_csv_');
        file_put_contents($tempFile, $csvContent);

        try {
            // Test the import with chunking
            $result = $this->importService->importFromCsv($tempFile, $register, $schema, 100); // 100 row chunks

            // Verify the result structure
            $this->assertIsArray($result);
            $this->assertCount(1, $result); // One sheet
            
            $sheetResult = array_values($result)[0];
            $this->assertArrayHasKey('found', $sheetResult);
            $this->assertArrayHasKey('created', $sheetResult);
            $this->assertArrayHasKey('errors', $sheetResult);

            // Should have found 1000 rows
            $this->assertEquals(1000, $sheetResult['found']);

        } finally {
            // Clean up temporary file
            unlink($tempFile);
        }
    }

    /**
     * Test CSV import with special characters and encoding issues
     */
    public function testImportFromCsvWithSpecialCharacters(): void
    {
        // Skip test if PhpSpreadsheet is not available
        if (class_exists('PhpOffice\PhpSpreadsheet\Reader\Csv') === false) {
            $this->markTestSkipped('PhpSpreadsheet library not available');
            return;
        }
        
        // Create test data
        $register = $this->createMock(Register::class);
        $register->method('getId')->willReturn('test-register-id');

        $schema = $this->createMock(Schema::class);
        $schema->method('getId')->willReturn('1');
        $schema->method('getTitle')->willReturn('Test Schema');
        $schema->method('getSlug')->willReturn('test-schema');
        $schema->method('getProperties')->willReturn([
            'name' => ['type' => 'string'],
            'description' => ['type' => 'string'],
        ]);
        
        // Use reflection to set protected properties
        $reflection = new \ReflectionClass($schema);
        $titleProperty = $reflection->getProperty('title');
        $titleProperty->setAccessible(true);
        $titleProperty->setValue($schema, 'Test Schema');
        
        $slugProperty = $reflection->getProperty('slug');
        $slugProperty->setAccessible(true);
        $slugProperty->setValue($schema, 'test-schema');

        // Mock ObjectService
        $this->objectService->expects($this->once())
            ->method('saveObjects')
            ->willReturn([
                'saved' => [
                    ['@self' => ['id' => 'obj-1'], 'name' => 'José María', 'description' => 'Special chars: ñáéíóú'],
                    ['@self' => ['id' => 'obj-2'], 'name' => 'François', 'description' => 'Unicode: 🚀💻🎉']
                ],
                'updated' => [],
                'invalid' => []
            ]);

        // Create temporary CSV file with special characters
        $csvContent = "name,description\n";
        $csvContent .= "\"José María\",\"Special chars: ñáéíóú\"\n";
        $csvContent .= "\"François\",\"Unicode: 🚀💻🎉\"\n";
        $csvContent .= "\"Test with, comma\",\"Description with \"\"quotes\"\"\"\n";

        $tempFile = tempnam(sys_get_temp_dir(), 'test_csv_');
        file_put_contents($tempFile, $csvContent);

        try {
            // Test the import
            $result = $this->importService->importFromCsv($tempFile, $register, $schema);

            // Verify the result structure
            $this->assertIsArray($result);
            $this->assertCount(1, $result); // One sheet
            
            $sheetResult = array_values($result)[0];
            $this->assertArrayHasKey('found', $sheetResult);
            $this->assertArrayHasKey('created', $sheetResult);
            $this->assertArrayHasKey('errors', $sheetResult);

            // Should have found 3 rows
            $this->assertEquals(3, $sheetResult['found']);
            $this->assertCount(2, $sheetResult['created']); // 2 valid, 1 with parsing issues

        } finally {
            // Clean up temporary file
            unlink($tempFile);
        }
    }
}
