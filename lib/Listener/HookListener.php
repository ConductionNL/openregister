<?php

/**
 * OpenRegister HookListener
 *
 * Listener that delegates schema hook execution to HookExecutor.
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
 *
 * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-71
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\HookExecutor;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * HookListener delegates object lifecycle events to HookExecutor
 *
 * Listens for ObjectCreatingEvent, ObjectUpdatingEvent, and ObjectDeletingEvent,
 * resolves the schema from the object, and calls HookExecutor to run any
 * configured hooks.
 *
 * @template-implements IEventListener<Event>
 */
class HookListener implements IEventListener
{
    /**
     * Constructor for HookListener
     *
     * @param HookExecutor    $hookExecutor Hook executor service
     * @param SchemaMapper    $schemaMapper Schema mapper for loading schemas
     * @param LoggerInterface $logger       Logger
     */
    public function __construct(
        private readonly HookExecutor $hookExecutor,
        private readonly SchemaMapper $schemaMapper,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Handle event by delegating to HookExecutor
     *
     * @param Event $event The lifecycle event
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-65
     * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-71
     */
    public function handle(Event $event): void
    {
        $object = $this->getObjectFromEvent(event: $event);
        if ($object === null) {
            return;
        }

        $schemaId = $object->getSchema();
        if ($schemaId === null || $schemaId === '' || $schemaId === '0') {
            return;
        }

        try {
            $schema = $this->schemaMapper->find(id: (int) $schemaId);
        } catch (Exception $e) {
            $this->logger->debug(
                message: '[HookListener] Could not load schema for hook execution',
                context: [
                    'schemaId' => $schemaId,
                    'error'    => $e->getMessage(),
                ]
            );
            return;
        }

        $hooks = ($schema->getHooks() ?? []);
        if (empty($hooks) === true) {
            return;
        }

        $this->hookExecutor->executeHooks(event: $event, schema: $schema);
    }//end handle()

    /**
     * Extract the ObjectEntity from the event
     *
     * @param Event $event The lifecycle event
     *
     * @return ObjectEntity|null The object entity or null
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-65
     */
    private function getObjectFromEvent(Event $event): ?ObjectEntity
    {
        if ($event instanceof ObjectCreatingEvent) {
            return $event->getObject();
        }

        if ($event instanceof ObjectUpdatingEvent) {
            return $event->getNewObject();
        }

        if ($event instanceof ObjectDeletingEvent) {
            return $event->getObject();
        }

        if ($event instanceof ObjectCreatedEvent) {
            return $event->getObject();
        }

        if ($event instanceof ObjectUpdatedEvent) {
            return $event->getNewObject();
        }

        if ($event instanceof ObjectDeletedEvent) {
            return $event->getObject();
        }

        return null;
    }//end getObjectFromEvent()
}//end class
