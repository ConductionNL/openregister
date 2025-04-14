<?php

/**
 * OpenRegister Object Entity Mapper
 *
 * This file contains the class for handling object entity mapper related operations
 * in the OpenRegister application.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Db;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectLockedEvent;
use OCA\OpenRegister\Event\ObjectUnlockedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\IDatabaseJsonService;
use OCA\OpenRegister\Service\MySQLJsonService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use OCP\IUserSession;

/**
 * The ObjectEntityMapper class
 *
 * @package OCA\OpenRegister\Db
 */
class ObjectEntityMapper extends QBMapper
{

    /**
     * Database JSON service instance
     *
     * @var IDatabaseJsonService
     */
    private IDatabaseJsonService $databaseJsonService;

    /**
     * Event dispatcher instance
     *
     * @var IEventDispatcher
     */
    private IEventDispatcher $eventDispatcher;

    /**
     * User session instance
     *
     * @var IUserSession
     */
    private IUserSession $userSession;

    public const MAIN_FILTERS = ['register', 'schema', 'uuid', 'created', 'updated'];

    public const DEFAULT_LOCK_DURATION = 3600;


    /**
     * Constructor for the ObjectEntityMapper
     *
     * @param IDBConnection    $db               The database connection
     * @param MySQLJsonService $mySQLJsonService The MySQL JSON service
     * @param IEventDispatcher $eventDispatcher  The event dispatcher
     * @param IUserSession     $userSession      The user session
     */
    public function __construct(
        IDBConnection $db,
        MySQLJsonService $mySQLJsonService,
        IEventDispatcher $eventDispatcher,
        IUserSession $userSession,
    ) {
        parent::__construct($db, 'openregister_objects');

        if ($db->getDatabasePlatform() instanceof MySQLPlatform === true) {
            $this->databaseJsonService = $mySQLJsonService;
        }

        $this->eventDispatcher = $eventDispatcher;
        $this->userSession     = $userSession;

    }//end __construct()


    /**
     * Find an object by ID or UUID with optional register and schema
     *
     * @param int|string    $identifier The ID or UUID of the object to find
     * @param Register|null $register   Optional register to filter by
     * @param Schema|null   $schema     Optional schema to filter by
     *
     * @return ObjectEntity The ObjectEntity
     * @throws MultipleObjectsReturnedException If multiple objects are found
     * @throws \OCP\DB\Exception If a database error occurs
     * @throws DoesNotExistException If the object is not found
     */
    public function find(string | int $identifier, ?Register $register=null, ?Schema $schema=null): ObjectEntity
    {
        $qb = $this->db->getQueryBuilder();

        // Determine ID parameter based on whether identifier is numeric.
        $idParam = -1;
        if (is_numeric($identifier) === true) {
            $idParam = $identifier;
        }

        $qb->select('*')
            ->from('openregister_objects')
            ->where(
                $qb->expr()->orX(
                    $qb->expr()->eq(
                        'id',
                        $qb->createNamedParameter($idParam, IQueryBuilder::PARAM_INT)
                    ),
                    $qb->expr()->eq('uuid', $qb->createNamedParameter($identifier)),
                    $qb->expr()->eq('uri', $qb->createNamedParameter($identifier))
                )
            );

        // Add optional register filter if provided
        if ($register !== null) {
            $qb->andWhere(
                $qb->expr()->eq('register', $qb->createNamedParameter($register->getId(), IQueryBuilder::PARAM_INT))
            );
        }

        // Add optional schema filter if provided
        if ($schema !== null) {
            $qb->andWhere(
                $qb->expr()->eq('schema', $qb->createNamedParameter($schema->getId(), IQueryBuilder::PARAM_INT))
            );
        }

        return $this->findEntity($qb);

    }//end find()


    /**
     * Counts all objects with optional register and schema filters
     *
     * @param array|null    $filters        The filters to apply
     * @param string|null   $search         The search string to apply
     * @param bool          $includeDeleted Whether to include deleted objects
     * @param Register|null $register       Optional register to filter by
     * @param Schema|null   $schema         Optional schema to filter by
     *
     * @return int The number of objects
     */
    public function countAll(
        ?array $filters=[],
        ?string $search=null,
        bool $includeDeleted=false,
        ?Register $register=null,
        ?Schema $schema=null
    ): int {
        $qb = $this->db->getQueryBuilder();

        $qb->selectAlias(select: $qb->createFunction(call: 'count(id)'), alias: 'count')
            ->from(from: 'openregister_objects');

        // Conditionally count objects based on $includeDeleted.
        if ($includeDeleted === false) {
            $qb->andWhere($qb->expr()->isNull('deleted'));
        }

        // Add optional register filter if provided
        if ($register !== null) {
            $qb->andWhere(
                $qb->expr()->eq('register', $qb->createNamedParameter($register->getId(), IQueryBuilder::PARAM_INT))
            );
        }

        // Add optional schema filter if provided
        if ($schema !== null) {
            $qb->andWhere(
                $qb->expr()->eq('schema', $qb->createNamedParameter($schema->getId(), IQueryBuilder::PARAM_INT))
            );
        }

        foreach ($filters as $filter => $value) {
            if ($value === 'IS NOT NULL' && in_array($filter, self::MAIN_FILTERS) === true) {
                $qb->andWhere($qb->expr()->isNotNull($filter));
            } else if ($value === 'IS NULL' && in_array($filter, self::MAIN_FILTERS) === true) {
                $qb->andWhere($qb->expr()->isNull($filter));
            } else if (in_array($filter, self::MAIN_FILTERS) === true) {
                $qb->andWhere($qb->expr()->eq($filter, $qb->createNamedParameter($value)));
            }
        }

        $qb = $this->databaseJsonService->filterJson($qb, $filters);
        $qb = $this->databaseJsonService->searchJson($qb, $search);

        $result = $qb->executeQuery();

        return $result->fetchAll()[0]['count'];

    }//end countAll()


    /**
     * Find all ObjectEntitys
     *
     * @param int|null      $limit            The number of objects to return
     * @param int|null      $offset           The offset of the objects to return
     * @param array|null    $filters          The filters to apply to the objects
     * @param array|null    $searchConditions The search conditions to apply to the objects
     * @param array|null    $searchParams     The search parameters to apply to the objects
     * @param array         $sort             The sort order to apply
     * @param string|null   $search           The search string to apply
     * @param array|null    $ids              Array of IDs or UUIDs to filter by
     * @param string|null   $uses             Value that must be present in relations
     * @param bool          $includeDeleted   Whether to include deleted objects
     * @param Register|null $register         Optional register to filter objects
     * @param Schema|null   $schema           Optional schema to filter objects
     *
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @return array An array of ObjectEntity objects
     */
    public function findAll(
        ?int $limit=null,
        ?int $offset=null,
        ?array $filters=[],
        ?array $searchConditions=[],
        ?array $searchParams=[],
        array $sort=[],
        ?string $search=null,
        ?array $ids=null,
        ?string $uses=null,
        bool $includeDeleted=false,
        ?Register $register=null,
        ?Schema $schema=null
    ): array {
        // Filter out system variables (starting with _)
        $filters = array_filter(
            $filters ?? [],
            function ($key) {
                return !str_starts_with($key, '_');
            },
            ARRAY_FILTER_USE_KEY
        );

        // Remove pagination parameters.
        unset(
            $filters['extend'],
            $filters['limit'],
            $filters['offset'],
            $filters['order'],
            $filters['page']
        );

        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from('openregister_objects')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        // By default, only include objects where 'deleted' is NULL unless $includeDeleted is true.
        if ($includeDeleted === false) {
            $qb->andWhere($qb->expr()->isNull('deleted'));
        }

        // Handle filtering by register if provided.
        if ($register !== null) {
            $qb->andWhere(
                $qb->expr()->eq('register', $qb->createNamedParameter($register->getId(), IQueryBuilder::PARAM_INT))
            );
        }

        // Handle filtering by schema if provided.
        if ($schema !== null) {
            $qb->andWhere(
                $qb->expr()->eq('schema', $qb->createNamedParameter($schema->getId(), IQueryBuilder::PARAM_INT))
            );
        }

        // Handle filtering by IDs/UUIDs if provided.
        if ($ids !== null && empty($ids) === false) {
            $orX = $qb->expr()->orX();
            $orX->add($qb->expr()->in('id', $qb->createNamedParameter($ids, IQuerybuilder::PARAM_STR_ARRAY)));
            $orX->add($qb->expr()->in('uuid', $qb->createNamedParameter($ids, IQuerybuilder::PARAM_STR_ARRAY)));
            $qb->andWhere($orX);
        }

        // Handle filtering by uses in relations if provided.
        if ($uses !== null) {
            $qb->andWhere(
                $qb->expr()->isNotNull(
                    $qb->createFunction(
                        "JSON_SEARCH(relations, 'one', ".$qb->createNamedParameter($uses).", NULL, '$')"
                    )
                )
            );
        }

        foreach ($filters as $filter => $value) {
            if ($value === 'IS NOT NULL' && in_array($filter, self::MAIN_FILTERS) === true) {
                $qb->andWhere($qb->expr()->isNotNull($filter));
            } else if ($value === 'IS NULL' && in_array($filter, self::MAIN_FILTERS) === true) {
                $qb->andWhere($qb->expr()->isNull($filter));
            } else if (in_array($filter, self::MAIN_FILTERS) === true) {
                $qb->andWhere($qb->expr()->eq($filter, $qb->createNamedParameter($value)));
            }
        }

        if (empty($searchConditions) === false) {
            $qb->andWhere('('.implode(' OR ', $searchConditions).')');
            foreach ($searchParams as $param => $value) {
                $qb->setParameter($param, $value);
            }
        }

        // Filter and search the objects.
        $qb = $this->databaseJsonService->filterJson(builder: $qb, filters: $filters);
        $qb = $this->databaseJsonService->searchJson(builder: $qb, search: $search);
        $qb = $this->databaseJsonService->orderJson(builder: $qb, order: $sort);

        return $this->findEntities(query: $qb);

    }//end findAll()


    /**
     * Inserts a new entity into the database.
     *
     * @param Entity $entity The entity to insert.
     *
     * @throws \OCP\DB\Exception If a database error occurs.
     *
     * @return Entity The inserted entity.
     */
    public function insert(Entity $entity): Entity
    {
        // Let's make sure that @self and id never enter the database.
        $object = $entity->getObject();
        unset($object['@self'], $object['id']);
        $entity->setObject($object);

        $entity = parent::insert($entity);
        // Dispatch creation event.
        $this->eventDispatcher->dispatchTyped(new ObjectCreatedEvent($entity));

        return $entity;

    }//end insert()


    /**
     * Creates an object from an array
     *
     * @param array $object The object to create
     *
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @return ObjectEntity The created object
     */
    public function createFromArray(array $object): ObjectEntity
    {
        $obj = new ObjectEntity();
        $obj->hydrate(object: $object);

        // Prepare the object before insertion.
        return $this->insert($obj);

    }//end createFromArray()


    /**
     * Updates an entity in the database
     *
     * @param Entity $entity The entity to update
     *
     * @return Entity The updated entity
     * @throws DoesNotExistException If the entity does not exist
     * @throws \OCP\DB\Exception|MultipleObjectsReturnedException If a database error occurs
     */
    public function update(Entity $entity): Entity
    {
        $oldObject = $this->find($entity->getId());

        // Let's make sure that @self and id never enter the database.
        $object = $entity->getObject();
        unset($object['@self'], $object['id']);
        $entity->setObject($object);

        $entity = parent::update($entity);

        // Dispatch update event.
        $this->eventDispatcher->dispatchTyped(new ObjectUpdatedEvent($entity, $oldObject));

        return $entity;

    }//end update()


    /**
     * Updates an object from an array
     *
     * @param int   $id     The id of the object to update
     * @param array $object The object to update
     *
     * @return ObjectEntity The updated object
     * @throws DoesNotExistException If the object is not found
     * @throws \OCP\DB\Exception|MultipleObjectsReturnedException If a database error occurs
     */
    public function updateFromArray(int $id, array $object): ObjectEntity
    {
        $oldObject = $this->find($id);
        $newObject = clone $oldObject;
        $newObject->hydrate($object);

        // Prepare the object before updating.
        return $this->update($this->prepareEntity($newObject));

    }//end updateFromArray()


    /**
     * Delete an object
     *
     * @param ObjectEntity $object The object to delete
     *
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @return ObjectEntity The deleted object
     */
    public function delete(Entity $object): ObjectEntity
    {
        $result = parent::delete($object);

        // Dispatch deletion event.
        $this->eventDispatcher->dispatch(
            ObjectDeletedEvent::class,
            new ObjectDeletedEvent($object)
        );

        return $result;

    }//end delete()


    /**
     * Gets the facets for the objects
     *
     * @param array       $filters The filters to apply
     * @param string|null $search  The search string to apply
	 *
     * @return array The facets
     */
    public function getFacets(array $filters=[], ?string $search=null): array
    {
        $register = null;
        $schema   = null;

        if (array_key_exists('register', $filters) === true) {
            $register = $filters['register'];
        }

        if (array_key_exists('schema', $filters) === true) {
            $schema = $filters['schema'];
        }

        $fields = [];
        if (isset($filters['_queries']) === true) {
            $fields = $filters['_queries'];
        }

        unset(
            $filters['_fields'],
            $filters['register'],
            $filters['schema'],
            $filters['created'],
            $filters['updated'],
            $filters['uuid']
        );

        return $this->databaseJsonService->getAggregations(
            builder: $this->db->getQueryBuilder(),
            fields: $fields,
            register: $register,
            schema: $schema,
            filters: $filters,
            search: $search
        );

    }//end getFacets()


    /**
     * Find objects that have a specific URI or UUID in their relations
     *
     * @param string $search       The URI or UUID to search for in relations
     * @param bool   $partialMatch Whether to search for partial matches (default: false)
     *
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @return array An array of ObjectEntities that have the specified URI/UUID
     */
    public function findByRelation(string $search, bool $partialMatch=true): array
    {
        $qb = $this->db->getQueryBuilder();

        // For partial matches, we use '%' wildcards and 'all' mode to search anywhere in the JSON.
        // For exact matches, we use 'one' mode which finds exact string matches.
        $mode       = 'one';
        $searchTerm = $search;

        if ($partialMatch === true) {
            $mode       = 'all';
            $searchTerm = '%'.$search.'%';
        }

        $searchFunction = "JSON_SEARCH(relations, '".$mode."', ".$qb->createNamedParameter($searchTerm);
        if ($partialMatch === true) {
            $searchFunction .= ", NULL, '$')";
        } else {
            $searchFunction .= ")";
        }

        $qb->select('*')
            ->from('openregister_objects')
            ->where(
                $qb->expr()->isNotNull(
                    $qb->createFunction($searchFunction)
                )
            );

        return $this->findEntities($qb);

    }//end findByRelation()


    /**
     * Lock an object
     *
     * @param int|string $identifier Object ID, UUID, or URI
     * @param string|null $process    Optional process identifier
     * @param int|null    $duration   Lock duration in seconds
     *
     * @return ObjectEntity The locked object
     * @throws \Exception If locking fails
     * @throws DoesNotExistException If object not found
     */
    public function lockObject(int|string $identifier, ?string $process=null, ?int $duration=null): ObjectEntity
    {
        $object = $this->find($identifier);

        if ($duration === null) {
            $duration = $this::DEFAULT_LOCK_DURATION;
        }

        // Check if user has permission to lock.
        if ($this->userSession->isLoggedIn() === false) {
            throw new \Exception('Must be logged in to lock objects');
        }

        // Attempt to lock the object.
        $object->lock($this->userSession, $process, $duration);

        // Save the locked object.
        $object = $this->update($object);

        // Dispatch lock event.
        $this->eventDispatcher->dispatch(
            ObjectLockedEvent::class,
            new ObjectLockedEvent($object)
        );

        return $object;

    }//end lockObject()


    /**
     * Unlock an object
     *
     * @param int|string $identifier Object ID, UUID, or URI
     *
     * @return ObjectEntity The unlocked object
     * @throws \Exception If unlocking fails
     * @throws DoesNotExistException If object not found
     */
    public function unlockObject(int|string $identifier): ObjectEntity
    {
        $object = $this->find($identifier);

        // Check if user has permission to unlock.
        if ($this->userSession->isLoggedIn() === false) {
            throw new \Exception('Must be logged in to unlock objects');
        }

        // Attempt to unlock the object.
        $object->unlock($this->userSession);

        // Save the unlocked object.
        $object = $this->update($object);

        // Dispatch unlock event.
        $this->eventDispatcher->dispatch(
            ObjectUnlockedEvent::class,
            new ObjectUnlockedEvent($object)
        );

        return $object;

    }//end unlockObject()


	/**
	 * Check if an object is locked
	 *
	 * @param int|string $identifier Object ID, UUID, or URI
	 *
	 * @return bool True if object is locked, false otherwise
	 * @throws DoesNotExistException If object not found
	 * @throws MultipleObjectsReturnedException
	 * @throws Exception
	 */
    public function isObjectLocked(int|string $identifier): bool
    {
        $object = $this->find($identifier);
        return $object->isLocked();

    }//end isObjectLocked()


    /**
     * Find multiple objects by their IDs, UUIDs, or URIs
     *
     * @param array $ids Array of IDs, UUIDs, or URIs to find
     *
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @return array An array of ObjectEntity objects
     */
    public function findMultiple(array $ids): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from('openregister_objects')
            ->orWhere($qb->expr()->in('id', $qb->createNamedParameter($ids, IQuerybuilder::PARAM_STR_ARRAY)))
            ->orWhere($qb->expr()->in('uuid', $qb->createNamedParameter($ids, IQuerybuilder::PARAM_STR_ARRAY)))
            ->orWhere($qb->expr()->in('uri', $qb->createNamedParameter($ids, IQuerybuilder::PARAM_STR_ARRAY)));

        return $this->findEntities($qb);

    }//end findMultiple()


}//end class
