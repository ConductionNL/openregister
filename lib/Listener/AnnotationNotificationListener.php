<?php

/**
 * OpenRegister Annotation Notification Listener
 *
 * Bridges system entity events (SchemaUpdatedEvent, ConfigurationUpdatedEvent,
 * SourceUpdatedEvent, AgentUpdatedEvent, RegisterUpdatedEvent, WebhookEventListener, etc.)
 * into the AnnotationNotificationDispatcher so that OpenRegister's own operational
 * events can drive notifications through the same engine path used for stored
 * register objects — with _oldData/_newData populated so the field-change
 * condition block is available for system schemas too.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/openregister-system-notifications/tasks.md#task-3.1
 * @spec openspec/changes/openregister-system-notifications/tasks.md#task-3.2
 * @spec openspec/changes/openregister-system-notifications/tasks.md#task-3.3
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Event\AgentCreatedEvent;
use OCA\OpenRegister\Event\AgentUpdatedEvent;
use OCA\OpenRegister\Event\ConfigurationCreatedEvent;
use OCA\OpenRegister\Event\ConfigurationUpdatedEvent;
use OCA\OpenRegister\Event\RegisterCreatedEvent;
use OCA\OpenRegister\Event\RegisterUpdatedEvent;
use OCA\OpenRegister\Event\SchemaCreatedEvent;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCA\OpenRegister\Event\SourceCreatedEvent;
use OCA\OpenRegister\Event\SourceUpdatedEvent;
use OCA\OpenRegister\Service\AnnotationNotificationDispatcher;
use OCA\OpenRegister\Service\SystemSchemaNotificationRegistry;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Listener that routes system entity events through AnnotationNotificationDispatcher.
 *
 * Supported events and their entity types:
 * - RegisterCreatedEvent / RegisterUpdatedEvent → 'register'
 * - SchemaCreatedEvent / SchemaUpdatedEvent     → 'schema'
 * - ConfigurationCreatedEvent / ConfigurationUpdatedEvent → 'configuration'
 * - SourceCreatedEvent / SourceUpdatedEvent     → 'source'
 * - AgentCreatedEvent / AgentUpdatedEvent       → 'agent'
 *
 * For each event the listener:
 * 1. Determines the entity type and trigger type.
 * 2. Serialises old and new entity state into plain arrays (populating _oldData/_newData).
 * 3. Looks up the declared rules from SystemSchemaNotificationRegistry.
 * 4. Delegates to AnnotationNotificationDispatcher.
 *
 * @template-implements IEventListener<Event>
 */
class AnnotationNotificationListener implements IEventListener
{
    /**
     * Constructor.
     *
     * @param AnnotationNotificationDispatcher $dispatcher Dispatcher that evaluates and sends.
     * @param SystemSchemaNotificationRegistry $registry   Registry of system-schema rules.
     * @param LoggerInterface                  $logger     Logger.
     *
     * @spec openspec/changes/openregister-system-notifications/tasks.md#task-3.1
     */
    public function __construct(
        private readonly AnnotationNotificationDispatcher $dispatcher,
        private readonly SystemSchemaNotificationRegistry $registry,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Handle a system entity event by routing it through the notification dispatcher.
     *
     * Extracts entity type, trigger, and old/new data from the event then invokes
     * the dispatcher with the rules declared in the registry. Unknown event types
     * are silently ignored.
     *
     * @param Event $event The system entity event.
     *
     * @return void
     *
     * @spec openspec/changes/openregister-system-notifications/tasks.md#task-3.1
     * @spec openspec/changes/openregister-system-notifications/tasks.md#task-3.3
     */
    public function handle(Event $event): void
    {
        $context = $this->extractEventContext(event: $event);
        if ($context === null) {
            return;
        }

        [$entityType, $trigger, $newData, $oldData] = $context;

        $rules = $this->registry->getRulesForEntityType(entityType: $entityType);
        if (empty($rules) === true) {
            return;
        }

        $this->logger->debug(
            message: '[AnnotationNotificationListener] Dispatching system entity notification',
            context: [
                'entityType' => $entityType,
                'trigger'    => $trigger,
                'rules'      => count($rules),
            ]
        );

        $this->dispatcher->dispatch(
            entityType: $entityType,
            trigger: $trigger,
            newData: $newData,
            oldData: $oldData,
            rules: $rules
        );
    }//end handle()

    /**
     * Extract entity type, trigger, and old/new data from a system entity event.
     *
     * Returns null for unrecognised event types so the listener silently skips
     * them without error.
     *
     * @param Event $event The incoming event.
     *
     * @return array{string, string, array<string, mixed>, array<string, mixed>|null}|null
     *   [entityType, trigger, newData, oldData] or null when event is not handled.
     *
     * @spec openspec/changes/openregister-system-notifications/tasks.md#task-3.1
     * @spec openspec/changes/openregister-system-notifications/tasks.md#task-3.2
     */
    private function extractEventContext(Event $event): ?array
    {
        // Register events.
        if ($event instanceof RegisterCreatedEvent) {
            $entity = $event->getRegister();
            return ['register', 'created', $entity->jsonSerialize(), null];
        }

        if ($event instanceof RegisterUpdatedEvent) {
            $new = $event->getNewRegister();
            $old = $event->getOldRegister();
            return ['register', 'updated', $new->jsonSerialize(), $old->jsonSerialize()];
        }

        // Schema events.
        if ($event instanceof SchemaCreatedEvent) {
            $entity = $event->getSchema();
            return ['schema', 'created', $entity->jsonSerialize(), null];
        }

        if ($event instanceof SchemaUpdatedEvent) {
            $new = $event->getNewSchema();
            $old = $event->getOldSchema();
            return ['schema', 'updated', $new->jsonSerialize(), $old->jsonSerialize()];
        }

        // Configuration events.
        if ($event instanceof ConfigurationCreatedEvent) {
            $entity = $event->getConfiguration();
            return ['configuration', 'created', $entity->jsonSerialize(), null];
        }

        if ($event instanceof ConfigurationUpdatedEvent) {
            $new = $event->getNewConfiguration();
            $old = $event->getOldConfiguration();
            return ['configuration', 'updated', $new->jsonSerialize(), $old->jsonSerialize()];
        }

        // Source events.
        if ($event instanceof SourceCreatedEvent) {
            $entity = $event->getSource();
            return ['source', 'created', $entity->jsonSerialize(), null];
        }

        if ($event instanceof SourceUpdatedEvent) {
            $new = $event->getNewSource();
            $old = $event->getOldSource();
            return ['source', 'updated', $new->jsonSerialize(), $old->jsonSerialize()];
        }

        // Agent events.
        if ($event instanceof AgentCreatedEvent) {
            $entity = $event->getAgent();
            return ['agent', 'created', $entity->jsonSerialize(), null];
        }

        if ($event instanceof AgentUpdatedEvent) {
            $new = $event->getNewAgent();
            $old = $event->getOldAgent();
            return ['agent', 'updated', $new->jsonSerialize(), $old->jsonSerialize()];
        }

        return null;
    }//end extractEventContext()
}//end class
