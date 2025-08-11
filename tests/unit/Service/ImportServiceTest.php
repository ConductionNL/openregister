<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Service;

use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\Entity\Register;
use OCA\OpenRegister\Db\Entity\Schema;
use OCA\OpenRegister\Db\Entity\ObjectEntity;
use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;

/**
 * Test class for ImportService
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

    protected function setUp(): void
    {
        parent::setUp();

        // Create mock dependencies
        $this->objectService = $this->createMock(ObjectService::class);
        $this->objectEntityMapper = $this->createMock(ObjectEntityMapper::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);

        // Create ImportService instance
        $this->importService = new ImportService(
            $this->objectEntityMapper,
            $this->schemaMapper,
            $this->objectService
        );
    }

    /**
     * Test CSV import with batch saving
     */
    public function testImportFromCsvWithBatchSaving(): void
    {
        // Create test data
        $register = $this->createMock(Register::class);
        $register->method('getId')->willReturn(1);
        $register->method('getTitle')->willReturn('Test Register');

        $schema = $this->createMock(Schema::class);
        $schema->method('getId')->willReturn(1);
        $schema->method('getTitle')->willReturn('Test Schema');
        $schema->method('getSlug')->willReturn('test-schema');
        $schema->method('getProperties')->willReturn([
            'name' => ['type' => 'string'],
            'age' => ['type' => 'integer'],
            'active' => ['type' => 'boolean'],
        ]);

        // Create mock saved objects
        $savedObject1 = $this->createMock(ObjectEntity::class);
        $savedObject1->method('getUuid')->willReturn('uuid-1');
        
        $savedObject2 = $this->createMock(ObjectEntity::class);
        $savedObject2->method('getUuid')->willReturn('uuid-2');

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
                        if (!isset($object['@self']['register']) || 
                            !isset($object['@self']['schema']) ||
                            !isset($object['name'])) {
                            return false;
                        }
                    }
                    
                    return true;
                }),
                1, // register
                1   // schema
            )
            ->willReturn([$savedObject1, $savedObject2]);

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
        // Create test data
        $register = $this->createMock(Register::class);
        $register->method('getId')->willReturn(1);

        $schema = $this->createMock(Schema::class);
        $schema->method('getId')->willReturn(1);
        $schema->method('getTitle')->willReturn('Test Schema');
        $schema->method('getSlug')->willReturn('test-schema');
        $schema->method('getProperties')->willReturn([]);

        // Mock ObjectService to throw an exception
        $this->objectService->expects($this->once())
            ->method('saveObjects')
            ->willThrowException(new \Exception('Database connection failed'));

        // Create temporary CSV file for testing
        $csvContent = "name,age\nJohn Doe,30\nJane Smith,25";
        $tempFile = tempnam(sys_get_temp_dir(), 'test_csv_');
        file_put_contents($tempFile, $csvContent);

        try {
            // Test the import
            $result = $this->importService->importFromCsv($tempFile, $register, $schema);

            // Verify the result structure
            $this->assertIsArray($result);
            $this->assertCount(1, $result);
            
            $sheetResult = array_values($result)[0];
            $this->assertArrayHasKey('errors', $sheetResult);
            $this->assertGreaterThan(0, count($sheetResult['errors']));

            // Verify that batch save error is included
            $hasBatchError = false;
            foreach ($sheetResult['errors'] as $error) {
                if (isset($error['row']) && $error['row'] === 'batch') {
                    $hasBatchError = true;
                    $this->assertStringContainsString('Batch save failed', $error['error']);
                    break;
                }
            }
            $this->assertTrue($hasBatchError, 'Batch save error should be included in results');

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
        // Create test data
        $register = $this->createMock(Register::class);
        $register->method('getId')->willReturn(1);

        $schema = $this->createMock(Schema::class);
        $schema->method('getId')->willReturn(1);
        $schema->method('getTitle')->willReturn('Test Schema');
        $schema->method('getSlug')->willReturn('test-schema');

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
        // Create test data
        $register = $this->createMock(Register::class);
        $register->method('getId')->willReturn(1);

        $schema = $this->createMock(Schema::class);
        $schema->method('getId')->willReturn(1);
        $schema->method('getTitle')->willReturn('Test Schema');
        $schema->method('getSlug')->willReturn('test-schema');
        $schema->method('getProperties')->willReturn(['name' => ['type' => 'string']]);

        // Mock ObjectService
        $savedObject = $this->createMock(ObjectEntity::class);
        $savedObject->method('getUuid')->willReturn('uuid-1');

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
        // Mock ObjectService to return different objects for created vs updated
        $mockObjectService = $this->createMock(ObjectService::class);
        
        // Create mock objects - one with existing ID (update), one without (create)
        $existingObject = $this->createMock(ObjectEntity::class);
        $existingObject->method('getUuid')->willReturn('existing-uuid-123');
        
        $newObject = $this->createMock(ObjectEntity::class);
        $newObject->method('getUuid')->willReturn('new-uuid-456');
        
        // Mock saveObjects to return both objects
        $mockObjectService->method('saveObjects')
            ->willReturn([$existingObject, $newObject]);
        
        $importService = new ImportService(
            $this->createMock(ObjectEntityMapper::class),
            $this->createMock(SchemaMapper::class),
            $mockObjectService
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
}
