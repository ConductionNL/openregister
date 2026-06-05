<?php

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenRegister\Event\ToolRegistrationEvent;
use OCA\OpenRegister\Listener\ToolRegistrationListener;
<<<<<<< HEAD
=======
use OCA\OpenRegister\Service\Mcp\McpToolsService;
>>>>>>> origin/development
use OCA\OpenRegister\Tool\AgentTool;
use OCA\OpenRegister\Tool\ApplicationTool;
use OCA\OpenRegister\Tool\ObjectsTool;
use OCA\OpenRegister\Tool\RegisterTool;
use OCA\OpenRegister\Tool\SchemaTool;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
<<<<<<< HEAD
=======
use Psr\Log\LoggerInterface;
>>>>>>> origin/development

class ToolRegistrationListenerTest extends TestCase
{
    private ToolRegistrationListener $listener;
    private RegisterTool&MockObject $registerTool;
    private SchemaTool&MockObject $schemaTool;
    private ObjectsTool&MockObject $objectsTool;
    private ApplicationTool&MockObject $applicationTool;
    private AgentTool&MockObject $agentTool;
<<<<<<< HEAD
=======
    private McpToolsService&MockObject $mcpToolsService;
    private LoggerInterface&MockObject $logger;
>>>>>>> origin/development

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerTool = $this->createMock(RegisterTool::class);
        $this->schemaTool = $this->createMock(SchemaTool::class);
        $this->objectsTool = $this->createMock(ObjectsTool::class);
        $this->applicationTool = $this->createMock(ApplicationTool::class);
        $this->agentTool = $this->createMock(AgentTool::class);
<<<<<<< HEAD

        $this->listener = new ToolRegistrationListener(
            $this->registerTool,
            $this->schemaTool,
            $this->objectsTool,
            $this->applicationTool,
            $this->agentTool,
=======
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
>>>>>>> origin/development
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
}
