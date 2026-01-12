<?php

/**
 * OpenRegister ObjectEntity CRUD Handler
 *
 * Handles basic Create, Read, Update, Delete operations.
 * Extracted from ObjectEntityMapper as part of SOLID refactoring.
 *
 * @category  Database
 * @package   OCA\OpenRegister\Db\ObjectEntity
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://OpenRegister.app
 */

namespace OCA\OpenRegister\Db\ObjectEntity;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCP\AppFramework\Db\Entity;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * CrudHandler
 *
 * Handles basic CRUD operations with event dispatching.
 */
class CrudHandler
{

    /**
     * Object entity mapper.
     *
     * @var ObjectEntityMapper
     */
    private ObjectEntityMapper $mapper;

    /**
     * Database connection.
     *
     * @var IDBConnection
     */
    private IDBConnection $db;

    /**
     * Event dispatcher.
     *
     * @var IEventDispatcher
     */
    private IEventDispatcher $eventDispatcher;

    /**
     * Logger.
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Constructor for CrudHandler.
     *
     * @param ObjectEntityMapper $mapper          Object entity mapper.
     * @param IDBConnection      $db              Database connection.
     * @param IEventDispatcher   $eventDispatcher Event dispatcher.
     * @param LoggerInterface    $logger          Logger.
     */
    public function __construct(
        ObjectEntityMapper $mapper,
        IDBConnection $db,
        IEventDispatcher $eventDispatcher,
        LoggerInterface $logger
    ) {
        $this->mapper = $mapper;
        $this->db     = $db;
        $this->eventDispatcher = $eventDispatcher;
        $this->logger          = $logger;
    }//end __construct()

    /**
     * Insert a new object entity
     *
     * @param Entity $entity The entity to insert.
     *
     * @return ObjectEntity The inserted entity
     */
    public function insert(Entity $entity): ObjectEntity
    {
        // Clean @self and id from object.
        $object = $entity->getObject();
        unset($object['@self'], $object['id']);
        $entity->setObject($object);

        $this->eventDispatcher->dispatchTyped(new ObjectCreatingEvent($entity));

        // Delegate to parent mapper.
        $entity = $this->mapper->insertEntity($entity);

        $this->eventDispatcher->dispatchTyped(new ObjectCreatedEvent($entity));

        $this->logger->info('[CrudHandler] Object inserted', ['id' => $entity->getId()]);

        return $entity;
    }//end insert()

    /**
     * Update an existing object entity
     *
     * @param Entity $entity         The entity to update.
     * @param bool   $includeDeleted Whether to include deleted entities in search (default: false).
     *
     * @return ObjectEntity The updated entity
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Include deleted toggle is intentional
     */
    public function update(Entity $entity, bool $includeDeleted=false): ObjectEntity
    {
        // Find old object for event.
        $oldObject = $this->mapper->find(identifier: $entity->getId(), includeDeleted: $includeDeleted);

        // Clean @self and id.
        $object = $entity->getObject();
        unset($object['@self'], $object['id']);
        $entity->setObject($object);

        $this->eventDispatcher->dispatchTyped(event: new ObjectUpdatingEvent(newObject: $entity, oldObject: $oldObject));

        $entity = $this->mapper->updateEntity($entity);

        $this->eventDispatcher->dispatchTyped(new ObjectUpdatedEvent($entity, $oldObject));

        $this->logger->info('[CrudHandler] Object updated', ['id' => $entity->getId()]);

        return $entity;
    }//end update()

    /**
     * Delete an object entity
     *
     * @param Entity $entity The entity to delete.
     *
     * @return ObjectEntity The deleted entity
     */
    public function delete(Entity $entity): ObjectEntity
    {
        $this->eventDispatcher->dispatchTyped(new ObjectDeletingEvent($entity));

        $result = $this->mapper->deleteEntity($entity);

        $this->eventDispatcher->dispatchTyped(new ObjectDeletedEvent($entity));

        $this->logger->info('[CrudHandler] Object deleted', ['id' => $entity->getId()]);

        return $result;
    }//end delete()
}//end class
