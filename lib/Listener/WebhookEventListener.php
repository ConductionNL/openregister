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
     * @return array<string, mixed>|null The event payload or null if not extractable
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
        if ($event instanceof ObjectCreatedEvent) {
            $object = $event->getObject();
            return [
                'objectType' => 'object',
                'action'     => 'created',
                'object'     => $object->jsonSerialize(),
                'register'   => $object->getRegister(),
                'schema'     => $object->getSchema(),
            ];
        }

        if ($event instanceof ObjectUpdatedEvent) {
            $object = $event->getNewObject();
            return [
                'objectType' => 'object',
                'action'     => 'updated',
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
        if ($event instanceof RegisterCreatedEvent) {
            return [
                'objectType' => 'register',
                'action'     => 'created',
                'register'   => $event->getRegister()->jsonSerialize(),
            ];
        }

        if ($event instanceof RegisterUpdatedEvent) {
            return [
                'objectType' => 'register',
                'action'     => 'updated',
                'register'   => $event->getNewRegister()->jsonSerialize(),
            ];
        }

        if ($event instanceof RegisterDeletedEvent) {
            return [
                'objectType' => 'register',
                'action'     => 'deleted',
                'register'   => $event->getRegister()->jsonSerialize(),
            ];
        }

        // Schema events.
        if ($event instanceof SchemaCreatedEvent) {
            return [
                'objectType' => 'schema',
                'action'     => 'created',
                'schema'     => $event->getSchema()->jsonSerialize(),
            ];
        }

        if ($event instanceof SchemaUpdatedEvent) {
            return [
                'objectType' => 'schema',
                'action'     => 'updated',
                'schema'     => $event->getNewSchema()->jsonSerialize(),
            ];
        }

        if ($event instanceof SchemaDeletedEvent) {
            return [
                'objectType' => 'schema',
                'action'     => 'deleted',
                'schema'     => $event->getSchema()->jsonSerialize(),
            ];
        }

        // Application events.
        if ($event instanceof ApplicationCreatedEvent) {
            return [
                'objectType'  => 'application',
                'action'      => 'created',
                'application' => $event->getApplication()->jsonSerialize(),
            ];
        }

        if ($event instanceof ApplicationUpdatedEvent) {
            return [
                'objectType'  => 'application',
                'action'      => 'updated',
                'application' => $event->getNewApplication()->jsonSerialize(),
            ];
        }

        if ($event instanceof ApplicationDeletedEvent) {
            return [
                'objectType'  => 'application',
                'action'      => 'deleted',
                'application' => $event->getApplication()->jsonSerialize(),
            ];
        }

        // Agent events.
        if ($event instanceof AgentCreatedEvent) {
            return [
                'objectType' => 'agent',
                'action'     => 'created',
                'agent'      => $event->getAgent()->jsonSerialize(),
            ];
        }

        if ($event instanceof AgentUpdatedEvent) {
            return [
                'objectType' => 'agent',
                'action'     => 'updated',
                'agent'      => $event->getAgent()->jsonSerialize(),
            ];
        }

        if ($event instanceof AgentDeletedEvent) {
            return [
                'objectType' => 'agent',
                'action'     => 'deleted',
                'agent'      => $event->getAgent()->jsonSerialize(),
            ];
        }

        // Source events.
        if ($event instanceof SourceCreatedEvent) {
            return [
                'objectType' => 'source',
                'action'     => 'created',
                'source'     => $event->getSource()->jsonSerialize(),
            ];
        }

        if ($event instanceof SourceUpdatedEvent) {
            return [
                'objectType' => 'source',
                'action'     => 'updated',
                'source'     => $event->getSource()->jsonSerialize(),
            ];
        }

        if ($event instanceof SourceDeletedEvent) {
            return [
                'objectType' => 'source',
                'action'     => 'deleted',
                'source'     => $event->getSource()->jsonSerialize(),
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
        if ($event instanceof ConversationCreatedEvent) {
            return [
                'objectType'   => 'conversation',
                'action'       => 'created',
                'conversation' => $event->getConversation()->jsonSerialize(),
            ];
        }

        if ($event instanceof ConversationUpdatedEvent) {
            return [
                'objectType'   => 'conversation',
                'action'       => 'updated',
                'conversation' => $event->getConversation()->jsonSerialize(),
            ];
        }

        if ($event instanceof ConversationDeletedEvent) {
            return [
                'objectType'   => 'conversation',
                'action'       => 'deleted',
                'conversation' => $event->getConversation()->jsonSerialize(),
            ];
        }

        // Organisation events.
        if ($event instanceof OrganisationCreatedEvent) {
            return [
                'objectType'   => 'organisation',
                'action'       => 'created',
                'organisation' => $event->getOrganisation()->jsonSerialize(),
            ];
        }

        if ($event instanceof OrganisationUpdatedEvent) {
            return [
                'objectType'   => 'organisation',
                'action'       => 'updated',
                'organisation' => $event->getOrganisation()->jsonSerialize(),
            ];
        }

        if ($event instanceof OrganisationDeletedEvent) {
            return [
                'objectType'   => 'organisation',
                'action'       => 'deleted',
                'organisation' => $event->getOrganisation()->jsonSerialize(),
            ];
        }

        return null;
    }//end extractPayload()
}//end class
