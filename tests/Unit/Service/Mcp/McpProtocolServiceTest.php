<?php

declare(strict_types=1);

/**
 * McpProtocolService Unit Tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Mcp
 * @author   OpenRegister Team
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/OpenRegister/OpenRegister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Mcp;

use OCA\OpenRegister\Service\Mcp\McpProtocolService;
use OCP\ICacheFactory;
use OCP\ICache;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for McpProtocolService
 *
 * Tests MCP protocol handshake, session creation, and validation.
 */
class McpProtocolServiceTest extends TestCase
{
    /** @var McpProtocolService */
    private McpProtocolService $service;

    /** @var ICache&MockObject */
    private ICache $cache;

    /** @var ISecureRandom&MockObject */
    private ISecureRandom $secureRandom;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cache = $this->createMock(ICache::class);
        $this->secureRandom = $this->createMock(ISecureRandom::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        /** @var ICacheFactory&MockObject $cacheFactory */
        $cacheFactory = $this->createMock(ICacheFactory::class);
        $cacheFactory->method('createDistributed')
            ->willReturn($this->cache);

        $this->service = new McpProtocolService(
            $cacheFactory,
            $this->secureRandom,
            $this->logger
        );
    }

    /**
     * Test that initialize returns correct protocol version and server info
     */
    public function testInitializeReturnsServerInfo(): void
    {
        $this->secureRandom->method('generate')
            ->willReturn('test-session-id-12345');

        $result = $this->service->initialize([], 'admin');

        $this->assertArrayHasKey('result', $result);
        $this->assertSame('2025-03-26', $result['result']['protocolVersion']);
        $this->assertSame('OpenRegister', $result['result']['serverInfo']['name']);
        $this->assertSame('1.0.0', $result['result']['serverInfo']['version']);
        $this->assertArrayHasKey('capabilities', $result['result']);
        $this->assertArrayHasKey('sessionId', $result);
        $this->assertSame('test-session-id-12345', $result['sessionId']);
    }

    /**
     * Test that initialize enables tools and resources capabilities
     */
    public function testInitializeCapabilities(): void
    {
        $this->secureRandom->method('generate')
            ->willReturn('session-id');

        $result = $this->service->initialize([], 'admin');

        $capabilities = $result['result']['capabilities'];
        $this->assertArrayHasKey('tools', $capabilities);
        $this->assertArrayHasKey('resources', $capabilities);
    }

    /**
     * Test ping returns empty result
     */
    public function testPingReturnsEmptyArray(): void
    {
        $result = $this->service->ping();

        $this->assertSame([], $result);
    }

    /**
     * Test createSession generates session ID
     */
    public function testCreateSessionReturnsSessionId(): void
    {
        $this->secureRandom->method('generate')
            ->willReturn('generated-session-id');

        $result = $this->service->createSession('admin');

        $this->assertSame('generated-session-id', $result);
    }

    /**
     * Test session validation with valid session returns user ID
     */
    public function testValidateSessionWithValidSession(): void
    {
        $this->cache->method('get')
            ->willReturn('admin');

        $result = $this->service->validateSession('valid-session-id');

        $this->assertSame('admin', $result);
    }

    /**
     * Test session validation with invalid session returns null
     */
    public function testValidateSessionWithInvalidSession(): void
    {
        $this->cache->method('get')
            ->willReturn(null);

        $result = $this->service->validateSession('invalid-session-id');

        $this->assertNull($result);
    }
}
