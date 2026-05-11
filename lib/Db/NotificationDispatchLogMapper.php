<?php

/**
 * Mapper for NotificationDispatchLog entities.
 *
 * Provides the idempotency-key deduplication API used by
 * AnnotationNotificationDispatcher. The core operation is
 * `isDuplicate()` — it returns true when a row for
 * (notification_slug, idempotency_key) already exists within the
 * configured retention window, allowing the dispatcher to skip
 * the actual send and record a `deduplicated` history row instead.
 *
 * Row cleanup (`pruneExpired()`) is best-effort: called lazily at
 * dispatch time and can also be invoked from a background job.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Class NotificationDispatchLogMapper.
 *
 * @method NotificationDispatchLog insert(Entity $entity)
 * @method NotificationDispatchLog update(Entity $entity)
 * @method NotificationDispatchLog delete(Entity $entity)
 *
 * @template-extends QBMapper<NotificationDispatchLog>
 *
 * @psalm-suppress PossiblyUnusedMethod
 */
class NotificationDispatchLogMapper extends QBMapper
{

    /**
     * Default deduplication window in seconds (24 hours).
     */
    public const DEFAULT_WINDOW_SECONDS = 86400;

    /**
     * Constructor.
     *
     * @param IDBConnection $db Database connection.
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct(
            db: $db,
            tableName: 'openregister_notif_dispatch_log',
            entityClass: NotificationDispatchLog::class
        );

    }//end __construct()

    /**
     * Check whether a (slug, key) pair was already dispatched within the window.
     *
     * Returns true when a log row for the pair exists whose `dispatched_at`
     * timestamp is no older than `$windowSeconds` from now. A match means
     * the current dispatch is a duplicate and should be skipped.
     *
     * @param string $notificationSlug The notification annotation key.
     * @param string $idempotencyKey   The resolved idempotency key.
     * @param int    $windowSeconds    Retention window. Default 86400 (24 h).
     *
     * @return bool True when a duplicate exists within the window.
     */
    public function isDuplicate(
        string $notificationSlug,
        string $idempotencyKey,
        int $windowSeconds=self::DEFAULT_WINDOW_SECONDS
    ): bool {
        $cutoff = new DateTime();
        $cutoff->modify(sprintf('-%d seconds', $windowSeconds));

        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*)'))
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq(
                    'notification_slug',
                    $qb->createNamedParameter($notificationSlug)
                )
            )
            ->andWhere(
                $qb->expr()->eq(
                    'idempotency_key',
                    $qb->createNamedParameter($idempotencyKey)
                )
            )
            ->andWhere(
                $qb->expr()->gte(
                    'dispatched_at',
                    $qb->createNamedParameter(
                        $cutoff->format('Y-m-d H:i:s'),
                        IQueryBuilder::PARAM_STR
                    )
                )
            );

        $result = $qb->executeQuery();
        $count  = (int) $result->fetchOne();
        $result->closeCursor();

        return $count > 0;

    }//end isDuplicate()

    /**
     * Record a new dispatch for the given (slug, key) pair.
     *
     * Should be called after the actual notification send succeeds so that
     * a failed emit does not poison the dedup log.
     *
     * @param string $notificationSlug The notification annotation key.
     * @param string $idempotencyKey   The resolved idempotency key.
     *
     * @return NotificationDispatchLog The persisted entity.
     */
    public function record(string $notificationSlug, string $idempotencyKey): NotificationDispatchLog
    {
        $entity = new NotificationDispatchLog();
        $entity->setNotificationSlug($notificationSlug);
        $entity->setIdempotencyKey($idempotencyKey);
        $entity->setDispatchedAt(new DateTime());

        return $this->insert(entity: $entity);

    }//end record()

    /**
     * Delete rows whose `dispatched_at` is older than `$windowSeconds`.
     *
     * Called lazily before each `isDuplicate()` check to prevent
     * unbounded table growth without requiring a separate cron job.
     * Best-effort: any DB error is silently swallowed so a prune
     * failure never blocks the dispatch path.
     *
     * @param int $windowSeconds Retention window. Default 86400 (24 h).
     *
     * @return int Number of rows deleted (0 on error).
     */
    public function pruneExpired(int $windowSeconds=self::DEFAULT_WINDOW_SECONDS): int
    {
        $cutoff = new DateTime();
        $cutoff->modify(sprintf('-%d seconds', $windowSeconds));

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->delete($this->getTableName())
                ->where(
                    $qb->expr()->lt(
                        'dispatched_at',
                        $qb->createNamedParameter(
                            $cutoff->format('Y-m-d H:i:s'),
                            IQueryBuilder::PARAM_STR
                        )
                    )
                );
            return (int) $qb->executeStatement();
        } catch (\Throwable) {
            return 0;
        }

    }//end pruneExpired()
}//end class
