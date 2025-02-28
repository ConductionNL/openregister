<?php

namespace OCA\OpenRegister\Db;

use OCA\OpenRegister\Db\Log;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Symfony\Component\Uid\Uuid;
use OCA\OpenRegister\Db\ObjectEntityMapper;

/**
 * The AuditTrailMapper class handles audit trail operations and object reversions
 *
 * @package OCA\OpenRegister\Db
 */
class AuditTrailMapper extends QBMapper
{
	private ObjectEntityMapper $objectEntityMapper;

    /**
     * Constructor for the AuditTrailMapper
     *
     * @param IDBConnection $db The database connection
     * @param ObjectEntityMapper $objectEntityMapper The object entity mapper
     */
	public function __construct(IDBConnection $db, ObjectEntityMapper $objectEntityMapper)
	{
		parent::__construct($db, 'openregister_audit_trails');
		$this->objectEntityMapper = $objectEntityMapper;
	}

    /**
     * Finds an audit trail by id
     *
     * @param int $id The id of the audit trail
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
	}

    /**
     * Finds all audit trails
     *
     * @param int|null $limit The limit of the results
     * @param int|null $offset The offset of the results
     * @param array|null $filters The filters to apply
     * @param array|null $searchConditions The search conditions to apply
     * @param array|null $searchParams The search parameters to apply
     * @param array|null $sort The sort parameters to apply
     * @param string|null $search Optional search term to filter by ext fields
     * @return array The audit trails
     */
	public function findAll(?int $limit = null, ?int $offset = null, ?array $filters = [], ?array $searchConditions = [], ?array $searchParams = [], ?array $sort = [], ?string $search = null): array
	{
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

        if (!empty($searchConditions)) {
            $qb->andWhere('(' . implode(' OR ', $searchConditions) . ')');
            foreach ($searchParams as $param => $value) {
                $qb->setParameter($param, $value);
            }
        }

        // Add search on ext fields if search term provided
        if ($search !== null) {
            $qb->andWhere(
                $qb->expr()->like('ext', $qb->createNamedParameter('%' . $search . '%'))
            );
        }

        // Add sorting if specified
        if (!empty($sort)) {
            foreach ($sort as $field => $direction) {
                $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
                $qb->addOrderBy($field, $direction);
            }
        }

		return $this->findEntities(query: $qb);
	}

    /**
     * Finds all audit trails for a given object
     *
     * @param string $identifier The id or uuid of the object
     * @return array The audit trails
     */
	public function findAllUuid(string $identifier, ?int $limit = null, ?int $offset = null, ?array $filters = [], ?array $searchConditions = [], ?array $searchParams = []): array
	{
		try {
			$object = $this->objectEntityMapper->find(identifier: $identifier);
			$objectId = $object->getId();
			$filters['object'] = $objectId;
			return $this->findAll($limit, $offset, $filters, $searchConditions, $searchParams);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			return [];
		}
	}

    /**
     * Creates an audit trail from an array
     *
     * @param array $object The object to create the audit trail from
     * @return Log The created audit trail
     */
	public function createFromArray(array $object): Log
	{
		$log = new Log();
		$log->hydrate(object: $object);

		// Set uuid if not provided
		if ($log->getUuid() === null) {
			$log->setUuid(Uuid::v4());
		}

		return $this->insert(entity: $log);
	}

    /**
     * Creates an audit trail for object changes
     *
     * @param ObjectEntity|null $old The old state of the object
     * @param ObjectEntity|null $new The new state of the object
     * @return AuditTrail The created audit trail
     */
    public function createAuditTrail(?ObjectEntity $old = null, ?ObjectEntity $new = null): AuditTrail
    {
        // Determine the action based on the presence of old and new objects
        $action = 'update';
        if ($new === null) {
            $action = 'delete';
            $objectEntity = $old;
        } elseif ($old === null) {
            $action = 'create';
            $objectEntity = $new;
        } else {
            $objectEntity = $new;
        }

        // Initialize an array to store changed fields
        $changed = [];
        if ($action !== 'delete') {
            $oldArray = $old ? $old->jsonSerialize() : [];
            $newArray = $new->jsonSerialize();

            // Compare old and new values to detect changes
            foreach ($newArray as $key => $value) {
                if (!isset($oldArray[$key]) || $oldArray[$key] !== $value) {
                    $changed[$key] = [
                        'old' => $oldArray[$key] ?? null,
                        'new' => $value
                    ];
                }
            }

            // For updates, check for removed fields
            if ($action === 'update') {
                foreach ($oldArray as $key => $value) {
                    if (!isset($newArray[$key])) {
                        $changed[$key] = [
                            'old' => $value,
                            'new' => null
                        ];
                    }
                }
            }
        }

        // Get the current user
        $user = \OC::$server->getUserSession()->getUser();

        // Create and populate a new AuditTrail object
        $auditTrail	= new AuditTrail();
        $auditTrail->setUuid(Uuid::v4());
        $auditTrail->setObject($objectEntity->getId());
        $auditTrail->setAction($action);
        $auditTrail->setChanged($changed);
        $auditTrail->setUser(($user !== null) ? $user->getUID() : 'System');
        $auditTrail->setUserName(($user !== null) ? $user->getDisplayName() : 'System');
        $auditTrail->setSession(session_id());
        $auditTrail->setRequest(\OC::$server->getRequest()->getId());
        $auditTrail->setIpAddress(\OC::$server->getRequest()->getRemoteAddress());
        $auditTrail->setCreated(new \DateTime());
        $auditTrail->setRegister($objectEntity->getRegister());
        $auditTrail->setSchema($objectEntity->getSchema());

        // Insert the new AuditTrail into the database and return it
		return $this->insert(entity: $auditTrail);
    }

	/**
	 * Get audit trails for an object until a specific point or version
	 *
	 * @param int $objectId The object ID
	 * @param string $objectUuid The object UUID
	 * @param DateTime|string|null $until DateTime, AuditTrail ID, or semantic version to get trails until
	 * @return array Array of AuditTrail objects
	 */
	public function findByObjectUntil(int $objectId, string $objectUuid, $until = null): array
	{
		$qb = $this->db->getQueryBuilder();
		
		// Base query
		$qb->select('*')
			->from('openregister_audit_trails')
			->where(
				$qb->expr()->eq('object_id', $qb->createNamedParameter($objectId, IQueryBuilder::PARAM_INT))
			)
			->andWhere(
				$qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid, IQueryBuilder::PARAM_STR))
			)
			->orderBy('created', 'DESC');

		// Add condition based on until parameter
		if ($until instanceof \DateTime) {
			$qb->andWhere(
				$qb->expr()->gte('created', $qb->createNamedParameter(
					$until->format('Y-m-d H:i:s'),
					IQueryBuilder::PARAM_STR
				))
			);
		} elseif (is_string($until)) {
			if ($this->isSemanticVersion($until)) {
				// Handle semantic version
				$qb->andWhere(
					$qb->expr()->eq('version', $qb->createNamedParameter($until, IQueryBuilder::PARAM_STR))
				);
			} else {
				// Handle audit trail ID
				$qb->andWhere(
					$qb->expr()->eq('id', $qb->createNamedParameter($until, IQueryBuilder::PARAM_STR))
				);
				// We want all entries up to and including this ID
				$qb->orWhere(
					$qb->expr()->gt('created', 
						$qb->createFunction('(SELECT created FROM `*PREFIX*openregister_audit_trails` WHERE id = ' . 
							$qb->createNamedParameter($until, IQueryBuilder::PARAM_STR) . ')')
					)
				);
			}
		}

		return $this->findEntities($qb);
	}

	/**
	 * Check if a string is a semantic version
	 *
	 * @param string $version The version string to check
	 * @return bool True if string is a semantic version
	 */
	private function isSemanticVersion(string $version): bool
	{
		return preg_match('/^\d+\.\d+\.\d+$/', $version) === 1;
	}

    /**
     * Revert an object to a previous state
     *
     * @param string|int $identifier Object ID, UUID, or URI
     * @param DateTime|string|null $until DateTime or AuditTrail ID to revert to
     * @param bool $overwriteVersion Whether to overwrite the version or increment it
     * @return ObjectEntity The reverted object (unsaved)
     * @throws DoesNotExistException If object not found
     * @throws \Exception If revert fails
     */
    public function revertObject($identifier, $until = null, bool $overwriteVersion = false): ObjectEntity 
    {
        // Get the current object
        $object = $this->objectEntityMapper->find($identifier);
        
        // Get audit trail entries until the specified point
        $auditTrails = $this->findByObjectUntil(
            $object->getId(),
            $object->getUuid(),
            $until
        );

        if (empty($auditTrails) && $until !== null) {
            throw new \Exception('No audit trail entries found for the specified reversion point');
        }

        // Create a clone of the current object to apply reversions
        $revertedObject = clone $object;

        // Apply changes in reverse
        foreach ($auditTrails as $audit) {
            $this->revertChanges($revertedObject, $audit);
        }

        // Handle versioning
        if (!$overwriteVersion) {
            $version = explode('.', $revertedObject->getVersion());
            $version[2] = (int) $version[2] + 1;
            $revertedObject->setVersion(implode('.', $version));
        }

        return $revertedObject;
    }

    /**
     * Helper function to revert changes from an audit trail entry
     *
     * @param ObjectEntity $object The object to apply reversions to
     * @param AuditTrail $audit The audit trail entry
     */
    private function revertChanges(ObjectEntity $object, AuditTrail $audit): void
    {
        $changes = $audit->getChanges();
        
        // Iterate through each change and apply the reverse
        foreach ($changes as $field => $change) {
            if (isset($change['old'])) {
                // Use reflection to set the value if it's a protected property
                $reflection = new \ReflectionClass($object);
                $property = $reflection->getProperty($field);
                $property->setAccessible(true);
                $property->setValue($object, $change['old']);
            }
        }
    }

	// We dont need update as we dont change the log
}
