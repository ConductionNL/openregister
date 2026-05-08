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

use DateTime;
use OCA\OpenRegister\Event\OrganisationCreatedEvent;
use OCA\OpenRegister\Event\OrganisationDeletedEvent;
use OCA\OpenRegister\Event\OrganisationUpdatedEvent;
use Exception;
use RuntimeException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\IDBConnection;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * OrganisationMapper
 *
 * Database mapper for Organisation entities with multi-tenancy support.
 * Manages CRUD operations and user-organisation relationships.
 *
 * @package OCA\OpenRegister\Db
 *
 * @method Organisation insert(Entity $entity)
 * @method Organisation update(Entity $entity)
 * @method Organisation insertOrUpdate(Entity $entity)
 * @method Organisation delete(Entity $entity)
 * @method Organisation find(int|string $id)
 * @method Organisation findEntity(IQueryBuilder $query)
 * @method Organisation[] findAll(int|null $limit=null, int|null $offset=null)
 * @method list<Organisation> findEntities(IQueryBuilder $query)
 *
 * @template-extends QBMapper<Organisation>
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 */
class OrganisationMapper extends QBMapper
{
    /**
     * OrganisationMapper constructor
     *
     * @param IDBConnection    $db              Database connection
     * @param LoggerInterface  $logger          Logger interface
     * @param IEventDispatcher $eventDispatcher Event dispatcher
     * @param IAppConfig       $appConfig       App configuration for reading default org
     * @param IConfig          $config          System configuration (used for the DB table prefix)
     */
    public function __construct(
        IDBConnection $db,
        private readonly LoggerInterface $logger,
        private readonly IEventDispatcher $eventDispatcher,
        private readonly IAppConfig $appConfig,
        private readonly IConfig $config
    ) {
        parent::__construct(db: $db, tableName: 'openregister_organisations', entityClass: Organisation::class);
    }//end __construct()

    /**
     * Insert a new organisation
     *
     * @param Entity $entity Organisation entity to insert
     *
     * @return Organisation The inserted organisation with updated ID
     */
    public function insert(Entity $entity): Entity
    {
        if ($entity instanceof Organisation) {
            // Generate UUID if not set.
            if (empty($entity->getUuid()) === true) {
                $entity->setUuid(Uuid::v4()->toRfc4122());
            }

            // Set timestamps.
            $entity->setCreated(new DateTime());
            $entity->setUpdated(new DateTime());
        }

        $entity = parent::insert(entity: $entity);

        // Dispatch creation event.
        $this->eventDispatcher->dispatchTyped(new OrganisationCreatedEvent(organisation: $entity));

        return $entity;
    }//end insert()

    /**
     * Update an existing organisation
     *
     * @param Entity $entity Organisation entity to update
     *
     * @return Organisation The updated organisation
     */
    public function update(Entity $entity): Entity
    {
        /*
         * Get old state before update.
         * @var Organisation $oldEntity
         */

        // Find old entity by database ID (not UUID) so this works even when the UUID
        // is being changed (e.g. slug collision path sets a new UUID before saving).
        $oldEntity = null;
        if ($entity->getId() !== null) {
            try {
                $qb = $this->db->getQueryBuilder();
                $qb->select('*')
                    ->from($this->getTableName())
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter($entity->getId(), IQueryBuilder::PARAM_INT)));
                $oldEntity = $this->findEntity(query: $qb);
            } catch (DoesNotExistException $e) {
                // Old entity not found — proceed without old state in event.
            }
        }

        if ($entity instanceof Organisation) {
            $entity->setUpdated(new DateTime());
        }

        $entity = parent::update(entity: $entity);

        // Dispatch update event.
        $event = new OrganisationUpdatedEvent(
            newOrganisation: $entity,
            oldOrganisation: $oldEntity ?? $entity
        );
        $this->eventDispatcher->dispatchTyped($event);

        return $entity;
    }//end update()

    /**
     * Delete an organisation
     *
     * @param Entity $entity Organisation entity to delete
     *
     * @return Organisation The deleted organisation
     */
    public function delete(Entity $entity): Entity
    {
        $entity = parent::delete(entity: $entity);

        // Dispatch deletion event.
        $this->eventDispatcher->dispatchTyped(new OrganisationDeletedEvent(organisation: $entity));

        return $entity;
    }//end delete()

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

        return $this->findEntity(query: $qb);
    }//end findByUuid()

    /**
     * Find multiple organisations by UUIDs using a single optimized query
     *
     * This method performs a single database query to fetch multiple organisations,
     * significantly improving performance compared to individual queries.
     *
     * @param array $uuids Array of organisation UUIDs to find
     *
     * @return Entity&Organisation[]
     *
     * @psalm-return array<Entity&Organisation>
     */
    public function findMultipleByUuid(array $uuids): array
    {
        if (empty($uuids) === true) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('openregister_organisations')
            ->where(
                $qb->expr()->in('uuid', $qb->createNamedParameter($uuids, IQueryBuilder::PARAM_STR_ARRAY))
            );

        $result        = $qb->executeQuery();
        $organisations = [];

        while (($row = $result->fetch()) !== false) {
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
     * @return Organisation[]
     *
     * @psalm-return list<\OCA\OpenRegister\Db\Organisation>
     */
    public function findByUserId(string $userId): array
    {
        $qb = $this->db->getQueryBuilder();

        // Get database platform to determine JSON handling.
        $platform = $qb->getConnection()->getDatabasePlatform();

        $qb->select('*')
            ->from($this->getTableName());

        // MySQL/MariaDB can use LIKE directly on JSON columns (default).
        $whereExpr = $qb->expr()->like('users', $qb->createNamedParameter('%"'.$userId.'"%'));
        // PostgreSQL requires explicit cast to text for LIKE on JSON columns.
        if ($platform instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform) {
            // Cast JSON column to text for comparison.
            $whereExpr = $qb->expr()->like(
                $qb->createFunction('CAST(users AS TEXT)'),
                $qb->createNamedParameter('%"'.$userId.'"%')
            );
        }

        $qb->where($whereExpr);

        return $this->findEntities(query: $qb);
    }//end findByUserId()

    /**
     * Get all organisations with user count
     *
     * @return Organisation[]
     *
     * @psalm-return list<\OCA\OpenRegister\Db\Organisation>
     */
    public function findAllWithUserCount(): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->orderBy('name', 'ASC');

        $organisations = $this->findEntities(query: $qb);

        // Add user count to each organisation.
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
        // Validate UUID if provided.
        $this->validateUuid(organisation: $organisation);

        // Generate UUID if not present and not explicitly set.
        if ($organisation->getUuid() === null || $organisation->getUuid() === '') {
            $generatedUuid = $this->generateUuid();
            $organisation->setUuid($generatedUuid);
        }

        // Set timestamps.
        $now = new DateTime();
        if ($organisation->getId() === null) {
            $organisation->setCreated($now);
        }

        $organisation->setUpdated($now);

        if ($organisation->getId() === null) {
            try {
                $result = $this->insert(entity: $organisation);
                return $result;
            } catch (Exception $e) {
                // Handle duplicate slug: find the existing org and update it instead.
                $isSlugConflict = str_contains(haystack: $e->getMessage(), needle: 'organisations_slug_unique') === true;
                if ($organisation->getSlug() !== null && $isSlugConflict === true) {
                    $this->logger->info(
                        message: '[OrganisationMapper] Duplicate slug, updating existing organisation',
                        context: ['slug' => $organisation->getSlug()]
                    );
                    $existing = $this->findBySlug(slug: $organisation->getSlug());
                    $organisation->setId($existing->getId());
                    $organisation->setCreated($existing->getCreated());
                    return $this->update(entity: $organisation);
                }

                $this->logger->error(
                    message: '[OrganisationMapper] insert() failed: '.$e->getMessage(),
                );
                throw $e;
            }//end try
        }//end if

        return $this->update(entity: $organisation);
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

        $result = $qb->executeQuery();
        $exists = $result->fetchOne() !== false;
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
     *
     * @SuppressWarnings(PHPMD.StaticAccess) Uuid::fromString is standard Symfony UID pattern
     */
    private function validateUuid(Organisation $organisation): void
    {
        $uuid = $organisation->getUuid();

        if ($uuid === null || $uuid === '') {
            return;
            // Will be generated in save method.
        }

        // Validate UUID format using Symfony UID.
        try {
            Uuid::fromString($uuid);
        } catch (\InvalidArgumentException $e) {
            throw new Exception('Invalid UUID format. UUID must be a valid RFC 4122 UUID.');
        }

        // Check for uniqueness.
        if ($this->uuidExists(uuid: $uuid, excludeId: $organisation->getId()) === true) {
            throw new Exception('UUID already exists. Please use a different UUID.');
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
     * @return Organisation[]
     *
     * @psalm-return list<OCA\OpenRegister\Db\Organisation>
     */
    public function findAll(int $limit=50, int $offset=0): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->orderBy('name', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        return $this->findEntities(query: $qb);
    }//end findAll()

    /**
     * Find organisations by name with pagination
     *
     * @param string $name   The name pattern to search for
     * @param int    $limit  Maximum number of results to return
     * @param int    $offset Number of results to skip
     *
     * @return Organisation[]
     *
     * @psalm-return list<\OCA\OpenRegister\Db\Organisation>
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

        return $this->findEntities(query: $qb);
    }//end findByName()

    /**
     * Find an organisation by its slug
     *
     * @param string $slug The slug to search for
     *
     * @return Organisation The found organisation
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException If not found
     */
    public function findBySlug(string $slug): Organisation
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('slug', $qb->createNamedParameter($slug)))
            ->setMaxResults(1);

        return $this->findEntity(query: $qb);
    }//end findBySlug()

    /**
     * Get organisation statistics
     *
     * @return int[] Statistics about organisations
     *
     * @psalm-return array{total: int}
     */
    public function getStatistics(): array
    {
        $qb = $this->db->getQueryBuilder();

        // Total organisations.
        $qb->select($qb->createFunction('COUNT(*) as total'))
            ->from($this->getTableName());
        $result = $qb->executeQuery();
        $total  = (int) $result->fetchOne();
        $result->closeCursor();

        return [
            'total' => $total,
        ];
    }//end getStatistics()

    /**
     * Add user to organisation by UUID
     *
     * @param string $organisationUuid The organisation UUID
     * @param string $userId           The user ID to add
     *
     * @return Organisation The updated organisation
     *
     * @throws DoesNotExistException If organisation not found
     *
     * @psalm-suppress PossiblyUnusedReturnValue
     */
    public function addUserToOrganisation(string $organisationUuid, string $userId): Organisation
    {
        $organisation = $this->findByUuid(uuid: $organisationUuid);
        $organisation->addUser($userId);
        return $this->update(entity: $organisation);
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
     *
     * @psalm-suppress PossiblyUnusedReturnValue
     */
    public function removeUserFromOrganisation(string $organisationUuid, string $userId): Organisation
    {
        $organisation = $this->findByUuid(uuid: $organisationUuid);
        $organisation->removeUser($userId);
        return $this->update(entity: $organisation);
    }//end removeUserFromOrganisation()

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
     *
     * @psalm-return list{0?: mixed,...}
     */
    public function findParentChain(string $organisationUuid): array
    {
        // Use raw SQL for recursive CTE (Common Table Expression).
        // This is more efficient than multiple queries for hierarchical data.
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
            $row     = $result->fetch();
            while ($row !== false) {
                $parents[] = $row['uuid'];

                $row = $result->fetch();
            }

            $this->logger->debug(
                message: '[OrganisationMapper] Found parent chain for organisation',
                context: [
                    'file'         => __FILE__,
                    'line'         => __LINE__,
                    'organisation' => $organisationUuid,
                    'parents'      => $parents,
                    'count'        => count($parents),
                ]
            );

            return $parents;
        } catch (Exception $e) {
            $this->logger->error(
                message: '[OrganisationMapper] Error finding parent chain',
                context: [
                    'file'         => __FILE__,
                    'line'         => __LINE__,
                    'organisation' => $organisationUuid,
                    'error'        => $e->getMessage(),
                ]
            );

            // Return empty array on error (fail gracefully).
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
     *
     * @psalm-return list{0?: mixed,...}
     */
    public function findChildrenChain(string $organisationUuid): array
    {
        // Use raw SQL for recursive CTE.
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
            $row      = $result->fetch();
            while ($row !== false) {
                $children[] = $row['uuid'];

                $row = $result->fetch();
            }

            $this->logger->debug(
                message: '[OrganisationMapper] Found children chain for organisation',
                context: [
                    'file'         => __FILE__,
                    'line'         => __LINE__,
                    'organisation' => $organisationUuid,
                    'children'     => $children,
                    'count'        => count($children),
                ]
            );

            return $children;
        } catch (Exception $e) {
            $this->logger->error(
                message: '[OrganisationMapper] Error finding children chain',
                context: [
                    'file'         => __FILE__,
                    'line'         => __LINE__,
                    'organisation' => $organisationUuid,
                    'error'        => $e->getMessage(),
                ]
            );

            // Return empty array on error (fail gracefully).
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
        // Allow setting parent to null (removing parent).
        if ($newParentUuid === null) {
            return;
        }

        // Prevent self-reference.
        if ($organisationUuid === $newParentUuid) {
            throw new Exception('Organisation cannot be its own parent.');
        }

        // Check if new parent exists (validation only).
        try {
            $this->findByUuid(uuid: $newParentUuid);
        } catch (Exception $e) {
            throw new Exception('Parent organisation not found.');
        }

        // Check for circular reference: if the new parent has this org in its parent chain.
        $parentChain = $this->findParentChain(organisationUuid: $newParentUuid);
        if (in_array($organisationUuid, $parentChain) === true) {
            throw new Exception(
                'Circular reference detected: The new parent organisation is already a descendant of this organisation.'
            );
        }

        // Check max depth: current parent chain + this org + existing children chain.
        $childrenChain = $this->findChildrenChain(organisationUuid: $organisationUuid);

        // Calculate maximum depth after assignment.
        $maxDepthAbove = count($parentChain) + 1;
        // Parent chain + new parent.
        $maxDepthBelow = $this->getMaxDepthInChain(childrenUuids: $childrenChain, rootUuid: $organisationUuid);
        $totalDepth    = $maxDepthAbove + $maxDepthBelow;

        if ($totalDepth > 10) {
            throw new Exception(
                "Maximum hierarchy depth exceeded. Total depth would be {$totalDepth} levels (max 10 allowed)."
            );
        }

        $this->logger->debug(
            message: '[OrganisationMapper] Parent assignment validated',
            context: [
                'file'         => __FILE__,
                'line'         => __LINE__,
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
     * @return int
     *
     * @psalm-return int<0, 20>
     */
    private function getMaxDepthInChain(array $childrenUuids, string $rootUuid): int
    {
        if (empty($childrenUuids) === true) {
            return 0;
        }

        // Build parent map for efficient lookup.
        $parentMap = [];
        foreach ($childrenUuids as $childUuid) {
            try {
                $child = $this->findByUuid(uuid: $childUuid);
                if ($child->getParent() !== null) {
                    $parentMap[$childUuid] = $child->getParent();
                }
            } catch (Exception $e) {
                // Skip if child not found.
                continue;
            }
        }

        // Calculate depth for each child.
        $maxDepth = 0;
        foreach ($childrenUuids as $childUuid) {
            $depth    = $this->calculateDepthFromRoot(nodeUuid: $childUuid, rootUuid: $rootUuid, parentMap: $parentMap);
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
     *
     * @psalm-return int<0, 20>
     */
    private function calculateDepthFromRoot(string $nodeUuid, string $rootUuid, array $parentMap): int
    {
        $depth   = 0;
        $current = $nodeUuid;

        while (($parentMap[$current] ?? null) !== null && ($current !== $rootUuid) === true && ($depth < 20) === true) {
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
        return $this->config->getSystemValue('dbtableprefix', 'oc_');
    }//end getTablePrefix()

    /**
     * Get active organisation UUID for a user from preferences
     *
     * This retrieves the user's currently active organisation from user preferences.
     * Returns null if no active organisation is set.
     *
     * @param string $userId The user ID
     *
     * @return string|null The active organisation UUID or null
     */
    public function getActiveOrganisationUuidForUser(string $userId): ?string
    {
        $qb = $this->db->getQueryBuilder();

        // Query the preferences table for active organisation.
        $qb->select('configvalue')
            ->from('preferences')
            ->where($qb->expr()->eq('userid', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('appid', $qb->createNamedParameter('openregister')))
            ->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter('active_organisation')));

        try {
            $result = $qb->executeQuery();
            $row    = $result->fetch();
            $result->closeCursor();

            if ($row !== false && isset($row['configvalue']) === true) {
                return $row['configvalue'];
            }
        } catch (Exception $e) {
            $this->logger->warning(
                message: '[OrganisationMapper] Failed to get active organisation for user: '.$e->getMessage(),
                context: ['file' => __FILE__, 'line' => __LINE__, 'userId' => $userId]
            );
        }

        return null;
    }//end getActiveOrganisationUuidForUser()

    /**
     * Get active organisation with fallback to default and set on session
     *
     * This method retrieves the active organisation for a user from preferences.
     * If no active organisation is set, it falls back to the default organisation
     * from configuration and sets it as the active organisation in preferences.
     *
     * This consolidates the logic of getting an organisation UUID with proper fallbacks,
     * ensuring users always have an organisation context.
     *
     * @param string $userId The user ID
     *
     * @return string|null The organisation UUID (active or default) or null if neither exists
     */
    public function getActiveOrganisationWithFallback(string $userId): ?string
    {
        // First try to get active organisation from preferences.
        $activeOrgUuid = $this->getActiveOrganisationUuidForUser(userId: $userId);
        if ($activeOrgUuid !== null) {
            $this->logger->debug(
                message: '[OrganisationMapper] Found active organisation for user in preferences',
                context: [
                    'file'             => __FILE__,
                    'line'             => __LINE__,
                    'userId'           => $userId,
                    'organisationUuid' => $activeOrgUuid,
                ]
            );
            return $activeOrgUuid;
        }

        // No active organisation, fall back to default from config.
        $defaultOrgUuid = $this->getDefaultOrganisationFromConfig();
        if ($defaultOrgUuid === null) {
            $this->logger->warning(
                message: '[OrganisationMapper] No active or default organisation found for user',
                context: ['file' => __FILE__, 'line' => __LINE__, 'userId' => $userId]
            );
            return null;
        }

        // Set the default organisation as active in preferences for future requests.
        try {
            $this->setActiveOrganisationForUser(userId: $userId, organisationUuid: $defaultOrgUuid);
            $this->logger->info(
                message: '[OrganisationMapper] Set default organisation as active for user',
                context: [
                    'file'             => __FILE__,
                    'line'             => __LINE__,
                    'userId'           => $userId,
                    'organisationUuid' => $defaultOrgUuid,
                ]
            );
        } catch (Exception $e) {
            $this->logger->warning(
                message: '[OrganisationMapper] Failed to set active organisation in preferences: '.$e->getMessage(),
                context: [
                    'file'             => __FILE__,
                    'line'             => __LINE__,
                    'userId'           => $userId,
                    'organisationUuid' => $defaultOrgUuid,
                ]
            );
        }//end try

        return $defaultOrgUuid;
    }//end getActiveOrganisationWithFallback()

    /**
     * Set active organisation for a user in preferences
     *
     * @param string $userId           The user ID
     * @param string $organisationUuid The organisation UUID to set as active
     *
     * @return void
     *
     * @throws Exception If the database operation fails
     */
    public function setActiveOrganisationForUser(string $userId, string $organisationUuid): void
    {
        $qb = $this->db->getQueryBuilder();

        // First check if preference already exists.
        $qb->select('userid')
            ->from('preferences')
            ->where($qb->expr()->eq('userid', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('appid', $qb->createNamedParameter('openregister')))
            ->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter('active_organisation')));

        $result = $qb->executeQuery();
        $exists = $result->fetch();
        $result->closeCursor();

        if ($exists !== false) {
            // Update existing preference.
            $qb = $this->db->getQueryBuilder();
            $qb->update('preferences')
                ->set('configvalue', $qb->createNamedParameter($organisationUuid))
                ->where($qb->expr()->eq('userid', $qb->createNamedParameter($userId)))
                ->andWhere($qb->expr()->eq('appid', $qb->createNamedParameter('openregister')))
                ->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter('active_organisation')));
            $qb->executeStatement();
            return;
        }

        // Insert new preference.
        $qb = $this->db->getQueryBuilder();
        $qb->insert('preferences')
            ->values(
                [
                    'userid'      => $qb->createNamedParameter($userId),
                    'appid'       => $qb->createNamedParameter('openregister'),
                    'configkey'   => $qb->createNamedParameter('active_organisation'),
                    'configvalue' => $qb->createNamedParameter($organisationUuid),
                ]
            );
        $qb->executeStatement();
    }//end setActiveOrganisationForUser()

    /**
     * Get default organisation UUID from configuration
     *
     * @return string|null The default organisation UUID or null if not configured
     */
    public function getDefaultOrganisationFromConfig(): ?string
    {
        // Try direct config key (newer format).
        $defaultOrg = $this->appConfig->getValueString('openregister', 'defaultOrganisation', '');
        if (empty($defaultOrg) === false) {
            return $defaultOrg;
        }

        // Try nested organisation config (legacy format).
        $organisationConfig = $this->appConfig->getValueString('openregister', 'organisation', '');
        if (empty($organisationConfig) === false) {
            $storedData = json_decode($organisationConfig, true);
            if (isset($storedData['default_organisation']) === true) {
                return $storedData['default_organisation'];
            }
        }

        return null;
    }//end getDefaultOrganisationFromConfig()

    /**
     * Get organisation hierarchy (organisation + all parents)
     *
     * Returns an array of organisation UUIDs including the given organisation
     * and all its parent organisations up the hierarchy.
     *
     * @param string $organisationUuid The organisation UUID
     *
     * @return string[] Array of organisation UUIDs (current + parents)
     */
    public function getOrganisationHierarchy(string $organisationUuid): array
    {
        // Start with the current organisation.
        $hierarchy = [$organisationUuid];

        // Add all parent organisations.
        $parents = $this->findParentChain(organisationUuid: $organisationUuid);
        if (empty($parents) === false) {
            $hierarchy = array_merge($hierarchy, $parents);
        }

        return $hierarchy;
    }//end getOrganisationHierarchy()
}//end class
