<?php

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenRegister\Event\ToolRegistrationEvent;
use OCA\OpenRegister\Listener\ToolRegistrationListener;
use OCA\OpenRegister\Tool\AgentTool;
use OCA\OpenRegister\Tool\ApplicationTool;
use OCA\OpenRegister\Tool\ObjectsTool;
use OCA\OpenRegister\Tool\RegisterTool;
use OCA\OpenRegister\Tool\SchemaTool;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ToolRegistrationListenerTest extends TestCase
{
    private ToolRegistrationListener $listener;
    private RegisterTool&MockObject $registerTool;
    private SchemaTool&MockObject $schemaTool;
    private ObjectsTool&MockObject $objectsTool;
    private ApplicationTool&MockObject $applicationTool;
    private AgentTool&MockObject $agentTool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerTool = $this->createMock(RegisterTool::class);
        $this->schemaTool = $this->createMock(SchemaTool::class);
        $this->objectsTool = $this->createMock(ObjectsTool::class);
        $this->applicationTool = $this->createMock(ApplicationTool::class);
        $this->agentTool = $this->createMock(AgentTool::class);

        $this->listener = new ToolRegistrationListener(
            $this->registerTool,
            $this->schemaTool,
            $this->objectsTool,
            $this->applicationTool,
            $this->agentTool,
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
