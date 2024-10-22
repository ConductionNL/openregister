<?php

namespace OCA\OpenRegister\Db;

use OCA\OpenRegister\Db\Log;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Symfony\Component\Uid\Uuid;

class AuditTrailMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'openregister_audit_trails');
	}

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

        $changed = [];
        if ($action !== 'delete') {
            $oldArray = $old ? $old->jsonSerialize() : [];
            $newArray = $new->jsonSerialize();
            foreach ($newArray as $key => $value) {
                if (!isset($oldArray[$key]) || $oldArray[$key] !== $value) {
                    $changed[$key] = [
                        'old' => $oldArray[$key] ?? null,
                        'new' => $value
                    ];
                }
            }
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

        $user = \OC::$server->getUserSession()->getUser();

        $auditTrail	= new AuditTrail();
        $auditTrail->setUuid(Uuid::v4());
        $auditTrail->setObject($objectEntity->getId());
        $auditTrail->setAction($action);
        $auditTrail->setChanged($changed);
        $auditTrail->setUser($user->getUID());
        $auditTrail->setUserName($user->getDisplayName());
        $auditTrail->setSession(session_id());
        $auditTrail->setRequest(\OC::$server->getRequest()->getId());
        $auditTrail->setIpAddress(\OC::$server->getRequest()->getRemoteAddress());
        $auditTrail->setCreated(new \DateTime());
        $auditTrail->setRegister($objectEntity->getRegister());
        $auditTrail->setSchema($objectEntity->getSchema());

		return $this->insert(entity: $auditTrail);
    }

	// We dont need update as we dont change the log
}
