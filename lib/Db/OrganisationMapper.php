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
use OCP\EventDispatcher\IEventDispatcher;
use OCA\OpenRegister\Event\OrganisationCreatedEvent;
use Symfony\Component\Uid\Uuid;


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
     * Event dispatcher for firing organisation events
     *
     * @var IEventDispatcher
     */
    private IEventDispatcher $eventDispatcher;

    /**
     * OrganisationMapper constructor
     * 
     * @param IDBConnection $db Database connection
     * @param IEventDispatcher $eventDispatcher Event dispatcher for firing events
     */
    public function __construct(IDBConnection $db, IEventDispatcher $eventDispatcher)
    {
        parent::__construct($db, 'openregister_organisations', Organisation::class);
        $this->eventDispatcher = $eventDispatcher;
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
     * 
     * @throws \Exception If UUID is invalid or already exists
     */
    public function save(Organisation $organisation): Organisation
    {
        // Validate UUID if provided
        $this->validateUuid($organisation);

        // Generate UUID if not present and not explicitly set
        if ($organisation->getUuid() === null || $organisation->getUuid() === '') {
            $generatedUuid = $this->generateUuid();
            $organisation->setUuid($generatedUuid);
            
            // Debug logging
            error_log('[OrganisationMapper] Generated UUID: ' . $generatedUuid);
            error_log('[OrganisationMapper] Organisation UUID after setting: ' . $organisation->getUuid());
        }

        // Set timestamps
        $now = new \DateTime();
        if ($organisation->getId() === null) {
            $organisation->setCreated($now);
        }
        $organisation->setUpdated($now);

        // Debug logging before insert/update
        \OC::$server->getLogger()->info('[OrganisationMapper] About to save organisation with UUID: ' . $organisation->getUuid());
        \OC::$server->getLogger()->info('[OrganisationMapper] Organisation object properties:', [
            'uuid' => $organisation->getUuid(),
            'name' => $organisation->getName(),
            'description' => $organisation->getDescription(),
            'owner' => $organisation->getOwner(),
            'users' => $organisation->getUsers(),
            'isDefault' => $organisation->getIsDefault()
        ]);

        if ($organisation->getId() === null) {
            \OC::$server->getLogger()->info('[OrganisationMapper] Calling insert() method');
            
            // Debug: Log the entity state before insert
            \OC::$server->getLogger()->info('[OrganisationMapper] Entity state before insert:', [
                'id' => $organisation->getId(),
                'uuid' => $organisation->getUuid(),
                'name' => $organisation->getName(),
                'description' => $organisation->getDescription(),
                'owner' => $organisation->getOwner(),
                'users' => $organisation->getUsers(),
                'isDefault' => $organisation->getIsDefault(),
                'created' => $organisation->getCreated(),
                'updated' => $organisation->getUpdated()
            ]);
            
            try {
                $result = $this->insert($organisation);
                \OC::$server->getLogger()->info('[OrganisationMapper] insert() completed successfully');
                
                // Fire OrganisationCreatedEvent after successful insert
                $event = new OrganisationCreatedEvent($result);
                $this->eventDispatcher->dispatchTyped($event);
                \OC::$server->getLogger()->info('[OrganisationMapper] OrganisationCreatedEvent dispatched', [
                    'organisationUuid' => $result->getUuid(),
                    'organisationName' => $result->getName()
                ]);
                
                return $result;
            } catch (\Exception $e) {
                \OC::$server->getLogger()->error('[OrganisationMapper] insert() failed: ' . $e->getMessage(), [
                    'exception' => $e->getMessage(),
                    'exceptionClass' => get_class($e),
                    'trace' => $e->getTraceAsString()
                ]);
                throw $e;
            }
        } else {
            \OC::$server->getLogger()->info('[OrganisationMapper] Calling update() method');
            return $this->update($organisation);
        }
    }

    /**
     * Generate a unique UUID for organisations
     * 
     * @return string A unique UUID
     */
    private function generateUuid(): string
    {
        return Uuid::v4()->toRfc4122();
    }

    /**
     * Check if a UUID already exists
     * 
     * @param string $uuid The UUID to check
     * @param int|null $excludeId Optional organisation ID to exclude from check (for updates)
     * 
     * @return bool True if UUID already exists
     */
    public function uuidExists(string $uuid, ?int $excludeId = null): bool
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('id')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)));

        if ($excludeId !== null) {
            $qb->andWhere($qb->expr()->neq('id', $qb->createNamedParameter($excludeId, IQueryBuilder::PARAM_INT)));
        }

        $result = $qb->execute();
        $exists = $result->fetchColumn() !== false;
        $result->closeCursor();

        return $exists;
    }

    /**
     * Validate and ensure UUID uniqueness
     * 
     * @param Organisation $organisation The organisation to validate
     * 
     * @throws \Exception If UUID is invalid or already exists
     * 
     * @return void
     */
    private function validateUuid(Organisation $organisation): void
    {
        $uuid = $organisation->getUuid();
        
        if ($uuid === null || $uuid === '') {
            return; // Will be generated in save method
        }

        // Validate UUID format using Symfony UID
        try {
            Uuid::fromString($uuid);
        } catch (\InvalidArgumentException $e) {
            throw new \Exception('Invalid UUID format. UUID must be a valid RFC 4122 UUID.');
        }

        // Check for uniqueness
        if ($this->uuidExists($uuid, $organisation->getId())) {
            throw new \Exception('UUID already exists. Please use a different UUID.');
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

    /**
     * Find the default organisation
     * 
     * @return Organisation The default organisation
     * 
     * @throws DoesNotExistException If no default organisation found
     */
    public function findDefault(): Organisation
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('is_default', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
           ->setMaxResults(1);

        return $this->findEntity($qb);
    }

    /**
     * Find the default organisation for a specific user
     * 
     * @param string $userId The user ID
     * 
     * @return Organisation The default organisation for the user
     * 
     * @throws DoesNotExistException If no default organisation found for user
     */
    public function findDefaultForUser(string $userId): Organisation
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('is_default', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
           ->andWhere($qb->expr()->like('users', $qb->createNamedParameter('%"' . $userId . '"%')))
           ->setMaxResults(1);

        return $this->findEntity($qb);
    }

    /**
     * Create a default organisation
     * 
     * @return Organisation The created default organisation
     */
    public function createDefault(): Organisation
    {
        $organisation = new Organisation();
        $organisation->setName('Default Organisation');
        $organisation->setDescription('Default organisation for the system');
        $organisation->setIsDefault(true);
        $organisation->setOwner('admin');
        $organisation->setUsers(['admin']);

        return $this->save($organisation);
    }

    /**
     * Create an organisation with a specific UUID
     * 
     * @param string $name Organisation name
     * @param string $description Organisation description
     * @param string $uuid Specific UUID to use
     * @param string $owner Owner user ID
     * @param array $users Array of user IDs
     * @param bool $isDefault Whether this is the default organisation
     * 
     * @return Organisation The created organisation
     * 
     * @throws \Exception If UUID is invalid or already exists
     */
    public function createWithUuid(
        string $name,
        string $description = '',
        string $uuid = '',
        string $owner = '',
        array $users = [],
        bool $isDefault = false
    ): Organisation {
        // Debug logging
        \OC::$server->getLogger()->info('[OrganisationMapper::createWithUuid] Starting with parameters:', [
            'name' => $name,
            'description' => $description,
            'uuid' => $uuid,
            'owner' => $owner,
            'users' => $users,
            'isDefault' => $isDefault
        ]);
        
        $organisation = new Organisation();
        $organisation->setName($name);
        $organisation->setDescription($description);
        $organisation->setOwner($owner);
        $organisation->setUsers($users);
        $organisation->setIsDefault($isDefault);

        // Set UUID if provided, otherwise let save() generate one
        if ($uuid !== '') {
            \OC::$server->getLogger()->info('[OrganisationMapper::createWithUuid] Setting UUID: ' . $uuid);
            $organisation->setUuid($uuid);
            \OC::$server->getLogger()->info('[OrganisationMapper::createWithUuid] UUID after setting: ' . $organisation->getUuid());
        } else {
            \OC::$server->getLogger()->info('[OrganisationMapper::createWithUuid] No UUID provided, will generate in save()');
        }

        \OC::$server->getLogger()->info('[OrganisationMapper::createWithUuid] About to call save() with UUID: ' . $organisation->getUuid());
        return $this->save($organisation);
    }

    /**
     * Set an organisation as the default and update all entities without organisation
     * 
     * @param Organisation $organisation The organisation to set as default
     * 
     * @return bool True if successful
     */
    public function setAsDefault(Organisation $organisation): bool
    {
        // First, unset any existing default organisation
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
           ->set('is_default', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
           ->where($qb->expr()->eq('is_default', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
        $qb->execute();

        // Set the new default organisation
        $organisation->setIsDefault(true);
        $this->update($organisation);

        // Update all registers without organisation
        $qb = $this->db->getQueryBuilder();
        $qb->update('openregister_registers')
           ->set('organisation', $qb->createNamedParameter($organisation->getUuid()))
           ->where($qb->expr()->isNull('organisation'));
        $qb->execute();

        // Update all schemas without organisation
        $qb = $this->db->getQueryBuilder();
        $qb->update('openregister_schemas')
           ->set('organisation', $qb->createNamedParameter($organisation->getUuid()))
           ->where($qb->expr()->isNull('organisation'));
        $qb->execute();

        // Update all objects without organisation
        $qb = $this->db->getQueryBuilder();
        $qb->update('openregister_objects')
           ->set('organisation', $qb->createNamedParameter($organisation->getUuid()))
           ->where($qb->expr()->isNull('organisation'));
        $qb->execute();

        return true;
    }

    /**
     * Find organisations updated after a specific datetime
     * 
     * @param \DateTime $cutoffTime The cutoff time to search after
     * 
     * @return array Array of Organisation entities updated after the cutoff time
     */
    public function findUpdatedAfter(\DateTime $cutoffTime): array
    {
        $qb = $this->db->getQueryBuilder();
        
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->gt('updated', $qb->createNamedParameter($cutoffTime->format('Y-m-d H:i:s'))))
           ->orderBy('updated', 'DESC');
        
        return $this->findEntities($qb);
    }
} 