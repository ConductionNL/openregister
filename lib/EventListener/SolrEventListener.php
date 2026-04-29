<?php

/**
 * OpenRegister Solr Event Listener
 *
 * Event listener that automatically handles Solr indexing for object lifecycle events.
 * This listener responds to object creation, update, and deletion events by
 * maintaining the corresponding Solr index entries.
 *
 * @category EventListener
 * @package  OCA\OpenRegister\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 *
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-26
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-27
 */

declare(strict_types=1);

namespace OCA\OpenRegister\EventListener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\SchemaCreatedEvent;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCA\OpenRegister\Event\SchemaDeletedEvent;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Event listener for Solr indexing operations
 *
 * Automatically maintains Solr index consistency by responding to object
 * lifecycle events (create, update, delete) and triggering appropriate
 * Solr operations.
 *
 * @implements IEventListener<Event>
 */
class SolrEventListener implements IEventListener
{
    /**
     * Constructor for SolrEventListener
     *
     * @param CacheHandler    $cacheHandler Service for handling object caching and Solr operations
     * @param LoggerInterface $logger       Logger for debugging and monitoring
     */
    public function __construct(
        private readonly CacheHandler $cacheHandler,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Handle incoming events and trigger appropriate Solr operations
     *
     * @param Event $event The event to handle
     *
     * @return void
     */
    public function handle(Event $event): void
    {
        // DEBUG: Check if we're getting called at all.
        $this->logger->debug(
            message: '[SolrEventListener] SolrEventListener handling event',
            context: ['file' => __FILE__, 'line' => __LINE__, 'event_type' => get_class($event)]
        );

        try {
            if ($event instanceof ObjectCreatedEvent) {
                $this->logger->debug(
                    message: '[SolrEventListener] === SOLR EVENT LISTENER DEBUG ===',
                    context: ['file' => __FILE__, 'line' => __LINE__]
                );
                $this->logger->debug(
                    message: '[SolrEventListener] Event: ObjectCreatedEvent',
                    context: ['file' => __FILE__, 'line' => __LINE__]
                );
                $this->logger->debug(
                    message: '[SolrEventListener] Object ID: '.$event->getObject()->getId(),
                    context: ['file' => __FILE__, 'line' => __LINE__]
                );
                $this->logger->debug(
                    message: '[SolrEventListener] Object UUID: '.($event->getObject()->getUuid() ?? 'null'),
                    context: ['file' => __FILE__, 'line' => __LINE__]
                );
                $this->logger->debug(
                    message: '[SolrEventListener] === END EVENT DEBUG ===',
                    context: ['file' => __FILE__, 'line' => __LINE__]
                );
                $this->logger->debug(
                    message: '[SolrEventListener] Handling ObjectCreatedEvent',
                    context: ['file' => __FILE__, 'line' => __LINE__, 'object_id' => $event->getObject()->getId()]
                );
                $this->handleObjectCreated(event: $event);
                return;
            }//end if

            if ($event instanceof ObjectUpdatedEvent) {
                $this->logger->debug(
                    message: '[SolrEventListener] Handling ObjectUpdatedEvent',
                    context: ['file' => __FILE__, 'line' => __LINE__, 'object_id' => $event->getNewObject()->getId()]
                );
                $this->handleObjectUpdated(event: $event);
                return;
            }

            if ($event instanceof ObjectDeletedEvent) {
                $this->logger->debug(
                    message: '[SolrEventListener] Handling ObjectDeletedEvent',
                    context: ['file' => __FILE__, 'line' => __LINE__, 'object_id' => $event->getObject()->getId()]
                );
                $this->handleObjectDeleted(event: $event);
                return;
            }

            if ($event instanceof SchemaCreatedEvent) {
                $this->handleSchemaCreated(event: $event);
                return;
            }

            if ($event instanceof SchemaUpdatedEvent) {
                $this->handleSchemaUpdated(event: $event);
                return;
            }

            if ($event instanceof SchemaDeletedEvent) {
                $this->handleSchemaDeleted(event: $event);
                return;
            }

            // Log unhandled events for debugging.
            $this->logger->debug(
                message: '[SolrEventListener] Received unhandled event',
                context: [
                    'file'       => __FILE__,
                    'line'       => __LINE__,
                    'eventClass' => get_class($event),
                    'app'        => 'openregister',
                ]
            );
        } catch (\Exception $e) {
            // Log errors but don't break the application flow.
            $this->logger->error(
                message: '[SolrEventListener] Error handling event',
                context: [
                    'file'       => __FILE__,
                    'line'       => __LINE__,
                    'eventClass' => get_class($event),
                    'error'      => $e->getMessage(),
                    'trace'      => $e->getTraceAsString(),
                    'app'        => 'openregister',
                ]
            );
        }//end try
    }//end handle()

    /**
     * Handle object creation event
     *
     * @param ObjectCreatedEvent $event The object creation event
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-26
     */
    private function handleObjectCreated(ObjectCreatedEvent $event): void
    {
        $object = $event->getObject();

        $this->logger->info(
            message: '[SolrEventListener] Indexing newly created object',
            context: [
                'file'       => __FILE__,
                'line'       => __LINE__,
                'objectId'   => $object->getId(),
                'objectUuid' => $object->getUuid(),
                'objectName' => $object->getName(),
                'app'        => 'openregister',
            ]
        );

        // Trigger Solr indexing for the created object only if indexing is available.
        try {
            $this->cacheHandler->invalidateForObjectChange(object: $object, operation: 'create');
        } catch (\Exception $e) {
            // If Solr/indexing is not configured, log and continue gracefully.
            $this->logger->debug(
                message: '[SolrEventListener] Indexing not available, skipping',
                context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
            );
        }
    }//end handleObjectCreated()

    /**
     * Handle object update event
     *
     * @param ObjectUpdatedEvent $event The object update event
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-26
     */
    private function handleObjectUpdated(ObjectUpdatedEvent $event): void
    {
        $newObject = $event->getNewObject();
        $oldObject = $event->getOldObject();

        $this->logger->info(
            message: '[SolrEventListener] Reindexing updated object',
            context: [
                'file'          => __FILE__,
                'line'          => __LINE__,
                'objectId'      => $newObject->getId(),
                'objectUuid'    => $newObject->getUuid(),
                'objectName'    => $newObject->getName(),
                'oldObjectName' => $oldObject?->getName(),
                'app'           => 'openregister',
            ]
        );

        // Trigger Solr reindexing for the updated object only if indexing is available.
        try {
            $this->cacheHandler->invalidateForObjectChange(object: $newObject, operation: 'update');
        } catch (\Exception $e) {
            // If Solr/indexing is not configured, log and continue gracefully.
            $this->logger->debug(
                message: '[SolrEventListener] Indexing not available, skipping',
                context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
            );
        }
    }//end handleObjectUpdated()

    /**
     * Handle object deletion event
     *
     * @param ObjectDeletedEvent $event The object deletion event
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-26
     */
    private function handleObjectDeleted(ObjectDeletedEvent $event): void
    {
        $object = $event->getObject();

        $this->logger->info(
            message: '[SolrEventListener] Removing deleted object from index',
            context: [
                'file'       => __FILE__,
                'line'       => __LINE__,
                'objectId'   => $object->getId(),
                'objectUuid' => $object->getUuid(),
                'objectName' => $object->getName(),
                'app'        => 'openregister',
            ]
        );

        // Trigger Solr removal for the deleted object only if indexing is available.
        try {
            $this->cacheHandler->invalidateForObjectChange(object: $object, operation: 'delete');
        } catch (\Exception $e) {
            // If Solr/indexing is not configured, log and continue gracefully.
            $this->logger->debug(
                message: '[SolrEventListener] Indexing not available, skipping',
                context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
            );
        }
    }//end handleObjectDeleted()

    /**
     * Handle schema creation event
     *
     * @param SchemaCreatedEvent $event The schema creation event
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-27
     */
    private function handleSchemaCreated(SchemaCreatedEvent $event): void
    {
        $schema = $event->getSchema();

        $this->logger->info(
            message: '[SolrEventListener] Schema created, updating Solr field mappings',
            context: [
                'file'        => __FILE__,
                'line'        => __LINE__,
                'schemaId'    => $schema->getId(),
                'schemaTitle' => $schema->getTitle(),
                'app'         => 'openregister',
            ]
        );

        // Schema creation might require Solr field mapping updates.
        // This could trigger a reindex of objects using this schema.
        $this->triggerSchemaReindex(schemaId: $schema->getId());
    }//end handleSchemaCreated()

    /**
     * Handle schema update event
     *
     * @param SchemaUpdatedEvent $event The schema update event
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-27
     */
    private function handleSchemaUpdated(SchemaUpdatedEvent $event): void
    {
        $newSchema = $event->getNewSchema();
        $oldSchema = $event->getOldSchema();

        $this->logger->info(
            message: '[SolrEventListener] Schema updated, checking for field mapping changes',
            context: [
                'file'        => __FILE__,
                'line'        => __LINE__,
                'schemaId'    => $newSchema->getId(),
                'schemaTitle' => $newSchema->getTitle(),
                'app'         => 'openregister',
            ]
        );

        // Compare schema properties to see if field mappings changed.
        if ($this->schemaFieldsChanged(oldSchema: $oldSchema, newSchema: $newSchema) === true) {
            $this->logger->info(
                message: '[SolrEventListener] Schema fields changed, triggering reindex',
                context: [
                    'file'     => __FILE__,
                    'line'     => __LINE__,
                    'schemaId' => $newSchema->getId(),
                    'app'      => 'openregister',
                ]
            );

            // Trigger reindex of all objects using this schema.
            $this->triggerSchemaReindex(schemaId: $newSchema->getId());
            // End if.
        }
    }//end handleSchemaUpdated()

    /**
     * Handle schema deletion event
     *
     * @param SchemaDeletedEvent $event The schema deletion event
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-27
     */
    private function handleSchemaDeleted(SchemaDeletedEvent $event): void
    {
        $schema = $event->getSchema();

        $this->logger->info(
            message: '[SolrEventListener] Schema deleted, cleaning up Solr entries',
            context: [
                'file'        => __FILE__,
                'line'        => __LINE__,
                'schemaId'    => $schema->getId(),
                'schemaTitle' => $schema->getTitle(),
                'app'         => 'openregister',
            ]
        );

        // When a schema is deleted, we should remove all objects using this schema from Solr.
        // This is handled automatically when objects are deleted, but we log it for tracking.
    }//end handleSchemaDeleted()

    /**
     * Check if schema fields changed between versions
     *
     * @param \OCA\OpenRegister\Db\Schema $oldSchema Old schema version
     * @param \OCA\OpenRegister\Db\Schema $newSchema New schema version
     *
     * @return bool True if fields changed and reindex is needed
     */
    private function schemaFieldsChanged($oldSchema, $newSchema): bool
    {
        // Compare the properties JSON to detect field changes.
        $oldProperties = $oldSchema->getProperties();
        $newProperties = $newSchema->getProperties();

        return $oldProperties !== $newProperties;
    }//end schemaFieldsChanged()

    /**
     * Trigger reindex of all objects using a specific schema
     *
     * @param int $schemaId Schema ID to reindex
     *
     * @return void
     */
    private function triggerSchemaReindex(int $schemaId): void
    {
        // This could be implemented to trigger a background job.
        // For reindexing all objects with the updated schema.
        $this->logger->info(
            message: '[SolrEventListener] Schema reindex requested',
            context: [
                'file'     => __FILE__,
                'line'     => __LINE__,
                'schemaId' => $schemaId,
                'app'      => 'openregister',
                'note'     => 'Background reindex should be implemented here',
            ]
        );
    }//end triggerSchemaReindex()
}//end class
