<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\UploadService;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use PHPUnit\Framework\TestCase;
use OCP\AppFramework\Http\JSONResponse;
use GuzzleHttp\Client;

/**
 * Test class for UploadService
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/ConductionNL/OpenRegister
 * @version  1.0.0
 */
class UploadServiceTest extends TestCase
{
    private UploadService $uploadService;
    private Client $client;
    private SchemaMapper $schemaMapper;
    private RegisterMapper $registerMapper;

    protected function setUp(): void
    {
        parent::setUp();

        // Create mock dependencies
        $this->client = $this->createMock(Client::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);

        // Create UploadService instance
        $this->uploadService = new UploadService(
            $this->client,
            $this->schemaMapper,
            $this->registerMapper
        );
    }

    /**
     * Test getUploadedJson method with valid data
     */
    public function testGetUploadedJsonWithValidData(): void
    {
        $data = [
            'url' => 'https://example.com/data.json',
            'register' => 'test-register'
        ];

        // Mock HTTP client response
        $response = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $stream = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $stream->method('getContents')->willReturn('{"test": "data"}');
        $response->method('getBody')->willReturn($stream);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getHeaderLine')->willReturn('application/json');

        $this->client->expects($this->once())
            ->method('request')
            ->with('GET', 'https://example.com/data.json')
            ->willReturn($response);

        $result = $this->uploadService->getUploadedJson($data);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('test', $result);
        $this->assertEquals('data', $result['test']);
    }

    /**
     * Test getUploadedJson method with invalid URL
     */
    public function testGetUploadedJsonWithInvalidUrl(): void
    {
        $data = [
            'url' => 'invalid-url',
            'register' => 'test-register'
        ];

        // Mock HTTP client to throw exception
        $this->client->expects($this->once())
            ->method('request')
            ->with('GET', 'invalid-url')
            ->willThrowException(new \GuzzleHttp\Exception\BadResponseException('Bad response', new \GuzzleHttp\Psr7\Request('GET', 'invalid-url'), $this->createMock(\Psr\Http\Message\ResponseInterface::class)));

        $result = $this->uploadService->getUploadedJson($data);

        $this->assertInstanceOf(JSONResponse::class, $result);
    }

    /**
     * Test getUploadedJson method with HTTP error
     */
    public function testGetUploadedJsonWithHttpError(): void
    {
        $data = [
            'url' => 'https://example.com/error.json',
            'register' => 'test-register'
        ];

        // Mock HTTP client to throw exception
        $this->client->expects($this->once())
            ->method('request')
            ->with('GET', 'https://example.com/error.json')
            ->willThrowException(new \GuzzleHttp\Exception\BadResponseException('Bad response', $this->createMock(\Psr\Http\Message\RequestInterface::class), $this->createMock(\Psr\Http\Message\ResponseInterface::class)));

        $result = $this->uploadService->getUploadedJson($data);

        $this->assertInstanceOf(JSONResponse::class, $result);
    }

    /**
     * Test handleRegisterSchemas method
     */
    public function testHandleRegisterSchemas(): void
    {
        // Create mock register
        $register = $this->createMock(Register::class);
        $register->method('__toString')->willReturn('1');
        $register->method('getId')->willReturn('1');

        $phpArray = [
            'components' => [
                'schemas' => [
                    'TestSchema' => [
                        'title' => 'Test Schema',
                        'description' => 'Test Description',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'age' => ['type' => 'integer']
                        ]
                    ]
                ]
            ]
        ];

        // Create mock schema
        $schema = $this->createMock(Schema::class);
        $schema->method('__toString')->willReturn('1');
        $schema->method('getTitle')->willReturn('Test Schema');
        $schema->method('hydrate')->willReturn($schema);

        // Mock register mapper
        $this->registerMapper->expects($this->once())
            ->method('hasSchemaWithTitle')
            ->with('1', 'TestSchema')
            ->willReturn($schema);

        // Mock schema mapper
        $this->schemaMapper->expects($this->once())
            ->method('update')
            ->with($schema)
            ->willReturn($schema);

        // Mock register mapper update
        $this->registerMapper->expects($this->once())
            ->method('update')
            ->with($register)
            ->willReturn($register);

        $result = $this->uploadService->handleRegisterSchemas($register, $phpArray);

        $this->assertInstanceOf(Register::class, $result);
        $this->assertEquals($register, $result);
    }

    /**
     * Test handleRegisterSchemas method with empty schemas
     */
    public function testHandleRegisterSchemasWithEmptySchemas(): void
    {
        // Create mock register
        $register = $this->createMock(Register::class);

        $phpArray = [
            'components' => [
                'schemas' => []
            ]
        ];

        $result = $this->uploadService->handleRegisterSchemas($register, $phpArray);

        $this->assertInstanceOf(Register::class, $result);
        $this->assertEquals($register, $result);
    }

    /**
     * Test handleRegisterSchemas method with no schemas key
     */
    public function testHandleRegisterSchemasWithNoSchemasKey(): void
    {
        // Create mock register
        $register = $this->createMock(Register::class);

        $phpArray = [
            'components' => [
                'schemas' => []
            ]
        ];

        $result = $this->uploadService->handleRegisterSchemas($register, $phpArray);

        $this->assertInstanceOf(Register::class, $result);
        $this->assertEquals($register, $result);
    }
}