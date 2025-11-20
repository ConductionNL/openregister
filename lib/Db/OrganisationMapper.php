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


use Psr\Log\LoggerInterface;
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
     * OrganisationMapper constructor
     *
     * @param IDBConnection $db Database connection
     */
    public function __construct(
        IDBConnection $db,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($db, 'openregister_organisations', Organisation::class);

    }//end __construct()


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

    }//end findByUuid()


    /**
     * Find multiple organisations by UUIDs using a single optimized query
     *
     * This method performs a single database query to fetch multiple organisations,
     * significantly improving performance compared to individual queries.
     *
     * @param  array $uuids Array of organisation UUIDs to find
     * @return array Associative array of UUID => Organisation entity
     */
    public function findMultipleByUuid(array $uuids): array
    {
        if (empty($uuids)) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('openregister_organisations')
            ->where(
                $qb->expr()->in('uuid', $qb->createNamedParameter($uuids, IQueryBuilder::PARAM_STR_ARRAY))
            );

        $result        = $qb->execute();
        $organisations = [];

        while ($row = $result->fetch()) {
            $organisation = new Organisation();
            $organisation = $organisation->fromRow($row);
            $organisations[$row['uuid']] = $organisation;
        }

        return $organisations;

    }//end findMultipleByUuid()


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
            ->where($qb->expr()->like('users', $qb->createNamedParameter('%"'.$userId.'"%')));

        return $this->findEntities($qb);

    }//end findByUserId()


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

    }//end findAllWithUserCount()


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
        }

        // Set timestamps
        $now = new \DateTime();
        if ($organisation->getId() === null) {
            $organisation->setCreated($now);
        }

        $organisation->setUpdated($now);

        // Debug logging before insert/update
        $this->logger->info('[OrganisationMapper] About to save organisation with UUID: '.$organisation->getUuid());
        $this->logger->info(
                '[OrganisationMapper] Organisation object properties:',
                [
                    'uuid'        => $organisation->getUuid(),
                    'name'        => $organisation->getName(),
                    'description' => $organisation->getDescription(),
                    'owner'       => $organisation->getOwner(),
                    'users'       => $organisation->getUsers(),
                ]
                );

        if ($organisation->getId() === null) {
            $this->logger->info('[OrganisationMapper] Calling insert() method');

            // Debug: Log the entity state before insert
            $this->logger->info(
                    '[OrganisationMapper] Entity state before insert:',
                    [
                        'id'          => $organisation->getId(),
                        'uuid'        => $organisation->getUuid(),
                        'name'        => $organisation->getName(),
                        'description' => $organisation->getDescription(),
                        'owner'       => $organisation->getOwner(),
                        'users'       => $organisation->getUsers(),
                        'created'     => $organisation->getCreated(),
                        'updated'     => $organisation->getUpdated(),
                    ]
                );

            try {
                $result = $this->insert($organisation);
                $this->logger->info('[OrganisationMapper] insert() completed successfully');

                // Organization events are now handled by cron job - no event dispatching needed
                return $result;
            } catch (\Exception $e) {
                $this->logger->error(
                        '[OrganisationMapper] insert() failed: '.$e->getMessage(),
                        [
                            'exception'      => $e->getMessage(),
                            'exceptionClass' => get_class($e),
                            'trace'          => $e->getTraceAsString(),
                        ]
                        );
                throw $e;
            }
        } else {
            $this->logger->info('[OrganisationMapper] Calling update() method');
            return $this->update($organisation);
        }//end if

    }//end save()


    /**
     * Generate a unique UUID for organisations
     *
     * @return string A unique UUID
     */
    private function generateUuid(): string
    {
        return Uuid::v4()->toRfc4122();

    }//end generateUuid()


    /**
     * Check if a UUID already exists
     *
     * @param string   $uuid      The UUID to check
     * @param int|null $excludeId Optional organisation ID to exclude from check (for updates)
     *
     * @return bool True if UUID already exists
     */
    public function uuidExists(string $uuid, ?int $excludeId=null): bool
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

    }//end uuidExists()


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
            return;
            // Will be generated in save method
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

    }//end validateUuid()


    /**
     * Find organisations by name (case-insensitive search)
     *
     * @param string $name Organisation name to search for
     *
     * @return array Array of matching organisations
     */


    /**
     * Find all organisations with pagination
     *
     * @param int $limit  Maximum number of results to return (default 50)
     * @param int $offset Number of results to skip (default 0)
     *
     * @return array List of organisation entities
     */
    public function findAll(int $limit=50, int $offset=0): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->orderBy('name', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        return $this->findEntities($qb);

    }//end findAll()


    /**
     * Find organisations by name with pagination
     *
     * @param string $name   The name pattern to search for
     * @param int    $limit  Maximum number of results to return
     * @param int    $offset Number of results to skip
     *
     * @return array List of organisation entities
     */
    public function findByName(string $name, int $limit=50, int $offset=0): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->like('name', $qb->createNamedParameter('%'.$name.'%')))
            ->orderBy('name', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        return $this->findEntities($qb);

    }//end findByName()


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
        $total  = (int) $result->fetchColumn();
        $result->closeCursor();

        return [
            'total' => $total,
        ];

    }//end getStatistics()


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
        $updated       = 0;

        foreach ($organisations as $organisation) {
            $organisation->removeUser($userId);
            $this->update($organisation);
            $updated++;
        }

        return $updated;

    }//end removeUserFromAll()


    /**
     * Add user to organisation by UUID
     *
     * @param string $organisationUuid The organisation UUID
     * @param string $userId           The user ID to add
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

    }//end addUserToOrganisation()


    /**
     * Remove user from organisation by UUID
     *
     * @param string $organisationUuid The organisation UUID
     * @param string $userId           The user ID to remove
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

    }//end removeUserFromOrganisation()


    /**
     * Find the default organisation
     *
     * @deprecated Use OrganisationService::getDefaultOrganisation() instead
     *
     * @return Organisation The default organisation
     *
     * @throws DoesNotExistException If no default organisation found
     */
    public function findDefault(): Organisation
    {
        // Default organisation is now managed via config, not database
        throw new \Exception('findDefault() is deprecated. Use OrganisationService::getDefaultOrganisation() instead.');

    }//end findDefault()


    /**
     * Find the default organisation for a specific user
     *
     * @deprecated Use OrganisationService::getDefaultOrganisation() instead
     *
     * @param string $userId The user ID
     *
     * @return Organisation The default organisation for the user
     *
     * @throws DoesNotExistException If no default organisation found for user
     */
    public function findDefaultForUser(string $userId): Organisation
    {
        // Default organisation is now managed via config, not database
        throw new \Exception('findDefaultForUser() is deprecated. Use OrganisationService::getDefaultOrganisation() instead.');

    }//end findDefaultForUser()


    /**
     * Create a default organisation
     *
     * @deprecated Use OrganisationService::createOrganisation() and setDefaultOrganisationId() instead
     *
     * @return Organisation The created default organisation
     */
    public function createDefault(): Organisation
    {
        // Default organisation is now managed via config, not database
        throw new \Exception('createDefault() is deprecated. Use OrganisationService::createOrganisation() and setDefaultOrganisationId() instead.');

    }//end createDefault()


    /**
     * Create an organisation with a specific UUID
     *
     * @param string $name        Organisation name
     * @param string $description Organisation description
     * @param string $uuid        Specific UUID to use
     * @param string $owner       Owner user ID
     * @param array  $users       Array of user IDs
     * @param bool   $isDefault   Whether this is the default organisation
     *
     * @return Organisation The created organisation
     *
     * @throws \Exception If UUID is invalid or already exists
     */
    public function createWithUuid(
        string $name,
        string $description='',
        string $uuid='',
        string $owner='',
        array $users=[]
    ): Organisation {
        // Debug logging
        $this->logger->info(
                '[OrganisationMapper::createWithUuid] Starting with parameters:',
                [
                    'name'        => $name,
                    'description' => $description,
                    'uuid'        => $uuid,
                    'owner'       => $owner,
                    'users'       => $users,
                ]
                );

        $organisation = new Organisation();
        $organisation->setName($name);
        $organisation->setDescription($description);
        $organisation->setOwner($owner);
        $organisation->setUsers($users);

        // Set UUID if provided, otherwise let save() generate one
        if ($uuid !== '') {
            $this->logger->info('[OrganisationMapper::createWithUuid] Setting UUID: '.$uuid);
            $organisation->setUuid($uuid);
            $this->logger->info('[OrganisationMapper::createWithUuid] UUID after setting: '.$organisation->getUuid());
        } else {
            $this->logger->info('[OrganisationMapper::createWithUuid] No UUID provided, will generate in save()');
        }

        $this->logger->info('[OrganisationMapper::createWithUuid] About to call save() with UUID: '.$organisation->getUuid());
        return $this->save($organisation);

    }//end createWithUuid()


    /**
     * Set an organisation as the default and update all entities without organisation
     *
     * @param Organisation $organisation The organisation to set as default
     *
     * @return bool True if successful
     */
    public function setAsDefault(Organisation $organisation): bool
    {
        // Default organisation is now managed via config, not database
        throw new \Exception('setAsDefault() is deprecated. Use OrganisationService::setDefaultOrganisationId() instead.');

    }//end setAsDefault()


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

    }//end findUpdatedAfter()


    /**
     * Find all parent organisations recursively for a given organisation UUID
     *
     * Uses recursive Common Table Expression (CTE) for efficient hierarchical queries.
     * Returns array of parent organisation UUIDs ordered from direct parent to root.
     * Maximum depth is limited to 10 levels to prevent infinite loops.
     *
     * Example:
     * - Organisation A (root)
     * - Organisation B (parent: A)
     * - Organisation C (parent: B)
     *
     * findParentChain(C) returns: [B, A]
     *
     * @param string $organisationUuid The starting organisation UUID
     *
     * @return array Array of parent organisation UUIDs ordered by level (direct parent first)
     */
    public function findParentChain(string $organisationUuid): array
    {
        // Use raw SQL for recursive CTE (Common Table Expression)
        // This is more efficient than multiple queries for hierarchical data
        $sql = "
            WITH RECURSIVE org_hierarchy AS (
                -- Base case: the organisation itself
                SELECT uuid, parent, 0 as level
                FROM ".$this->getTablePrefix().$this->getTableName()."
                WHERE uuid = :org_uuid
                
                UNION ALL
                
                -- Recursive case: get parent organisations
                SELECT o.uuid, o.parent, oh.level + 1
                FROM ".$this->getTablePrefix().$this->getTableName()." o
                INNER JOIN org_hierarchy oh ON o.uuid = oh.parent
                WHERE oh.level < 10  -- Prevent infinite loops, max 10 levels
            )
            SELECT uuid 
            FROM org_hierarchy 
            WHERE level > 0
            ORDER BY level ASC
        ";

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':org_uuid', $organisationUuid);
            $result = $stmt->execute();

            $parents = [];
            while ($row = $result->fetch()) {
                $parents[] = $row['uuid'];
            }

            $this->logger->debug(
                'Found parent chain for organisation',
                [
                    'organisation' => $organisationUuid,
                    'parents'      => $parents,
                    'count'        => count($parents),
                ]
            );

            return $parents;
        } catch (\Exception $e) {
            $this->logger->error(
                'Error finding parent chain',
                [
                    'organisation' => $organisationUuid,
                    'error'        => $e->getMessage(),
                ]
            );

            // Return empty array on error (fail gracefully)
            return [];
        }//end try

    }//end findParentChain()


    /**
     * Find all child organisations recursively for a given organisation UUID
     *
     * Uses recursive Common Table Expression (CTE) for efficient hierarchical queries.
     * Returns array of all child organisation UUIDs (direct and indirect children).
     * Maximum depth is limited to 10 levels to prevent infinite loops.
     *
     * Example:
     * - Organisation A (root)
     * - Organisation B (parent: A)
     * - Organisation C (parent: A)
     * - Organisation D (parent: B)
     *
     * findChildrenChain(A) returns: [B, C, D]
     *
     * @param string $organisationUuid The parent organisation UUID
     *
     * @return array Array of child organisation UUIDs
     */
    public function findChildrenChain(string $organisationUuid): array
    {
        // Use raw SQL for recursive CTE
        $sql = "
            WITH RECURSIVE org_hierarchy AS (
                -- Base case: direct children
                SELECT uuid, parent, 0 as level
                FROM ".$this->getTablePrefix().$this->getTableName()."
                WHERE parent = :org_uuid
                
                UNION ALL
                
                -- Recursive case: children of children
                SELECT o.uuid, o.parent, oh.level + 1
                FROM ".$this->getTablePrefix().$this->getTableName()." o
                INNER JOIN org_hierarchy oh ON o.parent = oh.uuid
                WHERE oh.level < 10  -- Prevent infinite loops, max 10 levels
            )
            SELECT uuid 
            FROM org_hierarchy
            ORDER BY level ASC
        ";

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':org_uuid', $organisationUuid);
            $result = $stmt->execute();

            $children = [];
            while ($row = $result->fetch()) {
                $children[] = $row['uuid'];
            }

            $this->logger->debug(
                'Found children chain for organisation',
                [
                    'organisation' => $organisationUuid,
                    'children'     => $children,
                    'count'        => count($children),
                ]
            );

            return $children;
        } catch (\Exception $e) {
            $this->logger->error(
                'Error finding children chain',
                [
                    'organisation' => $organisationUuid,
                    'error'        => $e->getMessage(),
                ]
            );

            // Return empty array on error (fail gracefully)
            return [];
        }//end try

    }//end findChildrenChain()


    /**
     * Validate parent assignment to prevent circular references and enforce max depth
     *
     * Checks that setting a parent organisation will not create:
     * - Circular references (A -> B -> A)
     * - Excessive depth (> 10 levels)
     * - Self-reference (organisation pointing to itself)
     *
     * @param string      $organisationUuid The organisation UUID to update
     * @param string|null $newParentUuid    The new parent UUID to assign (null to remove parent)
     *
     * @return void
     *
     * @throws \Exception If validation fails (circular reference, max depth exceeded, etc.)
     */
    public function validateParentAssignment(string $organisationUuid, ?string $newParentUuid): void
    {
        // Allow setting parent to null (removing parent)
        if ($newParentUuid === null) {
            return;
        }

        // Prevent self-reference
        if ($organisationUuid === $newParentUuid) {
            throw new \Exception('Organisation cannot be its own parent.');
        }

        // Check if new parent exists
        try {
            $parentOrg = $this->findByUuid($newParentUuid);
        } catch (\Exception $e) {
            throw new \Exception('Parent organisation not found.');
        }

        // Check for circular reference: if the new parent has this org in its parent chain
        $parentChain = $this->findParentChain($newParentUuid);
        if (in_array($organisationUuid, $parentChain)) {
            throw new \Exception(
                'Circular reference detected: The new parent organisation is already a descendant of this organisation.'
            );
        }

        // Check max depth: current parent chain + this org + existing children chain
        $childrenChain = $this->findChildrenChain($organisationUuid);

        // Calculate maximum depth after assignment
        $maxDepthAbove = count($parentChain) + 1;
        // Parent chain + new parent
        $maxDepthBelow = $this->getMaxDepthInChain($childrenChain, $organisationUuid);
        $totalDepth    = $maxDepthAbove + $maxDepthBelow;

        if ($totalDepth > 10) {
            throw new \Exception(
                "Maximum hierarchy depth exceeded. Total depth would be {$totalDepth} levels (max 10 allowed)."
            );
        }

        $this->logger->debug(
            'Parent assignment validated',
            [
                'organisation' => $organisationUuid,
                'newParent'    => $newParentUuid,
                'parentChain'  => $parentChain,
                'totalDepth'   => $totalDepth,
            ]
        );

    }//end validateParentAssignment()


    /**
     * Get maximum depth in a children chain
     *
     * Helper method to calculate the deepest level in a hierarchy chain.
     * Used for validating maximum depth constraints.
     *
     * @param array  $childrenUuids Array of child organisation UUIDs
     * @param string $rootUuid      The root organisation UUID
     *
     * @return int Maximum depth from root
     */
    private function getMaxDepthInChain(array $childrenUuids, string $rootUuid): int
    {
        if (empty($childrenUuids)) {
            return 0;
        }

        // Build parent map for efficient lookup
        $parentMap = [];
        foreach ($childrenUuids as $childUuid) {
            try {
                $child = $this->findByUuid($childUuid);
                if ($child->getParent() !== null) {
                    $parentMap[$childUuid] = $child->getParent();
                }
            } catch (\Exception $e) {
                // Skip if child not found
                continue;
            }
        }

        // Calculate depth for each child
        $maxDepth = 0;
        foreach ($childrenUuids as $childUuid) {
            $depth    = $this->calculateDepthFromRoot($childUuid, $rootUuid, $parentMap);
            $maxDepth = max($maxDepth, $depth);
        }

        return $maxDepth;

    }//end getMaxDepthInChain()


    /**
     * Calculate depth of a node from root
     *
     * @param string $nodeUuid  The node UUID
     * @param string $rootUuid  The root UUID
     * @param array  $parentMap Parent mapping array
     *
     * @return int Depth from root
     */
    private function calculateDepthFromRoot(string $nodeUuid, string $rootUuid, array $parentMap): int
    {
        $depth   = 0;
        $current = $nodeUuid;

        while (isset($parentMap[$current]) && $current !== $rootUuid && $depth < 20) {
            $depth++;
            $current = $parentMap[$current];
        }

        return $depth;

    }//end calculateDepthFromRoot()


    /**
     * Get table prefix for raw SQL queries
     *
     * Nextcloud uses 'oc_' prefix by default but can be customized.
     * This method ensures we use the correct prefix for raw SQL.
     *
     * @return string Table prefix (e.g., 'oc_')
     */
    private function getTablePrefix(): string
    {
        // Get table prefix from Nextcloud system configuration
        // Default is 'oc_' but can be customized per installation
        return \OC::$server->getSystemConfig()->getValue('dbtableprefix', 'oc_');

    }//end getTablePrefix()


}//end class
