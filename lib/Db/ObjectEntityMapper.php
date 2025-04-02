<?php
/**
 * OpenRegister Object Entity Mapper
 *
 * This file contains the class for handling object entity mapper related operations
 * in the OpenRegister application.
 *
 * @category  Database
 * @package   OCA\OpenRegister\Db
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
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

        if ($db->getDatabasePlatform() instanceof MySQLPlatform === TRUE) {
            $this->databaseJsonService = $mySQLJsonService;
        }

        $this->eventDispatcher = $eventDispatcher;
        $this->userSession = $userSession;

    }//end __construct()

    /**
     * Find an object by ID or UUID
     *
     * @param int|string $identifier The ID or UUID of the object to find
     *
     * @return ObjectEntity The ObjectEntity
     * @throws \OCP\AppFramework\Db\DoesNotExistException If the object is not found
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException If multiple objects are found
     * @throws \OCP\DB\Exception If a database error occurs
     */
    public function find(string | int $identifier): ObjectEntity
    {
        $qb = $this->db->getQueryBuilder();

        // Determine ID parameter based on whether identifier is numeric.
        $idParam = -1;
        if (is_numeric($identifier) === TRUE) {
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

        return $this->findEntity($qb);

    }//end find()

    /**
     * Find an object by UUID
     *
     * @param Register $register The register to search in
     * @param Schema   $schema   The schema to search in
     * @param string   $uuid     The UUID of the object to find
     *
     * @return ObjectEntity|null The object if found, null otherwise
     * @throws \OCP\DB\Exception If a database error occurs
     */
    public function findByUuid(Register $register, Schema $schema, string $uuid): ObjectEntity | NULL
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from('openregister_objects')
            ->where(
                $qb->expr()->eq('uuid', $qb->createNamedParameter($uuid))
            )
            ->andWhere(
                $qb->expr()->eq('register', $qb->createNamedParameter($register->getId()))
            )
            ->andWhere(
                $qb->expr()->eq('schema', $qb->createNamedParameter($schema->getId()))
            );

        try {
            return $this->findEntity($qb);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return NULL;
        }

    }//end findByUuid()

    /**
     * Find an object by UUID only
     *
     * @param string $uuid The UUID of the object to find
     *
     * @return ObjectEntity|null The object if found, null otherwise
     * @throws \OCP\DB\Exception If a database error occurs
     */
    public function findByUuidOnly(string $uuid): ObjectEntity | NULL
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from('openregister_objects')
            ->where(
                $qb->expr()->eq('uuid', $qb->createNamedParameter($uuid))
            );

        try {
            return $this->findEntity($qb);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return NULL;
        }

    }//end findByUuidOnly()

    /**
     * Find objects by register and schema
     *
     * @param string $register The register to find objects for
     * @param string $schema   The schema to find objects for
     *
     * @return array An array of ObjectEntity objects
     * @throws \OCP\DB\Exception If a database error occurs
     */
    public function findByRegisterAndSchema(string $register, string $schema): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from('openregister_objects')
            ->where(
                $qb->expr()->eq('register', $qb->createNamedParameter($register))
            )
            ->andWhere(
                $qb->expr()->eq('schema', $qb->createNamedParameter($schema))
            );

        return $this->findEntities(query: $qb);

    }//end findByRegisterAndSchema()

    /**
     * Counts all objects
     *
     * @param array|null  $filters        The filters to apply
     * @param string|null $search         The search string to apply     *
     * @param bool        $includeDeleted Whether to include deleted objects
     *
     * @return int The number of objects
     */
    public function countAll(?array $filters = [], ?string $search = NULL, bool $includeDeleted = FALSE): int
    {
        $qb = $this->db->getQueryBuilder();

        $qb->selectAlias(select: $qb->createFunction(call: 'count(id)'), alias: 'count')
            ->from(from: 'openregister_objects');

        // Conditionally count objects based on $includeDeleted.
        if ($includeDeleted === FALSE) {
            $qb->andWhere($qb->expr()->isNull('deleted'));
        }

        foreach ($filters as $filter => $value) {
            if ($value === 'IS NOT NULL' && in_array($filter, self::MAIN_FILTERS) === TRUE) {
                $qb->andWhere($qb->expr()->isNotNull($filter));
            } elseif ($value === 'IS NULL' && in_array($filter, self::MAIN_FILTERS) === TRUE) {
                $qb->andWhere($qb->expr()->isNull($filter));
            } elseif (in_array($filter, self::MAIN_FILTERS) === TRUE) {
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
     * @param int|null    $limit            The number of objects to return
     * @param int|null    $offset           The offset of the objects to return
     * @param array|null  $filters          The filters to apply to the objects
     * @param array|null  $searchConditions The search conditions to apply to the objects
     * @param array|null  $searchParams     The search parameters to apply to the objects
     * @param array       $sort             The sort order to apply
     * @param string|null $search           The search string to apply
     * @param array|null  $ids              Array of IDs or UUIDs to filter by
     * @param string|null $uses             Value that must be present in relations
     * @param bool        $includeDeleted   Whether to include deleted objects
     *
     * @return array An array of ObjectEntity objects
     * @throws \OCP\DB\Exception If a database error occurs
     */
    public function findAll(
        ?int $limit = NULL,
        ?int $offset = NULL,
        ?array $filters = [],
        ?array $searchConditions = [],
        ?array $searchParams = [],
        array $sort = [],
        ?string $search = NULL,
        ?array $ids = NULL,
        ?string $uses = NULL,
        bool $includeDeleted = FALSE
    ): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from('openregister_objects')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        // By default, only include objects where 'deleted' is NULL unless $includeDeleted is true.
        if ($includeDeleted === FALSE) {
            $qb->andWhere($qb->expr()->isNull('deleted'));
        }

        // Handle filtering by IDs/UUIDs if provided.
        if ($ids !== NULL && empty($ids) === FALSE) {
            $orX = $qb->expr()->orX();
            $orX->add($qb->expr()->in('id', $qb->createNamedParameter($ids, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)));
            $orX->add($qb->expr()->in('uuid', $qb->createNamedParameter($ids, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)));
            $qb->andWhere($orX);
        }

        // Handle filtering by uses in relations if provided.
        if ($uses !== NULL) {
            $qb->andWhere(
                $qb->expr()->isNotNull(
                    $qb->createFunction(
                        "JSON_SEARCH(relations, 'one', ".$qb->createNamedParameter($uses).", NULL, '$')"
                    )
                )
            );
        }

        foreach ($filters as $filter => $value) {
            if ($value === 'IS NOT NULL' && in_array($filter, self::MAIN_FILTERS) === TRUE) {
                $qb->andWhere($qb->expr()->isNotNull($filter));
            } elseif ($value === 'IS NULL' && in_array($filter, self::MAIN_FILTERS) === TRUE) {
                $qb->andWhere($qb->expr()->isNull($filter));
            } elseif (in_array($filter, self::MAIN_FILTERS) === TRUE) {
                $qb->andWhere($qb->expr()->eq($filter, $qb->createNamedParameter($value)));
            }
        }

        if (empty($searchConditions) === FALSE) {
            $qb->andWhere('('.implode(' OR ', $searchConditions).')');
            foreach ($searchParams as $param => $value) {
                $qb->setParameter($param, $value);
            }
        }

        // @todo: tidy this code up please and make it mongodb compatible.
        // Check if _relations filter exists to search in relations column.
        if (isset($filters['_relations']) === TRUE) {
            // Handle both single string and array of relations.
            $relations = (array) $filters['_relations'];

            // Build OR conditions for each relation.
            $orConditions = [];
            foreach ($relations as $relation) {
                $orConditions[] = $qb->expr()->isNotNull(
                    $qb->createFunction(
                        "JSON_SEARCH(relations, 'one', ".$qb->createNamedParameter($relation).", NULL, '$')"
                    )
                );
            }

            // Add the combined OR conditions to query.
            $qb->andWhere($qb->expr()->orX(...$orConditions));

            // Remove _relations from filters since it's handled separately.
            unset($filters['_relations']);
        }//end if

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
     * @return Entity The inserted entity.
     * @throws \OCP\DB\Exception If a database error occurs.
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
     * @return ObjectEntity The created object
     * @throws \OCP\DB\Exception If a database error occurs
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
     * @throws \OCP\DB\Exception If a database error occurs
     * @throws \OCP\AppFramework\Db\DoesNotExistException If the entity does not exist
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
     * @return ObjectEntity The updated object
     * @throws \OCP\DB\Exception If a database error occurs
     * @throws \OCP\AppFramework\Db\DoesNotExistException If the object is not found
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
     * @return ObjectEntity The deleted object
     * @throws \OCP\DB\Exception If a database error occurs
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
     * @throws \OCP\DB\Exception If a database error occurs
     */
    public function getFacets(array $filters = [], ?string $search = NULL): array
    {
        $register = NULL;
        $schema = NULL;

        if (array_key_exists('register', $filters) === TRUE) {
            $register = $filters['register'];
        }

        if (array_key_exists('schema', $filters) === TRUE) {
            $schema = $filters['schema'];
        }

        $fields = [];
        if (isset($filters['_queries']) === TRUE) {
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
     * @return array An array of ObjectEntities that have the specified URI/UUID
     * @throws \OCP\DB\Exception If a database error occurs
     */
    public function findByRelationUri(string $search, bool $partialMatch = FALSE): array
    {
        $qb = $this->db->getQueryBuilder();

        // For partial matches, we use '%' wildcards and 'all' mode to search anywhere in the JSON.
        // For exact matches, we use 'one' mode which finds exact string matches.
        $mode = 'one';
        $searchTerm = $search;

        if ($partialMatch === TRUE) {
            $mode = 'all';
            $searchTerm = '%'.$search.'%';
        }

        $searchFunction = "JSON_SEARCH(relations, '".$mode."', ".$qb->createNamedParameter($searchTerm);
        if ($partialMatch === TRUE) {
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

    }//end findByRelationUri()

    /**
     * Lock an object
     *
     * @param string|int  $identifier Object ID, UUID, or URI
     * @param string|null $process    Optional process identifier
     * @param int|null    $duration   Lock duration in seconds
     *
     * @return ObjectEntity The locked object
     * @throws \OCP\AppFramework\Db\DoesNotExistException If object not found
     * @throws \Exception If locking fails
     */
    public function lockObject($identifier, ?string $process = NULL, ?int $duration = NULL): ObjectEntity
    {
        $object = $this->find($identifier);

        if ($duration === NULL) {
            $duration = $this::DEFAULT_LOCK_DURATION;
        }

        // Check if user has permission to lock.
        if ($this->userSession->isLoggedIn() === FALSE) {
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
     * @return ObjectEntity The unlocked object
     * @throws \OCP\AppFramework\Db\DoesNotExistException If object not found
     * @throws \Exception If unlocking fails
     */
    public function unlockObject($identifier): ObjectEntity
    {
        $object = $this->find($identifier);

        // Check if user has permission to unlock.
        if ($this->userSession->isLoggedIn() === FALSE) {
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
     * @return bool True if object is locked, false otherwise
     * @throws \OCP\AppFramework\Db\DoesNotExistException If object not found
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
     * @return array An array of ObjectEntity objects
     * @throws \OCP\DB\Exception If a database error occurs
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
