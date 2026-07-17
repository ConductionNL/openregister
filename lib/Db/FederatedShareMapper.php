<?php
/**
 * OpenRegister FederatedShareMapper.
 *
 * Persistence for {@see FederatedShare} rows in `openregister_federated_shares`.
 * Organisation scoping is applied via {@see MultiTenancyTrait} so a share is
 * only listed for the organisation that owns it; the cross-instance ACCESS
 * decision itself is enforced separately by the scoped share token at the
 * federation serving endpoint.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use Symfony\Component\Uid\Uuid;

/**
 * The FederatedShareMapper class.
 *
 * @template-extends QBMapper<FederatedShare>
 */
class FederatedShareMapper extends QBMapper
{
    use MultiTenancyTrait;

    /**
     * Organisation mapper (required by MultiTenancyTrait).
     *
     * @var OrganisationMapper
     */
    protected OrganisationMapper $organisationMapper;

    /**
     * User session (required by MultiTenancyTrait).
     *
     * @var IUserSession
     */
    protected IUserSession $userSession;

    /**
     * Group manager (required by MultiTenancyTrait).
     *
     * @var IGroupManager
     */
    protected IGroupManager $groupManager;

    /**
     * App configuration (required by MultiTenancyTrait).
     *
     * @var IAppConfig
     */
    protected IAppConfig $appConfig;

    /**
     * Constructor.
     *
     * @param IDBConnection      $db                 Database connection.
     * @param OrganisationMapper $organisationMapper Organisation mapper.
     * @param IUserSession       $userSession        User session.
     * @param IGroupManager      $groupManager       Group manager.
     * @param IAppConfig         $appConfig          App configuration.
     */
    public function __construct(
        IDBConnection $db,
        OrganisationMapper $organisationMapper,
        IUserSession $userSession,
        IGroupManager $groupManager,
        IAppConfig $appConfig
    ) {
        parent::__construct(db: $db, tableName: 'openregister_federated_shares', entityClass: FederatedShare::class);
        $this->organisationMapper = $organisationMapper;
        $this->userSession        = $userSession;
        $this->groupManager       = $groupManager;
        $this->appConfig          = $appConfig;
    }//end __construct()

    /**
     * Find a federated share by database id.
     *
     * @param int $id The share id.
     *
     * @return FederatedShare The share.
     */
    public function find(int $id): FederatedShare
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from('openregister_federated_shares')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        $this->applyOrganisationFilter(qb: $qb);

        return $this->findEntity(query: $qb);
    }//end find()

    /**
     * Find a federated share by its scoped share token.
     *
     * Used by the federation serving endpoint to resolve an incoming request;
     * the organisation filter is intentionally NOT applied because the token
     * itself is the access credential (the caller is a remote instance, not a
     * local session).
     *
     * @param string $shareToken The scoped bearer token.
     *
     * @return FederatedShare The share.
     */
    public function findByToken(string $shareToken): FederatedShare
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from('openregister_federated_shares')
            ->where($qb->expr()->eq('share_token', $qb->createNamedParameter($shareToken)));

        return $this->findEntity(query: $qb);
    }//end findByToken()

    /**
     * Find an existing outgoing object-scope share for a uri + target, or null.
     *
     * Used by the federate-share flow action to stay idempotent (one share per
     * object per target). Bypasses the organisation filter — the flow that
     * created the share owns the dedup decision, not the acting session.
     *
     * @param string $objectUri  The shared object's uri/uuid.
     * @param string $sharedWith The federated target (slug@host).
     *
     * @return FederatedShare|null The existing share, or null.
     */
    public function findOutgoingObjectShare(string $objectUri, string $sharedWith): ?FederatedShare
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from('openregister_federated_shares')
            ->where($qb->expr()->eq('direction', $qb->createNamedParameter('outgoing')))
            ->andWhere($qb->expr()->eq('scope', $qb->createNamedParameter('object')))
            ->andWhere($qb->expr()->eq('object_uri', $qb->createNamedParameter($objectUri)))
            ->andWhere($qb->expr()->eq('shared_with', $qb->createNamedParameter($sharedWith)))
            ->setMaxResults(1);

        try {
            return $this->findEntity(query: $qb);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return null;
        }
    }//end findOutgoingObjectShare()

    /**
     * Find all federated shares, optionally filtered.
     *
     * @param int|null                  $limit   Maximum number of results.
     * @param int|null                  $offset  Result offset.
     * @param array<string, mixed>|null $filters Column => value equality filters.
     *
     * @return FederatedShare[] The matching shares.
     *
     * @psalm-return list<FederatedShare>
     */
    public function findAll(?int $limit=null, ?int $offset=null, ?array $filters=[]): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from('openregister_federated_shares')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        foreach ($filters ?? [] as $column => $value) {
            $qb->andWhere($qb->expr()->eq($column, $qb->createNamedParameter($value)));
        }

        $this->applyOrganisationFilter(qb: $qb);

        return $this->findEntities(query: $qb);
    }//end findAll()

    /**
     * Insert a new federated share, stamping uuid/timestamps/organisation.
     *
     * @param Entity $entity The share to insert.
     *
     * @return FederatedShare The inserted share.
     *
     * @SuppressWarnings(PHPMD.StaticAccess) Uuid::v4 is the standard Symfony UID pattern.
     */
    public function insert(Entity $entity): FederatedShare
    {
        if ($entity instanceof FederatedShare) {
            if (empty($entity->getUuid()) === true) {
                $entity->setUuid((string) Uuid::v4());
            }

            $entity->setCreated(new DateTime());
            $entity->setUpdated(new DateTime());
        }

        $this->setOrganisationOnCreate(entity: $entity);

        return parent::insert(entity: $entity);
    }//end insert()

    /**
     * Update an existing federated share, refreshing the updated timestamp.
     *
     * @param Entity $entity The share to update.
     *
     * @return FederatedShare The updated share.
     */
    public function update(Entity $entity): FederatedShare
    {
        if ($entity instanceof FederatedShare) {
            $entity->setUpdated(new DateTime());
        }

        return parent::update(entity: $entity);
    }//end update()

    /**
     * Create a share from an array of data.
     *
     * @param array<string, mixed> $data The share data.
     *
     * @return FederatedShare The created share.
     */
    public function createFromArray(array $data): FederatedShare
    {
        $share = new FederatedShare();
        $share->hydrate(object: $data);

        return $this->insert(entity: $share);
    }//end createFromArray()

    /**
     * Update a share from an array of data.
     *
     * @param int                  $id   The share id.
     * @param array<string, mixed> $data The updated share data.
     *
     * @return FederatedShare The updated share.
     */
    public function updateFromArray(int $id, array $data): FederatedShare
    {
        $share = $this->find(id: $id);
        $share->hydrate(object: $data);

        return $this->update(entity: $share);
    }//end updateFromArray()
}//end class
