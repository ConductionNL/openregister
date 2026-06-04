<?php

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Event\ConfigurationUpdatedEvent;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCA\OpenRegister\Event\SourceUpdatedEvent;
use OCA\OpenRegister\Listener\AnnotationNotificationListener;
use OCA\OpenRegister\Service\AnnotationNotificationDispatcher;
use OCA\OpenRegister\Service\SystemSchemaNotificationRegistry;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for AnnotationNotificationListener.
 *
 * @spec openspec/changes/openregister-system-notifications/tasks.md#task-5.1
 * @spec openspec/changes/openregister-system-notifications/tasks.md#task-5.2
 */
class AnnotationNotificationListenerTest extends TestCase
{
    private AnnotationNotificationDispatcher&MockObject $dispatcher;
    private SystemSchemaNotificationRegistry&MockObject $registry;
    private LoggerInterface&MockObject $logger;
    private AnnotationNotificationListener $listener;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dispatcher = $this->createMock(AnnotationNotificationDispatcher::class);
        $this->registry = $this->createMock(SystemSchemaNotificationRegistry::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->listener = new AnnotationNotificationListener(
            dispatcher: $this->dispatcher,
            registry: $this->registry,
            logger: $this->logger
        );
    }

    private function buildSchemaUpdatedEvent(): SchemaUpdatedEvent
    {
        $newSchema = new Schema();
        $newSchema->setTitle('New Schema');

        $oldSchema = new Schema();
        $oldSchema->setTitle('Old Schema');

        return new SchemaUpdatedEvent(newSchema: $newSchema, oldSchema: $oldSchema);
    }

    private function buildConfigurationUpdatedEvent(): ConfigurationUpdatedEvent
    {
        $newConfig = new Configuration();
        $newConfig->setTitle('New Config');

        $oldConfig = new Configuration();
        $oldConfig->setTitle('Old Config');

        return new ConfigurationUpdatedEvent(newConfiguration: $newConfig, oldConfiguration: $oldConfig);
    }

    public function testHandleSchemaUpdatedEventDelegatesToDispatcher(): void
    {
        $fakeRules = [['trigger' => 'updated', 'condition' => null, 'recipients' => [], 'subject' => ['en' => 'test']]];

        $this->registry->expects($this->once())
            ->method('getRulesForEntityType')
            ->with('schema')
            ->willReturn($fakeRules);

        $this->dispatcher->expects($this->once())
            ->method('dispatch')
            ->with(
                $this->equalTo('schema'),
                $this->equalTo('updated'),
                $this->isType('array'),
                $this->isType('array'),
                $this->equalTo($fakeRules)
            );

        $this->listener->handle($this->buildSchemaUpdatedEvent());
    }

    public function testHandleConfigurationUpdatedEventDelegatesToDispatcher(): void
    {
        $fakeRules = [['trigger' => 'updated', 'condition' => null, 'recipients' => [], 'subject' => ['en' => 'test']]];

        $this->registry->expects($this->once())
            ->method('getRulesForEntityType')
            ->with('configuration')
            ->willReturn($fakeRules);

        $this->dispatcher->expects($this->once())
            ->method('dispatch')
            ->with(
                $this->equalTo('configuration'),
                $this->equalTo('updated'),
                $this->isType('array'),
                $this->isType('array'),
                $this->equalTo($fakeRules)
            );

        $this->listener->handle($this->buildConfigurationUpdatedEvent());
    }

    public function testHandleUnknownEventIsIgnored(): void
    {
        $this->registry->expects($this->never())->method('getRulesForEntityType');
        $this->dispatcher->expects($this->never())->method('dispatch');

        $this->listener->handle(new class extends Event {});
    }

    public function testHandleSkipsDispatchWhenNoRules(): void
    {
        $this->registry->expects($this->once())
            ->method('getRulesForEntityType')
            ->with('schema')
            ->willReturn([]);

        $this->dispatcher->expects($this->never())->method('dispatch');

        $this->listener->handle($this->buildSchemaUpdatedEvent());
    }

    public function testHandleSourceUpdatedEventPassesOldAndNewData(): void
    {
        $newSource = new Source();
        $newSource->setTitle('New Source');

        $oldSource = new Source();
        $oldSource->setTitle('Old Source');

        $event = new SourceUpdatedEvent(newSource: $newSource, oldSource: $oldSource);

        $fakeRules = [['trigger' => 'updated', 'condition' => null, 'recipients' => [], 'subject' => ['en' => 'test']]];

        $this->registry->method('getRulesForEntityType')->willReturn($fakeRules);

        $this->dispatcher->expects($this->once())
            ->method('dispatch')
            ->with(
                'source',
                'updated',
                $this->callback(fn($d) => ($d['title'] ?? '') === 'New Source'),
                $this->callback(fn($d) => ($d['title'] ?? '') === 'Old Source'),
                $fakeRules
            );

        $this->listener->handle($event);
    }
}
