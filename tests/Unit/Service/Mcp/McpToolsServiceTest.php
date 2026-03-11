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

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\ObjectEntity;
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
 * Tests MCP tool listing, execution, error handling,
 * and all CRUD operations for registers, schemas, and objects.
 */
class McpToolsServiceTest extends TestCase
{

    /** @var McpToolsService */
    private McpToolsService $service;

    /** @var RegisterService&MockObject */
    private $registerService;

    /** @var SchemaMapper&MockObject */
    private $schemaMapper;

    /** @var ObjectService&MockObject */
    private $objectService;

    /** @var LoggerInterface&MockObject */
    private $logger;


    protected function setUp(): void
    {
        parent::setUp();

        $this->registerService = $this->createMock(RegisterService::class);
        $this->schemaMapper    = $this->createMock(SchemaMapper::class);
        $this->objectService   = $this->createMock(ObjectService::class);
        $this->logger          = $this->createMock(LoggerInterface::class);

        // ObjectService::setRegister and setSchema return $this (fluent).
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();

        $this->service = new McpToolsService(
            $this->registerService,
            $this->schemaMapper,
            $this->objectService,
            $this->logger
        );

    }//end setUp()


    // ---------------------------------------------------------------
    // Helper: create a mock entity that returns given array from jsonSerialize
    // ---------------------------------------------------------------

    /**
     * Create a mock register entity
     *
     * @param array $data Serialized data to return
     *
     * @return Register&MockObject
     */
    private function mockRegister(array $data): Register
    {
        $register = $this->createMock(Register::class);
        $register->method('jsonSerialize')->willReturn($data);
        return $register;

    }//end mockRegister()


    /**
     * Create a mock schema entity
     *
     * @param array $data Serialized data to return
     *
     * @return Schema&MockObject
     */
    private function mockSchema(array $data): Schema
    {
        $schema = $this->createMock(Schema::class);
        $schema->method('jsonSerialize')->willReturn($data);
        return $schema;

    }//end mockSchema()


    /**
     * Create a mock object entity
     *
     * @param array $data Serialized data to return
     *
     * @return ObjectEntity&MockObject
     */
    private function mockObjectEntity(array $data): ObjectEntity
    {
        $object = $this->createMock(ObjectEntity::class);
        $object->method('jsonSerialize')->willReturn($data);
        return $object;

    }//end mockObjectEntity()


    // ---------------------------------------------------------------
    // listTools tests
    // ---------------------------------------------------------------

    /**
     * Test listTools returns three tools
     */
    public function testListToolsReturnsThreeTools(): void
    {
        $result = $this->service->listTools();

        $this->assertArrayHasKey('tools', $result);
        $this->assertCount(3, $result['tools']);

    }//end testListToolsReturnsThreeTools()


    /**
     * Test listTools contains expected tool names
     */
    public function testListToolsContainsExpectedToolNames(): void
    {
        $result    = $this->service->listTools();
        $toolNames = array_column($result['tools'], 'name');

        $this->assertContains('registers', $toolNames);
        $this->assertContains('schemas', $toolNames);
        $this->assertContains('objects', $toolNames);

    }//end testListToolsContainsExpectedToolNames()


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

    }//end testListToolsHaveRequiredProperties()


    /**
     * Test all tools have properties and required fields in their inputSchema
     */
    public function testListToolsHavePropertiesAndRequired(): void
    {
        $result = $this->service->listTools();

        foreach ($result['tools'] as $tool) {
            $this->assertArrayHasKey('properties', $tool['inputSchema']);
            $this->assertArrayHasKey('required', $tool['inputSchema']);
            $this->assertIsArray($tool['inputSchema']['properties']);
            $this->assertIsArray($tool['inputSchema']['required']);
            // Every tool requires at least 'action'.
            $this->assertContains('action', $tool['inputSchema']['required']);
        }

    }//end testListToolsHavePropertiesAndRequired()


    /**
     * Test registers tool has correct properties
     */
    public function testRegistersToolHasCorrectProperties(): void
    {
        $result = $this->service->listTools();
        $tool   = $result['tools'][0];

        $this->assertSame('registers', $tool['name']);
        $props = array_keys($tool['inputSchema']['properties']);
        $this->assertContains('action', $props);
        $this->assertContains('id', $props);
        $this->assertContains('data', $props);
        $this->assertContains('limit', $props);
        $this->assertContains('offset', $props);
        $this->assertSame(['action'], $tool['inputSchema']['required']);

    }//end testRegistersToolHasCorrectProperties()


    /**
     * Test schemas tool has correct properties
     */
    public function testSchemasToolHasCorrectProperties(): void
    {
        $result = $this->service->listTools();
        $tool   = $result['tools'][1];

        $this->assertSame('schemas', $tool['name']);
        $props = array_keys($tool['inputSchema']['properties']);
        $this->assertContains('action', $props);
        $this->assertContains('id', $props);
        $this->assertContains('data', $props);
        $this->assertContains('limit', $props);
        $this->assertContains('offset', $props);
        $this->assertSame(['action'], $tool['inputSchema']['required']);

    }//end testSchemasToolHasCorrectProperties()


    /**
     * Test objects tool has correct properties including register and schema
     */
    public function testObjectsToolHasCorrectProperties(): void
    {
        $result = $this->service->listTools();
        $tool   = $result['tools'][2];

        $this->assertSame('objects', $tool['name']);
        $props = array_keys($tool['inputSchema']['properties']);
        $this->assertContains('action', $props);
        $this->assertContains('register', $props);
        $this->assertContains('schema', $props);
        $this->assertContains('id', $props);
        $this->assertContains('data', $props);
        $this->assertContains('limit', $props);
        $this->assertContains('offset', $props);
        $this->assertSame(['action', 'register', 'schema'], $tool['inputSchema']['required']);

    }//end testObjectsToolHasCorrectProperties()


    // ---------------------------------------------------------------
    // callTool: unknown tool
    // ---------------------------------------------------------------

    /**
     * Test callTool with unknown tool name returns error
     */
    public function testCallToolUnknownToolReturnsError(): void
    {
        $result = $this->service->callTool('nonexistent_tool', []);

        $this->assertArrayHasKey('isError', $result);
        $this->assertTrue($result['isError']);

    }//end testCallToolUnknownToolReturnsError()


    /**
     * Test callTool error result has content array with text type
     */
    public function testCallToolErrorHasContent(): void
    {
        $result = $this->service->callTool('unknown', []);

        $this->assertArrayHasKey('content', $result);
        $this->assertIsArray($result['content']);
        $this->assertNotEmpty($result['content']);
        $this->assertSame('text', $result['content'][0]['type']);

    }//end testCallToolErrorHasContent()


    /**
     * Test callTool error content contains the error message
     */
    public function testCallToolErrorContentContainsMessage(): void
    {
        $result  = $this->service->callTool('unknown_tool', []);
        $decoded = json_decode($result['content'][0]['text'], true);

        $this->assertArrayHasKey('error', $decoded);
        $this->assertStringContainsString('Unknown tool', $decoded['error']);

    }//end testCallToolErrorContentContainsMessage()


    /**
     * Test callTool logs error on failure
     */
    public function testCallToolLogsErrorOnFailure(): void
    {
        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                '[MCP] Tool execution failed',
                $this->callback(function ($context) {
                    return $context['tool'] === 'bad_tool'
                        && str_contains($context['error'], 'Unknown tool');
                })
            );

        $this->service->callTool('bad_tool', []);

    }//end testCallToolLogsErrorOnFailure()


    // ---------------------------------------------------------------
    // callTool: success structure
    // ---------------------------------------------------------------

    /**
     * Test callTool success result structure
     */
    public function testCallToolSuccessStructure(): void
    {
        $register = $this->mockRegister(['id' => 1, 'title' => 'Test']);
        $this->registerService->method('findAll')->willReturn([$register]);

        $result = $this->service->callTool('registers', ['action' => 'list']);

        $this->assertArrayHasKey('isError', $result);
        $this->assertFalse($result['isError']);
        $this->assertArrayHasKey('content', $result);
        $this->assertCount(1, $result['content']);
        $this->assertSame('text', $result['content'][0]['type']);

        // Content text should be valid JSON.
        $decoded = json_decode($result['content'][0]['text'], true);
        $this->assertNotNull($decoded);

    }//end testCallToolSuccessStructure()


    /**
     * Test callTool logs debug on every call
     */
    public function testCallToolLogsDebugOnEveryCall(): void
    {
        $this->registerService->method('findAll')->willReturn([]);

        $this->logger->expects($this->once())
            ->method('debug')
            ->with(
                '[MCP] Tool call',
                $this->callback(function ($context) {
                    return $context['tool'] === 'registers'
                        && $context['arguments'] === ['action' => 'list'];
                })
            );

        $this->service->callTool('registers', ['action' => 'list']);

    }//end testCallToolLogsDebugOnEveryCall()


    // ---------------------------------------------------------------
    // Registers: CRUD
    // ---------------------------------------------------------------

    /**
     * Test list registers with no arguments
     */
    public function testListRegisters(): void
    {
        $reg1 = $this->mockRegister(['id' => 1, 'title' => 'Reg A']);
        $reg2 = $this->mockRegister(['id' => 2, 'title' => 'Reg B']);
        $this->registerService->method('findAll')->willReturn([$reg1, $reg2]);

        $result  = $this->service->callTool('registers', ['action' => 'list']);
        $decoded = json_decode($result['content'][0]['text'], true);

        $this->assertFalse($result['isError']);
        $this->assertCount(2, $decoded);
        $this->assertSame('Reg A', $decoded[0]['title']);
        $this->assertSame('Reg B', $decoded[1]['title']);

    }//end testListRegisters()


    /**
     * Test list registers passes limit and offset
     */
    public function testListRegistersWithLimitAndOffset(): void
    {
        $this->registerService->expects($this->once())
            ->method('findAll')
            ->with(5, 10)
            ->willReturn([]);

        $result = $this->service->callTool('registers', [
            'action' => 'list',
            'limit'  => 5,
            'offset' => 10,
        ]);

        $this->assertFalse($result['isError']);

    }//end testListRegistersWithLimitAndOffset()


    /**
     * Test list registers defaults limit and offset to null
     */
    public function testListRegistersDefaultsToNull(): void
    {
        $this->registerService->expects($this->once())
            ->method('findAll')
            ->with(null, null)
            ->willReturn([]);

        $this->service->callTool('registers', ['action' => 'list']);

    }//end testListRegistersDefaultsToNull()


    /**
     * Test get register
     */
    public function testGetRegister(): void
    {
        $register = $this->mockRegister(['id' => 42, 'title' => 'Found']);
        $this->registerService->expects($this->once())
            ->method('find')
            ->with(42)
            ->willReturn($register);

        $result  = $this->service->callTool('registers', ['action' => 'get', 'id' => 42]);
        $decoded = json_decode($result['content'][0]['text'], true);

        $this->assertFalse($result['isError']);
        $this->assertSame(42, $decoded['id']);
        $this->assertSame('Found', $decoded['title']);

    }//end testGetRegister()


    /**
     * Test get register without id returns error
     */
    public function testGetRegisterMissingIdReturnsError(): void
    {
        $result  = $this->service->callTool('registers', ['action' => 'get']);
        $decoded = json_decode($result['content'][0]['text'], true);

        $this->assertTrue($result['isError']);
        $this->assertStringContainsString('Missing required parameter: id', $decoded['error']);

    }//end testGetRegisterMissingIdReturnsError()


    /**
     * Test create register
     */
    public function testCreateRegister(): void
    {
        $data     = ['title' => 'New Register'];
        $register = $this->mockRegister(['id' => 10, 'title' => 'New Register']);

        $this->registerService->expects($this->once())
            ->method('createFromArray')
            ->with($data)
            ->willReturn($register);

        $result  = $this->service->callTool('registers', ['action' => 'create', 'data' => $data]);
        $decoded = json_decode($result['content'][0]['text'], true);

        $this->assertFalse($result['isError']);
        $this->assertSame('New Register', $decoded['title']);

    }//end testCreateRegister()


    /**
     * Test create register without data returns error
     */
    public function testCreateRegisterMissingDataReturnsError(): void
    {
        $result = $this->service->callTool('registers', ['action' => 'create']);

        $this->assertTrue($result['isError']);
        $decoded = json_decode($result['content'][0]['text'], true);
        $this->assertStringContainsString('Missing required parameter: data', $decoded['error']);

    }//end testCreateRegisterMissingDataReturnsError()


    /**
     * Test update register
     */
    public function testUpdateRegister(): void
    {
        $data     = ['title' => 'Updated'];
        $register = $this->mockRegister(['id' => 5, 'title' => 'Updated']);

        $this->registerService->expects($this->once())
            ->method('updateFromArray')
            ->with(5, $data)
            ->willReturn($register);

        $result  = $this->service->callTool('registers', ['action' => 'update', 'id' => 5, 'data' => $data]);
        $decoded = json_decode($result['content'][0]['text'], true);

        $this->assertFalse($result['isError']);
        $this->assertSame('Updated', $decoded['title']);

    }//end testUpdateRegister()


    /**
     * Test update register without id returns error
     */
    public function testUpdateRegisterMissingIdReturnsError(): void
    {
        $result = $this->service->callTool('registers', ['action' => 'update', 'data' => ['title' => 'X']]);

        $this->assertTrue($result['isError']);
        $decoded = json_decode($result['content'][0]['text'], true);
        $this->assertStringContainsString('Missing required parameter: id', $decoded['error']);

    }//end testUpdateRegisterMissingIdReturnsError()


    /**
     * Test update register without data returns error
     */
    public function testUpdateRegisterMissingDataReturnsError(): void
    {
        $result = $this->service->callTool('registers', ['action' => 'update', 'id' => 5]);

        $this->assertTrue($result['isError']);
        $decoded = json_decode($result['content'][0]['text'], true);
        $this->assertStringContainsString('Missing required parameter: data', $decoded['error']);

    }//end testUpdateRegisterMissingDataReturnsError()


    /**
     * Test delete register
     */
    public function testDeleteRegister(): void
    {
        $register = $this->mockRegister(['id' => 7]);

        $this->registerService->expects($this->once())
            ->method('find')
            ->with(7)
            ->willReturn($register);

        $this->registerService->expects($this->once())
            ->method('delete')
            ->with($register);

        $result  = $this->service->callTool('registers', ['action' => 'delete', 'id' => 7]);
        $decoded = json_decode($result['content'][0]['text'], true);

        $this->assertFalse($result['isError']);
        $this->assertTrue($decoded['deleted']);
        $this->assertSame(7, $decoded['id']);

    }//end testDeleteRegister()


    /**
     * Test delete register without id returns error
     */
    public function testDeleteRegisterMissingIdReturnsError(): void
    {
        $result = $this->service->callTool('registers', ['action' => 'delete']);

        $this->assertTrue($result['isError']);
        $decoded = json_decode($result['content'][0]['text'], true);
        $this->assertStringContainsString('Missing required parameter: id', $decoded['error']);

    }//end testDeleteRegisterMissingIdReturnsError()


    /**
     * Test registers tool with unknown action returns error
     */
    public function testRegistersUnknownActionReturnsError(): void
    {
        $result  = $this->service->callTool('registers', ['action' => 'purge']);
        $decoded = json_decode($result['content'][0]['text'], true);

        $this->assertTrue($result['isError']);
        $this->assertStringContainsString('Unknown action', $decoded['error']);

    }//end testRegistersUnknownActionReturnsError()


    // ---------------------------------------------------------------
    // Schemas: CRUD
    // ---------------------------------------------------------------

    /**
     * Test list schemas
     */
    public function testListSchemas(): void
    {
        $s1 = $this->mockSchema(['id' => 1, 'title' => 'Schema A']);
        $s2 = $this->mockSchema(['id' => 2, 'title' => 'Schema B']);
        $this->schemaMapper->method('findAll')->willReturn([$s1, $s2]);

        $result  = $this->service->callTool('schemas', ['action' => 'list']);
        $decoded = json_decode($result['content'][0]['text'], true);

        $this->assertFalse($result['isError']);
        $this->assertCount(2, $decoded);

    }//end testListSchemas()


    /**
     * Test list schemas passes limit and offset
     */
    public function testListSchemasWithLimitAndOffset(): void
    {
        $this->schemaMapper->expects($this->once())
            ->method('findAll')
            ->with(3, 6)
            ->willReturn([]);

        $this->service->callTool('schemas', [
            'action' => 'list',
            'limit'  => 3,
            'offset' => 6,
        ]);

    }//end testListSchemasWithLimitAndOffset()


    /**
     * Test list schemas defaults to null
     */
    public function testListSchemasDefaultsToNull(): void
    {
        $this->schemaMapper->expects($this->once())
            ->method('findAll')
            ->with(null, null)
            ->willReturn([]);

        $this->service->callTool('schemas', ['action' => 'list']);

    }//end testListSchemasDefaultsToNull()


    /**
     * Test get schema
     */
    public function testGetSchema(): void
    {
        $schema = $this->mockSchema(['id' => 99, 'title' => 'Got It']);
        $this->schemaMapper->expects($this->once())
            ->method('find')
            ->with(99)
            ->willReturn($schema);

        $result  = $this->service->callTool('schemas', ['action' => 'get', 'id' => 99]);
        $decoded = json_decode($result['content'][0]['text'], true);

        $this->assertFalse($result['isError']);
        $this->assertSame(99, $decoded['id']);

    }//end testGetSchema()


    /**
     * Test get schema missing id
     */
    public function testGetSchemaMissingIdReturnsError(): void
    {
        $result = $this->service->callTool('schemas', ['action' => 'get']);

        $this->assertTrue($result['isError']);

    }//end testGetSchemaMissingIdReturnsError()


    /**
     * Test create schema
     */
    public function testCreateSchema(): void
    {
        $data   = ['title' => 'New Schema'];
        $schema = $this->mockSchema(['id' => 20, 'title' => 'New Schema']);

        $this->schemaMapper->expects($this->once())
            ->method('createFromArray')
            ->with($data)
            ->willReturn($schema);

        $result  = $this->service->callTool('schemas', ['action' => 'create', 'data' => $data]);
        $decoded = json_decode($result['content'][0]['text'], true);

        $this->assertFalse($result['isError']);
        $this->assertSame('New Schema', $decoded['title']);

    }//end testCreateSchema()


    /**
     * Test create schema missing data
     */
    public function testCreateSchemaMissingDataReturnsError(): void
    {
        $result = $this->service->callTool('schemas', ['action' => 'create']);

        $this->assertTrue($result['isError']);

    }//end testCreateSchemaMissingDataReturnsError()


    /**
     * Test update schema
     */
    public function testUpdateSchema(): void
    {
        $data   = ['title' => 'Updated Schema'];
        $schema = $this->mockSchema(['id' => 15, 'title' => 'Updated Schema']);

        $this->schemaMapper->expects($this->once())
            ->method('updateFromArray')
            ->with(15, $data)
            ->willReturn($schema);

        $result  = $this->service->callTool('schemas', ['action' => 'update', 'id' => 15, 'data' => $data]);
        $decoded = json_decode($result['content'][0]['text'], true);

        $this->assertFalse($result['isError']);
        $this->assertSame('Updated Schema', $decoded['title']);

    }//end testUpdateSchema()


    /**
     * Test update schema missing id
     */
    public function testUpdateSchemaMissingIdReturnsError(): void
    {
        $result = $this->service->callTool('schemas', ['action' => 'update', 'data' => ['title' => 'X']]);

        $this->assertTrue($result['isError']);

    }//end testUpdateSchemaMissingIdReturnsError()


    /**
     * Test update schema missing data
     */
    public function testUpdateSchemaMissingDataReturnsError(): void
    {
        $result = $this->service->callTool('schemas', ['action' => 'update', 'id' => 15]);

        $this->assertTrue($result['isError']);

    }//end testUpdateSchemaMissingDataReturnsError()


    /**
     * Test delete schema
     */
    public function testDeleteSchema(): void
    {
        $schema = $this->mockSchema(['id' => 30]);
        $this->schemaMapper->expects($this->once())
            ->method('find')
            ->with(30)
            ->willReturn($schema);

        $this->schemaMapper->expects($this->once())
            ->method('delete')
            ->with($schema);

        $result  = $this->service->callTool('schemas', ['action' => 'delete', 'id' => 30]);
        $decoded = json_decode($result['content'][0]['text'], true);

        $this->assertFalse($result['isError']);
        $this->assertTrue($decoded['deleted']);
        $this->assertSame(30, $decoded['id']);

    }//end testDeleteSchema()


    /**
     * Test delete schema missing id
     */
    public function testDeleteSchemaMissingIdReturnsError(): void
    {
        $result = $this->service->callTool('schemas', ['action' => 'delete']);

        $this->assertTrue($result['isError']);

    }//end testDeleteSchemaMissingIdReturnsError()


    /**
     * Test schemas tool with unknown action returns error
     */
    public function testSchemasUnknownActionReturnsError(): void
    {
        $result = $this->service->callTool('schemas', ['action' => 'drop']);

        $this->assertTrue($result['isError']);
        $decoded = json_decode($result['content'][0]['text'], true);
        $this->assertStringContainsString('Unknown action', $decoded['error']);

    }//end testSchemasUnknownActionReturnsError()


    // ---------------------------------------------------------------
    // Objects: CRUD
    // ---------------------------------------------------------------

    /**
     * Test list objects
     */
    public function testListObjects(): void
    {
        $obj1 = $this->mockObjectEntity(['id' => 'uuid-1', 'title' => 'Obj A']);
        $obj2 = $this->mockObjectEntity(['id' => 'uuid-2', 'title' => 'Obj B']);

        $this->objectService->expects($this->once())
            ->method('findAll')
            ->with([])
            ->willReturn([$obj1, $obj2]);

        $result  = $this->service->callTool('objects', [
            'action'   => 'list',
            'register' => 1,
            'schema'   => 2,
        ]);
        $decoded = json_decode($result['content'][0]['text'], true);

        $this->assertFalse($result['isError']);
        $this->assertCount(2, $decoded);

    }//end testListObjects()


    /**
     * Test list objects with limit and offset
     */
    public function testListObjectsWithLimitAndOffset(): void
    {
        $this->objectService->expects($this->once())
            ->method('findAll')
            ->with(['limit' => 10, 'offset' => 20])
            ->willReturn([]);

        $this->service->callTool('objects', [
            'action'   => 'list',
            'register' => 1,
            'schema'   => 2,
            'limit'    => 10,
            'offset'   => 20,
        ]);

    }//end testListObjectsWithLimitAndOffset()


    /**
     * Test list objects with only limit
     */
    public function testListObjectsWithOnlyLimit(): void
    {
        $this->objectService->expects($this->once())
            ->method('findAll')
            ->with(['limit' => 5])
            ->willReturn([]);

        $this->service->callTool('objects', [
            'action'   => 'list',
            'register' => 1,
            'schema'   => 2,
            'limit'    => 5,
        ]);

    }//end testListObjectsWithOnlyLimit()


    /**
     * Test list objects with only offset
     */
    public function testListObjectsWithOnlyOffset(): void
    {
        $this->objectService->expects($this->once())
            ->method('findAll')
            ->with(['offset' => 15])
            ->willReturn([]);

        $this->service->callTool('objects', [
            'action'   => 'list',
            'register' => 1,
            'schema'   => 2,
            'offset'   => 15,
        ]);

    }//end testListObjectsWithOnlyOffset()


    /**
     * Test list objects calls setRegister and setSchema
     */
    public function testListObjectsSetsRegisterAndSchema(): void
    {
        $this->objectService->expects($this->once())
            ->method('setRegister')
            ->with(100);
        $this->objectService->expects($this->once())
            ->method('setSchema')
            ->with(200);
        $this->objectService->method('findAll')->willReturn([]);

        $this->service->callTool('objects', [
            'action'   => 'list',
            'register' => 100,
            'schema'   => 200,
        ]);

    }//end testListObjectsSetsRegisterAndSchema()


    /**
     * Test objects without register returns error
     */
    public function testObjectsMissingRegisterReturnsError(): void
    {
        $result = $this->service->callTool('objects', [
            'action' => 'list',
            'schema' => 2,
        ]);

        $this->assertTrue($result['isError']);
        $decoded = json_decode($result['content'][0]['text'], true);
        $this->assertStringContainsString('register and schema IDs are required', $decoded['error']);

    }//end testObjectsMissingRegisterReturnsError()


    /**
     * Test objects without schema returns error
     */
    public function testObjectsMissingSchemaReturnsError(): void
    {
        $result = $this->service->callTool('objects', [
            'action'   => 'list',
            'register' => 1,
        ]);

        $this->assertTrue($result['isError']);
        $decoded = json_decode($result['content'][0]['text'], true);
        $this->assertStringContainsString('register and schema IDs are required', $decoded['error']);

    }//end testObjectsMissingSchemaReturnsError()


    /**
     * Test objects without both register and schema returns error
     */
    public function testObjectsMissingBothRegisterAndSchemaReturnsError(): void
    {
        $result = $this->service->callTool('objects', [
            'action' => 'list',
        ]);

        $this->assertTrue($result['isError']);

    }//end testObjectsMissingBothRegisterAndSchemaReturnsError()


    /**
     * Test get object
     */
    public function testGetObject(): void
    {
        $obj = $this->mockObjectEntity(['id' => 'abc-123', 'title' => 'Found Obj']);
        $this->objectService->expects($this->once())
            ->method('find')
            ->with('abc-123')
            ->willReturn($obj);

        $result  = $this->service->callTool('objects', [
            'action'   => 'get',
            'register' => 1,
            'schema'   => 2,
            'id'       => 'abc-123',
        ]);
        $decoded = json_decode($result['content'][0]['text'], true);

        $this->assertFalse($result['isError']);
        $this->assertSame('abc-123', $decoded['id']);

    }//end testGetObject()


    /**
     * Test get object missing id
     */
    public function testGetObjectMissingIdReturnsError(): void
    {
        $result = $this->service->callTool('objects', [
            'action'   => 'get',
            'register' => 1,
            'schema'   => 2,
        ]);

        $this->assertTrue($result['isError']);

    }//end testGetObjectMissingIdReturnsError()


    /**
     * Test create object
     */
    public function testCreateObject(): void
    {
        $data = ['name' => 'New Object'];
        $obj  = $this->mockObjectEntity(['id' => 'new-uuid', 'name' => 'New Object']);

        $this->objectService->expects($this->once())
            ->method('saveObject')
            ->with($data)
            ->willReturn($obj);

        $result  = $this->service->callTool('objects', [
            'action'   => 'create',
            'register' => 1,
            'schema'   => 2,
            'data'     => $data,
        ]);
        $decoded = json_decode($result['content'][0]['text'], true);

        $this->assertFalse($result['isError']);
        $this->assertSame('New Object', $decoded['name']);

    }//end testCreateObject()


    /**
     * Test create object missing data
     */
    public function testCreateObjectMissingDataReturnsError(): void
    {
        $result = $this->service->callTool('objects', [
            'action'   => 'create',
            'register' => 1,
            'schema'   => 2,
        ]);

        $this->assertTrue($result['isError']);

    }//end testCreateObjectMissingDataReturnsError()


    /**
     * Test update object
     */
    public function testUpdateObject(): void
    {
        $data = ['name' => 'Updated Object'];
        $obj  = $this->mockObjectEntity(['id' => 'upd-uuid', 'name' => 'Updated Object']);

        $this->objectService->expects($this->once())
            ->method('saveObject')
            ->with(
                $this->identicalTo($data),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->identicalTo('upd-uuid')
            )
            ->willReturn($obj);

        $result  = $this->service->callTool('objects', [
            'action'   => 'update',
            'register' => 1,
            'schema'   => 2,
            'id'       => 'upd-uuid',
            'data'     => $data,
        ]);
        $decoded = json_decode($result['content'][0]['text'], true);

        $this->assertFalse($result['isError']);
        $this->assertSame('Updated Object', $decoded['name']);

    }//end testUpdateObject()


    /**
     * Test update object missing id
     */
    public function testUpdateObjectMissingIdReturnsError(): void
    {
        $result = $this->service->callTool('objects', [
            'action'   => 'update',
            'register' => 1,
            'schema'   => 2,
            'data'     => ['name' => 'X'],
        ]);

        $this->assertTrue($result['isError']);

    }//end testUpdateObjectMissingIdReturnsError()


    /**
     * Test update object missing data
     */
    public function testUpdateObjectMissingDataReturnsError(): void
    {
        $result = $this->service->callTool('objects', [
            'action'   => 'update',
            'register' => 1,
            'schema'   => 2,
            'id'       => 'some-uuid',
        ]);

        $this->assertTrue($result['isError']);

    }//end testUpdateObjectMissingDataReturnsError()


    /**
     * Test delete object
     */
    public function testDeleteObject(): void
    {
        $this->objectService->expects($this->once())
            ->method('deleteObject')
            ->with('del-uuid');

        $result  = $this->service->callTool('objects', [
            'action'   => 'delete',
            'register' => 1,
            'schema'   => 2,
            'id'       => 'del-uuid',
        ]);
        $decoded = json_decode($result['content'][0]['text'], true);

        $this->assertFalse($result['isError']);
        $this->assertTrue($decoded['deleted']);
        $this->assertSame('del-uuid', $decoded['id']);

    }//end testDeleteObject()


    /**
     * Test delete object missing id
     */
    public function testDeleteObjectMissingIdReturnsError(): void
    {
        $result = $this->service->callTool('objects', [
            'action'   => 'delete',
            'register' => 1,
            'schema'   => 2,
        ]);

        $this->assertTrue($result['isError']);

    }//end testDeleteObjectMissingIdReturnsError()


    /**
     * Test objects tool with unknown action returns error
     */
    public function testObjectsUnknownActionReturnsError(): void
    {
        $result = $this->service->callTool('objects', [
            'action'   => 'truncate',
            'register' => 1,
            'schema'   => 2,
        ]);

        $this->assertTrue($result['isError']);
        $decoded = json_decode($result['content'][0]['text'], true);
        $this->assertStringContainsString('Unknown action', $decoded['error']);

    }//end testObjectsUnknownActionReturnsError()


    // ---------------------------------------------------------------
    // Exception propagation from underlying services
    // ---------------------------------------------------------------

    /**
     * Test service exception is caught and returned as error
     */
    public function testServiceExceptionReturnedAsError(): void
    {
        $this->registerService->method('findAll')
            ->willThrowException(new \RuntimeException('Database unavailable'));

        $result  = $this->service->callTool('registers', ['action' => 'list']);
        $decoded = json_decode($result['content'][0]['text'], true);

        $this->assertTrue($result['isError']);
        $this->assertStringContainsString('Database unavailable', $decoded['error']);

    }//end testServiceExceptionReturnedAsError()


    /**
     * Test schema mapper exception is caught
     */
    public function testSchemaMapperExceptionReturnedAsError(): void
    {
        $this->schemaMapper->method('find')
            ->willThrowException(new \Exception('Not found'));

        $result = $this->service->callTool('schemas', ['action' => 'get', 'id' => 999]);

        $this->assertTrue($result['isError']);

    }//end testSchemaMapperExceptionReturnedAsError()


    /**
     * Test object service exception is caught
     */
    public function testObjectServiceExceptionReturnedAsError(): void
    {
        $this->objectService->method('findAll')
            ->willThrowException(new \Exception('Object store down'));

        $result = $this->service->callTool('objects', [
            'action'   => 'list',
            'register' => 1,
            'schema'   => 2,
        ]);

        $this->assertTrue($result['isError']);
        $decoded = json_decode($result['content'][0]['text'], true);
        $this->assertStringContainsString('Object store down', $decoded['error']);

    }//end testObjectServiceExceptionReturnedAsError()


    /**
     * Test list registers returns empty array when no registers exist
     */
    public function testListRegistersReturnsEmptyArray(): void
    {
        $this->registerService->method('findAll')->willReturn([]);

        $result  = $this->service->callTool('registers', ['action' => 'list']);
        $decoded = json_decode($result['content'][0]['text'], true);

        $this->assertFalse($result['isError']);
        $this->assertSame([], $decoded);

    }//end testListRegistersReturnsEmptyArray()


    /**
     * Test list schemas returns empty array
     */
    public function testListSchemasReturnsEmptyArray(): void
    {
        $this->schemaMapper->method('findAll')->willReturn([]);

        $result  = $this->service->callTool('schemas', ['action' => 'list']);
        $decoded = json_decode($result['content'][0]['text'], true);

        $this->assertFalse($result['isError']);
        $this->assertSame([], $decoded);

    }//end testListSchemasReturnsEmptyArray()


    /**
     * Test list objects returns empty array
     */
    public function testListObjectsReturnsEmptyArray(): void
    {
        $this->objectService->method('findAll')->willReturn([]);

        $result  = $this->service->callTool('objects', [
            'action'   => 'list',
            'register' => 1,
            'schema'   => 2,
        ]);
        $decoded = json_decode($result['content'][0]['text'], true);

        $this->assertFalse($result['isError']);
        $this->assertSame([], $decoded);

    }//end testListObjectsReturnsEmptyArray()


}//end class
