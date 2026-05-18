<?php

/**
 * Mapper for email link entities.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Class EmailLinkMapper
 *
 * @template-extends QBMapper<EmailLink>
 */
class EmailLinkMapper extends QBMapper
{

    /**
     * Cache for tableExists() result. The schema doesn't change at
     * runtime so we can memoise the check.
     *
     * @var boolean|null
     */
    private ?bool $tableExistsCache = null;

    /**
     * Constructor.
     *
     * @param IDBConnection $db Database connection.
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct(db: $db, tableName: 'openregister_email_links', entityClass: EmailLink::class);
    }//end __construct()

    /**
     * Whether the underlying link table is present in this deployment.
     *
     * Migration `Version1Date20260326100001` drops this table on systems
     * that have moved to the `_mail` metadata column architecture.
     * Methods on this mapper short-circuit (returning empty / 0 / null)
     * when the table is missing, so callers don't have to wrap every
     * lookup in a try/catch.
     *
     * @return bool True when the link table exists in this deployment.
     */
    public function tableExists(): bool
    {
        if ($this->tableExistsCache !== null) {
            return $this->tableExistsCache;
        }

        // Doctrine's createSchema() can miss tables created outside the
        // migration framework. Fall back to a direct information_schema
        // query so manual CREATEs (e.g. recovery, dev sandboxes) are
        // honoured.
        try {
            $schema = $this->db->createSchema();
            $this->tableExistsCache = $schema->hasTable('*PREFIX*'.$this->getTableName())
                || $schema->hasTable($this->getTableName());
        } catch (\Throwable $e) {
            $this->tableExistsCache = false;
        }

        if ($this->tableExistsCache === false) {
            try {
                $qb     = $this->db->getQueryBuilder();
                $qb->select($qb->func()->count('*'))
                    ->from('information_schema.tables')
                    ->where($qb->expr()->eq('table_name', $qb->createNamedParameter('oc_'.$this->getTableName())));
                $result                  = $qb->executeQuery();
                $row                     = $result->fetch();
                $this->tableExistsCache  = $row !== false && (int) reset($row) > 0;
            } catch (\Throwable $e) {
                $this->tableExistsCache = false;
            }
        }

        return $this->tableExistsCache;
    }//end tableExists()

    /**
     * Find email links by object UUID.
     *
     * @param string   $objectUuid The object UUID.
     * @param int|null $limit      Maximum results.
     * @param int|null $offset     Results offset.
     *
     * @return EmailLink[] Array of email links.
     */
    public function findByObjectUuid(string $objectUuid, ?int $limit=null, ?int $offset=null): array
    {
        // Note: tableExists() short-circuits removed — Doctrine's schema
        // cache can lag manual CREATE TABLE statements (e.g. sandboxes
        // that recreated the table outside the migration framework).
        // The query below catches a real "table missing" via the
        // try/catch wrapper at the call site (provider).
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
            ->orderBy('mail_date', 'DESC');

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        if ($offset !== null) {
            $qb->setFirstResult($offset);
        }

        return $this->findEntities(query: $qb);
    }//end findByObjectUuid()

    /**
     * Count email links for an object.
     *
     * @param string $objectUuid The object UUID.
     *
     * @return int Count of links.
     */
    public function countByObjectUuid(string $objectUuid): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*)'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)));

        $result = $qb->executeQuery();
        $count  = (int) $result->fetchOne();
        $result->closeCursor();

        return $count;
    }//end countByObjectUuid()

    /**
     * Find email links by sender address.
     *
     * @param string $sender The sender email address.
     *
     * @return EmailLink[] Array of email links.
     */
    public function findBySender(string $sender): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('sender', $qb->createNamedParameter($sender)))
            ->orderBy('mail_date', 'DESC');

        return $this->findEntities(query: $qb);
    }//end findBySender()

    /**
     * Find a specific email link by object UUID and mail message ID.
     *
     * @param string $objectUuid    The object UUID.
     * @param int    $mailMessageId The mail message ID.
     *
     * @return EmailLink|null The link or null if not found.
     */
    public function findByObjectAndMessage(string $objectUuid, int $mailMessageId): ?EmailLink
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
            ->andWhere(
                $qb->expr()->eq(
                    'mail_message_id',
                    $qb->createNamedParameter($mailMessageId, IQueryBuilder::PARAM_INT)
                )
            );

        try {
            return $this->findEntity(query: $qb);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return null;
        }
    }//end findByObjectAndMessage()

    /**
     * Delete all email links for an object UUID.
     *
     * @param string $objectUuid The object UUID.
     *
     * @return int Number of deleted rows.
     */
    public function deleteByObjectUuid(string $objectUuid): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)));

        return $qb->executeStatement();
    }//end deleteByObjectUuid()
}//end class
