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
     * @param int|string    $identifier The ID or UUID of the object to find.
     * @param Register|null $register   Optional register to filter by.
     * @param Schema|null   $schema     Optional schema to filter by.
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException If the object is not found.
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException If multiple objects are found.
     * @throws \OCP\DB\Exception If a database error occurs.
     *
     * @return ObjectEntity The ObjectEntity.
     */
    public function find(string | int $identifier, ?Register $register=null, ?Schema $schema=null): ObjectEntity
    {
        $qb = $this->db->getQueryBuilder();

        // Determine ID parameter based on whether identifier is numeric.
        $idParam = -1;
        if (is_numeric($identifier) === true) {
            $idParam = $identifier;
        }

        // Build the base query.
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

        // Add optional register filter if provided.
        if ($register !== null) {
            $qb->andWhere(
                $qb->expr()->eq('register', $qb->createNamedParameter($register->getId(), IQueryBuilder::PARAM_INT))
            );
        }

        // Add optional schema filter if provided.
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
     * @param int|null      $limit            The number of objects to return.
     * @param int|null      $offset           The offset of the objects to return.
     * @param array|null    $filters          The filters to apply to the objects.
     * @param array|null    $searchConditions The search conditions to apply to the objects.
     * @param array|null    $searchParams     The search parameters to apply to the objects.
     * @param array         $sort             The sort order to apply.
     * @param string|null   $search           The search string to apply.
     * @param array|null    $ids              Array of IDs or UUIDs to filter by.
     * @param string|null   $uses             Value that must be present in relations.
     * @param bool          $includeDeleted   Whether to include deleted objects.
     * @param Register|null $register         Optional register to filter objects.
     * @param Schema|null   $schema           Optional schema to filter objects.
     * @param bool|null     $published        If true, only return currently published objects.
     *
     * @phpstan-param int|null $limit
     * @phpstan-param int|null $offset
     * @phpstan-param array|null $filters
     * @phpstan-param array|null $searchConditions
     * @phpstan-param array|null $searchParams
     * @phpstan-param array $sort
     * @phpstan-param string|null $search
     * @phpstan-param array|null $ids
     * @phpstan-param string|null $uses
     * @phpstan-param bool $includeDeleted
     * @phpstan-param Register|null $register
     * @phpstan-param Schema|null $schema
     * @phpstan-param bool|null $published
     *
     * @psalm-param int|null $limit
     * @psalm-param int|null $offset
     * @psalm-param array|null $filters
     * @psalm-param array|null $searchConditions
     * @psalm-param array|null $searchParams
     * @psalm-param array $sort
     * @psalm-param string|null $search
     * @psalm-param array|null $ids
     * @psalm-param string|null $uses
     * @psalm-param bool $includeDeleted
     * @psalm-param Register|null $register
     * @psalm-param Schema|null $schema
     * @psalm-param bool|null $published
     *
     * @throws \OCP\DB\Exception If a database error occurs.
     *
     * @return array<int, ObjectEntity> An array of ObjectEntity objects.
     */
    public function findAll(
        ?int $limit = null,
        ?int $offset = null,
        ?array $filters = [],
        ?array $searchConditions = [],
        ?array $searchParams = [],
        ?array $sort = [],
        ?string $search = null,
        ?array $ids = null,
        ?string $uses = null,
        bool $includeDeleted = false,
        ?Register $register = null,
        ?Schema $schema = null,
        ?bool $published = false
    ): array {
        // Filter out system variables (starting with _).
        $filters = array_filter(
            $filters ?? [],
            function ($key) {
                return str_starts_with($key, '_') === false;
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

        // Add register to filters if provided.
        if ($register !== null) {
            $filters['register'] = $register;
        }

        // Add schema to filters if provided.
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

        // If published filter is set, only include objects that are currently published.
        if ($published === true) {
            $now = (new \DateTime())->format('Y-m-d H:i:s');
            // published <= now AND (depublished IS NULL OR depublished > now)
            $qb->andWhere(
                $qb->expr()->andX(
                    $qb->expr()->isNotNull('published'),
                    $qb->expr()->lte('published', $qb->createNamedParameter($now)),
                    $qb->expr()->orX(
                        $qb->expr()->isNull('depublished'),
                        $qb->expr()->gt('depublished', $qb->createNamedParameter($now))
                    )
                )
            );
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
                // Add condition for IS NOT NULL.
                $qb->andWhere($qb->expr()->isNotNull($filter));
            } else if ($value === 'IS NULL' && in_array($filter, self::MAIN_FILTERS) === true) {
                // Add condition for IS NULL.
                $qb->andWhere($qb->expr()->isNull($filter));
            } else if (in_array($filter, self::MAIN_FILTERS) === true) {
                if (is_array($value) === true) {
                    // If the value is an array, use IN to search for any of the values in the array.
                    $qb->andWhere($qb->expr()->in($filter, $qb->createNamedParameter($value, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)));
                } else {
                    // Otherwise, use equality for the filter.
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

        $sortInRoot = [];
        foreach ($sort as $key => $descOrAsc) {
            if (str_starts_with($key, '@self.')) {
                $sortInRoot = [str_replace('@self.', '', $key) => $descOrAsc];
                break;
            }
        }

        if (empty($sortInRoot) === false) {
            $qb = $this->databaseJsonService->orderInRoot(builder: $qb, order: $sortInRoot);
        } else {
            $qb = $this->databaseJsonService->orderJson(builder: $qb, order: $sort);
        }


        // var_dump($qb->getSQL());

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
        ?Schema $schema=null,
        ?bool $published=false
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

        // If published filter is set, only include objects that are currently published.
        if ($published === true) {
            $now = (new \DateTime())->format('Y-m-d H:i:s');
            // published <= now AND (depublished IS NULL OR depublished > now)
            $qb->andWhere(
                $qb->expr()->andX(
                    $qb->expr()->isNotNull('published'),
                    $qb->expr()->lte('published', $qb->createNamedParameter($now)),
                    $qb->expr()->orX(
                        $qb->expr()->isNull('depublished'),
                        $qb->expr()->gt('depublished', $qb->createNamedParameter($now))
                    )
                )
            );
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
        $entity->setSize(strlen(serialize($entity->jsonSerialize()))); // Set the size to the byte size of the serialized object

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
        $entity->setSize(strlen(serialize($entity->jsonSerialize()))); // Set the size to the byte size of the serialized object

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
        $this->eventDispatcher->dispatchTyped(
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


    /**
     * Get statistics for objects with optional filtering
     *
     * @param int|int[]|null $registerId The register ID(s) (null for all registers).
     * @param int|int[]|null $schemaId   The schema ID(s) (null for all schemas).
     * @param array          $exclude    Array of register/schema combinations to exclude, format: [['register' => id, 'schema' => id], ...].
     *
     * @phpstan-param int|array|null $registerId
     * @phpstan-param int|array|null $schemaId
     * @phpstan-param array $exclude
     *
     * @psalm-param int|array|null $registerId
     * @psalm-param int|array|null $schemaId
     * @psalm-param array $exclude
     *
     * @return array<string, int> Array containing statistics about objects:
     *               - total: Total number of objects.
     *               - size: Total size of all objects in bytes.
     *               - invalid: Number of objects with validation errors.
     *               - deleted: Number of deleted objects.
     *               - locked: Number of locked objects.
     *               - published: Number of published objects.
     */
    public function getStatistics(int|array|null $registerId = null, int|array|null $schemaId = null, array $exclude = []): array
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $now = (new \DateTime())->format('Y-m-d H:i:s');
            $qb->select(
                $qb->createFunction('COUNT(id) as total'),
                $qb->createFunction('COALESCE(SUM(size), 0) as size'),
                $qb->createFunction('COUNT(CASE WHEN validation IS NOT NULL THEN 1 END) as invalid'),
                $qb->createFunction('COUNT(CASE WHEN deleted IS NOT NULL THEN 1 END) as deleted'),
                $qb->createFunction('COUNT(CASE WHEN locked IS NOT NULL AND locked = TRUE THEN 1 END) as locked'),
                // Only count as published if published <= now and (depublished is null or depublished > now)
                $qb->createFunction(
                    "COUNT(CASE WHEN published IS NOT NULL AND published <= '".$now."' AND (depublished IS NULL OR depublished > '".$now."') THEN 1 END) as published"
                )
            )
                ->from($this->getTableName());

            // Add register filter if provided (support int or array)
            if ($registerId !== null) {
                if (is_array($registerId)) {
                    $qb->andWhere($qb->expr()->in('register', $qb->createNamedParameter($registerId, \Doctrine\DBAL\Connection::PARAM_INT_ARRAY)));
                } else {
                    $qb->andWhere($qb->expr()->eq('register', $qb->createNamedParameter($registerId, IQueryBuilder::PARAM_INT)));
                }
            }

            // Add schema filter if provided (support int or array)
            if ($schemaId !== null) {
                if (is_array($schemaId)) {
                    $qb->andWhere($qb->expr()->in('schema', $qb->createNamedParameter($schemaId, \Doctrine\DBAL\Connection::PARAM_INT_ARRAY)));
                } else {
                    $qb->andWhere($qb->expr()->eq('schema', $qb->createNamedParameter($schemaId, IQueryBuilder::PARAM_INT)));
                }
            }

            // Add exclusions if provided.
            if (empty($exclude) === false) {
                foreach ($exclude as $combination) {
                    $orConditions = $qb->expr()->orX();

                    // Handle register exclusion.
                    if (isset($combination['register']) === true) {
                        $orConditions->add($qb->expr()->isNull('register'));
                        $orConditions->add($qb->expr()->neq('register', $qb->createNamedParameter($combination['register'], IQueryBuilder::PARAM_INT)));
                    }

                    // Handle schema exclusion.
                    if (isset($combination['schema']) === true) {
                        $orConditions->add($qb->expr()->isNull('schema'));
                        $orConditions->add($qb->expr()->neq('schema', $qb->createNamedParameter($combination['schema'], IQueryBuilder::PARAM_INT)));
                    }

                    // Add the OR conditions to the main query.
                    if ($orConditions->count() > 0) {
                        $qb->andWhere($orConditions);
                    }
                }//end foreach
            }//end if

            $result = $qb->executeQuery()->fetch();

            return [
                'total'     => (int) ($result['total'] ?? 0),
                'size'      => (int) ($result['size'] ?? 0),
                'invalid'   => (int) ($result['invalid'] ?? 0),
                'deleted'   => (int) ($result['deleted'] ?? 0),
                'locked'    => (int) ($result['locked'] ?? 0),
                'published' => (int) ($result['published'] ?? 0),
            ];
        } catch (\Exception $e) {
            return [
                'total'     => 0,
                'size'      => 0,
                'invalid'   => 0,
                'deleted'   => 0,
                'locked'    => 0,
                'published' => 0,
            ];
        }//end try

    }//end getStatistics()


    /**
     * Get chart data for objects grouped by register
     *
     * @param int|null $registerId The register ID (null for all registers).
     * @param int|null $schemaId   The schema ID (null for all schemas).
     *
     * @return array Array containing chart data:
     *               - labels: Array of register names.
     *               - series: Array of object counts per register.
     */
    public function getRegisterChartData(?int $registerId=null, ?int $schemaId=null): array
    {
        try {
            $qb = $this->db->getQueryBuilder();

            // Join with registers table to get register names.
            $qb->select(
                'r.title as register_name',
                $qb->createFunction('COUNT(o.id) as count')
            )
                ->from($this->getTableName(), 'o')
                ->leftJoin('o', 'openregister_registers', 'r', 'o.register = r.id')
                ->groupBy('r.id', 'r.title')
                ->orderBy('count', 'DESC');

            // Add register filter if provided.
            if ($registerId !== null) {
                $qb->andWhere($qb->expr()->eq('o.register', $qb->createNamedParameter($registerId, IQueryBuilder::PARAM_INT)));
            }

            // Add schema filter if provided.
            if ($schemaId !== null) {
                $qb->andWhere($qb->expr()->eq('o.schema', $qb->createNamedParameter($schemaId, IQueryBuilder::PARAM_INT)));
            }

            $results = $qb->executeQuery()->fetchAll();

            return [
                'labels' => array_map(function ($row) {
                    return $row['register_name'] ?? 'Unknown';
                }, $results),
                'series' => array_map(function ($row) {
                    return (int) $row['count'];
                }, $results),
            ];
        } catch (\Exception $e) {
            return [
                'labels' => [],
                'series' => [],
            ];
        }//end try

    }//end getRegisterChartData()


    /**
     * Get chart data for objects grouped by schema
     *
     * @param int|null $registerId The register ID (null for all registers).
     * @param int|null $schemaId   The schema ID (null for all schemas).
     *
     * @return array Array containing chart data:
     *               - labels: Array of schema names.
     *               - series: Array of object counts per schema.
     */
    public function getSchemaChartData(?int $registerId=null, ?int $schemaId=null): array
    {
        try {
            $qb = $this->db->getQueryBuilder();

            // Join with schemas table to get schema names.
            $qb->select(
                's.title as schema_name',
                $qb->createFunction('COUNT(o.id) as count')
            )
                ->from($this->getTableName(), 'o')
                ->leftJoin('o', 'openregister_schemas', 's', 'o.schema = s.id')
                ->groupBy('s.id', 's.title')
                ->orderBy('count', 'DESC');

            // Add register filter if provided.
            if ($registerId !== null) {
                $qb->andWhere($qb->expr()->eq('o.register', $qb->createNamedParameter($registerId, IQueryBuilder::PARAM_INT)));
            }

            // Add schema filter if provided.
            if ($schemaId !== null) {
                $qb->andWhere($qb->expr()->eq('o.schema', $qb->createNamedParameter($schemaId, IQueryBuilder::PARAM_INT)));
            }

            $results = $qb->executeQuery()->fetchAll();

            return [
                'labels' => array_map(function ($row) {
                    return $row['schema_name'] ?? 'Unknown';
                }, $results),
                'series' => array_map(function ($row) {
                    return (int) $row['count'];
                }, $results),
            ];
        } catch (\Exception $e) {
            return [
                'labels' => [],
                'series' => [],
            ];
        }//end try

    }//end getSchemaChartData()


    /**
     * Get chart data for objects grouped by size ranges
     *
     * @param int|null $registerId The register ID (null for all registers).
     * @param int|null $schemaId   The schema ID (null for all schemas).
     *
     * @return array Array containing chart data:
     *               - labels: Array of size range labels.
     *               - series: Array of object counts per size range.
     */
    public function getSizeDistributionChartData(?int $registerId=null, ?int $schemaId=null): array
    {
        try {
            $qb = $this->db->getQueryBuilder();

            // Define size ranges in bytes.
            $ranges = [
                ['min' => 0, 'max' => 1024, 'label' => '0-1 KB'],
                ['min' => 1024, 'max' => 10240, 'label' => '1-10 KB'],
                ['min' => 10240, 'max' => 102400, 'label' => '10-100 KB'],
                ['min' => 102400, 'max' => 1048576, 'label' => '100 KB-1 MB'],
                ['min' => 1048576, 'max' => null, 'label' => '> 1 MB'],
            ];

            $results = [];
            foreach ($ranges as $range) {
                $qb = $this->db->getQueryBuilder();
                $qb->select($qb->createFunction('COUNT(*) as count'))
                    ->from($this->getTableName());

                // Add size range conditions.
                if ($range['min'] !== null) {
                    $qb->andWhere($qb->expr()->gte('size', $qb->createNamedParameter($range['min'], IQueryBuilder::PARAM_INT)));
                }
                if ($range['max'] !== null) {
                    $qb->andWhere($qb->expr()->lt('size', $qb->createNamedParameter($range['max'], IQueryBuilder::PARAM_INT)));
                }

                // Add register filter if provided.
                if ($registerId !== null) {
                    $qb->andWhere($qb->expr()->eq('register', $qb->createNamedParameter($registerId, IQueryBuilder::PARAM_INT)));
                }

                // Add schema filter if provided.
                if ($schemaId !== null) {
                    $qb->andWhere($qb->expr()->eq('schema', $qb->createNamedParameter($schemaId, IQueryBuilder::PARAM_INT)));
                }

                $count = $qb->executeQuery()->fetchOne();
                $results[] = [
                    'label' => $range['label'],
                    'count' => (int) $count,
                ];
            }//end foreach

            return [
                'labels' => array_map(function ($row) {
                    return $row['label'];
                }, $results),
                'series' => array_map(function ($row) {
                    return $row['count'];
                }, $results),
            ];
        } catch (\Exception $e) {
            return [
                'labels' => [],
                'series' => [],
            ];
        }//end try

    }//end getSizeDistributionChartData()

}//end class
