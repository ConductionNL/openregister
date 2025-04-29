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
 * @author    Conduction Development Team <dev@conductio.nl>
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
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use OCP\IUserSession;
use Symfony\Component\Uid\Uuid;

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
     * @throws \OCP\AppFramework\Db\DoesNotExistException If the object is not found
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException If multiple objects are found
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @return ObjectEntity The ObjectEntity
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
                    $qb->expr()->eq('uuid', $qb->createNamedParameter($identifier, IQueryBuilder::PARAM_STR)),
                    $qb->expr()->eq('uri', $qb->createNamedParameter($identifier, IQueryBuilder::PARAM_STR))
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
     * Find all ObjectEntities
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
        ?array $sort=[],
        ?string $search=null,
        ?array $ids=null,
        ?string $uses=null,
        ?bool $includeDeleted=false,
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

        // Add register to filters if provided
        if ($register !== null) {
            $filters['register'] = $register;
        }

        // Add schema to filters if provided
        if ($schema !== null) {
            $filters['schema'] = $schema;
        }

        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from('openregister_objects')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        // By default, only include objects where 'deleted' is NULL unless $includeDeleted is true.
        if ($includeDeleted === false) {
            $qb->andWhere($qb->expr()->isNull('deleted'));
        }

        // Handle filtering by IDs/UUIDs if provided.
        if ($ids !== null && empty($ids) === false) {
            $orX = $qb->expr()->orX();
            $orX->add($qb->expr()->in('id', $qb->createNamedParameter($ids, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)));
            $orX->add($qb->expr()->in('uuid', $qb->createNamedParameter($ids, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)));
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
                // Add condition for IS NOT NULL
                $qb->andWhere($qb->expr()->isNotNull($filter));
            } else if ($value === 'IS NULL' && in_array($filter, self::MAIN_FILTERS) === true) {
                // Add condition for IS NULL
                $qb->andWhere($qb->expr()->isNull($filter));
            } else if (in_array($filter, self::MAIN_FILTERS) === true) {
                if (is_array($value)) {
                    // If the value is an array, use IN to search for any of the values in the array
                    $qb->andWhere($qb->expr()->in($filter, $qb->createNamedParameter($value, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)));
                } else {
                    // Otherwise, use equality for the filter
                    $qb->andWhere($qb->expr()->eq($filter, $qb->createNamedParameter($value)));
                }
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
        ?array $ids=null,
        ?string $uses=null,
        bool $includeDeleted=false,
        ?Register $register=null,
        ?Schema $schema=null
    ): int {
        $qb = $this->db->getQueryBuilder();

        $qb->selectAlias(select: $qb->createFunction(call: 'count(id)'), alias: 'count')
            ->from(from: 'openregister_objects');

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

        // Add register to filters if provided
        if ($register !== null) {
            $filters['register'] = $register;
        }

        // Add schema to filters if provided
        if ($schema !== null) {
            $filters['schema'] = $schema;
        }

        // By default, only include objects where 'deleted' is NULL unless $includeDeleted is true.
        if ($includeDeleted === false) {
            $qb->andWhere($qb->expr()->isNull('deleted'));
        }

        // Handle filtering by IDs/UUIDs if provided.
        if ($ids !== null && empty($ids) === false) {
            $orX = $qb->expr()->orX();
            $orX->add($qb->expr()->in('id', $qb->createNamedParameter($ids, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)));
            $orX->add($qb->expr()->in('uuid', $qb->createNamedParameter($ids, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)));
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
                // Add condition for IS NOT NULL
                $qb->andWhere($qb->expr()->isNotNull($filter));
            } else if ($value === 'IS NULL' && in_array($filter, self::MAIN_FILTERS) === true) {
                // Add condition for IS NULL
                $qb->andWhere($qb->expr()->isNull($filter));
            } else if (in_array($filter, self::MAIN_FILTERS) === true) {
                if (is_array($value)) {
                    // If the value is an array, use IN to search for any of the values in the array
                    $qb->andWhere($qb->expr()->in($filter, $qb->createNamedParameter($value, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)));
                } else {
                    // Otherwise, use equality for the filter
                    $qb->andWhere($qb->expr()->eq($filter, $qb->createNamedParameter($value)));
                }
            }
        }

        // Filter and search the objects.
        $qb = $this->databaseJsonService->filterJson(builder: $qb, filters: $filters);
        $qb = $this->databaseJsonService->searchJson(builder: $qb, search: $search);

//        var_dump($qb->getSQL());

        $result = $qb->executeQuery();

        return $result->fetchAll()[0]['count'];

    }//end countAll()


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
        // Lets make sure that @self and id never enter the database.
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

        // Ensure we have a UUID
        if (empty($object['uuid'])) {
            $object['uuid'] = Uuid::v4();
        }

        $obj->hydrate(object: $object);

        // Prepare the object before insertion.
        return $this->insert($obj);

    }//end createFromArray()


    /**
     * Updates an entity in the database
     *
     * @param Entity $entity The entity to update
     *
     * @throws \OCP\DB\Exception If a database error occurs
     * @throws \OCP\AppFramework\Db\DoesNotExistException If the entity does not exist
     *
     * @return Entity The updated entity
     */
    public function update(Entity $entity): Entity
    {
        $oldObject = $this->find($entity->getId());

        // Lets make sure that @self and id never enter the database.
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
     * @throws \OCP\DB\Exception If a database error occurs
     * @throws \OCP\AppFramework\Db\DoesNotExistException If the object is not found
     *
     * @return ObjectEntity The updated object
     */
    public function updateFromArray(int $id, array $object): ObjectEntity
    {
        $oldObject = $this->find($id);
        $newObject = clone $oldObject;

        // Ensure we preserve the UUID if it exists, or create a new one if it doesn't
        if (empty($object['id']) && empty($oldObject->getUuid())) {
            $object['id'] = Uuid::v4();
        } else if (empty($object['uuid'])) {
            $object['id'] = $oldObject->getUuid();
        }

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
     * @throws \OCP\DB\Exception If a database error occurs
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
     * @param string|int  $identifier Object ID, UUID, or URI
     * @param string|null $process    Optional process identifier
     * @param int|null    $duration   Lock duration in seconds
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException If object not found
     * @throws \Exception If locking fails
     *
     * @return ObjectEntity The locked object
     */
    public function lockObject($identifier, ?string $process=null, ?int $duration=null): ObjectEntity
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
     * @param string|int $identifier Object ID, UUID, or URI
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException If object not found
     * @throws \Exception If unlocking fails
     *
     * @return ObjectEntity The unlocked object
     */
    public function unlockObject($identifier): ObjectEntity
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
     * @param string|int $identifier Object ID, UUID, or URI
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException If object not found
     *
     * @return bool True if object is locked, false otherwise
     */
    public function isObjectLocked($identifier): bool
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
            ->orWhere($qb->expr()->in('id', $qb->createNamedParameter($ids, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)))
            ->orWhere($qb->expr()->in('uuid', $qb->createNamedParameter($ids, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)))
            ->orWhere($qb->expr()->in('uri', $qb->createNamedParameter($ids, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)));

        return $this->findEntities($qb);

    }//end findMultiple()


}//end class
