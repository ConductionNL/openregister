<?php

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenRegister\Db\Agent;
use OCA\OpenRegister\Db\Application;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\Conversation;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Db\View;
use OCA\OpenRegister\Event\AgentCreatedEvent;
use OCA\OpenRegister\Event\AgentDeletedEvent;
use OCA\OpenRegister\Event\AgentUpdatedEvent;
use OCA\OpenRegister\Event\ApplicationCreatedEvent;
use OCA\OpenRegister\Event\ApplicationDeletedEvent;
use OCA\OpenRegister\Event\ApplicationUpdatedEvent;
use OCA\OpenRegister\Event\ConfigurationCreatedEvent;
use OCA\OpenRegister\Event\ConfigurationDeletedEvent;
use OCA\OpenRegister\Event\ConfigurationUpdatedEvent;
use OCA\OpenRegister\Event\ConversationCreatedEvent;
use OCA\OpenRegister\Event\ConversationDeletedEvent;
use OCA\OpenRegister\Event\ConversationUpdatedEvent;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Event\ObjectLockedEvent;
use OCA\OpenRegister\Event\ObjectRevertedEvent;
use OCA\OpenRegister\Event\ObjectUnlockedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Event\OrganisationCreatedEvent;
use OCA\OpenRegister\Event\OrganisationDeletedEvent;
use OCA\OpenRegister\Event\OrganisationUpdatedEvent;
use OCA\OpenRegister\Event\RegisterCreatedEvent;
use OCA\OpenRegister\Event\RegisterDeletedEvent;
use OCA\OpenRegister\Event\RegisterUpdatedEvent;
use OCA\OpenRegister\Event\SchemaCreatedEvent;
use OCA\OpenRegister\Event\SchemaDeletedEvent;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCA\OpenRegister\Event\SourceCreatedEvent;
use OCA\OpenRegister\Event\SourceDeletedEvent;
use OCA\OpenRegister\Event\SourceUpdatedEvent;
use OCA\OpenRegister\Event\ViewCreatedEvent;
use OCA\OpenRegister\Event\ViewDeletedEvent;
use OCA\OpenRegister\Event\ViewUpdatedEvent;
use OCA\OpenRegister\Listener\WebhookEventListener;
use OCA\OpenRegister\Service\WebhookService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class WebhookEventListenerTest extends TestCase
{
    private WebhookEventListener $listener;
    private WebhookService&MockObject $webhookService;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->webhookService = $this->createMock(WebhookService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->listener = new WebhookEventListener(
            $this->webhookService,
            $this->logger,
        );
    }

    // --- Unknown event returns null payload, logs warning, no dispatch ---

    public function testUnknownEventLogsWarningAndReturns(): void
    {
        $event = $this->createMock(Event::class);

        $this->logger->expects($this->atLeastOnce())->method('warning');
        $this->webhookService->expects($this->never())->method('dispatchEvent');

        $this->listener->handle($event);
    }

    // --- ObjectCreatingEvent ---

    public function testObjectCreatingEventDispatchesWebhook(): void
    {
        $object = new ObjectEntity();
        $object->setRegister('reg-1');
        $object->setSchema('schema-1');
        $event = new ObjectCreatingEvent($object);

        $this->webhookService->expects($this->once())
            ->method('dispatchEvent')
            ->with(
                $this->identicalTo($event),
                ObjectCreatingEvent::class,
                $this->callback(function (array $payload) {
                    return $payload['objectType'] === 'object'
                        && $payload['action'] === 'creating'
                        && $payload['register'] === 'reg-1'
                        && $payload['schema'] === 'schema-1'
                        && isset($payload['object']);
                })
            );

        $this->listener->handle($event);
    }

    // --- ObjectUpdatingEvent ---

    public function testObjectUpdatingEventDispatchesWebhookWithOldObject(): void
    {
        $newObject = new ObjectEntity();
        $newObject->setRegister('reg-1');
        $newObject->setSchema('schema-1');

        $oldObject = new ObjectEntity();
        $oldObject->setRegister('reg-1');
        $oldObject->setSchema('schema-1');

        $event = new ObjectUpdatingEvent($newObject, $oldObject);

        $this->webhookService->expects($this->once())
            ->method('dispatchEvent')
            ->with(
                $this->identicalTo($event),
                ObjectUpdatingEvent::class,
                $this->callback(function (array $payload) {
                    return $payload['objectType'] === 'object'
                        && $payload['action'] === 'updating'
                        && isset($payload['newObject'])
                        && isset($payload['oldObject'])
                        && $payload['register'] === 'reg-1'
                        && $payload['schema'] === 'schema-1';
                })
            );

        $this->listener->handle($event);
    }

    public function testObjectUpdatingEventDispatchesWebhookWithNullOldObject(): void
    {
        $newObject = new ObjectEntity();
        $newObject->setRegister('reg-2');
        $newObject->setSchema('schema-2');

        $event = new ObjectUpdatingEvent($newObject, null);

        $this->webhookService->expects($this->once())
            ->method('dispatchEvent')
            ->with(
                $this->identicalTo($event),
                ObjectUpdatingEvent::class,
                $this->callback(function (array $payload) {
                    return $payload['action'] === 'updating'
                        && $payload['oldObject'] === null;
                })
            );

        $this->listener->handle($event);
    }

    // --- ObjectDeletingEvent ---

    public function testObjectDeletingEventDispatchesWebhook(): void
    {
        $object = new ObjectEntity();
        $event = new ObjectDeletingEvent($object);

        $this->webhookService->expects($this->once())
            ->method('dispatchEvent')
            ->with(
                $this->identicalTo($event),
                ObjectDeletingEvent::class,
                $this->callback(function (array $payload) {
                    return $payload['objectType'] === 'object'
                        && $payload['action'] === 'deleting'
                        && isset($payload['object']);
                })
            );

        $this->listener->handle($event);
    }

    // --- ObjectCreatedEvent ---

    public function testObjectCreatedEventDispatchesWebhook(): void
    {
        $object = new ObjectEntity();
        $object->setUuid('uuid-123');
        $object->setRegister('reg-1');
        $object->setSchema('schema-1');
        $event = new ObjectCreatedEvent($object);

        $this->webhookService->expects($this->once())
            ->method('dispatchEvent')
            ->with(
                $this->identicalTo($event),
                ObjectCreatedEvent::class,
                $this->callback(function (array $payload) {
                    return $payload['objectType'] === 'object'
                        && $payload['action'] === 'create'
                        && $payload['objectUuid'] === 'uuid-123'
                        && $payload['register'] === 'reg-1'
                        && $payload['schema'] === 'schema-1'
                        && isset($payload['timestamp'])
                        && isset($payload['object']);
                })
            );

        $this->listener->handle($event);
    }

    // --- ObjectUpdatedEvent ---

    public function testObjectUpdatedEventDispatchesWebhook(): void
    {
        $object = new ObjectEntity();
        $object->setUuid('uuid-456');
        $object->setRegister('reg-2');
        $object->setSchema('schema-2');
        $event = new ObjectUpdatedEvent($object);

        $this->webhookService->expects($this->once())
            ->method('dispatchEvent')
            ->with(
                $this->identicalTo($event),
                ObjectUpdatedEvent::class,
                $this->callback(function (array $payload) {
                    return $payload['objectType'] === 'object'
                        && $payload['action'] === 'update'
                        && $payload['objectUuid'] === 'uuid-456'
                        && $payload['register'] === 'reg-2'
                        && $payload['schema'] === 'schema-2'
                        && isset($payload['timestamp']);
                })
            );

        $this->listener->handle($event);
    }

    // --- ObjectDeletedEvent ---

    public function testObjectDeletedEventDispatchesWebhook(): void
    {
        $object = new ObjectEntity();
        $object->setUuid('uuid-789');
        $object->setRegister('reg-3');
        $object->setSchema('schema-3');
        $event = new ObjectDeletedEvent($object);

        $this->webhookService->expects($this->once())
            ->method('dispatchEvent')
            ->with(
                $this->identicalTo($event),
                ObjectDeletedEvent::class,
                $this->callback(function (array $payload) {
                    return $payload['objectType'] === 'object'
                        && $payload['action'] === 'delete'
                        && $payload['objectUuid'] === 'uuid-789'
                        && $payload['register'] === 'reg-3'
                        && $payload['schema'] === 'schema-3'
                        && isset($payload['timestamp']);
                })
            );

        $this->listener->handle($event);
    }

    // --- ObjectLockedEvent ---

    public function testObjectLockedEventDispatchesWebhook(): void
    {
        $object = new ObjectEntity();
        $event = new ObjectLockedEvent($object);

        $this->webhookService->expects($this->once())
            ->method('dispatchEvent')
            ->with(
                $this->identicalTo($event),
                ObjectLockedEvent::class,
                $this->callback(function (array $payload) {
                    return $payload['objectType'] === 'object'
                        && $payload['action'] === 'locked'
                        && isset($payload['object']);
                })
            );

        $this->listener->handle($event);
    }

    // --- ObjectUnlockedEvent ---

    public function testObjectUnlockedEventDispatchesWebhook(): void
    {
        $object = new ObjectEntity();
        $event = new ObjectUnlockedEvent($object);

        $this->webhookService->expects($this->once())
            ->method('dispatchEvent')
            ->with(
                $this->identicalTo($event),
                ObjectUnlockedEvent::class,
                $this->callback(function (array $payload) {
                    return $payload['objectType'] === 'object'
                        && $payload['action'] === 'unlocked'
                        && isset($payload['object']);
                })
            );

        $this->listener->handle($event);
    }

    // --- ObjectRevertedEvent ---

    public function testObjectRevertedEventDispatchesWebhook(): void
    {
        $object = new ObjectEntity();
        $event = new ObjectRevertedEvent($object);

        $this->webhookService->expects($this->once())
            ->method('dispatchEvent')
            ->with(
                $this->identicalTo($event),
                ObjectRevertedEvent::class,
                $this->callback(function (array $payload) {
                    return $payload['objectType'] === 'object'
                        && $payload['action'] === 'reverted'
                        && isset($payload['object'])
                        && array_key_exists('revertPoint', $payload);
                })
            );

        $this->listener->handle($event);
    }

    public function testObjectRevertedEventIncludesRevertPoint(): void
    {
        $object = new ObjectEntity();
        $event = new ObjectRevertedEvent($object, 'audit-id-42');

        $this->webhookService->expects($this->once())
            ->method('dispatchEvent')
            ->with(
                $this->identicalTo($event),
                ObjectRevertedEvent::class,
                $this->callback(function (array $payload) {
                    return $payload['revertPoint'] === 'audit-id-42';
                })
            );

        $this->listener->handle($event);
    }

    // --- RegisterCreatedEvent ---

    public function testRegisterCreatedEventDispatchesWebhook(): void
    {
        $register = new Register();
        $event = new RegisterCreatedEvent($register);

        $this->webhookService->expects($this->once())
            ->method('dispatchEvent')
            ->with(
                $this->identicalTo($event),
                RegisterCreatedEvent::class,
                $this->callback(function (array $payload) {
                    return $payload['objectType'] === 'register'
                        && $payload['action'] === 'created'
                        && isset($payload['register']);
                })
            );

        $this->listener->handle($event);
    }

    // --- RegisterUpdatedEvent ---

    public function testRegisterUpdatedEventDispatchesWebhook(): void
    {
        $newRegister = new Register();
        $oldRegister = new Register();
        $event = new RegisterUpdatedEvent($newRegister, $oldRegister);

        $this->webhookService->expects($this->once())
            ->method('dispatchEvent')
            ->with(
                $this->identicalTo($event),
                RegisterUpdatedEvent::class,
                $this->callback(function (array $payload) {
                    return $payload['objectType'] === 'register'
                        && $payload['action'] === 'updated'
                        && isset($payload['register']);
                })
            );

        $this->listener->handle($event);
    }

    // --- RegisterDeletedEvent ---

    public function testRegisterDeletedEventDispatchesWebhook(): void
    {
        $register = new Register();
        $event = new RegisterDeletedEvent($register);

        $this->webhookService->expects($this->once())
            ->method('dispatchEvent')
            ->with(
                $this->identicalTo($event),
                RegisterDeletedEvent::class,
                $this->callback(function (array $payload) {
                    return $payload['objectType'] === 'register'
                        && $payload['action'] === 'deleted'
                        && isset($payload['register']);
                })
            );

        $this->listener->handle($event);
    }

    // --- SchemaCreatedEvent ---

    public function testSchemaCreatedEventDispatchesWebhook(): void
    {
        $schema = new Schema();
        $event = new SchemaCreatedEvent($schema);

        $this->webhookService->expects($this->once())
            ->method('dispatchEvent')
            ->with(
                $this->identicalTo($event),
                SchemaCreatedEvent::class,
                $this->callback(function (array $payload) {
                    return $payload['objectType'] === 'schema'
                        && $payload['action'] === 'created'
                        && isset($payload['schema']);
                })
            );

        $this->listener->handle($event);
    }

    // --- SchemaUpdatedEvent ---

    public function testSchemaUpdatedEventDispatchesWebhook(): void
    {
        $newSchema = new Schema();
        $oldSchema = new Schema();
        $event = new SchemaUpdatedEvent($newSchema, $oldSchema);

        $this->webhookService->expects($this->once())
            ->method('dispatchEvent')
            ->with(
                $this->identicalTo($event),
                SchemaUpdatedEvent::class,
                $this->callback(function (array $payload) {
                    return $payload['objectType'] === 'schema'
                        && $payload['action'] === 'updated'
                        && isset($payload['schema']);
                })
            );

        $this->listener->handle($event);
    }

    // --- SchemaDeletedEvent ---

    public function testSchemaDeletedEventDispatchesWebhook(): void
    {
        $schema = new Schema();
        $event = new SchemaDeletedEvent($schema);

        $this->webhookService->expects($this->once())
            ->method('dispatchEvent')
            ->with(
                $this->identicalTo($event),
                SchemaDeletedEvent::class,
                $this->callback(function (array $payload) {
                    return $payload['objectType'] === 'schema'
                        && $payload['action'] === 'deleted'
                        && isset($payload['schema']);
                })
            );

        $this->listener->handle($event);
    }

    // --- ApplicationCreatedEvent ---

    public function testApplicationCreatedEventDispatchesWebhook(): void
    {
        $application = new Application();
        $event = new ApplicationCreatedEvent($application);

        $this->webhookService->expects($this->once())
            ->method('dispatchEvent')
            ->with(
                $this->identicalTo($event),
                ApplicationCreatedEvent::class,
                $this->callback(function (array $payload) {
                    return $payload['objectType'] === 'application'
                        && $payload['action'] === 'created'
                        && isset($payload['application']);
                })
            );

        $this->listener->handle($event);
    }

    // --- ApplicationUpdatedEvent ---

    public function testApplicationUpdatedEventDispatchesWebhook(): void
    {
        $newApp = new Application();
        $oldApp = new Application();
        $event = new ApplicationUpdatedEvent($newApp, $oldApp);

        $this->webhookService->expects($this->once())
            ->method('dispatchEvent')
            ->with(
                $this->identicalTo($event),
                ApplicationUpdatedEvent::class,
                $this->callback(function (array $payload) {
                    return $payload['objectType'] === 'application'
                        && $payload['action'] === 'updated'
                        && isset($payload['application']);
                })
            );

        $this->listener->handle($event);
    }

    // --- ApplicationDeletedEvent ---

    public function testApplicationDeletedEventDispatchesWebhook(): void
    {
        $application = new Application();
        $event = new ApplicationDeletedEvent($application);

        $this->webhookService->expects($this->once())
            ->method('dispatchEvent')
            ->with(
                $this->identicalTo($event),
                ApplicationDeletedEvent::class,
                $this->callback(function (array $payload) {
                    return $payload['objectType'] === 'application'
                        && $payload['action'] === 'deleted'
                        && isset($payload['application']);
                })
            );

        $this->listener->handle($event);
    }

    // --- AgentCreatedEvent ---

    public function testAgentCreatedEventDispatchesWebhook(): void
    {
        $agent = new Agent();
        $event = new AgentCreatedEvent($agent);

        $this->webhookService->expects($this->once())
            ->method('dispatchEvent')
            ->with(
                $this->identicalTo($event),
                AgentCreatedEvent::class,
                $this->callback(function (array $payload) {
                    return $payload['objectType'] === 'agent'
                        && $payload['action'] === 'created'
                        && isset($payload['agent']);
                })
            );

        $this->listener->handle($event);
    }

    // --- AgentUpdatedEvent ---

    public function testAgentUpdatedEventDispatchesWebhook(): void
    {
        $newAgent = new Agent();
        $oldAgent = new Agent();
        $event = new AgentUpdatedEvent($newAgent, $oldAgent);

        $this->webhookService->expects($this->once())
            ->method('dispatchEvent')
            ->with(
                $this->identicalTo($event),
                AgentUpdatedEvent::class,
                $this->callback(function (array $payload) {
                    return $payload['objectType'] === 'agent'
                        && $payload['action'] === 'updated'
                        && isset($payload['agent']);
                })
            );

        $this->listener->handle($event);
    }

    // --- AgentDeletedEvent ---

    public function testAgentDeletedEventDispatchesWebhook(): void
    {
        $agent = new Agent();
        $event = new AgentDeletedEvent($agent);

        $this->webhookService->expects($this->once())
            ->method('dispatchEvent')
            ->with(
                $this->identicalTo($event),
                AgentDeletedEvent::class,
                $this->callback(function (array $payload) {
                    return $payload['objectType'] === 'agent'
                        && $payload['action'] === 'deleted'
                        && isset($payload['agent']);
                })
            );

        $this->listener->handle($event);
    }

    // --- SourceCreatedEvent ---

    public function testSourceCreatedEventDispatchesWebhook(): void
    {
        $source = new Source();
        $event = new SourceCreatedEvent($source);

        $this->webhookService->expects($this->once())
            ->method('dispatchEvent')
            ->with(
                $this->identicalTo($event),
                SourceCreatedEvent::class,
                $this->callback(function (array $payload) {
                    return $payload['objectType'] === 'source'
                        && $payload['action'] === 'created'
                        && isset($payload['source']);
                })
            );

        $this->listener->handle($event);
    }

    // --- SourceUpdatedEvent ---

    public function testSourceUpdatedEventDispatchesWebhook(): void
    {
        $newSource = new Source();
        $oldSource = new Source();
        $event = new SourceUpdatedEvent($newSource, $oldSource);

        $this->webhookService->expects($this->once())
            ->method('dispatchEvent')
            ->with(
                $this->identicalTo($event),
                SourceUpdatedEvent::class,
                $this->callback(function (array $payload) {
                    return $payload['objectType'] === 'source'
                        && $payload['action'] === 'updated'
                        && isset($payload['source']);
                })
            );

        $this->listener->handle($event);
    }

    // --- SourceDeletedEvent ---

    public function testSourceDeletedEventDispatchesWebhook(): void
    {
        $source = new Source();
        $event = new SourceDeletedEvent($source);

        $this->webhookService->expects($this->once())
            ->method('dispatchEvent')
            ->with(
                $this->identicalTo($event),
                SourceDeletedEvent::class,
                $this->callback(function (array $payload) {
                    return $payload['objectType'] === 'source'
                        && $payload['action'] === 'deleted'
                        && isset($payload['source']);
                })
            );

        $this->listener->handle($event);
    }

    // --- ConfigurationCreatedEvent ---

    public function testConfigurationCreatedEventDispatchesWebhook(): void
    {
        $configuration = new Configuration();
        $event = new ConfigurationCreatedEvent($configuration);

        $this->webhookService->expects($this->once())
            ->method('dispatchEvent')
            ->with(
                $this->identicalTo($event),
                ConfigurationCreatedEvent::class,
                $this->callback(function (array $payload) {
                    return $payload['objectType'] === 'configuration'
                        && $payload['action'] === 'created'
                        && isset($payload['configuration']);
                })
            );

        $this->listener->handle($event);
    }

    // --- ConfigurationUpdatedEvent ---
    // Note: ConfigurationUpdatedEvent lacks a getConfiguration() method,
    // so we must mock the event to add it.

    public function testConfigurationUpdatedEventDispatchesWebhook(): void
    {
        $configuration = new Configuration();

        $event = $this->getMockBuilder(ConfigurationUpdatedEvent::class)
            ->disableOriginalConstructor()
            ->addMethods(['getConfiguration'])
            ->getMock();
        $event->method('getConfiguration')->willReturn($configuration);

        $this->webhookService->expects($this->once())
            ->method('dispatchEvent')
            ->with(
                $this->identicalTo($event),
                $this->callback(function (string $eventName) {
                    // The mock class name contains a suffix, so just check it contains the right part.
                    return str_contains($eventName, 'ConfigurationUpdatedEvent');
                }),
                $this->callback(function (array $payload) {
                    return $payload['objectType'] === 'configuration'
                        && $payload['action'] === 'updated'
                        && isset($payload['configuration']);
                })
            );

        $this->listener->handle($event);
    }

    // --- ConfigurationDeletedEvent ---

    public function testConfigurationDeletedEventDispatchesWebhook(): void
    {
        $configuration = new Configuration();
        $event = new ConfigurationDeletedEvent($configuration);

        $this->webhookService->expects($this->once())
            ->method('dispatchEvent')
            ->with(
                $this->identicalTo($event),
                ConfigurationDeletedEvent::class,
                $this->callback(function (array $payload) {
                    return $payload['objectType'] === 'configuration'
                        && $payload['action'] === 'deleted'
                        && isset($payload['configuration']);
                })
            );

        $this->listener->handle($event);
    }

    // --- ViewCreatedEvent ---

    public function testViewCreatedEventDispatchesWebhook(): void
    {
        $view = new View();
        $event = new ViewCreatedEvent($view);

        $this->webhookService->expects($this->once())
            ->method('dispatchEvent')
            ->with(
                $this->identicalTo($event),
                ViewCreatedEvent::class,
                $this->callback(function (array $payload) {
                    return $payload['objectType'] === 'view'
                        && $payload['action'] === 'created'
                        && isset($payload['view']);
                })
            );

        $this->listener->handle($event);
    }

    // --- ViewUpdatedEvent ---
    // Note: ViewUpdatedEvent lacks a getView() method,
    // so we must mock the event to add it.

    public function testViewUpdatedEventDispatchesWebhook(): void
    {
        $view = new View();

        $event = $this->getMockBuilder(ViewUpdatedEvent::class)
            ->disableOriginalConstructor()
            ->addMethods(['getView'])
            ->getMock();
        $event->method('getView')->willReturn($view);

        $this->webhookService->expects($this->once())
            ->method('dispatchEvent')
            ->with(
                $this->identicalTo($event),
                $this->callback(function (string $eventName) {
                    return str_contains($eventName, 'ViewUpdatedEvent');
                }),
                $this->callback(function (array $payload) {
                    return $payload['objectType'] === 'view'
                        && $payload['action'] === 'updated'
                        && isset($payload['view']);
                })
            );

        $this->listener->handle($event);
    }

    // --- ViewDeletedEvent ---

    public function testViewDeletedEventDispatchesWebhook(): void
    {
        $view = new View();
        $event = new ViewDeletedEvent($view);

        $this->webhookService->expects($this->once())
            ->method('dispatchEvent')
            ->with(
                $this->identicalTo($event),
                ViewDeletedEvent::class,
                $this->callback(function (array $payload) {
                    return $payload['objectType'] === 'view'
                        && $payload['action'] === 'deleted'
                        && isset($payload['view']);
                })
            );

        $this->listener->handle($event);
    }

    // --- ConversationCreatedEvent ---

    public function testConversationCreatedEventDispatchesWebhook(): void
    {
        $conversation = new Conversation();
        $event = new ConversationCreatedEvent($conversation);

        $this->webhookService->expects($this->once())
            ->method('dispatchEvent')
            ->with(
                $this->identicalTo($event),
                ConversationCreatedEvent::class,
                $this->callback(function (array $payload) {
                    return $payload['objectType'] === 'conversation'
                        && $payload['action'] === 'created'
                        && isset($payload['conversation']);
                })
            );

        $this->listener->handle($event);
    }

    // --- ConversationUpdatedEvent ---

    public function testConversationUpdatedEventDispatchesWebhook(): void
    {
        $newConversation = new Conversation();
        $oldConversation = new Conversation();
        $event = new ConversationUpdatedEvent($newConversation, $oldConversation);

        $this->webhookService->expects($this->once())
            ->method('dispatchEvent')
            ->with(
                $this->identicalTo($event),
                ConversationUpdatedEvent::class,
                $this->callback(function (array $payload) {
                    return $payload['objectType'] === 'conversation'
                        && $payload['action'] === 'updated'
                        && isset($payload['conversation']);
                })
            );

        $this->listener->handle($event);
    }

    // --- ConversationDeletedEvent ---

    public function testConversationDeletedEventDispatchesWebhook(): void
    {
        $conversation = new Conversation();
        $event = new ConversationDeletedEvent($conversation);

        $this->webhookService->expects($this->once())
            ->method('dispatchEvent')
            ->with(
                $this->identicalTo($event),
                ConversationDeletedEvent::class,
                $this->callback(function (array $payload) {
                    return $payload['objectType'] === 'conversation'
                        && $payload['action'] === 'deleted'
                        && isset($payload['conversation']);
                })
            );

        $this->listener->handle($event);
    }

    // --- OrganisationCreatedEvent ---

    public function testOrganisationCreatedEventDispatchesWebhook(): void
    {
        $organisation = new Organisation();
        $event = new OrganisationCreatedEvent($organisation);

        $this->webhookService->expects($this->once())
            ->method('dispatchEvent')
            ->with(
                $this->identicalTo($event),
                OrganisationCreatedEvent::class,
                $this->callback(function (array $payload) {
                    return $payload['objectType'] === 'organisation'
                        && $payload['action'] === 'created'
                        && isset($payload['organisation']);
                })
            );

        $this->listener->handle($event);
    }

    // --- OrganisationUpdatedEvent ---

    public function testOrganisationUpdatedEventDispatchesWebhook(): void
    {
        $newOrganisation = new Organisation();
        $oldOrganisation = new Organisation();
        $event = new OrganisationUpdatedEvent($newOrganisation, $oldOrganisation);

        $this->webhookService->expects($this->once())
            ->method('dispatchEvent')
            ->with(
                $this->identicalTo($event),
                OrganisationUpdatedEvent::class,
                $this->callback(function (array $payload) {
                    return $payload['objectType'] === 'organisation'
                        && $payload['action'] === 'updated'
                        && isset($payload['organisation']);
                })
            );

        $this->listener->handle($event);
    }

    // --- OrganisationDeletedEvent ---

    public function testOrganisationDeletedEventDispatchesWebhook(): void
    {
        $organisation = new Organisation();
        $event = new OrganisationDeletedEvent($organisation);

        $this->webhookService->expects($this->once())
            ->method('dispatchEvent')
            ->with(
                $this->identicalTo($event),
                OrganisationDeletedEvent::class,
                $this->callback(function (array $payload) {
                    return $payload['objectType'] === 'organisation'
                        && $payload['action'] === 'deleted'
                        && isset($payload['organisation']);
                })
            );

        $this->listener->handle($event);
    }

    // --- Verify debug logging on successful dispatch ---

    public function testSuccessfulEventLogsDebug(): void
    {
        $object = new ObjectEntity();
        $event = new ObjectCreatedEvent($object);

        $this->logger->expects($this->once())
            ->method('debug')
            ->with(
                $this->stringContains('[WebhookEventListener] Processing event for webhooks'),
                $this->callback(function (array $context) {
                    return isset($context['event'])
                        && $context['event'] === ObjectCreatedEvent::class;
                })
            );

        $this->listener->handle($event);
    }

    // --- Verify warning log contains event class ---

    public function testUnknownEventWarningContainsEventClass(): void
    {
        $event = $this->createMock(Event::class);

        $this->logger->expects($this->atLeastOnce())
            ->method('warning')
            ->with(
                $this->stringContains('[WebhookEventListener] Could not extract payload'),
                $this->callback(function (array $context) {
                    return isset($context['event']) && isset($context['file']) && isset($context['line']);
                })
            );

        $this->listener->handle($event);
    }
}
