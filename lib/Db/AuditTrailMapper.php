<?php
/**
 * OpenRegister Audit Trail Mapper
 *
 * This file contains the class for handling audit trail related operations
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

use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Symfony\Component\Uid\Uuid;

/**
 * The AuditTrailMapper class handles audit trail operations and object reversions
 *
 * @package OCA\OpenRegister\Db
 */
class AuditTrailMapper extends QBMapper
{

    /**
     * The object entity mapper instance
     *
     * @var ObjectEntityMapper
     */
    private ObjectEntityMapper $objectEntityMapper;


    /**
     * Constructor for the AuditTrailMapper
     *
     * @param IDBConnection      $db                 The database connection
     * @param ObjectEntityMapper $objectEntityMapper The object entity mapper
     *
     * @return void
     */
    public function __construct(IDBConnection $db, ObjectEntityMapper $objectEntityMapper)
    {
        parent::__construct($db, 'openregister_audit_trails');
        $this->objectEntityMapper = $objectEntityMapper;

    }//end __construct()


    /**
     * Finds an audit trail by id
     *
     * @param int $id The id of the audit trail
     *
     * @return Log The audit trail
     */
    public function find(int $id): Log
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from('openregister_audit_trails')
            ->where(
                $qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT))
            );

        return $this->findEntity(query: $qb);

    }//end find()


    /**
     * Find all audit trails with filters and sorting
     *
     * @param int|null    $limit   The limit of the results
     * @param int|null    $offset  The offset of the results
     * @param array|null  $filters The filters to apply
     * @param array|null  $sort    The sort to apply
     * @param string|null $search  Optional search term to filter by ext fields
     *
     * @return array The audit trails
     */
    public function findAll(
        ?int $limit=null,
        ?int $offset=null,
        ?array $filters=[],
        ?array $sort=['created' => 'DESC'],
        ?string $search=null
    ): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from('openregister_audit_trails');

        // Filter out system variables (starting with _).
        $filters = array_filter(
            $filters ?? [],
            function ($key) {
                return !str_starts_with($key, '_');
            },
            ARRAY_FILTER_USE_KEY
        );

        // Apply filters.
        foreach ($filters as $field => $value) {
            // Ensure the field is a valid column name.
            if (!in_array(
                    $field,
                    [
                        'id',
                        'uuid',
                        'schema',
                        'register',
                        'object',
                        'action',
                        'changed',
                        'user',
                        'user_name',
                        'session',
                        'request',
                        'ip_address',
                        'version',
                        'created',
                    ]
                    )
            ) {
                continue;
            }

            if ($value === 'IS NOT NULL') {
                $qb->andWhere($qb->expr()->isNotNull($field));
            } else if ($value === 'IS NULL') {
                $qb->andWhere($qb->expr()->isNull($field));
            } else {
                $qb->andWhere($qb->expr()->eq($field, $qb->createNamedParameter($value)));
            }
        }//end foreach

        // Add search on changed field if search term provided.
        if ($search !== null) {
            $qb->andWhere(
                $qb->expr()->like('changed', $qb->createNamedParameter('%'.$search.'%'))
            );
        }

        // Add sorting.
        foreach ($sort as $field => $direction) {
            // Ensure the field is a valid column name.
            if (!in_array(
                    $field,
                    [
                        'id',
                        'uuid',
                        'schema',
                        'register',
                        'object',
                        'action',
                        'changed',
                        'user',
                        'user_name',
                        'session',
                        'request',
                        'ip_address',
                        'version',
                        'created',
                    ]
                    )
            ) {
                continue;
            }

            $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
            $qb->addOrderBy($field, $direction);
        }//end foreach.

        // Apply pagination.
        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        if ($offset !== null) {
            $qb->setFirstResult($offset);
        }

        return $this->findEntities($qb);

    }//end findAll()


    /**
     * Finds all audit trails for a given object
     *
     * @param string     $identifier       The id or uuid of the object
     * @param int|null   $limit            The limit of the results
     * @param int|null   $offset           The offset of the results
     * @param array|null $filters          The filters to apply
     * @param array|null $searchConditions The search conditions to apply
     * @param array|null $searchParams     The search parameters to apply
     *
     * @return array The audit trails
     */
    public function findAllUuid(
        string $identifier,
        ?int $limit=null,
        ?int $offset=null,
        ?array $filters=[],
        ?array $searchConditions=[],
        ?array $searchParams=[]
    ): array {
        try {
            $object            = $this->objectEntityMapper->find(identifier: $identifier);
            $objectId          = $object->getId();
            $filters['object'] = $objectId;
            return $this->findAll($limit, $offset, $filters);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            // Object not found.
            return [];
        }

    }//end findAllUuid()


    /**
     * Creates an audit trail from an array
     *
     * @param array $object The object to create the audit trail from
     *
     * @return Log The created audit trail
     */
    public function createFromArray(array $object): Log
    {
        $log = new Log();
        $log->hydrate(object: $object);

        // Set uuid if not provided.
        if ($log->getUuid() === null) {
            $log->setUuid(Uuid::v4());
        }
        $log->setSize(strlen(serialize( $object))); // Set the size to the byte size of the serialized object

        return $this->insert(entity: $log);

    }//end createFromArray()


    /**
     * Creates an audit trail for object changes
     *
     * @param ObjectEntity|null $old The old state of the object
     * @param ObjectEntity|null $new The new state of the object
     *
     * @return AuditTrail The created audit trail
     */
    public function createAuditTrail(?ObjectEntity $old=null, ?ObjectEntity $new=null): AuditTrail
    {
        // Determine the action based on the presence of old and new objects.
        $action = 'update';
        if ($new === null) {
            $action       = 'delete';
            $objectEntity = $old;
        } else if ($old === null) {
            $action       = 'create';
            $objectEntity = $new;
        } else {
            $objectEntity = $new;
        }

        // Initialize an array to store changed fields.
        $changed = [];
        if ($action !== 'delete') {
            if ($old !== null) {
                $oldArray = $old->jsonSerialize();
            } else {
                $oldArray = [];
            }

            $newArray = $new->jsonSerialize();

            // Compare old and new values to detect changes.
            foreach ($newArray as $key => $value) {
                if ((isset($oldArray[$key]) === false) || ($oldArray[$key] !== $value)) {
                    $changed[$key] = [
                        'old' => ($oldArray[$key] ?? null),
                        'new' => $value,
                    ];
                }
            }

            // For updates, check for removed fields.
            if ($action === 'update') {
                foreach ($oldArray as $key => $value) {
                    if (isset($newArray[$key]) === false) {
                        $changed[$key] = [
                            'old' => $value,
                            'new' => null,
                        ];
                    }
                }
            }
        }//end if

        // Get the current user.
        $user = \OC::$server->getUserSession()->getUser();

        // Create and populate a new AuditTrail object.
        $auditTrail = new AuditTrail();
        $auditTrail->setUuid(Uuid::v4());
        // $auditTrail->setObject($objectEntity->getId()); @todo change migration!!
        $auditTrail->setObject($objectEntity->getId());
        $auditTrail->setAction($action);
        $auditTrail->setChanged($changed);

        if ($user !== null) {
            $auditTrail->setUser($user->getUID());
            $auditTrail->setUserName($user->getDisplayName());
        } else {
            $auditTrail->setUser('System');
            $auditTrail->setUserName('System');
        }

        $auditTrail->setSession(session_id());
        $auditTrail->setRequest(\OC::$server->getRequest()->getId());
        $auditTrail->setIpAddress(\OC::$server->getRequest()->getRemoteAddress());
        $auditTrail->setCreated(new \DateTime());
        $auditTrail->setRegister($objectEntity->getRegister());
        $auditTrail->setSchema($objectEntity->getSchema());
        $auditTrail->setSize(strlen(serialize($objectEntity->jsonSerialize()))); // Set the size to the byte size of the serialized object

        // Insert the new AuditTrail into the database and return it.
        return $this->insert(entity: $auditTrail);

    }//end createAuditTrail()


    /**
     * Get audit trails for an object until a specific point or version
     *
     * @param int                  $objectId   The object ID
     * @param string               $objectUuid The object UUID
     * @param DateTime|string|null $until      DateTime, AuditTrail ID, or semantic version to get trails until
     *
     * @return array Array of AuditTrail objects
     */
    public function findByObjectUntil(int $objectId, string $objectUuid, $until=null): array
    {
        $qb = $this->db->getQueryBuilder();

        // Base query.
        $qb->select('*')
            ->from('openregister_audit_trails')
            ->where(
                $qb->expr()->eq('object_id', $qb->createNamedParameter($objectId, IQueryBuilder::PARAM_INT))
            )
            ->andWhere(
                $qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid, IQueryBuilder::PARAM_STR))
            )
            ->orderBy('created', 'DESC');

        // Add condition based on until parameter.
        if ($until instanceof \DateTime) {
            $qb->andWhere(
                $qb->expr()->gte(
                    'created',
                    $qb->createNamedParameter(
                        $until->format('Y-m-d H:i:s'),
                        IQueryBuilder::PARAM_STR
                    )
                )
            );
        } else if (is_string($until) === true) {
            if ($this->isSemanticVersion($until) === true) {
                // Handle semantic version.
                $qb->andWhere(
                    $qb->expr()->eq('version', $qb->createNamedParameter($until, IQueryBuilder::PARAM_STR))
                );
            } else {
                // Handle audit trail ID.
                $qb->andWhere(
                    $qb->expr()->eq('id', $qb->createNamedParameter($until, IQueryBuilder::PARAM_STR))
                );
                // We want all entries up to and including this ID.
                $qb->orWhere(
                    $qb->expr()->gt(
                        'created',
                        $qb->createFunction(
                            sprintf(
                                '(SELECT created FROM `*PREFIX*openregister_audit_trails` WHERE id = %s)',
                                $qb->createNamedParameter($until, IQueryBuilder::PARAM_STR)
                            )
                        )
                    )
                );
            }//end if
        }//end if

        return $this->findEntities($qb);

    }//end findByObjectUntil()


    /**
     * Check if a string is a semantic version
     *
     * @param string $version The version string to check
     *
     * @return bool True if string is a semantic version
     */
    private function isSemanticVersion(string $version): bool
    {
        return (preg_match('/^\d+\.\d+\.\d+$/', $version) === 1);

    }//end isSemanticVersion()


    /**
     * Revert an object to a previous state
     *
     * @param string|int           $identifier       Object ID, UUID, or URI
     * @param DateTime|string|null $until            DateTime or AuditTrail ID to revert to
     * @param bool                 $overwriteVersion Whether to overwrite the version or increment it
     *
     * @throws DoesNotExistException If object not found
     * @throws \Exception If revert fails
     *
     * @return ObjectEntity The reverted object (unsaved)
     */
    public function revertObject($identifier, $until=null, bool $overwriteVersion=false): ObjectEntity
    {
        // Get the current object.
        $object = $this->objectEntityMapper->find($identifier);

        // Get audit trail entries until the specified point.
        $auditTrails = $this->findByObjectUntil(
            $object->getId(),
            $object->getUuid(),
            $until
        );

        if (empty($auditTrails) === true && $until !== null) {
            throw new \Exception('No audit trail entries found for the specified reversion point.');
        }

        // Create a clone of the current object to apply reversions.
        $revertedObject = clone $object;

        // Apply changes in reverse.
        foreach ($auditTrails as $audit) {
            $this->revertChanges($revertedObject, $audit);
        }

        // Handle versioning.
        if ($overwriteVersion === false) {
            $version    = explode('.', $revertedObject->getVersion());
            $version[2] = ((int) $version[2] + 1);
            $revertedObject->setVersion(implode('.', $version));
        }

        return $revertedObject;

    }//end revertObject()


    /**
     * Helper function to revert changes from an audit trail entry
     *
     * @param ObjectEntity $object The object to apply reversions to
     * @param AuditTrail   $audit  The audit trail entry
     *
     * @return void
     */
    private function revertChanges(ObjectEntity $object, AuditTrail $audit): void
    {
        $changes = $audit->getChanges();

        // Iterate through each change and apply the reverse.
        foreach ($changes as $field => $change) {
            if (isset($change['old']) === true) {
                // Use reflection to set the value if it's a protected property.
                $reflection = new \ReflectionClass($object);
                $property   = $reflection->getProperty($field);
                $property->setAccessible(true);
                $property->setValue($object, $change['old']);
            }
        }

    }//end revertChanges()


    /**
     * Get statistics for audit trails with optional filtering
     *
     * @param int|null $registerId The register ID (null for all registers)
     * @param int|null $schemaId   The schema ID (null for all schemas)
     * @param array    $exclude    Array of register/schema combinations to exclude, format: [['register' => id, 'schema' => id], ...]
     *
     * @return array Array containing total count and size of audit trails:
     *               - total: Total number of audit trails
     *               - size: Total size of all audit trails in bytes
     */
    public function getStatistics(?int $registerId = null, ?int $schemaId = null, array $exclude = []): array
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select(
                $qb->createFunction('COUNT(id) as total'),
                $qb->createFunction('COALESCE(SUM(size), 0) as size')
            )
                ->from($this->getTableName());

            // Add register filter if provided
            if ($registerId !== null) {
                $qb->andWhere($qb->expr()->eq('register', $qb->createNamedParameter($registerId, IQueryBuilder::PARAM_INT)));
            }

            // Add schema filter if provided
            if ($schemaId !== null) {
                $qb->andWhere($qb->expr()->eq('schema', $qb->createNamedParameter($schemaId, IQueryBuilder::PARAM_INT)));
            }

            // Add exclusions if provided
            if (!empty($exclude)) {
                foreach ($exclude as $combination) {
                    $orConditions = $qb->expr()->orX();
                    
                    // Handle register exclusion
                    if (isset($combination['register'])) {
                        $orConditions->add($qb->expr()->isNull('register'));
                        $orConditions->add($qb->expr()->neq('register', $qb->createNamedParameter($combination['register'], IQueryBuilder::PARAM_INT)));
                    }
                    
                    // Handle schema exclusion
                    if (isset($combination['schema'])) {
                        $orConditions->add($qb->expr()->isNull('schema'));
                        $orConditions->add($qb->expr()->neq('schema', $qb->createNamedParameter($combination['schema'], IQueryBuilder::PARAM_INT)));
                    }
                    
                    // Add the OR conditions to the main query
                    if ($orConditions->count() > 0) {
                        $qb->andWhere($orConditions);
                    }
                }
            }

            $result = $qb->executeQuery()->fetch();

            return [
                'total' => (int) ($result['total'] ?? 0),
                'size' => (int) ($result['size'] ?? 0)
            ];
        } catch (\Exception $e) {
            return [
                'total' => 0,
                'size' => 0
            ];
        }
    }


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
        // Recalculate size before update
        $entity->setSize(strlen(serialize($entity->jsonSerialize()))); // Set the size to the byte size of the serialized object

        return parent::update($entity);
    }


    /**
     * Get chart data for audit trail actions over time
     *
     * @param \DateTime|null $from      Start date for the chart data
     * @param \DateTime|null $till      End date for the chart data
     * @param int|null      $registerId Optional register ID to filter by
     * @param int|null      $schemaId   Optional schema ID to filter by
     *
     * @return array Array containing chart data:
     *               - labels: Array of dates
     *               - series: Array of series data, each containing:
     *                 - name: Action name (create, update, delete)
     *                 - data: Array of counts for each date
     */
    public function getActionChartData(?\DateTime $from = null, ?\DateTime $till = null, ?int $registerId = null, ?int $schemaId = null): array
    {
        try {
            $qb = $this->db->getQueryBuilder();

            // Main query for orphaned audit trails
            $qb->select(
                $qb->createFunction('DATE(created) as date'),
                'action',
                $qb->createFunction('COUNT(*) as count')
            )
                ->from($this->getTableName())
                ->groupBy('date', 'action')
                ->orderBy('date', 'ASC');

            // Add date range filters if provided
            if ($from !== null) {
                $qb->andWhere($qb->expr()->gte('created', $qb->createNamedParameter($from->format('Y-m-d'), IQueryBuilder::PARAM_STR)));
            }
            if ($till !== null) {
                $qb->andWhere($qb->expr()->lte('created', $qb->createNamedParameter($till->format('Y-m-d'), IQueryBuilder::PARAM_STR)));
            }

            // Add register filter if provided
            if ($registerId !== null) {
                $qb->andWhere($qb->expr()->eq('register', $qb->createNamedParameter($registerId, IQueryBuilder::PARAM_INT)));
            }

            // Add schema filter if provided
            if ($schemaId !== null) {
                $qb->andWhere($qb->expr()->eq('schema', $qb->createNamedParameter($schemaId, IQueryBuilder::PARAM_INT)));
            }

            $results = $qb->executeQuery()->fetchAll();

            // Process results into chart format
            $dateData = [];
            $actions = ['create', 'update', 'delete'];
            
            // Initialize data structure
            foreach ($results as $row) {
                $date = $row['date'];
                if (!isset($dateData[$date])) {
                    $dateData[$date] = array_fill_keys($actions, 0);
                }
                $dateData[$date][$row['action']] = (int)$row['count'];
            }

            // Sort dates and ensure all dates in range are included
            ksort($dateData);

            // Prepare series data
            $series = [];
            foreach ($actions as $action) {
                $series[] = [
                    'name' => ucfirst($action),
                    'data' => array_values(array_map(function($data) use ($action) {
                        return $data[$action];
                    }, $dateData))
                ];
            }

            return [
                'labels' => array_keys($dateData),
                'series' => $series
            ];
        } catch (\Exception $e) {
            return [
                'labels' => [],
                'series' => []
            ];
        }
    }

}//end class
