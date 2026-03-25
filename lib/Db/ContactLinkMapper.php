<?php

/**
 * Mapper for contact link entities.
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

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Class ContactLinkMapper
 *
 * @template-extends QBMapper<ContactLink>
 */
class ContactLinkMapper extends QBMapper
{
    /**
     * Constructor.
     *
     * @param IDBConnection $db Database connection.
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'openregister_contact_links', ContactLink::class);
    }//end __construct()

    /**
     * Find contact links by object UUID.
     *
     * @param string $objectUuid The object UUID.
     *
     * @return ContactLink[] Array of contact links.
     */
    public function findByObjectUuid(string $objectUuid): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
            ->orderBy('linked_at', 'DESC');

        return $this->findEntities($qb);
    }//end findByObjectUuid()

    /**
     * Find contact links by contact UID.
     *
     * @param string $contactUid The contact UID from the vCard.
     *
     * @return ContactLink[] Array of contact links.
     */
    public function findByContactUid(string $contactUid): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('contact_uid', $qb->createNamedParameter($contactUid)))
            ->orderBy('linked_at', 'DESC');

        return $this->findEntities($qb);
    }//end findByContactUid()

    /**
     * Count contact links for an object.
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
     * Delete all contact links for an object UUID.
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
