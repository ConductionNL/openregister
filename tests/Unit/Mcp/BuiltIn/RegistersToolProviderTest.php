<?php

declare(strict_types=1);

/**
 * RegistersToolProvider Unit Tests
 *
 * Exercises the register CRUD logic that was relocated from
 * McpToolsService::executeRegisters() into the built-in
 * RegistersToolProvider by the ai-chat-companion-orchestrator change.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Mcp\BuiltIn
 * @author   OpenRegister Team
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/OpenRegister/OpenRegister
 */

namespace OCA\OpenRegister\Tests\Unit\Mcp\BuiltIn;

use InvalidArgumentException;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Mcp\BuiltIn\RegistersToolProvider;
use OCA\OpenRegister\Service\RegisterService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RegistersToolProvider.
 */
class RegistersToolProviderTest extends TestCase
{

    /** @var RegisterService&MockObject */
    private $registerService;

    private RegistersToolProvider $provider;


    protected function setUp(): void
    {
        parent::setUp();
        $this->registerService = $this->createMock(RegisterService::class);
        $this->provider        = new RegistersToolProvider($this->registerService);

    }//end setUp()


    /**
     * Build a mock Register whose jsonSerialize() returns $data.
     *
     * @param array<string, mixed> $data Serialized payload
     *
     * @return Register&MockObject
     */
    private function mockRegister(array $data): Register
    {
        $register = $this->createMock(Register::class);
        $register->method('jsonSerialize')->willReturn($data);
        return $register;

    }//end mockRegister()


    // ── descriptor surface ─────────────────────────────────────────


    public function testGetAppIdIsOpenregister(): void
    {
        $this->assertSame('openregister', $this->provider->getAppId());

    }//end testGetAppIdIsOpenregister()


    public function testGetToolsReturnsOneNamespacedDescriptor(): void
    {
        $tools = $this->provider->getTools();

        $this->assertCount(1, $tools);
        $this->assertSame('openregister.registers', $tools[0]['id']);
        $this->assertSame(RegistersToolProvider::TOOL_ID, $tools[0]['id']);
        $this->assertNotEmpty($tools[0]['description']);
        $this->assertSame('object', $tools[0]['inputSchema']['type']);
        $this->assertArrayHasKey('action', $tools[0]['inputSchema']['properties']);
        $this->assertSame(['action'], $tools[0]['inputSchema']['required']);

    }//end testGetToolsReturnsOneNamespacedDescriptor()


    // ── list ───────────────────────────────────────────────────────


    public function testListReturnsSerializedRegisters(): void
    {
        $this->registerService->expects($this->once())
            ->method('findAll')
            ->with(limit: null, offset: null)
            ->willReturn([$this->mockRegister(['id' => 1]), $this->mockRegister(['id' => 2])]);

        $result = $this->provider->invokeTool(RegistersToolProvider::TOOL_ID, ['action' => 'list']);

        $this->assertSame([['id' => 1], ['id' => 2]], $result);

    }//end testListReturnsSerializedRegisters()


    public function testListPassesLimitAndOffset(): void
    {
        $this->registerService->expects($this->once())
            ->method('findAll')
            ->with(limit: 5, offset: 10)
            ->willReturn([]);

        $this->assertSame([], $this->provider->invokeTool(RegistersToolProvider::TOOL_ID, ['action' => 'list', 'limit' => 5, 'offset' => 10]));

    }//end testListPassesLimitAndOffset()


    public function testListReturnsEmptyArrayWhenNoRegisters(): void
    {
        $this->registerService->method('findAll')->willReturn([]);
        $this->assertSame([], $this->provider->invokeTool(RegistersToolProvider::TOOL_ID, ['action' => 'list']));

    }//end testListReturnsEmptyArrayWhenNoRegisters()


    // ── get ────────────────────────────────────────────────────────


    public function testGetReturnsSerializedRegister(): void
    {
        $this->registerService->expects($this->once())->method('find')->with(id: 7)->willReturn($this->mockRegister(['id' => 7, 'title' => 'R']));

        $this->assertSame(['id' => 7, 'title' => 'R'], $this->provider->invokeTool(RegistersToolProvider::TOOL_ID, ['action' => 'get', 'id' => 7]));

    }//end testGetReturnsSerializedRegister()


    public function testGetRequiresId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required parameter: id');
        $this->provider->invokeTool(RegistersToolProvider::TOOL_ID, ['action' => 'get']);

    }//end testGetRequiresId()


    // ── create ─────────────────────────────────────────────────────


    public function testCreateReturnsSerializedRegister(): void
    {
        $this->registerService->expects($this->once())
            ->method('createFromArray')
            ->with(data: ['title' => 'New'])
            ->willReturn($this->mockRegister(['id' => 9, 'title' => 'New']));

        $this->assertSame(['id' => 9, 'title' => 'New'], $this->provider->invokeTool(RegistersToolProvider::TOOL_ID, ['action' => 'create', 'data' => ['title' => 'New']]));

    }//end testCreateReturnsSerializedRegister()


    public function testCreateRequiresData(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required parameter: data');
        $this->provider->invokeTool(RegistersToolProvider::TOOL_ID, ['action' => 'create']);

    }//end testCreateRequiresData()


    // ── update ─────────────────────────────────────────────────────


    public function testUpdateReturnsSerializedRegister(): void
    {
        $this->registerService->expects($this->once())
            ->method('updateFromArray')
            ->with(id: 3, data: ['title' => 'Edited'])
            ->willReturn($this->mockRegister(['id' => 3, 'title' => 'Edited']));

        $this->assertSame(['id' => 3, 'title' => 'Edited'], $this->provider->invokeTool(RegistersToolProvider::TOOL_ID, ['action' => 'update', 'id' => 3, 'data' => ['title' => 'Edited']]));

    }//end testUpdateReturnsSerializedRegister()


    public function testUpdateRequiresId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required parameter: id');
        $this->provider->invokeTool(RegistersToolProvider::TOOL_ID, ['action' => 'update', 'data' => []]);

    }//end testUpdateRequiresId()


    public function testUpdateRequiresData(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required parameter: data');
        $this->provider->invokeTool(RegistersToolProvider::TOOL_ID, ['action' => 'update', 'id' => 3]);

    }//end testUpdateRequiresData()


    // ── delete ─────────────────────────────────────────────────────


    public function testDeleteFindsThenDeletesAndReturnsConfirmation(): void
    {
        $register = $this->mockRegister(['id' => 4]);
        $this->registerService->expects($this->once())->method('find')->with(id: 4)->willReturn($register);
        $this->registerService->expects($this->once())->method('delete')->with(register: $register);

        $this->assertSame(['deleted' => true, 'id' => 4], $this->provider->invokeTool(RegistersToolProvider::TOOL_ID, ['action' => 'delete', 'id' => 4]));

    }//end testDeleteFindsThenDeletesAndReturnsConfirmation()


    public function testDeleteRequiresId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required parameter: id');
        $this->provider->invokeTool(RegistersToolProvider::TOOL_ID, ['action' => 'delete']);

    }//end testDeleteRequiresId()


    // ── unknown / missing action ───────────────────────────────────


    public function testUnknownActionThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown action: frobnicate');
        $this->provider->invokeTool(RegistersToolProvider::TOOL_ID, ['action' => 'frobnicate']);

    }//end testUnknownActionThrows()


    public function testMissingActionThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->provider->invokeTool(RegistersToolProvider::TOOL_ID, []);

    }//end testMissingActionThrows()
}//end class
