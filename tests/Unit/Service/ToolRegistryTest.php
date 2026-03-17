<?php

declare(strict_types=1);

namespace Unit\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Service\ToolRegistry;
use OCA\OpenRegister\Tool\ToolInterface;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

class ToolRegistryTest extends TestCase
{
    private ToolRegistry $registry;
    private IEventDispatcher&MockObject $eventDispatcher;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->eventDispatcher = $this->createMock(IEventDispatcher::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->registry = new ToolRegistry($this->eventDispatcher, $this->logger);
    }

    private function createToolMock(): ToolInterface&MockObject
    {
        return $this->createMock(ToolInterface::class);
    }

    private function validMetadata(): array
    {
        return [
            'name' => 'Test Tool',
            'description' => 'A test tool',
            'icon' => 'icon-test',
            'app' => 'testapp',
        ];
    }

    // ── registerTool ──

    public function testRegisterToolSucceeds(): void
    {
        $tool = $this->createToolMock();
        $this->registry->registerTool('testapp.testtool', $tool, $this->validMetadata());

        // Verify it was registered by getting all tools.
        // loadTools dispatches event, so we get it directly from getAllTools after registering.
        // But getAllTools calls loadTools which dispatches event. We need to register before that.
        // Since we manually registered, getAllTools should include it.
        $this->eventDispatcher->method('dispatchTyped'); // Allow the event dispatch.
        $allTools = $this->registry->getAllTools();
        $this->assertArrayHasKey('testapp.testtool', $allTools);
    }

    public function testRegisterToolThrowsForInvalidIdFormat(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid tool ID format');

        $this->registry->registerTool('invalid-format', $this->createToolMock(), $this->validMetadata());
    }

    public function testRegisterToolThrowsForUppercaseId(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->registry->registerTool('App.Tool', $this->createToolMock(), $this->validMetadata());
    }

    public function testRegisterToolThrowsForDuplicateId(): void
    {
        $this->registry->registerTool('testapp.tool1', $this->createToolMock(), $this->validMetadata());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Tool already registered');

        $this->registry->registerTool('testapp.tool1', $this->createToolMock(), $this->validMetadata());
    }

    public function testRegisterToolThrowsForMissingMetadataName(): void
    {
        $metadata = $this->validMetadata();
        unset($metadata['name']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required metadata field: name');

        $this->registry->registerTool('testapp.tool1', $this->createToolMock(), $metadata);
    }

    public function testRegisterToolThrowsForMissingMetadataDescription(): void
    {
        $metadata = $this->validMetadata();
        unset($metadata['description']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required metadata field: description');

        $this->registry->registerTool('testapp.tool1', $this->createToolMock(), $metadata);
    }

    public function testRegisterToolThrowsForMissingMetadataIcon(): void
    {
        $metadata = $this->validMetadata();
        unset($metadata['icon']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required metadata field: icon');

        $this->registry->registerTool('testapp.tool1', $this->createToolMock(), $metadata);
    }

    public function testRegisterToolThrowsForMissingMetadataApp(): void
    {
        $metadata = $this->validMetadata();
        unset($metadata['app']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required metadata field: app');

        $this->registry->registerTool('testapp.tool1', $this->createToolMock(), $metadata);
    }

    // ── getTool ──

    public function testGetToolReturnsRegisteredTool(): void
    {
        $tool = $this->createToolMock();
        $this->registry->registerTool('testapp.tool1', $tool, $this->validMetadata());

        $this->eventDispatcher->method('dispatchTyped');

        $result = $this->registry->getTool('testapp.tool1');
        $this->assertSame($tool, $result);
    }

    public function testGetToolReturnsNullForUnregistered(): void
    {
        $this->eventDispatcher->method('dispatchTyped');

        $result = $this->registry->getTool('nonexistent.tool');
        $this->assertNull($result);
    }

    // ── getAllTools ──

    public function testGetAllToolsDispatchesEventOnFirstCall(): void
    {
        $this->eventDispatcher->expects($this->once())->method('dispatchTyped');

        $this->registry->getAllTools();
    }

    public function testGetAllToolsDoesNotDispatchTwice(): void
    {
        $this->eventDispatcher->expects($this->once())->method('dispatchTyped');

        $this->registry->getAllTools();
        $this->registry->getAllTools();
    }

    public function testGetAllToolsReturnsMetadataOnly(): void
    {
        $this->registry->registerTool('testapp.tool1', $this->createToolMock(), $this->validMetadata());
        $this->eventDispatcher->method('dispatchTyped');

        $result = $this->registry->getAllTools();

        $this->assertArrayHasKey('testapp.tool1', $result);
        $this->assertSame('Test Tool', $result['testapp.tool1']['name']);
        // Should not contain the 'tool' instance, only metadata.
        $this->assertArrayNotHasKey('tool', $result['testapp.tool1']);
    }

    // ── getTools ──

    public function testGetToolsReturnsRequestedToolsOnly(): void
    {
        $tool1 = $this->createToolMock();
        $tool2 = $this->createToolMock();
        $this->registry->registerTool('testapp.tool1', $tool1, $this->validMetadata());
        $this->registry->registerTool('testapp.tool2', $tool2, $this->validMetadata());

        $this->eventDispatcher->method('dispatchTyped');

        $result = $this->registry->getTools(['testapp.tool1']);

        $this->assertCount(1, $result);
        $this->assertSame($tool1, $result['testapp.tool1']);
    }

    public function testGetToolsSkipsMissingToolsAndLogsWarning(): void
    {
        $this->eventDispatcher->method('dispatchTyped');
        $this->logger->expects($this->once())->method('warning');

        $result = $this->registry->getTools(['nonexistent.tool']);

        $this->assertSame([], $result);
    }

    public function testGetToolsReturnsEmptyForEmptyInput(): void
    {
        $this->eventDispatcher->method('dispatchTyped');

        $result = $this->registry->getTools([]);
        $this->assertSame([], $result);
    }
}
