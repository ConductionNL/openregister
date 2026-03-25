<?php

/**
 * Mapper for email links.
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
 * Provides database access for the openregister_email_links table.
 *
 * @method EmailLink insert(Entity $entity)
 * @method EmailLink update(Entity $entity)
 * @method EmailLink delete(Entity $entity)
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
        parent::__construct(
            db: $db,
            tableName: 'openregister_email_links',
            entityClass: EmailLink::class
        );
    }//end __construct()

    /**
     * Find email links by mail account ID and message ID.
     *
     * @param int $accountId The mail account ID.
     * @param int $messageId The mail message ID.
     *
     * @return EmailLink[] Array of email links.
     */
    public function findByAccountAndMessage(int $accountId, int $messageId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq(
                    'mail_account_id',
                    $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)
                )
            )
            ->andWhere(
                $qb->expr()->eq(
                    'mail_message_id',
                    $qb->createNamedParameter($messageId, IQueryBuilder::PARAM_INT)
                )
            );

        return $this->findEntities(query: $qb);
    }//end findByAccountAndMessage()

    /**
     * Find email links by sender email address.
     *
     * Returns raw rows with object_uuid, register_id, schema_id and email count.
     *
     * @param string $sender The sender email address.
     *
     * @return array<int, array<string, mixed>> Array of grouped results.
     */
    public function findBySender(string $sender): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('object_uuid', 'register_id', 'schema_id')
            ->selectAlias(
                $qb->createFunction('COUNT(*)'),
                'linked_email_count'
            )
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq(
                    'sender',
                    $qb->createNamedParameter($sender)
                )
            )
            ->groupBy('object_uuid', 'register_id', 'schema_id')
            ->orderBy('linked_email_count', 'DESC');

        $result = $qb->executeQuery();
        $rows   = $result->fetchAll();
        $result->closeCursor();

        return $rows;
    }//end findBySender()

    /**
     * Find an existing link between a specific email and object.
     *
     * @param int    $accountId  The mail account ID.
     * @param int    $messageId  The mail message ID.
     * @param string $objectUuid The object UUID.
     *
     * @return EmailLink|null The email link or null if not found.
     */
    public function findExistingLink(
        int $accountId,
        int $messageId,
        string $objectUuid
    ): ?EmailLink {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq(
                    'mail_account_id',
                    $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)
                )
            )
            ->andWhere(
                $qb->expr()->eq(
                    'mail_message_id',
                    $qb->createNamedParameter($messageId, IQueryBuilder::PARAM_INT)
                )
            )
            ->andWhere(
                $qb->expr()->eq(
                    'object_uuid',
                    $qb->createNamedParameter($objectUuid)
                )
            )
            ->setMaxResults(1);

        $entities = $this->findEntities(query: $qb);

        return $entities[0] ?? null;
    }//end findExistingLink()

    /**
     * Find an email link by its ID.
     *
     * @param int $id The link ID.
     *
     * @return EmailLink The email link entity.
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException If not found.
     */
    public function findById(int $id): EmailLink
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq(
                    'id',
                    $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)
                )
            );

        return $this->findEntity(query: $qb);
    }//end findById()
}//end class
