<?php

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenRegister\Event\ToolRegistrationEvent;
use OCA\OpenRegister\Listener\ToolRegistrationListener;
use OCA\OpenRegister\Service\Mcp\McpToolsService;
use OCA\OpenRegister\Tool\AgentTool;
use OCA\OpenRegister\Tool\ApplicationTool;
use OCA\OpenRegister\Tool\ObjectsTool;
use OCA\OpenRegister\Tool\RegisterTool;
use OCA\OpenRegister\Tool\SchemaTool;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ToolRegistrationListenerTest extends TestCase
{
    private ToolRegistrationListener $listener;
    private RegisterTool&MockObject $registerTool;
    private SchemaTool&MockObject $schemaTool;
    private ObjectsTool&MockObject $objectsTool;
    private ApplicationTool&MockObject $applicationTool;
    private AgentTool&MockObject $agentTool;
    private McpToolsService&MockObject $mcpToolsService;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerTool = $this->createMock(RegisterTool::class);
        $this->schemaTool = $this->createMock(SchemaTool::class);
        $this->objectsTool = $this->createMock(ObjectsTool::class);
        $this->applicationTool = $this->createMock(ApplicationTool::class);
        $this->agentTool = $this->createMock(AgentTool::class);
        $this->mcpToolsService = $this->createMock(McpToolsService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->listener = new ToolRegistrationListener(
            registerTool: $this->registerTool,
            schemaTool: $this->schemaTool,
            objectsTool: $this->objectsTool,
            applicationTool: $this->applicationTool,
            agentTool: $this->agentTool,
            mcpToolsService: $this->mcpToolsService,
            logger: $this->logger,
        );
    }

    public function testEarlyReturnForNonToolRegistrationEvent(): void
    {
        $event = $this->createMock(Event::class);
        // Should not throw, just return
        $this->listener->handle($event);
        $this->assertTrue(true);
    }

    public function testRegistersAllFiveTools(): void
    {
        $event = $this->createMock(ToolRegistrationEvent::class);

        $event->expects($this->exactly(5))
            ->method('registerTool');

        $this->listener->handle($event);
    }

    public function testRegistersCorrectToolIds(): void
    {
        $event = $this->createMock(ToolRegistrationEvent::class);

        $registeredIds = [];
        $event->method('registerTool')
            ->willReturnCallback(function (string $id) use (&$registeredIds) {
                $registeredIds[] = $id;
            });

        $this->listener->handle($event);

        $this->assertContains('openregister.register', $registeredIds);
        $this->assertContains('openregister.schema', $registeredIds);
        $this->assertContains('openregister.objects', $registeredIds);
        $this->assertContains('openregister.application', $registeredIds);
        $this->assertContains('openregister.agent', $registeredIds);
    }

    /**
     * ADR-063 chain 2/3: a schema-derived provider's three-segment
     * `{appId}.{schema}.{verb}` ids must pass the listener's id-format gate
     * and be bridged onto the chat/facade surface (the previous regex only
     * accepted two segments and would have silently dropped every derived
     * tool from the facade surface).
     *
     * @spec openspec/changes/or-mcp-derived-tool-provider/specs/ai-mcp/spec.md
     */
    public function testBridgesThreeSegmentSchemaDerivedIds(): void
    {
        $provider = $this->createMock(\OCA\OpenRegister\Mcp\IMcpToolProvider::class);
        $provider->method('getAppId')->willReturn('pipelinq');
        $provider->method('getTools')->willReturn([
            [
                'id'          => 'pipelinq.lead.search',
                'name'        => 'lead_search',
                'description' => 'Search lead objects in pipelinq.',
                'inputSchema' => ['type' => 'object', 'properties' => []],
            ],
        ]);

        $this->mcpToolsService->method('getProviders')->willReturn([$provider]);

        $event = $this->createMock(ToolRegistrationEvent::class);

        $registeredIds = [];
        $event->method('registerTool')
            ->willReturnCallback(function (string $id) use (&$registeredIds) {
                $registeredIds[] = $id;
            });

        $this->listener->handle($event);

        $this->assertContains('pipelinq.lead.search', $registeredIds);
    }
}
