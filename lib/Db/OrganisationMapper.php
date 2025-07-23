<?php
/**
 * OpenRegister Organisation Mapper
 *
 * This file contains the class for the Organisation mapper.
 * Handles database operations for Organisation entities including
 * multi-tenancy user-organisation relationships.
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

use OCP\AppFramework\Db\QBMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\IDBConnection;
use OCP\DB\QueryBuilder\IQueryBuilder;


/**
 * OrganisationMapper
 * 
 * Database mapper for Organisation entities with multi-tenancy support.
 * Manages CRUD operations and user-organisation relationships.
 * 
 * @package OCA\OpenRegister\Db
 */
class OrganisationMapper extends QBMapper
{
    /**
     * OrganisationMapper constructor
     * 
     * @param IDBConnection $db Database connection
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'openregister_organisations', Organisation::class);
    }

    /**
     * Find organisation by UUID
     * 
     * @param string $uuid The organisation UUID
     * 
     * @return Organisation The organisation entity
     * 
     * @throws DoesNotExistException If organisation not found
     * @throws MultipleObjectsReturnedException If multiple organisations found
     */
    public function findByUuid(string $uuid): Organisation
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)));

        return $this->findEntity($qb);
    }

    /**
     * Find all organisations for a specific user
     * 
     * @param string $userId The Nextcloud user ID
     * 
     * @return array Array of Organisation entities
     */
    public function findByUserId(string $userId): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->like('users', $qb->createNamedParameter('%"' . $userId . '"%')));

        return $this->findEntities($qb);
    }

    /**
     * Get all organisations with user count
     * 
     * @return array Array of organisations with additional user count information
     */
    public function findAllWithUserCount(): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
           ->from($this->getTableName())
           ->orderBy('name', 'ASC');

        $organisations = $this->findEntities($qb);
        
        // Add user count to each organisation
        foreach ($organisations as &$organisation) {
            $organisation->userCount = count($organisation->getUserIds());
        }

        return $organisations;
    }

    /**
     * Insert or update organisation with UUID generation
     * 
     * @param Organisation $organisation The organisation to save
     * 
     * @return Organisation The saved organisation
     */
    public function save(Organisation $organisation): Organisation
    {
        // Generate UUID if not present
        if ($organisation->getUuid() === null) {
            $organisation->setUuid(bin2hex(random_bytes(16)));
        }

        // Set timestamps
        $now = new \DateTime();
        if ($organisation->getId() === null) {
            $organisation->setCreated($now);
        }
        $organisation->setUpdated($now);

        if ($organisation->getId() === null) {
            return $this->insert($organisation);
        } else {
            return $this->update($organisation);
        }
    }

    /**
     * Find organisations by name (case-insensitive search)
     * 
     * @param string $name Organisation name to search for
     * 
     * @return array Array of matching organisations
     */
    public function findByName(string $name): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->like('name', $qb->createNamedParameter('%' . $name . '%')))
           ->orderBy('name', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Get organisation statistics
     * 
     * @return array Statistics about organisations
     */
    public function getStatistics(): array
    {
        $qb = $this->db->getQueryBuilder();

        // Total organisations
        $qb->select($qb->createFunction('COUNT(*) as total'))
           ->from($this->getTableName());
        $result = $qb->execute();
        $total = (int) $result->fetchColumn();
        $result->closeCursor();

        return [
            'total' => $total
        ];
    }

    /**
     * Remove user from all organisations
     * 
     * @param string $userId The user ID to remove
     * 
     * @return int Number of organisations updated
     */
    public function removeUserFromAll(string $userId): int
    {
        $organisations = $this->findByUserId($userId);
        $updated = 0;

        foreach ($organisations as $organisation) {
            $organisation->removeUser($userId);
            $this->update($organisation);
            $updated++;
        }

        return $updated;
    }

    /**
     * Add user to organisation by UUID
     * 
     * @param string $organisationUuid The organisation UUID
     * @param string $userId The user ID to add
     * 
     * @return Organisation The updated organisation
     * 
     * @throws DoesNotExistException If organisation not found
     */
    public function addUserToOrganisation(string $organisationUuid, string $userId): Organisation
    {
        $organisation = $this->findByUuid($organisationUuid);
        $organisation->addUser($userId);
        return $this->update($organisation);
    }

    /**
     * Remove user from organisation by UUID
     * 
     * @param string $organisationUuid The organisation UUID
     * @param string $userId The user ID to remove
     * 
     * @return Organisation The updated organisation
     * 
     * @throws DoesNotExistException If organisation not found
     */
    public function removeUserFromOrganisation(string $organisationUuid, string $userId): Organisation
    {
        $organisation = $this->findByUuid($organisationUuid);
        $organisation->removeUser($userId);
        return $this->update($organisation);
    }
} 