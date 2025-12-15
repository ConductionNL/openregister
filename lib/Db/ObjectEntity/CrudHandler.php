<?php
/**
 * OpenRegister ObjectEntity CRUD Handler
 *
 * Handles basic Create, Read, Update, Delete operations.
 * Extracted from ObjectEntityMapper as part of SOLID refactoring.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db\ObjectEntity
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

    private ObjectEntityMapper $mapper;

    private IDBConnection $db;

    private IEventDispatcher $eventDispatcher;

    private LoggerInterface $logger;


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
     */
    public function insert(Entity $entity): Entity
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
     */
    public function update(Entity $entity, bool $includeDeleted=false): Entity
    {
        // Find old object for event.
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('openregister_objects')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($entity->getId())));

        if ($includeDeleted === false) {
            $qb->andWhere($qb->expr()->isNull('deleted'));
        }

        $oldObject = $this->mapper->findEntity($qb);

        // Clean @self and id.
        $object = $entity->getObject();
        unset($object['@self'], $object['id']);
        $entity->setObject($object);

        $this->eventDispatcher->dispatchTyped(new ObjectUpdatingEvent($entity, $oldObject));

        $entity = $this->mapper->updateEntity($entity);

        $this->eventDispatcher->dispatchTyped(new ObjectUpdatedEvent($entity, $oldObject));

        $this->logger->info('[CrudHandler] Object updated', ['id' => $entity->getId()]);

        return $entity;

    }//end update()


    /**
     * Delete an object entity
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
