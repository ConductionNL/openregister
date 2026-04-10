<?php

namespace Unit\Listener;

use OCA\OpenRegister\Db\Action;
use OCA\OpenRegister\Db\ActionMapper;
use OCA\OpenRegister\Listener\ActionListener;
use OCA\OpenRegister\Service\ActionExecutor;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ActionListenerTest extends TestCase
{
    private ActionListener $listener;
    private $actionMapper;
    private $actionExecutor;
    private $logger;

    protected function setUp(): void
    {
        $this->actionMapper   = $this->createMock(ActionMapper::class);
        $this->actionExecutor = $this->createMock(ActionExecutor::class);
        $this->logger         = $this->createMock(LoggerInterface::class);

        $this->listener = new ActionListener(
            $this->actionMapper,
            $this->actionExecutor,
            $this->logger
        );
    }

    public function testHandleSkipsWhenPropagationStopped(): void
    {
        $event = new class extends Event {
            public function isPropagationStopped(): bool { return true; }
        };

        // Should never call findMatchingActions if propagation is stopped.
        $this->actionMapper
            ->expects($this->never())
            ->method('findMatchingActions');

        $this->listener->handle($event);
    }

    public function testHandleSkipsWhenNoMatchingActions(): void
    {
        $event = new Event();

        $this->actionMapper
            ->method('findMatchingActions')
            ->willReturn([]);

        $this->actionExecutor
            ->expects($this->never())
            ->method('executeActions');

        $this->listener->handle($event);
    }

    public function testHandleDelegatesMatchingActionsToExecutor(): void
    {
        $event = new Event();

        $action = new Action();
        $action->setId(1);
        $action->setUuid('uuid-1');
        $action->setName('Match');
        $action->setEventType('Event');
        $action->setEngine('n8n');
        $action->setWorkflowId('wf-1');

        $this->actionMapper
            ->method('findMatchingActions')
            ->willReturn([$action]);

        $this->actionExecutor
            ->expects($this->once())
            ->method('executeActions');

        $this->listener->handle($event);
    }

    public function testHandleCatchesExceptionsGracefully(): void
    {
        $event = new Event();

        $this->actionMapper
            ->method('findMatchingActions')
            ->willThrowException(new \Exception('DB error'));

        // Should not throw, just log.
        $this->logger
            ->expects($this->once())
            ->method('error');

        $this->listener->handle($event);
    }

    public function testHandleFiltersOutActionsByFilterCondition(): void
    {
        $event = new Event();

        // Action with filter condition that won't match the empty payload.
        $action = new Action();
        $action->setId(1);
        $action->setUuid('uuid-1');
        $action->setName('Filtered');
        $action->setEventType('Event');
        $action->setEngine('n8n');
        $action->setWorkflowId('wf-1');
        $action->setFilterConditionArray(['object.status' => 'critical']);

        $this->actionMapper
            ->method('findMatchingActions')
            ->willReturn([$action]);

        // Because filter condition doesn't match empty payload, executor should not be called.
        $this->actionExecutor
            ->expects($this->never())
            ->method('executeActions');

        $this->listener->handle($event);
    }
}
