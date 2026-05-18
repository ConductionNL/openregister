<?php

declare(strict_types=1);

/**
 * SchemasToolProvider Unit Tests
 *
 * Exercises the schema CRUD logic that was relocated from
 * McpToolsService::executeSchemas() into the built-in SchemasToolProvider
 * by the ai-chat-companion-orchestrator change.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Mcp\BuiltIn
 * @author   OpenRegister Team
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/OpenRegister/OpenRegister
 */

namespace OCA\OpenRegister\Tests\Unit\Mcp\BuiltIn;

use InvalidArgumentException;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Mcp\BuiltIn\SchemasToolProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SchemasToolProvider.
 */
class SchemasToolProviderTest extends TestCase
{

    /** @var SchemaMapper&MockObject */
    private $schemaMapper;

    private SchemasToolProvider $provider;


    protected function setUp(): void
    {
        parent::setUp();
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->provider     = new SchemasToolProvider($this->schemaMapper);

    }//end setUp()


    /**
     * Build a mock Schema whose jsonSerialize() returns $data.
     *
     * @param array<string, mixed> $data Serialized payload
     *
     * @return Schema&MockObject
     */
    private function mockSchema(array $data): Schema
    {
        $schema = $this->createMock(Schema::class);
        $schema->method('jsonSerialize')->willReturn($data);
        return $schema;

    }//end mockSchema()


    // ── descriptor surface ─────────────────────────────────────────


    public function testGetAppIdIsOpenregister(): void
    {
        $this->assertSame('openregister', $this->provider->getAppId());

    }//end testGetAppIdIsOpenregister()


    public function testGetToolsReturnsOneNamespacedDescriptor(): void
    {
        $tools = $this->provider->getTools();

        $this->assertCount(1, $tools);
        $this->assertSame('openregister.schemas', $tools[0]['id']);
        $this->assertSame(SchemasToolProvider::TOOL_ID, $tools[0]['id']);
        $this->assertNotEmpty($tools[0]['description']);
        $this->assertSame('object', $tools[0]['inputSchema']['type']);
        $this->assertArrayHasKey('action', $tools[0]['inputSchema']['properties']);
        $this->assertSame(['action'], $tools[0]['inputSchema']['required']);

    }//end testGetToolsReturnsOneNamespacedDescriptor()


    // ── list ───────────────────────────────────────────────────────


    public function testListReturnsSerializedSchemas(): void
    {
        $this->schemaMapper->expects($this->once())
            ->method('findAll')
            ->with(limit: null, offset: null)
            ->willReturn([$this->mockSchema(['id' => 1]), $this->mockSchema(['id' => 2])]);

        $this->assertSame([['id' => 1], ['id' => 2]], $this->provider->invokeTool(SchemasToolProvider::TOOL_ID, ['action' => 'list']));

    }//end testListReturnsSerializedSchemas()


    public function testListPassesLimitAndOffset(): void
    {
        $this->schemaMapper->expects($this->once())->method('findAll')->with(limit: 3, offset: 6)->willReturn([]);

        $this->assertSame([], $this->provider->invokeTool(SchemasToolProvider::TOOL_ID, ['action' => 'list', 'limit' => 3, 'offset' => 6]));

    }//end testListPassesLimitAndOffset()


    public function testListReturnsEmptyArrayWhenNoSchemas(): void
    {
        $this->schemaMapper->method('findAll')->willReturn([]);
        $this->assertSame([], $this->provider->invokeTool(SchemasToolProvider::TOOL_ID, ['action' => 'list']));

    }//end testListReturnsEmptyArrayWhenNoSchemas()


    // ── get ────────────────────────────────────────────────────────


    public function testGetReturnsSerializedSchema(): void
    {
        $this->schemaMapper->expects($this->once())->method('find')->with(11)->willReturn($this->mockSchema(['id' => 11]));

        $this->assertSame(['id' => 11], $this->provider->invokeTool(SchemasToolProvider::TOOL_ID, ['action' => 'get', 'id' => 11]));

    }//end testGetReturnsSerializedSchema()


    public function testGetRequiresId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required parameter: id');
        $this->provider->invokeTool(SchemasToolProvider::TOOL_ID, ['action' => 'get']);

    }//end testGetRequiresId()


    // ── create ─────────────────────────────────────────────────────


    public function testCreateReturnsSerializedSchema(): void
    {
        $this->schemaMapper->expects($this->once())
            ->method('createFromArray')
            ->with(object: ['title' => 'S'])
            ->willReturn($this->mockSchema(['id' => 12, 'title' => 'S']));

        $this->assertSame(['id' => 12, 'title' => 'S'], $this->provider->invokeTool(SchemasToolProvider::TOOL_ID, ['action' => 'create', 'data' => ['title' => 'S']]));

    }//end testCreateReturnsSerializedSchema()


    public function testCreateRequiresData(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required parameter: data');
        $this->provider->invokeTool(SchemasToolProvider::TOOL_ID, ['action' => 'create']);

    }//end testCreateRequiresData()


    // ── update ─────────────────────────────────────────────────────


    public function testUpdateReturnsSerializedSchema(): void
    {
        $this->schemaMapper->expects($this->once())
            ->method('updateFromArray')
            ->with(id: 5, object: ['title' => 'Edited'])
            ->willReturn($this->mockSchema(['id' => 5, 'title' => 'Edited']));

        $this->assertSame(['id' => 5, 'title' => 'Edited'], $this->provider->invokeTool(SchemasToolProvider::TOOL_ID, ['action' => 'update', 'id' => 5, 'data' => ['title' => 'Edited']]));

    }//end testUpdateReturnsSerializedSchema()


    public function testUpdateRequiresId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required parameter: id');
        $this->provider->invokeTool(SchemasToolProvider::TOOL_ID, ['action' => 'update', 'data' => []]);

    }//end testUpdateRequiresId()


    public function testUpdateRequiresData(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required parameter: data');
        $this->provider->invokeTool(SchemasToolProvider::TOOL_ID, ['action' => 'update', 'id' => 5]);

    }//end testUpdateRequiresData()


    // ── delete ─────────────────────────────────────────────────────


    public function testDeleteFindsThenDeletesAndReturnsConfirmation(): void
    {
        $schema = $this->mockSchema(['id' => 8]);
        $this->schemaMapper->expects($this->once())->method('find')->with(8)->willReturn($schema);
        $this->schemaMapper->expects($this->once())->method('delete')->with($schema);

        $this->assertSame(['deleted' => true, 'id' => 8], $this->provider->invokeTool(SchemasToolProvider::TOOL_ID, ['action' => 'delete', 'id' => 8]));

    }//end testDeleteFindsThenDeletesAndReturnsConfirmation()


    public function testDeleteRequiresId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required parameter: id');
        $this->provider->invokeTool(SchemasToolProvider::TOOL_ID, ['action' => 'delete']);

    }//end testDeleteRequiresId()


    // ── unknown / missing action ───────────────────────────────────


    public function testUnknownActionThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown action: bogus');
        $this->provider->invokeTool(SchemasToolProvider::TOOL_ID, ['action' => 'bogus']);

    }//end testUnknownActionThrows()


    public function testMissingActionThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->provider->invokeTool(SchemasToolProvider::TOOL_ID, []);

    }//end testMissingActionThrows()
}//end class
