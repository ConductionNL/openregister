<?php

declare(strict_types=1);

/**
 * ObjectsToolProvider Unit Tests
 *
 * Exercises the object CRUD logic that was relocated from
 * McpToolsService::executeObjects() into the built-in ObjectsToolProvider
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
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Mcp\BuiltIn\ObjectsToolProvider;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ObjectsToolProvider.
 */
class ObjectsToolProviderTest extends TestCase
{

    /** @var ObjectService&MockObject */
    private $objectService;

    private ObjectsToolProvider $provider;


    protected function setUp(): void
    {
        parent::setUp();
        $this->objectService = $this->createMock(ObjectService::class);
        $this->provider      = new ObjectsToolProvider($this->objectService);

    }//end setUp()


    /**
     * Build a mock ObjectEntity whose jsonSerialize() returns $data.
     *
     * @param array<string, mixed> $data Serialized payload
     *
     * @return ObjectEntity&MockObject
     */
    private function mockObject(array $data): ObjectEntity
    {
        $object = $this->createMock(ObjectEntity::class);
        $object->method('jsonSerialize')->willReturn($data);
        return $object;

    }//end mockObject()


    /**
     * Arguments with register + schema set (always required for object ops).
     *
     * @param array<string, mixed> $extra Additional arguments to merge in
     *
     * @return array<string, mixed>
     */
    private function args(array $extra): array
    {
        return array_merge(['register' => 1, 'schema' => 2], $extra);

    }//end args()


    // ── descriptor surface ─────────────────────────────────────────


    public function testGetAppIdIsOpenregister(): void
    {
        $this->assertSame('openregister', $this->provider->getAppId());

    }//end testGetAppIdIsOpenregister()


    public function testGetToolsReturnsOneNamespacedDescriptor(): void
    {
        $tools = $this->provider->getTools();

        $this->assertCount(1, $tools);
        $this->assertSame('openregister.objects', $tools[0]['id']);
        $this->assertSame(ObjectsToolProvider::TOOL_ID, $tools[0]['id']);
        $this->assertNotEmpty($tools[0]['description']);
        $this->assertSame('object', $tools[0]['inputSchema']['type']);
        $this->assertArrayHasKey('action', $tools[0]['inputSchema']['properties']);

    }//end testGetToolsReturnsOneNamespacedDescriptor()


    // ── register/schema requirement + scoping ──────────────────────


    public function testInvokeRequiresRegisterAndSchema(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Both register and schema IDs are required for object operations');
        $this->provider->invokeTool(ObjectsToolProvider::TOOL_ID, ['action' => 'list']);

    }//end testInvokeRequiresRegisterAndSchema()


    public function testInvokeRequiresSchemaWhenOnlyRegisterGiven(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->provider->invokeTool(ObjectsToolProvider::TOOL_ID, ['action' => 'list', 'register' => 1]);

    }//end testInvokeRequiresSchemaWhenOnlyRegisterGiven()


    public function testInvokeScopesObjectServiceToRegisterAndSchema(): void
    {
        $this->objectService->expects($this->once())->method('setRegister')->with(1);
        $this->objectService->expects($this->once())->method('setSchema')->with(2);
        $this->objectService->method('findAll')->willReturn([]);

        $this->provider->invokeTool(ObjectsToolProvider::TOOL_ID, $this->args(['action' => 'list']));

    }//end testInvokeScopesObjectServiceToRegisterAndSchema()


    // ── list ───────────────────────────────────────────────────────


    public function testListReturnsSerializedObjects(): void
    {
        $this->objectService->expects($this->once())
            ->method('findAll')
            ->with(config: [])
            ->willReturn([$this->mockObject(['uuid' => 'a']), $this->mockObject(['uuid' => 'b'])]);

        $this->assertSame([['uuid' => 'a'], ['uuid' => 'b']], $this->provider->invokeTool(ObjectsToolProvider::TOOL_ID, $this->args(['action' => 'list'])));

    }//end testListReturnsSerializedObjects()


    public function testListPassesLimitAndOffsetIntoConfig(): void
    {
        $this->objectService->expects($this->once())->method('findAll')->with(config: ['limit' => 5, 'offset' => 10])->willReturn([]);

        $this->provider->invokeTool(ObjectsToolProvider::TOOL_ID, $this->args(['action' => 'list', 'limit' => 5, 'offset' => 10]));

    }//end testListPassesLimitAndOffsetIntoConfig()


    public function testListPassesOnlyLimitWhenOffsetAbsent(): void
    {
        $this->objectService->expects($this->once())->method('findAll')->with(config: ['limit' => 7])->willReturn([]);

        $this->provider->invokeTool(ObjectsToolProvider::TOOL_ID, $this->args(['action' => 'list', 'limit' => 7]));

    }//end testListPassesOnlyLimitWhenOffsetAbsent()


    public function testListReturnsEmptyArrayWhenNoObjects(): void
    {
        $this->objectService->method('findAll')->willReturn([]);
        $this->assertSame([], $this->provider->invokeTool(ObjectsToolProvider::TOOL_ID, $this->args(['action' => 'list'])));

    }//end testListReturnsEmptyArrayWhenNoObjects()


    // ── get ────────────────────────────────────────────────────────


    public function testGetReturnsSerializedObject(): void
    {
        $this->objectService->expects($this->once())->method('find')->with('uuid-1')->willReturn($this->mockObject(['uuid' => 'uuid-1']));

        $this->assertSame(['uuid' => 'uuid-1'], $this->provider->invokeTool(ObjectsToolProvider::TOOL_ID, $this->args(['action' => 'get', 'id' => 'uuid-1'])));

    }//end testGetReturnsSerializedObject()


    public function testGetRequiresId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required parameter: id');
        $this->provider->invokeTool(ObjectsToolProvider::TOOL_ID, $this->args(['action' => 'get']));

    }//end testGetRequiresId()


    // ── create ─────────────────────────────────────────────────────


    public function testCreateReturnsSerializedObject(): void
    {
        $this->objectService->expects($this->once())
            ->method('saveObject')
            ->with(object: ['name' => 'X'])
            ->willReturn($this->mockObject(['uuid' => 'new', 'name' => 'X']));

        $this->assertSame(['uuid' => 'new', 'name' => 'X'], $this->provider->invokeTool(ObjectsToolProvider::TOOL_ID, $this->args(['action' => 'create', 'data' => ['name' => 'X']])));

    }//end testCreateReturnsSerializedObject()


    public function testCreateRequiresData(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required parameter: data');
        $this->provider->invokeTool(ObjectsToolProvider::TOOL_ID, $this->args(['action' => 'create']));

    }//end testCreateRequiresData()


    // ── update ─────────────────────────────────────────────────────


    public function testUpdateReturnsSerializedObject(): void
    {
        $this->objectService->expects($this->once())
            ->method('saveObject')
            ->with(object: ['name' => 'Edited'], uuid: 'uuid-2')
            ->willReturn($this->mockObject(['uuid' => 'uuid-2', 'name' => 'Edited']));

        $this->assertSame(['uuid' => 'uuid-2', 'name' => 'Edited'], $this->provider->invokeTool(ObjectsToolProvider::TOOL_ID, $this->args(['action' => 'update', 'id' => 'uuid-2', 'data' => ['name' => 'Edited']])));

    }//end testUpdateReturnsSerializedObject()


    public function testUpdateRequiresId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required parameter: id');
        $this->provider->invokeTool(ObjectsToolProvider::TOOL_ID, $this->args(['action' => 'update', 'data' => []]));

    }//end testUpdateRequiresId()


    public function testUpdateRequiresData(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required parameter: data');
        $this->provider->invokeTool(ObjectsToolProvider::TOOL_ID, $this->args(['action' => 'update', 'id' => 'uuid-2']));

    }//end testUpdateRequiresData()


    // ── delete ─────────────────────────────────────────────────────


    public function testDeleteCallsDeleteObjectAndReturnsConfirmation(): void
    {
        $this->objectService->expects($this->once())->method('deleteObject')->with(uuid: 'uuid-3');

        $this->assertSame(['deleted' => true, 'id' => 'uuid-3'], $this->provider->invokeTool(ObjectsToolProvider::TOOL_ID, $this->args(['action' => 'delete', 'id' => 'uuid-3'])));

    }//end testDeleteCallsDeleteObjectAndReturnsConfirmation()


    public function testDeleteRequiresId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required parameter: id');
        $this->provider->invokeTool(ObjectsToolProvider::TOOL_ID, $this->args(['action' => 'delete']));

    }//end testDeleteRequiresId()


    // ── unknown / missing action ───────────────────────────────────


    public function testUnknownActionThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown action: weird');
        $this->provider->invokeTool(ObjectsToolProvider::TOOL_ID, $this->args(['action' => 'weird']));

    }//end testUnknownActionThrows()


    public function testMissingActionThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->provider->invokeTool(ObjectsToolProvider::TOOL_ID, $this->args([]));

    }//end testMissingActionThrows()
}//end class
