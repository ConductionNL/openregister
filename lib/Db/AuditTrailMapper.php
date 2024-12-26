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
 * The AuditTrailMapper class
 *
 * @package OCA\OpenRegister\Db
 */
class AuditTrailMapper extends QBMapper
{
	private $objectEntityMapper;

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
     * @return array The audit trails
     */
	public function findAll(?int $limit = null, ?int $offset = null, ?array $filters = [], ?array $searchConditions = [], ?array $searchParams = []): array
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

		return $this->findEntities(query: $qb);
	}

    /**
     * Finds all audit trails for a given object
     *
     * @param string $idOrUuid The id or uuid of the object
     * @return array The audit trails
     */
	public function findAllUuid(string $idOrUuid, ?int $limit = null, ?int $offset = null, ?array $filters = [], ?array $searchConditions = [], ?array $searchParams = []): array
	{
		try {
			$object = $this->objectEntityMapper->find(idOrUuid: $idOrUuid);
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

	// We dont need update as we dont change the log
}
