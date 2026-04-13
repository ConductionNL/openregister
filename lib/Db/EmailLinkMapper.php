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
     * Constructor.
     *
     * @param IDBConnection $db Database connection.
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct(db: $db, tableName: 'openregister_email_links', entityClass: EmailLink::class);
    }//end __construct()

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
            ->andWhere($qb->expr()->eq('mail_message_id', $qb->createNamedParameter($mailMessageId, IQueryBuilder::PARAM_INT)));

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
