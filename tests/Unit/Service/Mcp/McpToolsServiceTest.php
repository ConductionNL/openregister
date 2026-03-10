<?php

declare(strict_types=1);

/**
 * McpToolsService Unit Tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Mcp
 * @author   OpenRegister Team
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/OpenRegister/OpenRegister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Mcp;

use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Mcp\McpToolsService;
use OCA\OpenRegister\Service\RegisterService;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for McpToolsService
 *
 * Tests MCP tool listing and execution structure.
 */
class McpToolsServiceTest extends TestCase
{
    /** @var McpToolsService */
    private McpToolsService $service;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var RegisterService&MockObject $registerService */
        $registerService = $this->createMock(RegisterService::class);
        /** @var SchemaMapper&MockObject $schemaMapper */
        $schemaMapper = $this->createMock(SchemaMapper::class);
        /** @var ObjectService&MockObject $objectService */
        $objectService = $this->createMock(ObjectService::class);
        /** @var LoggerInterface&MockObject $logger */
        $logger = $this->createMock(LoggerInterface::class);

        $this->service = new McpToolsService(
            $registerService,
            $schemaMapper,
            $objectService,
            $logger
        );
    }

    /**
     * Test listTools returns three tools
     */
    public function testListToolsReturnsThreeTools(): void
    {
        $result = $this->service->listTools();

        $this->assertArrayHasKey('tools', $result);
        $this->assertCount(3, $result['tools']);
    }

    /**
     * Test listTools contains expected tool names
     */
    public function testListToolsContainsExpectedToolNames(): void
    {
        $result = $this->service->listTools();
        $toolNames = array_column($result['tools'], 'name');

        $this->assertContains('registers', $toolNames);
        $this->assertContains('schemas', $toolNames);
        $this->assertContains('objects', $toolNames);
    }

    /**
     * Test each tool has required MCP properties
     */
    public function testListToolsHaveRequiredProperties(): void
    {
        $result = $this->service->listTools();

        foreach ($result['tools'] as $tool) {
            $this->assertArrayHasKey('name', $tool);
            $this->assertArrayHasKey('description', $tool);
            $this->assertArrayHasKey('inputSchema', $tool);
            $this->assertArrayHasKey('type', $tool['inputSchema']);
            $this->assertSame('object', $tool['inputSchema']['type']);
        }
    }

    /**
     * Test callTool with unknown tool name returns error
     */
    public function testCallToolUnknownToolReturnsError(): void
    {
        $result = $this->service->callTool('nonexistent_tool', []);

        $this->assertArrayHasKey('isError', $result);
        $this->assertTrue($result['isError']);
    }

    /**
     * Test callTool error result has content array
     */
    public function testCallToolErrorHasContent(): void
    {
        $result = $this->service->callTool('unknown', []);

        $this->assertArrayHasKey('content', $result);
        $this->assertIsArray($result['content']);
        $this->assertNotEmpty($result['content']);
        $this->assertSame('text', $result['content'][0]['type']);
    }
}
