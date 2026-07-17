<?php

/**
 * OpenRegister SystemEntityNotificationListener
 *
 * Bridges create/update events from OpenRegister's own system entities
 * (Register, Schema, Configuration, Source, Agent, Webhook) into the
 * AnnotationNotificationDispatcher so their declared
 * x-openregister-notifications rules fire through the same listener/dispatcher
 * path used for stored register objects.
 *
 * Populates _oldData / _newData on updated-event dispatches so the
 * field-change `condition` block is available for system schemas too.
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
 * @spec openspec/changes/openregister-system-notifications/tasks.md#task-3
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
use OCA\OpenRegister\Service\Notification\AnnotationNotificationDispatcher;
use OCA\OpenRegister\Service\Notification\SystemEntityObjectAdapter;
use OCA\OpenRegister\Service\Notification\SystemSchemaRules;
use OCP\AppFramework\Db\Entity;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Routes system-entity lifecycle events through the annotation notification pipeline.
 *
 * @template-implements IEventListener<AgentCreatedEvent|AgentUpdatedEvent|ConfigurationCreatedEvent|ConfigurationUpdatedEvent|RegisterCreatedEvent|RegisterUpdatedEvent|SchemaCreatedEvent|SchemaUpdatedEvent|SourceCreatedEvent|SourceUpdatedEvent>
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Listener maps ten distinct event classes to two
 * dispatcher calls; each event class import is required by the switch-by-instanceof dispatch below
 * and cannot be reduced without losing event coverage.
 *
 * @spec openspec/changes/openregister-system-notifications/tasks.md#task-3
 */
class SystemEntityNotificationListener implements IEventListener
{
    /**
     * Wire the dispatcher and the system-schema rule registry.
     *
     * @param AnnotationNotificationDispatcher $dispatcher Dispatcher used to fire notifications.
     * @param SystemSchemaRules                $rules      Registry of declared system-schema rules.
     *
     * @return void
     */
    public function __construct(
        private readonly AnnotationNotificationDispatcher $dispatcher,
        private readonly SystemSchemaRules $rules
    ) {
    }//end __construct()

    /**
     * Bridge the inbound system-entity event to the notification dispatcher.
     *
     * Resolves the entity + slug from the concrete event type, wraps the entity as a
     * virtual ObjectEntity, loads the synthetic schema from the rule registry, and
     * delegates to AnnotationNotificationDispatcher::dispatchWithSchema() — the same
     * path used for stored register objects.
     *
     * @param Event $event Inbound event.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) handle() dispatches ten distinct event types
     * in one method to keep the bridge compact; each instanceof branch is a different system entity
     * and cannot be merged.
     * @SuppressWarnings(PHPMD.NPathComplexity)      Ten event types × two trigger types (created/updated)
     * produce many paths; all are required for full system-entity coverage.
     *
     * @spec openspec/changes/openregister-system-notifications/tasks.md#task-3
     */
    public function handle(Event $event): void
    {
        [$entity, $slug, $trigger, $oldData] = $this->extractEventData(event: $event);

        if ($entity === null || $slug === null) {
            return;
        }

        $schema = $this->rules->buildSchema(slug: $slug);
        if ($schema === null) {
            return;
        }

        $adapter = new SystemEntityObjectAdapter(entity: $entity, systemSlug: $slug);
        $context = [];

        if ($trigger === 'updated' && $oldData !== null) {
            $newData = [];
            if ($entity instanceof \JsonSerializable) {
                $newData = $entity->jsonSerialize();
            }

            if (is_array($newData) === true && is_array($oldData) === true) {
                $context['_newData'] = $newData;
                $context['_oldData'] = $oldData;
            }
        }

        $this->dispatcher->dispatchWithSchema(
            object: $adapter,
            trigger: $trigger,
            context: $context,
            schema: $schema
        );

    }//end handle()

    /**
     * Extract entity, system slug, trigger and old-data from the concrete event.
     *
     * Returns [entity, slug, trigger, oldData|null]. Returns [null, null, '', null] when
     * the event type is not recognised as a system-entity event.
     *
     * @param Event $event The inbound event.
     *
     * @return array{0: Entity|null, 1: string|null, 2: string, 3: array<string,mixed>|null}
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) extractEventData() maps ten event classes to
     * (entity, slug, trigger, oldData) tuples; each branch is a distinct system-entity type.
     *
     * @spec openspec/changes/openregister-system-notifications/tasks.md#task-3
     */
    private function extractEventData(Event $event): array
    {
        if ($event instanceof RegisterCreatedEvent) {
            return [$event->getRegister(), SystemSchemaRules::SLUG_REGISTER, 'created', null];
        }

        if ($event instanceof RegisterUpdatedEvent) {
            $old     = $event->getOldRegister();
            $oldData = null;
            if ($old instanceof \JsonSerializable) {
                $oldData = $old->jsonSerialize();
            }

            return [$event->getNewRegister(), SystemSchemaRules::SLUG_REGISTER, 'updated', $oldData];
        }

        if ($event instanceof SchemaCreatedEvent) {
            return [$event->getSchema(), SystemSchemaRules::SLUG_SCHEMA, 'created', null];
        }

        if ($event instanceof SchemaUpdatedEvent) {
            $old     = $event->getOldSchema();
            $oldData = null;
            if ($old instanceof \JsonSerializable) {
                $oldData = $old->jsonSerialize();
            }

            return [$event->getNewSchema(), SystemSchemaRules::SLUG_SCHEMA, 'updated', $oldData];
        }

        if ($event instanceof ConfigurationCreatedEvent) {
            return [$event->getConfiguration(), SystemSchemaRules::SLUG_CONFIGURATION, 'created', null];
        }

        if ($event instanceof ConfigurationUpdatedEvent) {
            $old     = $event->getOldConfiguration();
            $oldData = null;
            if ($old instanceof \JsonSerializable) {
                $oldData = $old->jsonSerialize();
            }

            return [$event->getNewConfiguration(), SystemSchemaRules::SLUG_CONFIGURATION, 'updated', $oldData];
        }

        if ($event instanceof SourceCreatedEvent) {
            return [$event->getSource(), SystemSchemaRules::SLUG_SOURCE, 'created', null];
        }

        if ($event instanceof SourceUpdatedEvent) {
            $old     = $event->getOldSource();
            $oldData = null;
            if ($old instanceof \JsonSerializable) {
                $oldData = $old->jsonSerialize();
            }

            return [$event->getNewSource(), SystemSchemaRules::SLUG_SOURCE, 'updated', $oldData];
        }

        if ($event instanceof AgentCreatedEvent) {
            return [$event->getAgent(), SystemSchemaRules::SLUG_AGENT, 'created', null];
        }

        if ($event instanceof AgentUpdatedEvent) {
            $old     = $event->getOldAgent();
            $oldData = null;
            if ($old instanceof \JsonSerializable) {
                $oldData = $old->jsonSerialize();
            }

            return [$event->getNewAgent(), SystemSchemaRules::SLUG_AGENT, 'updated', $oldData];
        }

        return [null, null, '', null];

    }//end extractEventData()
}//end class
