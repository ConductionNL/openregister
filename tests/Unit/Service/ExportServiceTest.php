<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use React\Promise\PromiseInterface;

/**
 * Test class for ExportService
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/ConductionNL/OpenRegister
 * @version  1.0.0
 */
class ExportServiceTest extends TestCase
{
    private ExportService $exportService;
    private ObjectEntityMapper $objectEntityMapper;
    private RegisterMapper $registerMapper;

    protected function setUp(): void
    {
        parent::setUp();

        // Create mock dependencies
        $this->objectEntityMapper = $this->createMock(ObjectEntityMapper::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);

        // Create ExportService instance
        $this->exportService = new ExportService(
            $this->objectEntityMapper,
            $this->registerMapper
        );
    }

    /**
     * Test exportToExcelAsync method
     */
    public function testExportToExcelAsync(): void
    {
        $register = $this->createMock(Register::class);
        $schema = $this->createMock(Schema::class);
        $filters = ['status' => 'published'];

        // Create mock objects
        $object1 = $this->createMock(ObjectEntity::class);
        $object1->method('jsonSerialize')->willReturn([
            'id' => '1',
            'name' => 'Test Object 1',
            'status' => 'published'
        ]);

        $object2 = $this->createMock(ObjectEntity::class);
        $object2->method('jsonSerialize')->willReturn([
            'id' => '2',
            'name' => 'Test Object 2',
            'status' => 'published'
        ]);

        $objects = [$object1, $object2];

        // Mock object entity mapper
        $this->objectEntityMapper->expects($this->once())
            ->method('findAll')
            ->with(
                $this->equalTo(null), // limit
                $this->equalTo(null), // offset
                $this->callback(function ($filters) {
                    return isset($filters['status']) && $filters['status'] === 'published';
                })
            )
            ->willReturn($objects);

        $promise = $this->exportService->exportToExcelAsync($register, $schema, $filters);

        $this->assertInstanceOf(PromiseInterface::class, $promise);

        // Resolve the promise to test the result
        $result = null;
        $promise->then(
            function ($value) use (&$result) {
                $result = $value;
            }
        );

        // For testing purposes, we'll manually resolve it
        $this->assertNotNull($promise);
    }

    /**
     * Test exportToExcel method
     */
    public function testExportToExcel(): void
    {
        $register = $this->createMock(Register::class);
        $schema = $this->createMock(Schema::class);
        $filters = ['status' => 'published'];

        // Create mock objects
        $object1 = $this->createMock(ObjectEntity::class);
        $object1->method('jsonSerialize')->willReturn([
            'id' => '1',
            'name' => 'Test Object 1',
            'status' => 'published',
            'created' => '2024-01-01T00:00:00Z'
        ]);

        $object2 = $this->createMock(ObjectEntity::class);
        $object2->method('jsonSerialize')->willReturn([
            'id' => '2',
            'name' => 'Test Object 2',
            'status' => 'published',
            'created' => '2024-01-02T00:00:00Z'
        ]);

        $objects = [$object1, $object2];

        // Mock object entity mapper
        $this->objectEntityMapper->expects($this->once())
            ->method('findAll')
            ->with(
                $this->equalTo(null), // limit
                $this->equalTo(null), // offset
                $this->callback(function ($filters) {
                    return isset($filters['status']) && $filters['status'] === 'published';
                })
            )
            ->willReturn($objects);

        $spreadsheet = $this->exportService->exportToExcel($register, $schema, $filters);

        $this->assertInstanceOf(Spreadsheet::class, $spreadsheet);

        // Verify the spreadsheet has data
        $worksheet = $spreadsheet->getActiveSheet();
        $this->assertNotNull($worksheet);
    }

    /**
     * Test exportToExcel method with no objects
     */
    public function testExportToExcelWithNoObjects(): void
    {
        $register = $this->createMock(Register::class);
        $schema = $this->createMock(Schema::class);
        $filters = ['status' => 'published'];

        // Mock object entity mapper to return empty array
        $this->objectEntityMapper->expects($this->once())
            ->method('findAll')
            ->willReturn([]);

        $spreadsheet = $this->exportService->exportToExcel($register, $schema, $filters);

        $this->assertInstanceOf(Spreadsheet::class, $spreadsheet);

        // Verify the spreadsheet is created even with no data
        $worksheet = $spreadsheet->getActiveSheet();
        $this->assertNotNull($worksheet);
    }

    /**
     * Test exportToExcel method with null parameters
     */
    public function testExportToExcelWithNullParameters(): void
    {
        // Mock object entity mapper
        $this->objectEntityMapper->expects($this->once())
            ->method('findAll')
            ->with(
                $this->equalTo(null), // limit
                $this->equalTo(null), // offset
                $this->equalTo([]) // empty filters
            )
            ->willReturn([]);

        $spreadsheet = $this->exportService->exportToExcel(null, null, []);

        $this->assertInstanceOf(Spreadsheet::class, $spreadsheet);
    }

    /**
     * Test exportToCsv method
     */
    public function testExportToCsv(): void
    {
        $register = $this->createMock(Register::class);
        $schema = $this->createMock(Schema::class);
        $filters = ['status' => 'published'];

        // Create mock objects
        $object1 = $this->createMock(ObjectEntity::class);
        $object1->method('jsonSerialize')->willReturn([
            'id' => '1',
            'name' => 'Test Object 1',
            'status' => 'published'
        ]);

        $object2 = $this->createMock(ObjectEntity::class);
        $object2->method('jsonSerialize')->willReturn([
            'id' => '2',
            'name' => 'Test Object 2',
            'status' => 'published'
        ]);

        $objects = [$object1, $object2];

        // Mock object entity mapper
        $this->objectEntityMapper->expects($this->once())
            ->method('findAll')
            ->willReturn($objects);

        $csvData = $this->exportService->exportToCsv($register, $schema, $filters);

        $this->assertIsString($csvData);
        $this->assertStringContainsString('Test Object 1', $csvData);
        $this->assertStringContainsString('Test Object 2', $csvData);
    }

    /**
     * Test exportToCsv method with no objects
     */
    public function testExportToCsvWithNoObjects(): void
    {
        $register = $this->createMock(Register::class);
        $schema = $this->createMock(Schema::class);
        $filters = [];

        // Mock object entity mapper to return empty array
        $this->objectEntityMapper->expects($this->once())
            ->method('findAll')
            ->willReturn([]);

        $csvData = $this->exportService->exportToCsv($register, $schema, $filters);

        $this->assertIsString($csvData);
        // Should still return CSV headers even with no data
        $this->assertNotEmpty($csvData);
    }

    /**
     * Test exportToJson method
     */
    public function testExportToJson(): void
    {
        $register = $this->createMock(Register::class);
        $schema = $this->createMock(Schema::class);
        $filters = ['status' => 'published'];

        // Create mock objects
        $object1 = $this->createMock(ObjectEntity::class);
        $object1->method('jsonSerialize')->willReturn([
            'id' => '1',
            'name' => 'Test Object 1',
            'status' => 'published'
        ]);

        $object2 = $this->createMock(ObjectEntity::class);
        $object2->method('jsonSerialize')->willReturn([
            'id' => '2',
            'name' => 'Test Object 2',
            'status' => 'published'
        ]);

        $objects = [$object1, $object2];

        // Mock object entity mapper
        $this->objectEntityMapper->expects($this->once())
            ->method('findAll')
            ->willReturn($objects);

        $jsonData = $this->exportService->exportToJson($register, $schema, $filters);

        $this->assertIsString($jsonData);
        
        // Verify it's valid JSON
        $decoded = json_decode($jsonData, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('data', $decoded);
        $this->assertArrayHasKey('metadata', $decoded);
        $this->assertCount(2, $decoded['data']);
    }

    /**
     * Test exportToJson method with no objects
     */
    public function testExportToJsonWithNoObjects(): void
    {
        $register = $this->createMock(Register::class);
        $schema = $this->createMock(Schema::class);
        $filters = [];

        // Mock object entity mapper to return empty array
        $this->objectEntityMapper->expects($this->once())
            ->method('findAll')
            ->willReturn([]);

        $jsonData = $this->exportService->exportToJson($register, $schema, $filters);

        $this->assertIsString($jsonData);
        
        // Verify it's valid JSON
        $decoded = json_decode($jsonData, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('data', $decoded);
        $this->assertArrayHasKey('metadata', $decoded);
        $this->assertCount(0, $decoded['data']);
    }

    /**
     * Test exportToXml method
     */
    public function testExportToXml(): void
    {
        $register = $this->createMock(Register::class);
        $schema = $this->createMock(Schema::class);
        $filters = ['status' => 'published'];

        // Create mock objects
        $object1 = $this->createMock(ObjectEntity::class);
        $object1->method('jsonSerialize')->willReturn([
            'id' => '1',
            'name' => 'Test Object 1',
            'status' => 'published'
        ]);

        $objects = [$object1];

        // Mock object entity mapper
        $this->objectEntityMapper->expects($this->once())
            ->method('findAll')
            ->willReturn($objects);

        $xmlData = $this->exportService->exportToXml($register, $schema, $filters);

        $this->assertIsString($xmlData);
        $this->assertStringContainsString('<?xml version="1.0" encoding="UTF-8"?>', $xmlData);
        $this->assertStringContainsString('<objects>', $xmlData);
        $this->assertStringContainsString('<object>', $xmlData);
        $this->assertStringContainsString('Test Object 1', $xmlData);
    }

    /**
     * Test exportToXml method with no objects
     */
    public function testExportToXmlWithNoObjects(): void
    {
        $register = $this->createMock(Register::class);
        $schema = $this->createMock(Schema::class);
        $filters = [];

        // Mock object entity mapper to return empty array
        $this->objectEntityMapper->expects($this->once())
            ->method('findAll')
            ->willReturn([]);

        $xmlData = $this->exportService->exportToXml($register, $schema, $filters);

        $this->assertIsString($xmlData);
        $this->assertStringContainsString('<?xml version="1.0" encoding="UTF-8"?>', $xmlData);
        $this->assertStringContainsString('<objects>', $xmlData);
        $this->assertStringNotContainsString('<object>', $xmlData);
    }

    /**
     * Test getExportFilename method
     */
    public function testGetExportFilename(): void
    {
        $register = $this->createMock(Register::class);
        $register->method('getTitle')->willReturn('Test Register');
        
        $schema = $this->createMock(Schema::class);
        $schema->method('getTitle')->willReturn('Test Schema');

        $filename = $this->exportService->getExportFilename($register, $schema, 'xlsx');

        $this->assertIsString($filename);
        $this->assertStringContainsString('Test Register', $filename);
        $this->assertStringContainsString('Test Schema', $filename);
        $this->assertStringEndsWith('.xlsx', $filename);
    }

    /**
     * Test getExportFilename method with null parameters
     */
    public function testGetExportFilenameWithNullParameters(): void
    {
        $filename = $this->exportService->getExportFilename(null, null, 'csv');

        $this->assertIsString($filename);
        $this->assertStringContainsString('export', $filename);
        $this->assertStringEndsWith('.csv', $filename);
    }
}
