<?php
/**
 * OpenRegister Webhook Event Listener
 *
 * Listener for webhook events.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

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
use OCA\OpenRegister\Service\WebhookService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * WebhookEventListener dispatches webhooks for all OpenRegister events
 *
 * @template-implements IEventListener<Event>
 */
class WebhookEventListener implements IEventListener
{

    /**
     * Webhook service
     *
     * @var WebhookService
     */
    private WebhookService $webhookService;

    /**
     * Logger
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Constructor
     *
     * @param WebhookService  $webhookService Webhook service
     * @param LoggerInterface $logger         Logger
     */
    public function __construct(
        WebhookService $webhookService,
        LoggerInterface $logger
    ) {
        $this->webhookService = $webhookService;
        $this->logger         = $logger;

    }//end __construct()

    /**
     * Handle event
     *
     * @param Event $event The event to handle
     *
     * @return void
     */
    public function handle(Event $event): void
    {
        $eventClass = get_class($event);
        $payload    = $this->extractPayload($event);

        if ($payload === null) {
            $this->logger->debug(
                    'Could not extract payload from event',
                    [
                        'event' => $eventClass,
                    ]
                    );
            return;
        }

        $this->logger->debug(
                'Processing event for webhooks',
                [
                    'event' => $eventClass,
                ]
                );

        // Dispatch to webhook service.
        $this->webhookService->dispatchEvent(_event: $event, eventName: $eventClass, payload: $payload);

    }//end handle()

    /**
     * Extract payload from event
     *
     * @param Event $event The event
     *
     * @return (((int|mixed|null|string[])[]|mixed|null|scalar)[]|\DateTime|mixed|null|string)[]|null
     *
     * @psalm-return array{objectType: string, action: string, object?: array{'@self': array{name: mixed|null|string,...},...}, register?: array{id: int, uuid: null|string, slug: null|string, title: null|string, version: null|string, description: null|string, schemas: array<int|string>, source: null|string, tablePrefix: null|string, folder: null|string, updated: null|string, created: null|string, owner: null|string, application: null|string, organisation: null|string, authorization: array|null, groups: array<string, list<string>>, quota: array{storage: null, bandwidth: null, requests: null, users: null, groups: null}, usage: array{storage: 0, bandwidth: 0, requests: 0, users: 0, groups: int<0, max>}, deleted: null|string, published: null|string, depublished: null|string}|null|string, schema?: array{id: int, uuid: null|string, uri: null|string, slug: null|string, title: null|string, description: null|string, version: null|string, summary: null|string, icon: null|string, required: array, properties: array, archive: array|null, source: null|string, hardValidation: bool, immutable: bool, searchable: bool, updated: null|string, created: null|string, maxDepth: int, owner: null|string, application: null|string, organisation: null|string, groups: array<string, list<string>>|null, authorization: array|null, deleted: null|string, published: null|string, depublished: null|string, configuration: array|null|string, allOf: array|null, oneOf: array|null, anyOf: array|null}|null|string, newObject?: array{'@self': array{name: mixed|null|string,...},...}, oldObject?: array{'@self': array{name: mixed|null|string,...},...}, revertPoint?: \DateTime|null|string, application?: array{id: int, uuid: null|string, name: null|string, description: null|string, version: null|string, organisation: null|string, configurations: array|null, registers: array|null, schemas: array|null, owner: null|string, active: bool|null, groups: array|null, quota: array{storage: int|null, bandwidth: int|null, requests: int|null, users: null, groups: null}, usage: array{storage: 0, bandwidth: 0, requests: 0, users: 0, groups: int<0, max>}, authorization: array, created: null|string, updated: null|string, managedByConfiguration: array{id: int, uuid: null|string, title: null|string}|null}, agent?: array{id: int, uuid: null|string, name: null|string, description: null|string, type: null|string, provider: null|string, model: null|string, prompt: null|string, temperature: float|null, maxTokens: int|null, configuration: array|null, organisation: null|string, owner: null|string, active: bool, enableRag: bool, ragSearchMode: null|string, ragNumSources: int|null, ragIncludeFiles: bool, ragIncludeObjects: bool, requestQuota: int|null, tokenQuota: int|null, views: array|null, searchFiles: bool|null, searchObjects: bool|null, isPrivate: bool|null, invitedUsers: array|null, groups: array|null, tools: array|null, user: null|string, created: null|string, updated: null|string, managedByConfiguration: array{id: int, uuid: null|string, title: null|string}|null}, source?: array{id: int, uuid: null|string, title: null|string, version: null|string, description: null|string, databaseUrl: null|string, type: null|string, organisation: null|string, updated: null|string, created: null|string, managedByConfiguration: array{id: int, uuid: null|string, title: null|string}|null}, configuration?: mixed, view?: mixed, conversation?: array{id: int, uuid: null|string, title: null|string, userId: null|string, organisation: null|string, agentId: int|null, metadata: array|null, deletedAt: null|string, created: null|string, updated: null|string}, organisation?: mixed}|null
     */
    private function extractPayload(Event $event): array|null
    {
        // Object events - Before events (ing).
        if ($event instanceof ObjectCreatingEvent) {
            $object = $event->getObject();
            return [
                'objectType' => 'object',
                'action'     => 'creating',
                'object'     => $object->jsonSerialize(),
                'register'   => $object->getRegister(),
                'schema'     => $object->getSchema(),
            ];
        }

        if ($event instanceof ObjectUpdatingEvent) {
            $newObject = $event->getNewObject();
            $oldObject = $event->getOldObject();
            return [
                'objectType' => 'object',
                'action'     => 'updating',
                'newObject'  => $newObject->jsonSerialize(),
                'oldObject'  => $oldObject->jsonSerialize(),
                'register'   => $newObject->getRegister(),
                'schema'     => $newObject->getSchema(),
            ];
        }

        if ($event instanceof ObjectDeletingEvent) {
            $object = $event->getObject();
            return [
                'objectType' => 'object',
                'action'     => 'deleting',
                'object'     => $object->jsonSerialize(),
            ];
        }

        // Object events - After events (ed).
        if ($event instanceof ObjectCreatedEvent || $event instanceof ObjectUpdatedEvent) {
            if ($event instanceof ObjectCreatedEvent) {
                $object = $event->getObject();
            } else {
                $object = $event->getNewObject();
            }

            $action = 'updated';
            if ($event instanceof ObjectCreatedEvent) {
                $action = 'created';
            }

            return [
                'objectType' => 'object',
                'action'     => $action,
                'object'     => $object->jsonSerialize(),
                'register'   => $object->getRegister(),
                'schema'     => $object->getSchema(),
            ];
        }

        if ($event instanceof ObjectDeletedEvent) {
            $object = $event->getObject();
            return [
                'objectType' => 'object',
                'action'     => 'deleted',
                'object'     => $object->jsonSerialize(),
            ];
        }

        if ($event instanceof ObjectLockedEvent || $event instanceof ObjectUnlockedEvent) {
            $object = $event->getObject();
            $action = 'unlocked';
            if ($event instanceof ObjectLockedEvent) {
                $action = 'locked';
            }

            return [
                'objectType' => 'object',
                'action'     => $action,
                'object'     => $object->jsonSerialize(),
            ];
        }

        if ($event instanceof ObjectRevertedEvent) {
            return [
                'objectType'  => 'object',
                'action'      => 'reverted',
                'object'      => $event->getObject()->jsonSerialize(),
                'revertPoint' => $event->getRevertPoint(),
            ];
        }

        // Register events.
        if ($event instanceof RegisterCreatedEvent || $event instanceof RegisterUpdatedEvent || $event instanceof RegisterDeletedEvent) {
            // Get the register based on event type.
            if ($event instanceof RegisterCreatedEvent || $event instanceof RegisterDeletedEvent) {
                $register = $event->getRegister();
            } else {
                // RegisterUpdatedEvent has newRegister and oldRegister.
                $register = $event->getNewRegister();
            }

            $action = match (true) {
                $event instanceof RegisterCreatedEvent => 'created',
                $event instanceof RegisterUpdatedEvent => 'updated',
                $event instanceof RegisterDeletedEvent => 'deleted',
            };

            return [
                'objectType' => 'register',
                'action'     => $action,
                'register'   => $register->jsonSerialize(),
            ];
        }//end if

        // Schema events.
        if ($event instanceof SchemaCreatedEvent || $event instanceof SchemaUpdatedEvent || $event instanceof SchemaDeletedEvent) {
            // Get the schema based on event type.
            if ($event instanceof SchemaCreatedEvent || $event instanceof SchemaDeletedEvent) {
                $schema = $event->getSchema();
            } else {
                // SchemaUpdatedEvent has newSchema and oldSchema.
                $schema = $event->getNewSchema();
            }

            $action = match (true) {
                $event instanceof SchemaCreatedEvent => 'created',
                $event instanceof SchemaUpdatedEvent => 'updated',
                $event instanceof SchemaDeletedEvent => 'deleted',
            };

            return [
                'objectType' => 'schema',
                'action'     => $action,
                'schema'     => $schema->jsonSerialize(),
            ];
        }//end if

        // Application events.
        if ($event instanceof ApplicationCreatedEvent || $event instanceof ApplicationUpdatedEvent || $event instanceof ApplicationDeletedEvent) {
            // Get the application based on event type.
            if ($event instanceof ApplicationCreatedEvent || $event instanceof ApplicationDeletedEvent) {
                $application = $event->getApplication();
            } else {
                // ApplicationUpdatedEvent has newApplication and oldApplication.
                $application = $event->getNewApplication();
            }

            $action = match (true) {
                $event instanceof ApplicationCreatedEvent => 'created',
                $event instanceof ApplicationUpdatedEvent => 'updated',
                $event instanceof ApplicationDeletedEvent => 'deleted',
            };

            return [
                'objectType'  => 'application',
                'action'      => $action,
                'application' => $application->jsonSerialize(),
            ];
        }//end if

        // Agent events.
        if ($event instanceof AgentCreatedEvent || $event instanceof AgentUpdatedEvent || $event instanceof AgentDeletedEvent) {
            $agent  = $event->getAgent();
            $action = match (true) {
                $event instanceof AgentCreatedEvent => 'created',
                $event instanceof AgentUpdatedEvent => 'updated',
                $event instanceof AgentDeletedEvent => 'deleted',
            };

            return [
                'objectType' => 'agent',
                'action'     => $action,
                'agent'      => $agent->jsonSerialize(),
            ];
        }

        // Source events.
        if ($event instanceof SourceCreatedEvent || $event instanceof SourceUpdatedEvent || $event instanceof SourceDeletedEvent) {
            $source = $event->getSource();
            $action = match (true) {
                $event instanceof SourceCreatedEvent => 'created',
                $event instanceof SourceUpdatedEvent => 'updated',
                $event instanceof SourceDeletedEvent => 'deleted',
            };

            return [
                'objectType' => 'source',
                'action'     => $action,
                'source'     => $source->jsonSerialize(),
            ];
        }

        // Configuration events.
        if ($event instanceof ConfigurationCreatedEvent
            || $event instanceof ConfigurationUpdatedEvent
            || $event instanceof ConfigurationDeletedEvent
        ) {
            $configuration = $event->getConfiguration();
            $action        = match (true) {
                $event instanceof ConfigurationCreatedEvent => 'created',
                $event instanceof ConfigurationUpdatedEvent => 'updated',
                $event instanceof ConfigurationDeletedEvent => 'deleted',
            };

            return [
                'objectType'    => 'configuration',
                'action'        => $action,
                'configuration' => $configuration->jsonSerialize(),
            ];
        }

        // View events.
        if ($event instanceof ViewCreatedEvent || $event instanceof ViewUpdatedEvent || $event instanceof ViewDeletedEvent) {
            $view   = $event->getView();
            $action = match (true) {
                $event instanceof ViewCreatedEvent => 'created',
                $event instanceof ViewUpdatedEvent => 'updated',
                $event instanceof ViewDeletedEvent => 'deleted',
            };

            return [
                'objectType' => 'view',
                'action'     => $action,
                'view'       => $view->jsonSerialize(),
            ];
        }

        // Conversation events.
        if ($event instanceof ConversationCreatedEvent || $event instanceof ConversationUpdatedEvent || $event instanceof ConversationDeletedEvent) {
            $conversation = $event->getConversation();
            $action       = match (true) {
                $event instanceof ConversationCreatedEvent => 'created',
                $event instanceof ConversationUpdatedEvent => 'updated',
                $event instanceof ConversationDeletedEvent => 'deleted',
            };

            return [
                'objectType'   => 'conversation',
                'action'       => $action,
                'conversation' => $conversation->jsonSerialize(),
            ];
        }

        // Organisation events.
        if ($event instanceof OrganisationCreatedEvent || $event instanceof OrganisationUpdatedEvent || $event instanceof OrganisationDeletedEvent) {
            $organisation = $event->getOrganisation();
            $action       = match (true) {
                $event instanceof OrganisationCreatedEvent => 'created',
                $event instanceof OrganisationUpdatedEvent => 'updated',
                $event instanceof OrganisationDeletedEvent => 'deleted',
            };

            return [
                'objectType'   => 'organisation',
                'action'       => $action,
                'organisation' => $organisation->jsonSerialize(),
            ];
        }//end if

        return null;

    }//end extractPayload()
}//end class
