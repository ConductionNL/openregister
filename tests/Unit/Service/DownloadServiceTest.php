<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\DownloadService;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use PHPUnit\Framework\TestCase;
use OCP\IURLGenerator;
use Exception;

/**
 * Test class for DownloadService
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/ConductionNL/OpenRegister
 * @version  1.0.0
 */
class DownloadServiceTest extends TestCase
{
    private DownloadService $downloadService;
    private IURLGenerator $urlGenerator;
    private SchemaMapper $schemaMapper;
    private RegisterMapper $registerMapper;

    protected function setUp(): void
    {
        parent::setUp();

        // Create mock dependencies
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);

        // Create DownloadService instance
        $this->downloadService = new DownloadService(
            $this->urlGenerator,
            $this->schemaMapper,
            $this->registerMapper
        );
    }

    /**
     * Test download method with register object and JSON format
     */
    public function testDownloadRegisterWithJsonFormat(): void
    {
        $objectType = 'register';
        $id = 'test-register-id';
        $accept = 'application/json';

        // Create mock register
        $register = $this->createMock(Register::class);
        $register->method('getId')->willReturn($id);
        $register->method('getTitle')->willReturn('Test Register');
        $register->method('getVersion')->willReturn('1.0.0');
        $register->method('jsonSerialize')->willReturn([
            'id' => $id,
            'title' => 'Test Register',
            'version' => '1.0.0',
            'description' => 'Test description'
        ]);

        // Mock register mapper
        $this->registerMapper->expects($this->once())
            ->method('find')
            ->with($id)
            ->willReturn($register);

        // Mock URL generator
        $expectedUrl = 'https://example.com/openregister/registers/test-register-id';
        $this->urlGenerator->expects($this->once())
            ->method('getAbsoluteURL')
            ->willReturn($expectedUrl);

        $this->urlGenerator->expects($this->once())
            ->method('linkToRoute')
            ->with('openregister.Registers.show', ['id' => $id])
            ->willReturn('/openregister/registers/test-register-id');

        $result = $this->downloadService->download($objectType, $id, $accept);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('title', $result);
        $this->assertArrayHasKey('$id', $result);
        $this->assertArrayHasKey('$schema', $result);
        $this->assertArrayHasKey('version', $result);
        $this->assertArrayHasKey('type', $result);
        $this->assertEquals('Test Register', $result['title']);
        $this->assertEquals($expectedUrl, $result['$id']);
        $this->assertEquals('https://docs.commongateway.nl/schemas/Register.schema.json', $result['$schema']);
        $this->assertEquals('1.0.0', $result['version']);
        $this->assertEquals('register', $result['type']);
    }

    /**
     * Test download method with schema object and JSON format
     */
    public function testDownloadSchemaWithJsonFormat(): void
    {
        $objectType = 'schema';
        $id = 'test-schema-id';
        $accept = 'application/json';

        // Create mock schema
        $schema = $this->createMock(Schema::class);
        $schema->method('getId')->willReturn($id);
        $schema->method('getTitle')->willReturn('Test Schema');
        $schema->method('getVersion')->willReturn('2.1.0');
        $schema->method('jsonSerialize')->willReturn([
            'id' => $id,
            'title' => 'Test Schema',
            'version' => '2.1.0',
            'properties' => ['name' => ['type' => 'string']]
        ]);

        // Mock schema mapper
        $this->schemaMapper->expects($this->once())
            ->method('find')
            ->with($id)
            ->willReturn($schema);

        // Mock URL generator
        $expectedUrl = 'https://example.com/openregister/schemas/test-schema-id';
        $this->urlGenerator->expects($this->once())
            ->method('getAbsoluteURL')
            ->willReturn($expectedUrl);

        $this->urlGenerator->expects($this->once())
            ->method('linkToRoute')
            ->with('openregister.Schemas.show', ['id' => $id])
            ->willReturn('/openregister/schemas/test-schema-id');

        $result = $this->downloadService->download($objectType, $id, $accept);

        $this->assertIsArray($result);
        $this->assertEquals('Test Schema', $result['title']);
        $this->assertEquals($expectedUrl, $result['$id']);
        $this->assertEquals('https://docs.commongateway.nl/schemas/Schema.schema.json', $result['$schema']);
        $this->assertEquals('2.1.0', $result['version']);
        $this->assertEquals('schema', $result['type']);
    }

    /**
     * Test download method with wildcard accept header
     */
    public function testDownloadWithWildcardAccept(): void
    {
        $objectType = 'register';
        $id = 'test-register-id';
        $accept = '*/*';

        // Create mock register
        $register = $this->createMock(Register::class);
        $register->method('getId')->willReturn($id);
        $register->method('getTitle')->willReturn('Test Register');
        $register->method('getVersion')->willReturn('1.0.0');
        $register->method('jsonSerialize')->willReturn([
            'id' => $id,
            'title' => 'Test Register',
            'version' => '1.0.0'
        ]);

        // Mock register mapper
        $this->registerMapper->expects($this->once())
            ->method('find')
            ->with($id)
            ->willReturn($register);

        // Mock URL generator
        $this->urlGenerator->expects($this->once())
            ->method('getAbsoluteURL')
            ->willReturn('https://example.com/openregister/registers/test-register-id');

        $this->urlGenerator->expects($this->once())
            ->method('linkToRoute')
            ->willReturn('/openregister/registers/test-register-id');

        $result = $this->downloadService->download($objectType, $id, $accept);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('title', $result);
        $this->assertArrayHasKey('$id', $result);
    }

    /**
     * Test download method with non-existent object
     */
    public function testDownloadWithNonExistentObject(): void
    {
        $objectType = 'register';
        $id = 'non-existent-id';
        $accept = 'application/json';

        // Mock register mapper to throw exception
        $this->registerMapper->expects($this->once())
            ->method('find')
            ->with($id)
            ->willThrowException(new Exception('Object not found'));

        $result = $this->downloadService->download($objectType, $id, $accept);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('error', $result);
        $this->assertArrayHasKey('statusCode', $result);
        $this->assertEquals('Could not find register with id non-existent-id.', $result['error']);
        $this->assertEquals(404, $result['statusCode']);
    }

    /**
     * Test download method with invalid object type
     */
    public function testDownloadWithInvalidObjectType(): void
    {
        $objectType = 'invalid';
        $id = 'test-id';
        $accept = 'application/json';

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Invalid object type: invalid');

        $this->downloadService->download($objectType, $id, $accept);
    }

    /**
     * Test download method with CSV format
     */
    public function testDownloadWithCsvFormat(): void
    {
        $objectType = 'register';
        $id = 'test-register-id';
        $accept = 'text/csv';

        // Create mock register
        $register = $this->createMock(Register::class);
        $register->method('getId')->willReturn($id);
        $register->method('getTitle')->willReturn('Test Register');
        $register->method('getVersion')->willReturn('1.0.0');
        $register->method('jsonSerialize')->willReturn([
            'id' => $id,
            'title' => 'Test Register',
            'version' => '1.0.0',
            'description' => 'Test description'
        ]);

        // Mock register mapper
        $this->registerMapper->expects($this->once())
            ->method('find')
            ->with($id)
            ->willReturn($register);

        $result = $this->downloadService->download($objectType, $id, $accept);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('filename', $result);
        $this->assertArrayHasKey('mimetype', $result);
        $this->assertEquals('Test RegisterRegister-v1.0.0.csv', $result['filename']);
        $this->assertEquals('text/csv', $result['mimetype']);
        $this->assertStringContainsString('Test Register', $result['data']);
    }

    /**
     * Test download method with Excel format
     */
    public function testDownloadWithExcelFormat(): void
    {
        $objectType = 'schema';
        $id = 'test-schema-id';
        $accept = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

        // Create mock schema
        $schema = $this->createMock(Schema::class);
        $schema->method('getId')->willReturn($id);
        $schema->method('getTitle')->willReturn('Test Schema');
        $schema->method('getVersion')->willReturn('2.1.0');
        $schema->method('jsonSerialize')->willReturn([
            'id' => $id,
            'title' => 'Test Schema',
            'version' => '2.1.0',
            'properties' => ['name' => ['type' => 'string']]
        ]);

        // Mock schema mapper
        $this->schemaMapper->expects($this->once())
            ->method('find')
            ->with($id)
            ->willReturn($schema);

        $result = $this->downloadService->download($objectType, $id, $accept);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('filename', $result);
        $this->assertArrayHasKey('mimetype', $result);
        $this->assertEquals('Test SchemaSchema-v2.1.0.xlsx', $result['filename']);
        $this->assertEquals('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $result['mimetype']);
    }

    /**
     * Test download method with XML format
     */
    public function testDownloadWithXmlFormat(): void
    {
        $objectType = 'register';
        $id = 'test-register-id';
        $accept = 'application/xml';

        // Create mock register
        $register = $this->createMock(Register::class);
        $register->method('getId')->willReturn($id);
        $register->method('getTitle')->willReturn('Test Register');
        $register->method('getVersion')->willReturn('1.0.0');
        $register->method('jsonSerialize')->willReturn([
            'id' => $id,
            'title' => 'Test Register',
            'version' => '1.0.0',
            'description' => 'Test description'
        ]);

        // Mock register mapper
        $this->registerMapper->expects($this->once())
            ->method('find')
            ->with($id)
            ->willReturn($register);

        $result = $this->downloadService->download($objectType, $id, $accept);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('filename', $result);
        $this->assertArrayHasKey('mimetype', $result);
        $this->assertEquals('Test RegisterRegister-v1.0.0.xml', $result['filename']);
        $this->assertEquals('application/xml', $result['mimetype']);
        $this->assertStringContainsString('<register>', $result['data']);
    }

    /**
     * Test download method with YAML format
     */
    public function testDownloadWithYamlFormat(): void
    {
        $objectType = 'schema';
        $id = 'test-schema-id';
        $accept = 'application/x-yaml';

        // Create mock schema
        $schema = $this->createMock(Schema::class);
        $schema->method('getId')->willReturn($id);
        $schema->method('getTitle')->willReturn('Test Schema');
        $schema->method('getVersion')->willReturn('2.1.0');
        $schema->method('jsonSerialize')->willReturn([
            'id' => $id,
            'title' => 'Test Schema',
            'version' => '2.1.0',
            'properties' => ['name' => ['type' => 'string']]
        ]);

        // Mock schema mapper
        $this->schemaMapper->expects($this->once())
            ->method('find')
            ->with($id)
            ->willReturn($schema);

        $result = $this->downloadService->download($objectType, $id, $accept);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('filename', $result);
        $this->assertArrayHasKey('mimetype', $result);
        $this->assertEquals('Test SchemaSchema-v2.1.0.yaml', $result['filename']);
        $this->assertEquals('application/x-yaml', $result['mimetype']);
    }
}
