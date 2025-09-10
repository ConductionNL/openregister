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
 * @template T of Event
 * @implements IEventListener<T>
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
        try {
            if ($event instanceof ObjectCreatedEvent) {
                $this->handleObjectCreated($event);
            } elseif ($event instanceof ObjectUpdatedEvent) {
                $this->handleObjectUpdated($event);
            } elseif ($event instanceof ObjectDeletedEvent) {
                $this->handleObjectDeleted($event);
            } else {
                // Log unhandled events for debugging
                $this->logger->debug('SolrEventListener: Received unhandled event', [
                    'eventClass' => get_class($event),
                    'app' => 'openregister'
                ]);
            }
        } catch (\Exception $e) {
            // Log errors but don't break the application flow
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

        // Trigger Solr indexing for the created object
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

        // Trigger Solr reindexing for the updated object
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

        // Trigger Solr removal for the deleted object
        $this->objectCacheService->invalidateForObjectChange($object, 'delete');
    }
}
