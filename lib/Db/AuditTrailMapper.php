<?php
/**
 * OpenRegister Audit Trail Mapper
 *
 * This file contains the class for handling audit trail related operations
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
     * @param int|null    $limit            The limit of the results
     * @param int|null    $offset           The offset of the results
     * @param array|null  $filters          The filters to apply
     * @param array|null  $searchConditions The search conditions to apply
     * @param array|null  $searchParams     The search parameters to apply
     * @param array|null  $sort             The sort to apply
     * @param string|null $search           Optional search term to filter by ext fields
     *
     * @return array The audit trails
     */
    public function findAll(
        ?int $limit = NULL,
        ?int $offset = NULL,
        ?array $filters = [],
        ?array $searchConditions = [],
        ?array $searchParams = [],
        ?array $sort = [],
        ?string $search = NULL
    ): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from('openregister_audit_trails')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        foreach ($filters as $filter => $value) {
            if ($value === 'IS NOT NULL') {
                $qb->andWhere($qb->expr()->isNotNull($filter));
            } elseif ($value === 'IS NULL') {
                $qb->andWhere($qb->expr()->isNull($filter));
            } else {
                $qb->andWhere($qb->expr()->eq($filter, $qb->createNamedParameter($value)));
            }
        }

        if (empty($searchConditions) === FALSE) {
            $qb->andWhere('('.implode(' OR ', $searchConditions).')');
            foreach ($searchParams as $param => $value) {
                $qb->setParameter($param, $value);
            }
        }

        // Add search on ext fields if search term provided.
        if ($search !== NULL) {
            $qb->andWhere(
                $qb->expr()->like('ext', $qb->createNamedParameter('%'.$search.'%'))
            );
        }

        // Add sorting if specified.
        if (empty($sort) === FALSE) {
            foreach ($sort as $field => $direction) {
                if (strtoupper($direction) === 'DESC') {
                    $direction = 'DESC';
                } else {
                    $direction = 'ASC';
                }

                $qb->addOrderBy($field, $direction);
            }
        }

        return $this->findEntities(query: $qb);

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
        ?int $limit = NULL,
        ?int $offset = NULL,
        ?array $filters = [],
        ?array $searchConditions = [],
        ?array $searchParams = []
    ): array {
        try {
            $object = $this->objectEntityMapper->find(identifier: $identifier);
            $objectId = $object->getId();
            $filters['object'] = $objectId;
            return $this->findAll($limit, $offset, $filters, $searchConditions, $searchParams);
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
        if ($log->getUuid() === NULL) {
            $log->setUuid(Uuid::v4());
        }

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
    public function createAuditTrail(?ObjectEntity $old = NULL, ?ObjectEntity $new = NULL): AuditTrail
    {
        // Determine the action based on the presence of old and new objects.
        $action = 'update';
        if ($new === NULL) {
            $action = 'delete';
            $objectEntity = $old;
        } elseif ($old === NULL) {
            $action = 'create';
            $objectEntity = $new;
        } else {
            $objectEntity = $new;
        }

        // Initialize an array to store changed fields.
        $changed = [];
        if ($action !== 'delete') {
            if ($old !== NULL) {
                $oldArray = $old->jsonSerialize();
            } else {
                $oldArray = [];
            }

            $newArray = $new->jsonSerialize();

            // Compare old and new values to detect changes.
            foreach ($newArray as $key => $value) {
                if ((isset($oldArray[$key]) === FALSE) || ($oldArray[$key] !== $value)) {
                    $changed[$key] = [
                        'old' => ($oldArray[$key] ?? NULL),
                        'new' => $value,
                    ];
                }
            }

            // For updates, check for removed fields.
            if ($action === 'update') {
                foreach ($oldArray as $key => $value) {
                    if (isset($newArray[$key]) === FALSE) {
                        $changed[$key] = [
                            'old' => $value,
                            'new' => NULL,
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

        if ($user !== NULL) {
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
    public function findByObjectUntil(int $objectId, string $objectUuid, $until = NULL): array
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
        } elseif (is_string($until) === TRUE) {
            if ($this->isSemanticVersion($until) === TRUE) {
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
     * @return ObjectEntity The reverted object (unsaved)
     * @throws DoesNotExistException If object not found
     * @throws \Exception If revert fails
     */
    public function revertObject($identifier, $until = NULL, bool $overwriteVersion = FALSE): ObjectEntity
    {
        // Get the current object.
        $object = $this->objectEntityMapper->find($identifier);

        // Get audit trail entries until the specified point.
        $auditTrails = $this->findByObjectUntil(
            $object->getId(),
            $object->getUuid(),
            $until
        );

        if (empty($auditTrails) === TRUE && $until !== NULL) {
            throw new \Exception('No audit trail entries found for the specified reversion point.');
        }

        // Create a clone of the current object to apply reversions.
        $revertedObject = clone $object;

        // Apply changes in reverse.
        foreach ($auditTrails as $audit) {
            $this->revertChanges($revertedObject, $audit);
        }

        // Handle versioning.
        if ($overwriteVersion === FALSE) {
            $version = explode('.', $revertedObject->getVersion());
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
            if (isset($change['old']) === TRUE) {
                // Use reflection to set the value if it's a protected property.
                $reflection = new \ReflectionClass($object);
                $property = $reflection->getProperty($field);
                $property->setAccessible(TRUE);
                $property->setValue($object, $change['old']);
            }
        }

    }//end revertChanges()

    // We dont need update as we dont change the log.
}//end class
