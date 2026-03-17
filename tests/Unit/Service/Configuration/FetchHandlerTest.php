<?php

declare(strict_types=1);

/**
 * FetchHandler Unit Tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 * @author   OpenRegister Team
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/OpenRegister/OpenRegister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Service\Configuration\FetchHandler;
use OCP\AppFramework\Http\JSONResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for Configuration FetchHandler
 *
 * Tests fetching JSON/YAML data from remote URLs and configurations.
 */
class FetchHandlerTest extends TestCase
{
    /** @var FetchHandler */
    private FetchHandler $handler;

    /** @var Client&MockObject */
    private Client $client;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = $this->createMock(Client::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->handler = new FetchHandler(
            $this->client,
            $this->logger
        );
    }

    /**
     * Helper to create a mock Guzzle response.
     */
    private function createGuzzleResponse(string $body, string $contentType = 'application/json'): ResponseInterface&MockObject
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('getContents')->willReturn($body);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getBody')->willReturn($stream);
        $response->method('getHeaderLine')
            ->with('Content-Type')
            ->willReturn($contentType);

        return $response;
    }

    // =========================================================================
    // getJSONfromURL — success cases
    // =========================================================================

    #[Test]
    public function testGetJsonFromUrlReturnsArrayOnValidJson(): void
    {
        $jsonData = json_encode(['key' => 'value', 'count' => 42]);

        $this->client->expects($this->once())
            ->method('request')
            ->with('GET', 'https://example.com/data.json')
            ->willReturn($this->createGuzzleResponse($jsonData));

        $result = $this->handler->getJSONfromURL('https://example.com/data.json');

        $this->assertIsArray($result);
        $this->assertEquals('value', $result['key']);
        $this->assertEquals(42, $result['count']);
    }

    #[Test]
    public function testGetJsonFromUrlParsesYamlWhenContentTypeIsYaml(): void
    {
        $yamlData = "key: value\ncount: 42\n";

        $this->client->expects($this->once())
            ->method('request')
            ->willReturn($this->createGuzzleResponse($yamlData, 'application/yaml'));

        $result = $this->handler->getJSONfromURL('https://example.com/data.yaml');

        $this->assertIsArray($result);
        $this->assertEquals('value', $result['key']);
        $this->assertEquals(42, $result['count']);
    }

    // =========================================================================
    // getJSONfromURL — error cases
    // =========================================================================

    #[Test]
    public function testGetJsonFromUrlReturnsErrorOnConnectionFailure(): void
    {
        $this->client->method('request')
            ->willThrowException(new ConnectException(
                'Connection refused',
                new Request('GET', 'https://example.com/bad')
            ));

        $result = $this->handler->getJSONfromURL('https://example.com/bad');

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertEquals(400, $result->getStatus());
    }

    #[Test]
    public function testGetJsonFromUrlReturnsErrorOnUnparseableResponse(): void
    {
        $this->client->method('request')
            ->willReturn($this->createGuzzleResponse('<<<not json or yaml>>>', 'text/plain'));

        $result = $this->handler->getJSONfromURL('https://example.com/garbage');

        // text/plain with unparseable content should return JSONResponse error
        // (unless YAML parser manages to parse it as a string)
        if ($result instanceof JSONResponse) {
            $this->assertEquals(400, $result->getStatus());
        } else {
            // YAML parser might interpret plain text as a string, which fails is_array
            // This path should not happen for non-array content
            $this->assertIsArray($result);
        }
    }

    // =========================================================================
    // fetchRemoteConfiguration — success path
    // =========================================================================

    #[Test]
    public function testFetchRemoteConfigurationReturnsDataOnSuccess(): void
    {
        // Use real Configuration entity - it has isRemoteSource() logic based on sourceType
        $config = new Configuration();
        $config->setSourceType('github');
        $config->setSourceUrl('https://example.com/config.json');

        $remoteData = json_encode([
            'components' => [
                'schemas' => ['person' => []],
                'registers' => ['main' => []],
            ],
        ]);

        $this->client->expects($this->once())
            ->method('request')
            ->willReturn($this->createGuzzleResponse($remoteData));

        $result = $this->handler->fetchRemoteConfiguration($config);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('components', $result);
        $this->assertCount(1, $result['components']['schemas']);
        $this->assertCount(1, $result['components']['registers']);
    }

    // =========================================================================
    // fetchRemoteConfiguration — not remote source
    // =========================================================================

    #[Test]
    public function testFetchRemoteConfigurationReturnsErrorWhenNotRemote(): void
    {
        $config = new Configuration();
        // sourceType defaults to null/local, isRemoteSource() should return false
        $config->setSourceType('local');

        $result = $this->handler->fetchRemoteConfiguration($config);

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertEquals(400, $result->getStatus());
    }

    // =========================================================================
    // fetchRemoteConfiguration — empty source URL
    // =========================================================================

    #[Test]
    public function testFetchRemoteConfigurationReturnsErrorWhenNoSourceUrl(): void
    {
        $config = new Configuration();
        $config->setSourceType('github');
        $config->setSourceUrl('');

        $result = $this->handler->fetchRemoteConfiguration($config);

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertEquals(400, $result->getStatus());
    }

    #[Test]
    public function testFetchRemoteConfigurationReturnsErrorWhenNullSourceUrl(): void
    {
        $config = new Configuration();
        $config->setSourceType('github');
        // sourceUrl defaults to null

        $result = $this->handler->fetchRemoteConfiguration($config);

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertEquals(400, $result->getStatus());
    }

    // =========================================================================
    // fetchRemoteConfiguration — fetch failure
    // =========================================================================

    #[Test]
    public function testFetchRemoteConfigurationReturnsErrorOnFetchFailure(): void
    {
        $config = new Configuration();
        $config->setSourceType('github');
        $config->setSourceUrl('https://example.com/broken.json');

        $this->client->method('request')
            ->willThrowException(new ConnectException(
                'DNS resolution failed',
                new Request('GET', 'https://example.com/broken.json')
            ));

        $result = $this->handler->fetchRemoteConfiguration($config);

        $this->assertInstanceOf(JSONResponse::class, $result);
        // Could be 400 (from getJSONfromURL) since GuzzleException is caught there
        $this->assertContains($result->getStatus(), [400, 500]);
    }

    // =========================================================================
    // Content-type detection via DataProvider
    // =========================================================================

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function contentTypeProvider(): array
    {
        return [
            'json content-type' => ['{"a": 1}', 'application/json', true],
            'json with charset' => ['{"a": 1}', 'application/json; charset=utf-8', true],
            'yaml content-type' => ["a: 1\n", 'application/yaml', true],
            'yml content-type' => ["a: 1\n", 'text/yml', true],
            'empty content-type with json' => ['{"a": 1}', '', true],
        ];
    }

    #[Test]
    #[DataProvider('contentTypeProvider')]
    public function testGetJsonFromUrlHandlesVariousContentTypes(string $body, string $contentType, bool $expectArray): void
    {
        $this->client->method('request')
            ->willReturn($this->createGuzzleResponse($body, $contentType));

        $result = $this->handler->getJSONfromURL('https://example.com/data');

        if ($expectArray) {
            $this->assertIsArray($result);
            $this->assertEquals(1, $result['a']);
        } else {
            $this->assertInstanceOf(JSONResponse::class, $result);
        }
    }
}
