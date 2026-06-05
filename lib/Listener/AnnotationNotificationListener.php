<?php

/**
<<<<<<< HEAD
 * OpenRegister Annotation Notification Listener
 *
 * Bridges system entity events (SchemaUpdatedEvent, ConfigurationUpdatedEvent,
 * SourceUpdatedEvent, AgentUpdatedEvent, RegisterUpdatedEvent, WebhookEventListener, etc.)
 * into the AnnotationNotificationDispatcher so that OpenRegister's own operational
 * events can drive notifications through the same engine path used for stored
 * register objects — with _oldData/_newData populated so the field-change
 * condition block is available for system schemas too.
=======
 * OpenRegister AnnotationNotificationListener
 *
 * Subscribes to ObjectCreatedEvent / ObjectUpdatedEvent /
 * ObjectTransitionedEvent and asks the dispatcher to fire any matching
 * notifications declared on the schema.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
>>>>>>> origin/development
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
<<<<<<< HEAD
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/openregister-system-notifications/tasks.md#task-3.1
 * @spec openspec/changes/openregister-system-notifications/tasks.md#task-3.2
 * @spec openspec/changes/openregister-system-notifications/tasks.md#task-3.3
=======
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
>>>>>>> origin/development
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

<<<<<<< HEAD
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
=======
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\Notification\AnnotationNotificationDispatcher;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Listener that fires schema-declared notifications on object events.
 *
 * @template-implements IEventListener<ObjectCreatedEvent|ObjectUpdatedEvent|ObjectTransitionedEvent>
>>>>>>> origin/development
 */
class AnnotationNotificationListener implements IEventListener
{
    /**
<<<<<<< HEAD
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
=======
     * Wire the notification dispatcher.
     *
     * @param AnnotationNotificationDispatcher $dispatcher Dispatcher used to fire notifications.
     *
     * @return void
     */
    public function __construct(
        private readonly AnnotationNotificationDispatcher $dispatcher
>>>>>>> origin/development
    ) {
    }//end __construct()

    /**
<<<<<<< HEAD
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
=======
     * Dispatch any matching annotation notifications for the inbound event.
     *
     * @param Event $event Inbound dispatcher event.
     *
     * @return void
     *
     * @spec exclude Dispatch-only event router that delegates verbatim to AnnotationNotificationDispatcher;
     *              the dispatcher carries the notification behaviour.
     */
    public function handle(Event $event): void
    {
        if ($event instanceof ObjectTransitionedEvent) {
            $this->dispatcher->dispatch(
                object: $event->getObject(),
                trigger: 'transition',
                context: [
                    'action' => $event->getAction(),
                    'from'   => $event->getFrom(),
                    'to'     => $event->getTo(),
                ]
            );
            return;
        }

        if ($event instanceof ObjectCreatedEvent) {
            $object = $this->extractObject(event: $event);
            if ($object !== null) {
                $this->dispatcher->dispatch(object: $object, trigger: 'created');
            }

            return;
        }

        if ($event instanceof ObjectUpdatedEvent) {
            $newObject = $event->getNewObject();
            $oldObject = $event->getOldObject();

            // Forward old/new data on the plain `updated` dispatch too (when an
            // old object is available) so rules declaring a field-change
            // `condition` can compare without re-reading versioned history.
            // Condition-less `updated` rules ignore this context.
            $updatedContext = [];
            if ($oldObject !== null) {
                $updatedContext = [
                    '_newData' => $newObject->getObject() ?? [],
                    '_oldData' => $oldObject->getObject() ?? [],
                ];
            }

            $this->dispatcher->dispatch(object: $newObject, trigger: 'updated', context: $updatedContext);

            // Also evaluate calculatedChange rules when both old and new
            // objects are available. Pass the previous and new calculated
            // field values so the dispatcher can detect boundary crossings
            // without re-reading versioned history.
            if ($oldObject !== null) {
                $this->dispatcher->dispatch(
                    object: $newObject,
                    trigger: 'calculatedChange',
                    context: [
                        '_newData' => $newObject->getObject() ?? [],
                        '_oldData' => $oldObject->getObject() ?? [],
                    ]
                );
            }//end if
        }//end if
    }//end handle()

    /**
     * Different Object*Event classes expose the entity under different
     * accessors. Normalise to one.
     *
     * @param Event $event Inbound dispatcher event.
     *
     * @return \OCA\OpenRegister\Db\ObjectEntity|null Object instance, or null when none could be derived.
     */
    private function extractObject(Event $event): ?\OCA\OpenRegister\Db\ObjectEntity
    {
        if (method_exists($event, 'getObject') === true) {
            $obj = $event->getObject();
            if ($obj instanceof \OCA\OpenRegister\Db\ObjectEntity) {
                return $obj;
            }
        }

        if (method_exists($event, 'getNewObject') === true) {
            $obj = $event->getNewObject();
            if ($obj instanceof \OCA\OpenRegister\Db\ObjectEntity) {
                return $obj;
            }
        }

        return null;
    }//end extractObject()
>>>>>>> origin/development
}//end class
