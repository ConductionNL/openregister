<?php

namespace Unit\Event;

use OCA\OpenRegister\Event\DeepLinkRegistrationEvent;
use OCA\OpenRegister\Event\ToolRegistrationEvent;
use OCA\OpenRegister\Service\DeepLinkRegistryService;
use OCA\OpenRegister\Service\ToolRegistry;
use OCA\OpenRegister\Tool\ToolInterface;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class RegistrationEventsTest extends TestCase
{
    public function testToolRegistrationEventExtendsEvent(): void
    {
        $registry = $this->createMock(ToolRegistry::class);
        $event = new ToolRegistrationEvent($registry);
        $this->assertInstanceOf(Event::class, $event);
    }

    public function testToolRegistrationEventRegisterToolDelegatesToRegistry(): void
    {
        $registry = $this->createMock(ToolRegistry::class);
        $tool = $this->createMock(ToolInterface::class);
        $metadata = ['name' => 'Test Tool', 'description' => 'A test tool'];

        $registry->expects($this->once())
            ->method('registerTool')
            ->with('myapp.test', $tool, $metadata);

        $event = new ToolRegistrationEvent($registry);
        $event->registerTool('myapp.test', $tool, $metadata);
    }

    public function testDeepLinkRegistrationEventExtendsEvent(): void
    {
        $registry = $this->createMock(DeepLinkRegistryService::class);
        $event = new DeepLinkRegistrationEvent($registry);
        $this->assertInstanceOf(Event::class, $event);
    }

    public function testDeepLinkRegistrationEventGetRegistry(): void
    {
        $registry = $this->createMock(DeepLinkRegistryService::class);
        $event = new DeepLinkRegistrationEvent($registry);
        $this->assertSame($registry, $event->getRegistry());
    }

    public function testDeepLinkRegistrationEventRegisterDelegatesToRegistry(): void
    {
        $registry = $this->createMock(DeepLinkRegistryService::class);

        $registry->expects($this->once())
            ->method('register')
            ->with('procest', 'cases', 'case', '/apps/procest/#/cases/{uuid}', 'icon-case');

        $event = new DeepLinkRegistrationEvent($registry);
        $event->register('procest', 'cases', 'case', '/apps/procest/#/cases/{uuid}', 'icon-case');
    }

    public function testDeepLinkRegistrationEventRegisterWithEmptyIcon(): void
    {
        $registry = $this->createMock(DeepLinkRegistryService::class);

        $registry->expects($this->once())
            ->method('register')
            ->with('myapp', 'reg', 'schema', '/apps/myapp/{uuid}', '');

        $event = new DeepLinkRegistrationEvent($registry);
        $event->register('myapp', 'reg', 'schema', '/apps/myapp/{uuid}');
    }
}
