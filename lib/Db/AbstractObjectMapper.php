<?php

/**
 * OpenRegister Abstract Object Mapper
 *
 * Base class defining the interface for object mappers in the OpenRegister application.
 * This abstraction allows the system to switch between different storage strategies
 * (blob storage via ObjectEntityMapper or column-mapped storage via MagicMapper)
 * while maintaining a consistent interface.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
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
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Abstract base class for object mappers
 *
 * Defines the contract that all object mappers must implement, ensuring that both
 * blob storage (ObjectEntityMapper) and column-mapped storage (MagicMapper) provide
 * the same interface for object operations.
 *
 * This abstraction enables:
 * - Transparent switching between storage strategies
 * - Consistent API for all ObjectEntity operations
 * - Support for soft deletes, locking, RBAC, and multi-tenancy
 * - Uniform bulk operations and statistics gathering
 *
 * @package OCA\OpenRegister\Db
 */
abstract class AbstractObjectMapper
{
    // ==================================================================================
    // CORE CRUD OPERATIONS
    // ==================================================================================

    /**
     * Find an object entity by identifier (ID, UUID, slug, or URI).
     *
     * @param string|int    $identifier     Object identifier (ID, UUID, slug, or URI).
     * @param Register|null $register       Optional register to filter by.
     * @param Schema|null   $schema         Optional schema to filter by.
     * @param bool          $includeDeleted Whether to include deleted objects.
     * @param bool          $rbac           Whether to apply RBAC checks (default: true).
     * @param bool          $multitenancy   Whether to apply multitenancy filtering (default: true).
     *
     * @return ObjectEntity The found object.
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException If object not found.
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException If multiple objects found.
     */
    abstract public function find(
        string|int $identifier,
        ?Register $register=null,
        ?Schema $schema=null,
        bool $includeDeleted=false,
        bool $rbac=true,
        bool $multitenancy=true
    ): ObjectEntity;

    /**
     * Find all ObjectEntities with filtering, pagination, and search.
     *
     * @param int|null      $limit            The number of objects to return.
     * @param int|null      $offset           The offset of the objects to return.
     * @param array|null    $filters          The filters to apply to the objects.
     * @param array|null    $searchConditions The search conditions to apply to the objects.
     * @param array|null    $searchParams     The search parameters to apply to the objects.
     * @param array         $sort             The sort order to apply.
     * @param string|null   $search           The search string to apply.
     * @param array|null    $ids              Array of IDs or UUIDs to filter by.
     * @param string|null   $uses             Value that must be present in relations.
     * @param bool          $includeDeleted   Whether to include deleted objects.
     * @param Register|null $register         Optional register to filter objects.
     * @param Schema|null   $schema           Optional schema to filter objects.
     * @param bool|null     $published        If true, only return currently published objects.
     *
     * @return ObjectEntity[]
     *
     * @throws \OCP\DB\Exception If a database error occurs.
     *
     * @psalm-return list<ObjectEntity>
     */
    abstract public function findAll(
        ?int $limit=null,
        ?int $offset=null,
        ?array $filters=null,
        ?array $searchConditions=null,
        ?array $searchParams=null,
        array $sort=[],
        ?string $search=null,
        ?array $ids=null,
        ?string $uses=null,
        bool $includeDeleted=false,
        ?Register $register=null,
        ?Schema $schema=null,
        ?bool $published=null
    ): array;

    /**
     * Find multiple objects by their IDs or UUIDs.
     *
     * @param array $ids Array of IDs or UUIDs.
     *
     * @return ObjectEntity[]
     *
     * @psalm-return list<ObjectEntity>
     */
    abstract public function findMultiple(array $ids): array;

    /**
     * Find all objects for a given schema.
     *
     * @param int $schemaId Schema ID.
     *
     * @return ObjectEntity[]
     *
     * @psalm-return list<ObjectEntity>
     */
    abstract public function findBySchema(int $schemaId): array;

    /**
     * Insert a new object entity with event dispatching.
     *
     * @param Entity $entity Entity to insert.
     *
     * @return Entity Inserted entity.
     *
     * @throws \Exception If insertion fails.
     */
    abstract public function insert(Entity $entity): Entity;

    /**
     * Update an existing object entity with event dispatching.
     *
     * @param Entity $entity Entity to update.
     *
     * @return Entity Updated entity.
     *
     * @throws \Exception If update fails.
     */
    abstract public function update(Entity $entity): Entity;

    /**
     * Delete an object entity with event dispatching.
     *
     * @param Entity $entity Entity to delete.
     *
     * @return Entity Deleted entity.
     *
     * @throws \Exception If deletion fails.
     */
    abstract public function delete(Entity $entity): Entity;

    // ==================================================================================
    // LOCKING OPERATIONS
    // ==================================================================================

    /**
     * Lock an object to prevent concurrent modifications.
     *
     * @param string   $uuid         Object UUID to lock.
     * @param int|null $lockDuration Lock duration in seconds (null for default).
     *
     * @return array Lock information including expiry time.
     *
     * @throws \Exception If locking fails.
     *
     * @psalm-return array{locked: mixed, uuid: string}
     */
    abstract public function lockObject(string $uuid, ?int $lockDuration=null): array;

    /**
     * Unlock an object to allow modifications.
     *
     * @param string $uuid Object UUID to unlock.
     *
     * @return bool True if unlocked successfully.
     *
     * @throws \Exception If unlocking fails.
     */
    abstract public function unlockObject(string $uuid): bool;

    // ==================================================================================
    // BULK OPERATIONS
    // ==================================================================================

    /**
     * ULTRA PERFORMANCE: Memory-intensive unified bulk save operation.
     *
     * @param array $insertObjects Array of arrays (insert data).
     * @param array $updateObjects Array of ObjectEntity instances (update data).
     *
     * @return array Array of processed UUIDs.
     */
    abstract public function ultraFastBulkSave(array $insertObjects=[], array $updateObjects=[]): array;

    /**
     * Perform bulk delete operations on objects by UUID.
     *
     * @param array $uuids      Array of object UUIDs to delete.
     * @param bool  $hardDelete Whether to force hard delete.
     *
     * @return array Array of UUIDs of deleted objects.
     */
    abstract public function deleteObjects(array $uuids=[], bool $hardDelete=false): array;

    /**
     * Perform bulk publish operations on objects by UUID.
     *
     * @param array         $uuids    Array of object UUIDs to publish.
     * @param DateTime|bool $datetime Optional datetime for publishing.
     *
     * @return array Array of UUIDs of published objects.
     */
    abstract public function publishObjects(array $uuids=[], DateTime|bool $datetime=true): array;

    /**
     * Perform bulk depublish operations on objects by UUID.
     *
     * @param array         $uuids    Array of object UUIDs to depublish.
     * @param DateTime|bool $datetime Optional datetime for depublishing.
     *
     * @return array Array of UUIDs of depublished objects.
     */
    abstract public function depublishObjects(array $uuids=[], DateTime|bool $datetime=true): array;

    // ==================================================================================
    // STATISTICS OPERATIONS
    // ==================================================================================

    /**
     * Get statistics for objects.
     *
     * @param int|array|null $registerId Filter by register ID(s).
     * @param int|array|null $schemaId   Filter by schema ID(s).
     * @param array          $exclude    Combinations to exclude.
     *
     * @return array Statistics including total, size, invalid, deleted, locked, published counts.
     */
    abstract public function getStatistics(
        int|array|null $registerId=null,
        int|array|null $schemaId=null,
        array $exclude=[]
    ): array;

    /**
     * Get chart data for objects grouped by register.
     *
     * @param int|null $registerId Filter by register ID.
     * @param int|null $schemaId   Filter by schema ID.
     *
     * @return array Chart data with 'labels' and 'series' keys.
     */
    abstract public function getRegisterChartData(?int $registerId=null, ?int $schemaId=null): array;

    /**
     * Get chart data for objects grouped by schema.
     *
     * @param int|null $registerId Filter by register ID.
     * @param int|null $schemaId   Filter by schema ID.
     *
     * @return array Chart data with 'labels' and 'series' keys.
     */
    abstract public function getSchemaChartData(?int $registerId=null, ?int $schemaId=null): array;

    // ==================================================================================
    // FACETING OPERATIONS
    // ==================================================================================

    /**
     * Get simple facets using the facet handlers.
     *
     * @param array $query Search query array containing filters and facet configuration.
     *
     * @return array Simple facet data.
     *
     * @throws \OCP\DB\Exception If a database error occurs.
     */
    abstract public function getSimpleFacets(array $query=[]): array;

    /**
     * Get facetable fields from schemas.
     *
     * @param array $baseQuery Base query filters for context.
     *
     * @return array Facetable fields with their configuration.
     *
     * @throws \OCP\DB\Exception If a database error occurs.
     */
    abstract public function getFacetableFieldsFromSchemas(array $baseQuery=[]): array;

    // ==================================================================================
    // SEARCH OPERATIONS
    // ==================================================================================

    /**
     * Search for objects with complex filtering.
     *
     * @param array       $query                  Query parameters.
     * @param string|null $activeOrganisationUuid Active organisation UUID.
     * @param bool        $rbac                   Whether to apply RBAC checks.
     * @param bool        $multitenancy           Whether to apply multitenancy filtering.
     * @param array|null  $ids                    Array of IDs or UUIDs to filter by.
     * @param string|null $uses                   Value that must be present in relations.
     *
     * @return ObjectEntity[]|int
     *
     * @psalm-return list<ObjectEntity>|int
     */
    abstract public function searchObjects(
        array $query=[],
        ?string $activeOrganisationUuid=null,
        bool $rbac=true,
        bool $multitenancy=true,
        ?array $ids=null,
        ?string $uses=null
    ): array|int;

    /**
     * Count search results.
     *
     * @param array       $query                  Query parameters.
     * @param string|null $activeOrganisationUuid Active organisation UUID.
     * @param bool        $rbac                   Whether to apply RBAC checks.
     * @param bool        $multitenancy           Whether to apply multitenancy filtering.
     * @param array|null  $ids                    Array of IDs or UUIDs to filter by.
     * @param string|null $uses                   Value that must be present in relations.
     *
     * @return int Count of objects.
     */
    abstract public function countSearchObjects(
        array $query=[],
        ?string $activeOrganisationUuid=null,
        bool $rbac=true,
        bool $multitenancy=true,
        ?array $ids=null,
        ?string $uses=null
    ): int;

    /**
     * Count all objects with optional filtering.
     *
     * @param array|null    $filters  Filter parameters.
     * @param Schema|null   $schema   Optional schema to filter by.
     * @param Register|null $register Optional register to filter by.
     *
     * @return int Count of objects.
     */
    abstract public function countAll(
        ?array $filters=null,
        ?Schema $schema=null,
        ?Register $register=null
    ): int;

    // ==================================================================================
    // QUERY BUILDER OPERATIONS
    // ==================================================================================

    /**
     * Get query builder instance.
     *
     * @return IQueryBuilder Query builder instance.
     */
    abstract public function getQueryBuilder(): IQueryBuilder;

    /**
     * Get the actual max_allowed_packet value from the database.
     *
     * @return int The max_allowed_packet value in bytes.
     */
    abstract public function getMaxAllowedPacketSize(): int;
}//end class
