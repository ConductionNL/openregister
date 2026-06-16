<?php

/**
 * Mapper for PushSubscription entities (Web Push endpoints).
 *
 * Owner-scoped by surface: every read/write/delete is keyed by the userId
 * supplied by the controller (the current session user), never by a wire id —
 * so there is no IDOR path. Backed by `openregister_push_subscriptions`.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://www.OpenRegister.app
 *
 * @spec openspec/changes/openregister-web-push-engine/specs/web-push-delivery/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * Class PushSubscriptionMapper
 *
 * @template-extends QBMapper<PushSubscription>
 */
class PushSubscriptionMapper extends QBMapper
{
    /**
     * Constructor.
     *
     * @param IDBConnection $db Database connection.
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct(db: $db, tableName: 'openregister_push_subscriptions', entityClass: PushSubscription::class);
    }//end __construct()

    /**
     * Find every push subscription owned by a user.
     *
     * @param string $userId The owning Nextcloud user id.
     *
     * @return PushSubscription[] The user's subscriptions.
     *
     * @spec openspec/changes/openregister-web-push-engine/specs/web-push-delivery/spec.md
     */
    public function findByUser(string $userId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        return $this->findEntities(query: $qb);
    }//end findByUser()

    /**
     * Find a single subscription by owner + endpoint, or null when absent.
     *
     * @param string $userId   The owning user id.
     * @param string $endpoint The push endpoint URL.
     *
     * @return PushSubscription|null The matching subscription, or null.
     *
     * @spec openspec/changes/openregister-web-push-engine/specs/web-push-delivery/spec.md
     */
    public function findByUserAndEndpoint(string $userId, string $endpoint): ?PushSubscription
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('endpoint', $qb->createNamedParameter($endpoint)))
            ->setMaxResults(1);

        $rows = $this->findEntities(query: $qb);
        if (count($rows) === 0) {
            return null;
        }

        return $rows[0];
    }//end findByUserAndEndpoint()

    /**
     * Store (upsert) a subscription for a user + endpoint.
     *
     * Re-subscribing the same endpoint refreshes the keys rather than
     * creating a duplicate row.
     *
     * @param string $userId    The owning user id.
     * @param string $endpoint  The push endpoint URL.
     * @param string $p256dh    The client P-256 public key.
     * @param string $auth      The client auth secret.
     * @param string $userAgent The browser user agent (diagnostics).
     *
     * @return PushSubscription The persisted subscription.
     *
     * @spec openspec/changes/openregister-web-push-engine/specs/web-push-delivery/spec.md
     */
    public function store(string $userId, string $endpoint, string $p256dh, string $auth, string $userAgent): PushSubscription
    {
        $existing = $this->findByUserAndEndpoint(userId: $userId, endpoint: $endpoint);
        if ($existing !== null) {
            $existing->setP256dh($p256dh);
            $existing->setAuth($auth);
            $existing->setUserAgent($userAgent);
            return $this->update(entity: $existing);
        }

        $entity = new PushSubscription();
        $entity->setUserId($userId);
        $entity->setEndpoint($endpoint);
        $entity->setP256dh($p256dh);
        $entity->setAuth($auth);
        $entity->setUserAgent($userAgent);
        $entity->setCreatedAt(new DateTime());

        return $this->insert(entity: $entity);
    }//end store()

    /**
     * Delete a user's subscription for a specific endpoint.
     *
     * @param string $userId   The owning user id.
     * @param string $endpoint The push endpoint URL.
     *
     * @return int Number of deleted rows (0 or 1).
     *
     * @spec openspec/changes/openregister-web-push-engine/specs/web-push-delivery/spec.md
     */
    public function deleteByUserAndEndpoint(string $userId, string $endpoint): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('endpoint', $qb->createNamedParameter($endpoint)));

        return $qb->executeStatement();
    }//end deleteByUserAndEndpoint()

    /**
     * Delete a subscription by its endpoint only (used when a push service
     * returns 404/410 Gone — the endpoint is dead regardless of owner).
     *
     * @param string $endpoint The push endpoint URL.
     *
     * @return int Number of deleted rows.
     *
     * @spec openspec/changes/openregister-web-push-engine/specs/web-push-delivery/spec.md
     */
    public function deleteByEndpoint(string $endpoint): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('endpoint', $qb->createNamedParameter($endpoint)));

        return $qb->executeStatement();
    }//end deleteByEndpoint()
}//end class
