<?php
/**
 * OpenRegister Source Mapper
 *
 * This file contains the class for handling source mapper related operations
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

use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use Symfony\Component\Uid\Uuid;

/**
 * The SourceMapper class
 *
 * Mapper for Source entities with multi-tenancy and RBAC support.
 *
 * @package OCA\OpenRegister\Db
 */
class SourceMapper extends QBMapper
{
    use MultiTenancyTrait;

    /**
     * Organisation service for multi-tenancy
     *
     * @var OrganisationService
     */
    private OrganisationService $organisationService;

    /**
     * User session for current user
     *
     * @var IUserSession
     */
    private IUserSession $userSession;

    /**
     * Group manager for RBAC
     *
     * @var IGroupManager
     */
    private IGroupManager $groupManager;


    /**
     * Constructor for the SourceMapper
     *
     * @param IDBConnection       $db                  The database connection
     * @param OrganisationService $organisationService Organisation service for multi-tenancy
     * @param IUserSession        $userSession         User session
     * @param IGroupManager       $groupManager        Group manager for RBAC
     */
    public function __construct(
        IDBConnection $db,
        OrganisationService $organisationService,
        IUserSession $userSession,
        IGroupManager $groupManager
    ) {
        parent::__construct($db, 'openregister_sources');
        $this->organisationService = $organisationService;
        $this->userSession         = $userSession;
        $this->groupManager        = $groupManager;

    }//end __construct()


    /**
     * Finds a source by id
     *
     * @param int $id The id of the source
     *
     * @return Source The source
     * @throws \Exception If user doesn't have read permission
     */
    public function find(int $id): Source
    {
        // Verify RBAC permission to read
        $this->verifyRbacPermission('read', 'source');

        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from('openregister_sources')
            ->where(
                $qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT))
            );

        // Apply organisation filter (all users including admins must have active org)
        $this->applyOrganisationFilter($qb);

        return $this->findEntity(query: $qb);

    }//end find()


    /**
     * Finds all sources
     *
     * @param int|null   $limit            The limit of the results
     * @param int|null   $offset           The offset of the results
     * @param array|null $filters          The filters to apply
     * @param array|null $searchConditions The search conditions to apply
     * @param array|null $searchParams     The search parameters to apply
     *
     * @return array The sources
     * @throws \Exception If user doesn't have read permission
     */
    public function findAll(
        ?int $limit=null,
        ?int $offset=null,
        ?array $filters=[],
        ?array $searchConditions=[],
        ?array $searchParams=[]
    ): array {
        // Verify RBAC permission to read
        $this->verifyRbacPermission('read', 'source');

        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from('openregister_sources')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        foreach ($filters as $filter => $value) {
            if ($value === 'IS NOT NULL') {
                $qb->andWhere($qb->expr()->isNotNull($filter));
            } else if ($value === 'IS NULL') {
                $qb->andWhere($qb->expr()->isNull($filter));
            } else {
                $qb->andWhere($qb->expr()->eq($filter, $qb->createNamedParameter($value)));
            }
        }

        if (empty($searchConditions) === false) {
            $qb->andWhere('('.implode(' OR ', $searchConditions).')');
            foreach ($searchParams as $param => $value) {
                $qb->setParameter($param, $value);
            }
        }

        // Apply organisation filter (all users including admins must have active org)
        $this->applyOrganisationFilter($qb);

        return $this->findEntities(query: $qb);

    }//end findAll()


    /**
     * Insert a new source
     *
     * @param Entity $entity Source entity to insert
     *
     * @return Entity The inserted source
     * @throws \Exception If user doesn't have create permission
     */
    public function insert(Entity $entity): Entity
    {
        // Verify RBAC permission to create
        $this->verifyRbacPermission('create', 'source');

        if ($entity instanceof Source) {
            // Generate UUID if not set
            if (empty($entity->getUuid())) {
                $entity->setUuid(Uuid::v4());
            }

            $entity->setCreated(new \DateTime());
            $entity->setUpdated(new \DateTime());
        }

        // Auto-set organisation from active session
        $this->setOrganisationOnCreate($entity);

        return parent::insert($entity);

    }//end insert()


    /**
     * Update an existing source
     *
     * @param Entity $entity Source entity to update
     *
     * @return Entity The updated source
     * @throws \Exception If user doesn't have update permission or access to this organisation
     */
    public function update(Entity $entity): Entity
    {
        // Verify RBAC permission to update
        $this->verifyRbacPermission('update', 'source');

        // Verify user has access to this organisation
        $this->verifyOrganisationAccess($entity);

        if ($entity instanceof Source) {
            $entity->setUpdated(new \DateTime());
        }

        return parent::update($entity);

    }//end update()


    /**
     * Delete a source
     *
     * @param Entity $entity Source entity to delete
     *
     * @return Entity The deleted source
     * @throws \Exception If user doesn't have delete permission or access to this organisation
     */
    public function delete(Entity $entity): Entity
    {
        // Verify RBAC permission to delete
        $this->verifyRbacPermission('delete', 'source');

        // Verify user has access to this organisation
        $this->verifyOrganisationAccess($entity);

        return parent::delete($entity);

    }//end delete()


    /**
     * Creates a source from an array
     *
     * @param array $object The object to create
     *
     * @return Source The created source
     */
    public function createFromArray(array $object): Source
    {
        $source = new Source();
        $source->hydrate(object: $object);

        // Set uuid if not provided.
        if ($source->getUuid() === null) {
            $source->setUuid(Uuid::v4());
        }

        return $this->insert(entity: $source);

    }//end createFromArray()


    /**
     * Updates a source from an array
     *
     * @param int   $id     The id of the source to update
     * @param array $object The object to update
     *
     * @return Source The updated source
     */
    public function updateFromArray(int $id, array $object): Source
    {
        $obj = $this->find($id);
        $obj->hydrate($object);

        // Set or update the version.
        if (isset($object['version']) === false) {
            $version    = explode('.', $obj->getVersion());
            $version[2] = ((int) $version[2] + 1);
            $obj->setVersion(implode('.', $version));
        }

        return $this->update($obj);

    }//end updateFromArray()


}//end class
