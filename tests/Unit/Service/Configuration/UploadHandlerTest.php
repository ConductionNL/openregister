<?php

declare(strict_types=1);

/**
 * UploadHandler Unit Tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 * @author   OpenRegister Team
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/OpenRegister/OpenRegister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use GuzzleHttp\Client;
use OCA\OpenRegister\Service\Configuration\UploadHandler;
use OCP\AppFramework\Http\JSONResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for Configuration UploadHandler
 *
 * Tests file upload parsing and JSON/YAML decoding for configuration imports.
 */
class UploadHandlerTest extends TestCase
{
    /** @var UploadHandler */
    private UploadHandler $handler;

    /** @var Client&MockObject */
    private Client $client;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = $this->createMock(Client::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->handler = new UploadHandler(
            $this->client,
            $this->logger
        );
    }

    // =========================================================================
    // getUploadedJson — missing input
    // =========================================================================

    #[Test]
    public function testGetUploadedJsonReturnsErrorOnNoInput(): void
    {
        $result = $this->handler->getUploadedJson([], null);

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertEquals(400, $result->getStatus());
    }

    #[Test]
    public function testGetUploadedJsonReturnsErrorOnEmptyDataAndEmptyFiles(): void
    {
        $result = $this->handler->getUploadedJson([], []);

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertEquals(400, $result->getStatus());
    }

    // =========================================================================
    // getUploadedJson — multiple files
    // =========================================================================

    #[Test]
    public function testGetUploadedJsonReturnsErrorOnMultipleFiles(): void
    {
        $files = [
            'file1' => ['name' => 'a.json', 'tmp_name' => '/tmp/a', 'error' => UPLOAD_ERR_OK],
            'file2' => ['name' => 'b.json', 'tmp_name' => '/tmp/b', 'error' => UPLOAD_ERR_OK],
        ];

        $result = $this->handler->getUploadedJson([], $files);

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertEquals(400, $result->getStatus());
    }

    // =========================================================================
    // getUploadedJson — single file upload
    // =========================================================================

    #[Test]
    public function testGetUploadedJsonParsesJsonFile(): void
    {
        $jsonData = ['key' => 'value', 'nested' => ['a' => 1]];
        $tmpFile = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($tmpFile, json_encode($jsonData));

        $files = [
            'file' => [
                'name' => 'config.json',
                'tmp_name' => $tmpFile,
                'error' => UPLOAD_ERR_OK,
            ],
        ];

        $result = $this->handler->getUploadedJson([], $files);

        $this->assertIsArray($result);
        $this->assertEquals('value', $result['key']);
        $this->assertEquals(1, $result['nested']['a']);

        unlink($tmpFile);
    }

    #[Test]
    public function testGetUploadedJsonReturnsErrorOnUploadError(): void
    {
        $files = [
            'file' => [
                'name' => 'config.json',
                'tmp_name' => '/tmp/none',
                'error' => UPLOAD_ERR_INI_SIZE,
            ],
        ];

        $result = $this->handler->getUploadedJson([], $files);

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertEquals(400, $result->getStatus());
    }

    #[Test]
    public function testGetUploadedJsonReturnsErrorOnBinaryFile(): void
    {
        // Use binary content that cannot be parsed as JSON or YAML
        $tmpFile = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($tmpFile, "\x00\x01\x02\x03\xFF\xFE");

        $files = [
            'file' => [
                'name' => 'config.bin',
                'tmp_name' => $tmpFile,
                'error' => UPLOAD_ERR_OK,
            ],
        ];

        $result = $this->handler->getUploadedJson([], $files);

        // Binary content should fail decode (returns null) and produce a JSONResponse error
        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertEquals(400, $result->getStatus());

        unlink($tmpFile);
    }

    // =========================================================================
    // getUploadedJson — URL-based input
    // =========================================================================

    #[Test]
    public function testGetUploadedJsonFetchesFromUrl(): void
    {
        $jsonData = json_encode(['from' => 'url']);

        $mockResponse = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $mockStream = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $mockStream->method('getContents')->willReturn($jsonData);
        $mockResponse->method('getBody')->willReturn($mockStream);
        $mockResponse->method('getHeaderLine')
            ->with('Content-Type')
            ->willReturn('application/json');

        $this->client->expects($this->once())
            ->method('request')
            ->with('GET', 'https://example.com/config.json')
            ->willReturn($mockResponse);

        $result = $this->handler->getUploadedJson(['url' => 'https://example.com/config.json'], null);

        $this->assertIsArray($result);
        $this->assertEquals('url', $result['from']);
    }

    #[Test]
    public function testGetUploadedJsonReturnsErrorOnUrlFetchFailure(): void
    {
        $this->client->method('request')
            ->willThrowException(new \GuzzleHttp\Exception\ConnectException(
                'Connection failed',
                new \GuzzleHttp\Psr7\Request('GET', 'https://example.com/bad')
            ));

        $result = $this->handler->getUploadedJson(['url' => 'https://example.com/bad'], null);

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertEquals(400, $result->getStatus());
    }

    #[Test]
    public function testGetUploadedJsonReturnsErrorOnUrlWithBinaryResponse(): void
    {
        $mockResponse = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $mockStream = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        // Binary content that cannot be parsed as JSON or YAML
        $mockStream->method('getContents')->willReturn("\x00\x01\x02\xFF");
        $mockResponse->method('getBody')->willReturn($mockStream);
        $mockResponse->method('getHeaderLine')
            ->with('Content-Type')
            ->willReturn('application/octet-stream');

        $this->client->method('request')
            ->willReturn($mockResponse);

        $result = $this->handler->getUploadedJson(['url' => 'https://example.com/bad'], null);

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertEquals(400, $result->getStatus());
    }

    // =========================================================================
    // getUploadedJson — JSON body input
    // =========================================================================

    #[Test]
    public function testGetUploadedJsonParsesJsonBody(): void
    {
        $data = ['json' => ['items' => [1, 2, 3]]];

        $result = $this->handler->getUploadedJson($data, null);

        $this->assertIsArray($result);
        $this->assertEquals([1, 2, 3], $result['items']);
    }

    #[Test]
    public function testGetUploadedJsonParsesJsonStringBody(): void
    {
        $data = ['json' => '{"items": [4, 5, 6]}'];

        $result = $this->handler->getUploadedJson($data, null);

        $this->assertIsArray($result);
        $this->assertEquals([4, 5, 6], $result['items']);
    }

    #[Test]
    public function testGetUploadedJsonReturnsErrorOnInvalidJsonString(): void
    {
        $data = ['json' => 'not valid json {{{{'];

        $result = $this->handler->getUploadedJson($data, null);

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertEquals(400, $result->getStatus());
    }

    // =========================================================================
    // getUploadedJson — priority: file > url > json
    // =========================================================================

    #[Test]
    public function testGetUploadedJsonPrioritizesFileOverUrl(): void
    {
        $jsonData = ['from' => 'file'];
        $tmpFile = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($tmpFile, json_encode($jsonData));

        $files = [
            'file' => [
                'name' => 'config.json',
                'tmp_name' => $tmpFile,
                'error' => UPLOAD_ERR_OK,
            ],
        ];

        $data = ['url' => 'https://example.com/config.json'];

        // Should NOT call client->request since file takes priority
        $this->client->expects($this->never())
            ->method('request');

        $result = $this->handler->getUploadedJson($data, $files);

        $this->assertIsArray($result);
        $this->assertEquals('file', $result['from']);

        unlink($tmpFile);
    }

    #[Test]
    public function testGetUploadedJsonPrioritizesUrlOverJsonBody(): void
    {
        $jsonData = json_encode(['from' => 'url']);

        $mockResponse = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $mockStream = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $mockStream->method('getContents')->willReturn($jsonData);
        $mockResponse->method('getBody')->willReturn($mockStream);
        $mockResponse->method('getHeaderLine')
            ->with('Content-Type')
            ->willReturn('application/json');

        $this->client->expects($this->once())
            ->method('request')
            ->willReturn($mockResponse);

        $data = [
            'url' => 'https://example.com/config.json',
            'json' => ['from' => 'body'],
        ];

        $result = $this->handler->getUploadedJson($data, null);

        $this->assertIsArray($result);
        $this->assertEquals('url', $result['from']);
    }

    // =========================================================================
    // YAML parsing
    // =========================================================================

    #[Test]
    public function testGetUploadedJsonParsesYamlFile(): void
    {
        $yamlContent = "key: value\nnested:\n  a: 1\n";
        $tmpFile = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($tmpFile, $yamlContent);

        $files = [
            'file' => [
                'name' => 'config.yaml',
                'tmp_name' => $tmpFile,
                'error' => UPLOAD_ERR_OK,
            ],
        ];

        $result = $this->handler->getUploadedJson([], $files);

        $this->assertIsArray($result);
        $this->assertEquals('value', $result['key']);
        $this->assertEquals(1, $result['nested']['a']);

        unlink($tmpFile);
    }
}
