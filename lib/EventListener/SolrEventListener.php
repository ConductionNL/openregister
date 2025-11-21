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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\EventListener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\SchemaCreatedEvent;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCA\OpenRegister\Event\SchemaDeletedEvent;
use OCA\OpenRegister\Service\ObjectCacheService;
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
     * @param ObjectCacheService $objectCacheService Service for handling object caching and Solr operations
     * @param LoggerInterface    $logger             Logger for debugging and monitoring
     */
    public function __construct(
        private readonly ObjectCacheService $objectCacheService,
        private readonly LoggerInterface $logger
    ) {
    }

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
        $this->logger->debug('SolrEventListener handling event', ['event_type' => get_class($event)]);
        
        try {
            if ($event instanceof ObjectCreatedEvent) {
                $this->logger->debug('=== SOLR EVENT LISTENER DEBUG ===');
                $this->logger->debug('Event: ObjectCreatedEvent');
                $this->logger->debug('Object ID: ' . $event->getObject()->getId());
                $this->logger->debug('Object UUID: ' . ($event->getObject()->getUuid() ?? 'null'));
                $this->logger->debug('=== END EVENT DEBUG ===');
                $this->logger->debug('Handling ObjectCreatedEvent', ['object_id' => $event->getObject()->getId()]);
                $this->handleObjectCreated($event);
            } elseif ($event instanceof ObjectUpdatedEvent) {
                $this->logger->debug('Handling ObjectUpdatedEvent', ['object_id' => $event->getNewObject()->getId()]);
                $this->handleObjectUpdated($event);
            } elseif ($event instanceof ObjectDeletedEvent) {
                $this->logger->debug('Handling ObjectDeletedEvent', ['object_id' => $event->getObject()->getId()]);
                $this->handleObjectDeleted($event);
            } elseif ($event instanceof SchemaCreatedEvent) {
                $this->handleSchemaCreated($event);
            } elseif ($event instanceof SchemaUpdatedEvent) {
                $this->handleSchemaUpdated($event);
            } elseif ($event instanceof SchemaDeletedEvent) {
                $this->handleSchemaDeleted($event);
            } else {
                var_dump("🔥 Unhandled event: " . get_class($event));
                // Log unhandled events for debugging.
                $this->logger->debug('SolrEventListener: Received unhandled event', [
                    'eventClass' => get_class($event),
                    'app' => 'openregister'
                ]);
            }
        } catch (\Exception $e) {
            var_dump("🔥 ERROR in SolrEventListener: " . $e->getMessage());
            // Log errors but don't break the application flow.
            $this->logger->error('SolrEventListener: Error handling event', [
                'eventClass' => get_class($event),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'app' => 'openregister'
            ]);
        }
    }

    /**
     * Handle object creation event
     *
     * @param ObjectCreatedEvent $event The object creation event
     *
     * @return void
     */
    private function handleObjectCreated(ObjectCreatedEvent $event): void
    {
        $object = $event->getObject();
        
        $this->logger->info('SolrEventListener: Indexing newly created object', [
            'objectId' => $object->getId(),
            'objectUuid' => $object->getUuid(),
            'objectName' => $object->getName(),
            'app' => 'openregister'
        ]);

        // Trigger Solr indexing for the created object.
        $this->objectCacheService->invalidateForObjectChange($object, 'create');
    }

    /**
     * Handle object update event
     *
     * @param ObjectUpdatedEvent $event The object update event
     *
     * @return void
     */
    private function handleObjectUpdated(ObjectUpdatedEvent $event): void
    {
        $newObject = $event->getNewObject();
        $oldObject = $event->getOldObject();
        
        $this->logger->info('SolrEventListener: Reindexing updated object', [
            'objectId' => $newObject->getId(),
            'objectUuid' => $newObject->getUuid(),
            'objectName' => $newObject->getName(),
            'oldObjectName' => $oldObject->getName(),
            'app' => 'openregister'
        ]);

        // Trigger Solr reindexing for the updated object.
        $this->objectCacheService->invalidateForObjectChange($newObject, 'update');
    }

    /**
     * Handle object deletion event
     *
     * @param ObjectDeletedEvent $event The object deletion event
     *
     * @return void
     */
    private function handleObjectDeleted(ObjectDeletedEvent $event): void
    {
        $object = $event->getObject();
        
        $this->logger->info('SolrEventListener: Removing deleted object from index', [
            'objectId' => $object->getId(),
            'objectUuid' => $object->getUuid(),
            'objectName' => $object->getName(),
            'app' => 'openregister'
        ]);

        // Trigger Solr removal for the deleted object.
        $this->objectCacheService->invalidateForObjectChange($object, 'delete');
    }

    /**
     * Handle schema creation event
     *
     * @param SchemaCreatedEvent $event The schema creation event
     *
     * @return void
     */
    private function handleSchemaCreated(SchemaCreatedEvent $event): void
    {
        $schema = $event->getSchema();
        
        $this->logger->info('SolrEventListener: Schema created, updating Solr field mappings', [
            'schemaId' => $schema->getId(),
            'schemaTitle' => $schema->getTitle(),
            'app' => 'openregister'
        ]);

        // Schema creation might require Solr field mapping updates.
        // This could trigger a reindex of objects using this schema.
        $this->triggerSchemaReindex($schema->getId());
    }

    /**
     * Handle schema update event
     *
     * @param SchemaUpdatedEvent $event The schema update event
     *
     * @return void
     */
    private function handleSchemaUpdated(SchemaUpdatedEvent $event): void
    {
        $newSchema = $event->getNewSchema();
        $oldSchema = $event->getOldSchema();
        
        $this->logger->info('SolrEventListener: Schema updated, checking for field mapping changes', [
            'schemaId' => $newSchema->getId(),
            'schemaTitle' => $newSchema->getTitle(),
            'app' => 'openregister'
        ]);

        // Compare schema properties to see if field mappings changed.
        if ($this->schemaFieldsChanged($oldSchema, $newSchema)) {
            $this->logger->info('SolrEventListener: Schema fields changed, triggering reindex', [
                'schemaId' => $newSchema->getId(),
                'app' => 'openregister'
            ]);
            
            // Trigger reindex of all objects using this schema.
            $this->triggerSchemaReindex($newSchema->getId());
        }
    }

    /**
     * Handle schema deletion event
     *
     * @param SchemaDeletedEvent $event The schema deletion event
     *
     * @return void
     */
    private function handleSchemaDeleted(SchemaDeletedEvent $event): void
    {
        $schema = $event->getSchema();
        
        $this->logger->info('SolrEventListener: Schema deleted, cleaning up Solr entries', [
            'schemaId' => $schema->getId(),
            'schemaTitle' => $schema->getTitle(),
            'app' => 'openregister'
        ]);

        // When a schema is deleted, we should remove all objects using this schema from Solr.
        // This is handled automatically when objects are deleted, but we log it for tracking.
    }

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
    }

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
        // for reindexing all objects with the updated schema.
        $this->logger->info('SolrEventListener: Schema reindex requested', [
            'schemaId' => $schemaId,
            'app' => 'openregister',
            'note' => 'Background reindex should be implemented here'
        ]);
    }
}
