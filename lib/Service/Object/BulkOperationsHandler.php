<?php

/**
 * BulkOperationsHandler
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/ConductionNL/openregister
 */

namespace OCA\OpenRegister\Service\Object;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\SaveObjects;
use Psr\Log\LoggerInterface;

/**
 * Handles bulk operations for ObjectService.
 *
 * This handler is responsible for:
 * - Bulk save operations with cache invalidation
 * - Bulk delete operations
 * - Bulk publish/depublish operations
 * - Schema-wide and register-wide operations
 * - Permission filtering for bulk operations
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/ConductionNL/openregister
 * @version  1.0.0
 */
class BulkOperationsHandler
{
    /**
     * Constructor for BulkOperationsHandler.
     *
     * @param SaveObjects        $saveObjectsHandler Handler for bulk save operations.
     * @param ObjectEntityMapper $objectEntityMapper Mapper for object entities.
     * @param PermissionHandler  $permissionHandler  Handler for permission operations.
     * @param CacheHandler       $cacheHandler       Handler for cache operations.
     * @param LoggerInterface    $logger             Logger for logging operations.
     */
    public function __construct(
        private readonly SaveObjects $saveObjectsHandler,
        private readonly ObjectEntityMapper $objectEntityMapper,
        private readonly PermissionHandler $permissionHandler,
        private readonly CacheHandler $cacheHandler,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Bulk save operations orchestrator with cache invalidation.
     *
     * @param array         $objects         Array of objects to save.
     * @param Register|null $currentRegister Current register context.
     * @param Schema|null   $currentSchema   Current schema context.
     * @param bool          $_rbac           Whether to apply RBAC checks.
     * @param bool          $_multitenancy   Whether to apply multitenancy filtering.
     * @param bool          $validation      Whether to validate objects.
     * @param bool          $events          Whether to trigger events.
     *
     * @psalm-param array<int, array<string, mixed>> $objects
     * @psalm-param Register|null $currentRegister
     * @psalm-param Schema|null $currentSchema
     *
     * @phpstan-param array<int, array<string, mixed>> $objects
     * @phpstan-param Register|null $currentRegister
     * @phpstan-param Schema|null $currentSchema
     *
     * @return array Bulk save results with performance, statistics, and categorized objects.
     */
    public function saveObjects(
        array $objects,
        ?Register $currentRegister=null,
        ?Schema $currentSchema=null,
        bool $_rbac=true,
        bool $_multitenancy=true,
        bool $validation=false,
        bool $events=false
    ): array {

        // ARCHITECTURAL DELEGATION: Use specialized SaveObjects handler for bulk operations.
        // This provides better separation of concerns and optimized bulk processing.
        $bulkResult = $this->saveObjectsHandler->saveObjects(
            objects: $objects,
            register: $currentRegister,
            schema: $currentSchema,
            _rbac: $_rbac,
            _multitenancy: $_multitenancy,
            validation: $validation,
            events: $events
        );

        // **BULK CACHE INVALIDATION**: Clear collection caches after successful bulk operations.
        // Bulk imports can create/update hundreds of objects, requiring cache invalidation
        // To ensure collection queries immediately reflect the new/updated data.
        try {
            $createdCount  = $bulkResult['statistics']['objectsCreated'] ?? 0;
            $updatedCount  = $bulkResult['statistics']['objectsUpdated'] ?? 0;
            $totalAffected = $createdCount + $updatedCount;

            if ($totalAffected > 0) {
                $this->logger->debug(
                    message: 'Bulk operation cache invalidation starting',
                    context: [
                        'objectsCreated' => $createdCount,
                        'objectsUpdated' => $updatedCount,
                        'totalAffected'  => $totalAffected,
                        'register'       => $currentRegister?->getId(),
                        'schema'         => $currentSchema?->getId(),
                    ]
                );

                // **BULK CACHE COORDINATION**: Invalidate collection caches for affected contexts.
                // This ensures that GET collection calls immediately see the bulk imported objects.
                $this->cacheHandler->invalidateForObjectChange(
                    object: null,
                    // Bulk operation affects multiple objects.
                        operation: 'bulk_save',
                    registerId: $currentRegister?->getId(),
                    schemaId: $currentSchema?->getId()
                );

                $this->logger->debug(
                    message: 'Bulk operation cache invalidation completed',
                    context: [
                        'totalAffected'     => $totalAffected,
                        'cacheInvalidation' => 'success',
                    ]
                );
            }//end if
        } catch (Exception $e) {
            // Log cache invalidation errors but don't fail the bulk operation.
            $this->logger->warning(
                message: 'Bulk operation cache invalidation failed',
                context: [
                    'error'         => $e->getMessage(),
                    'totalAffected' => $totalAffected ?? 0,
                ]
            );
        }//end try

        return $bulkResult;
    }//end saveObjects()

    /**
     * Bulk delete operations with cache invalidation.
     *
     * @param array         $uuids         Array of UUIDs to delete.
     * @param bool          $_rbac         Whether to apply RBAC filtering.
     * @param bool          $_multitenancy Whether to apply multitenancy filtering.
     * @param Register|null $register      Optional register to filter by.
     * @param Schema|null   $schema        Optional schema to filter by.
     *
     * @return array Array of deleted object IDs.
     *
     * @psalm-param    array<int, string> $uuids
     * @phpstan-param  array<int, string> $uuids
     * @psalm-return   array<int, int>
     * @phpstan-return array<int, int>
     */
    public function deleteObjects(
        array $uuids=[],
        bool $_rbac=true,
        bool $_multitenancy=true,
        ?Register $register=null,
        ?Schema $schema=null
    ): array {
        if (empty($uuids) === true) {
            return [];
        }

        // Apply RBAC and multi-organization filtering if enabled.
        if ($_rbac === true || $_multitenancy === true) {
            $filteredUuids = $this->permissionHandler->filterUuidsForPermissions(
                uuids: $uuids,
                rbac: $_rbac,
                multitenancy: $_multitenancy
            );
        } else {
            $filteredUuids = $uuids;
        }

        // Use the mapper's bulk delete operation (now with register/schema for magic mapper).
        $deletedObjectIds = $this->objectEntityMapper->deleteObjects(
            uuids: $filteredUuids,
            hardDelete: false,
            register: $register,
            schema: $schema
        );

        // **BULK CACHE INVALIDATION**: Clear collection caches after bulk delete operations.
        if (empty($deletedObjectIds) === false) {
            try {
                $this->logger->debug(
                    message: 'Bulk delete cache invalidation starting',
                    context: [
                        'deletedCount' => count($deletedObjectIds),
                        'operation'    => 'bulk_delete',
                    ]
                );

                $this->cacheHandler->invalidateForObjectChange(
                    object: null,
                    // Bulk operation affects multiple objects.
                    operation: 'bulk_delete',
                    registerId: null,
                    // Affects multiple registers potentially.
                    schemaId: null
                    // Affects multiple schemas potentially.
                );

                $this->logger->debug(
                    message: 'Bulk delete cache invalidation completed',
                    context: [
                        'deletedCount'      => count($deletedObjectIds),
                        'cacheInvalidation' => 'success',
                    ]
                );
            } catch (Exception $e) {
                $this->logger->warning(
                    message: 'Bulk delete cache invalidation failed',
                    context: [
                        'error'        => $e->getMessage(),
                        'deletedCount' => count($deletedObjectIds),
                    ]
                );
            }//end try
        }//end if

        return $deletedObjectIds;
    }//end deleteObjects()

    /**
     * Perform bulk publish operations on objects by UUID.
     *
     * @param array         $uuids         Array of object UUIDs to publish.
     * @param DateTime|bool $datetime      Optional datetime for publishing (false to unset).
     * @param bool          $_rbac         Whether to apply RBAC filtering.
     * @param bool          $_multitenancy Whether to apply multi-organization filtering.
     * @param Register|null $register      Optional register to filter by.
     * @param Schema|null   $schema        Optional schema to filter by.
     *
     * @return array Array of UUIDs of published objects.
     *
     * @psalm-param    array<int, string> $uuids
     * @phpstan-param  array<int, string> $uuids
     * @psalm-return   array<int, string>
     * @phpstan-return array<int, string>
     */
    public function publishObjects(
        array $uuids=[],
        DateTime|bool $datetime=true,
        bool $_rbac=true,
        bool $_multitenancy=true,
        ?Register $register=null,
        ?Schema $schema=null
    ): array {
        if (empty($uuids) === true) {
            return [];
        }

        // Apply RBAC and multi-organization filtering if enabled.
        if ($_rbac === true || $_multitenancy === true) {
            $filteredUuids = $this->permissionHandler->filterUuidsForPermissions(
                uuids: $uuids,
                rbac: $_rbac,
                multitenancy: $_multitenancy
            );
        } else {
            $filteredUuids = $uuids;
        }

        // Use the mapper's bulk publish operation (now with register/schema for magic mapper).
        $publishedObjectIds = $this->objectEntityMapper->publishObjects(
            uuids: $filteredUuids,
            datetime: $datetime,
            register: $register,
            schema: $schema
        );

        // **BULK CACHE INVALIDATION**: Clear collection caches after bulk publish operations.
        if (empty($publishedObjectIds) === false) {
            try {
                $this->logger->debug(
                    message: 'Bulk publish cache invalidation starting',
                    context: [
                        'publishedCount' => count($publishedObjectIds),
                        'operation'      => 'bulk_publish',
                    ]
                );

                $this->cacheHandler->invalidateForObjectChange(
                    object: null,
                    // Bulk operation affects multiple objects.
                    operation: 'bulk_publish',
                    registerId: null,
                    // Affects multiple registers potentially.
                    schemaId: null
                    // Affects multiple schemas potentially.
                );

                $this->logger->debug(
                    message: 'Bulk publish cache invalidation completed',
                    context: [
                        'publishedCount'    => count($publishedObjectIds),
                        'cacheInvalidation' => 'success',
                    ]
                );
            } catch (Exception $e) {
                $this->logger->warning(
                    message: 'Bulk publish cache invalidation failed',
                    context: [
                        'error'          => $e->getMessage(),
                        'publishedCount' => count($publishedObjectIds),
                    ]
                );
            }//end try
        }//end if

        return $publishedObjectIds;
    }//end publishObjects()

    /**
     * Perform bulk depublish operations on objects by UUID.
     *
     * @param array         $uuids         Array of object UUIDs to depublish.
     * @param DateTime|bool $datetime      Optional datetime for depublishing (false to unset).
     * @param bool          $_rbac         Whether to apply RBAC filtering.
     * @param bool          $_multitenancy Whether to apply multi-organization filtering.
     * @param Register|null $register      Optional register to filter by.
     * @param Schema|null   $schema        Optional schema to filter by.
     *
     * @return array Array of UUIDs of depublished objects.
     *
     * @psalm-param    array<int, string> $uuids
     * @phpstan-param  array<int, string> $uuids
     * @psalm-return   array<int, string>
     * @phpstan-return array<int, string>
     */
    public function depublishObjects(
        array $uuids=[],
        DateTime|bool $datetime=true,
        bool $_rbac=true,
        bool $_multitenancy=true,
        ?Register $register=null,
        ?Schema $schema=null
    ): array {
        if (empty($uuids) === true) {
            return [];
        }

        // Apply RBAC and multi-organization filtering if enabled.
        if ($_rbac === true || $_multitenancy === true) {
            $filteredUuids = $this->permissionHandler->filterUuidsForPermissions(
                uuids: $uuids,
                rbac: $_rbac,
                multitenancy: $_multitenancy
            );
        } else {
            $filteredUuids = $uuids;
        }

        // Use the mapper's bulk depublish operation (now with register/schema for magic mapper).
        $depublishedObjectIds = $this->objectEntityMapper->depublishObjects(
            uuids: $filteredUuids,
            datetime: $datetime,
            register: $register,
            schema: $schema
        );

        // **BULK CACHE INVALIDATION**: Clear collection caches after bulk depublish operations.
        if (empty($depublishedObjectIds) === false) {
            try {
                $this->logger->debug(
                    message: 'Bulk depublish cache invalidation starting',
                    context: [
                        'depublishedCount' => count($depublishedObjectIds),
                        'operation'        => 'bulk_depublish',
                    ]
                );

                $this->cacheHandler->invalidateForObjectChange(
                    object: null,
                    // Bulk operation affects multiple objects.
                    operation: 'bulk_depublish',
                    registerId: null,
                    // Affects multiple registers potentially.
                    schemaId: null
                    // Affects multiple schemas potentially.
                );

                $this->logger->debug(
                    message: 'Bulk depublish cache invalidation completed',
                    context: [
                        'depublishedCount'  => count($depublishedObjectIds),
                        'cacheInvalidation' => 'success',
                    ]
                );
            } catch (Exception $e) {
                $this->logger->warning(
                    message: 'Bulk depublish cache invalidation failed',
                    context: [
                        'error'            => $e->getMessage(),
                        'depublishedCount' => count($depublishedObjectIds),
                    ]
                );
            }//end try
        }//end if

        return $depublishedObjectIds;
    }//end depublishObjects()

    /**
     * Publish all objects belonging to a specific schema.
     *
     * @param int  $schemaId   The ID of the schema whose objects should be published.
     * @param bool $publishAll Whether to publish all objects (default: false).
     *
     * @return array Result array with published count, uuids, and schema ID.
     *
     * @throws \Exception If the publishing operation fails.
     *
     * @phpstan-return array{published_count: int, published_uuids: array<int, string>, schema_id: int}
     * @psalm-return   array{published_count: int<min, max>, published_uuids: array<int, string>, schema_id: int}
     */
    public function publishObjectsBySchema(int $schemaId, bool $publishAll=false): array
    {
        // Use the mapper's schema publishing operation.
        $result = $this->objectEntityMapper->publishObjectsBySchema(schemaId: $schemaId, publishAll: $publishAll);

        // **BULK CACHE INVALIDATION**: Clear collection caches after bulk publish operations.
        if ($result['published_count'] > 0) {
            try {
                $this->logger->debug(
                    message: 'Schema objects publishing cache invalidation starting',
                    context: [
                        'publishedCount' => $result['published_count'],
                        'schemaId'       => $schemaId,
                        'operation'      => 'schema_publish',
                        'publishAll'     => $publishAll,
                    ]
                );

                $this->cacheHandler->invalidateForObjectChange(
                    object: null,
                    operation: 'bulk_publish',
                    registerId: null,
                    schemaId: $schemaId
                );

                $this->logger->debug(
                    message: 'Schema objects publishing cache invalidation completed',
                    context: [
                        'publishedCount' => $result['published_count'],
                        'schemaId'       => $schemaId,
                        'publishAll'     => $publishAll,
                    ]
                );
            } catch (Exception $e) {
                $this->logger->warning(
                    message: 'Schema objects publishing cache invalidation failed',
                    context: [
                        'error'          => $e->getMessage(),
                        'schemaId'       => $schemaId,
                        'publishedCount' => $result['published_count'],
                        'publishAll'     => $publishAll,
                    ]
                );
            }//end try
        }//end if

        return $result;
    }//end publishObjectsBySchema()

    /**
     * Delete all objects belonging to a specific schema.
     *
     * @param int  $schemaId   The ID of the schema whose objects should be deleted.
     * @param bool $hardDelete Whether to force hard delete (default: false).
     *
     * @return array Result array with deleted count, uuids, and schema ID.
     *
     * @throws \Exception If the deletion operation fails.
     *
     * @phpstan-return array{deleted_count: int, deleted_uuids: array<int, string>, schema_id: int}
     * @psalm-return   array{deleted_count: int<min, max>, deleted_uuids: array<int, string>, schema_id: int}
     */
    public function deleteObjectsBySchema(int $schemaId, bool $hardDelete=false): array
    {
        // Use the mapper's schema deletion operation.
        $result = $this->objectEntityMapper->deleteObjectsBySchema(schemaId: $schemaId, hardDelete: $hardDelete);

        // **BULK CACHE INVALIDATION**: Clear collection caches after bulk delete operations.
        if ($result['deleted_count'] > 0) {
            try {
                $this->logger->debug(
                    message: 'Schema objects deletion cache invalidation starting',
                    context: [
                        'deletedCount' => $result['deleted_count'],
                        'schemaId'     => $schemaId,
                        'operation'    => 'schema_delete',
                        'hardDelete'   => $hardDelete,
                    ]
                );

                $this->cacheHandler->invalidateForObjectChange(
                    object: null,
                    operation: 'bulk_delete',
                    registerId: null,
                    schemaId: $schemaId
                );

                $this->logger->debug(
                    message: 'Schema objects deletion cache invalidation completed',
                    context: [
                        'deletedCount' => $result['deleted_count'],
                        'schemaId'     => $schemaId,
                        'hardDelete'   => $hardDelete,
                    ]
                );
            } catch (Exception $e) {
                $this->logger->warning(
                    message: 'Schema objects deletion cache invalidation failed',
                    context: [
                        'error'        => $e->getMessage(),
                        'schemaId'     => $schemaId,
                        'deletedCount' => $result['deleted_count'],
                        'hardDelete'   => $hardDelete,
                    ]
                );
            }//end try
        }//end if

        return $result;
    }//end deleteObjectsBySchema()

    /**
     * Delete all objects belonging to a specific register.
     *
     * @param int $registerId The ID of the register whose objects should be deleted.
     *
     * @return array Result array with deleted count, uuids, and register ID.
     *
     * @throws \Exception If the deletion operation fails.
     *
     * @phpstan-return array{deleted_count: int, deleted_uuids: array<int, string>, register_id: int}
     * @psalm-return   array{deleted_count: int<min, max>, deleted_uuids: array<int, string>, register_id: int}
     */
    public function deleteObjectsByRegister(int $registerId): array
    {
        // Use the mapper's register deletion operation.
        $result = $this->objectEntityMapper->deleteObjectsByRegister($registerId);

        // **BULK CACHE INVALIDATION**: Clear collection caches after bulk delete operations.
        if ($result['deleted_count'] > 0) {
            try {
                $this->logger->debug(
                    message: 'Register objects deletion cache invalidation starting',
                    context: [
                        'deletedCount' => $result['deleted_count'],
                        'registerId'   => $registerId,
                        'operation'    => 'register_delete',
                    ]
                );

                $this->cacheHandler->invalidateForObjectChange(
                    object: null,
                    operation: 'bulk_delete',
                    registerId: $registerId,
                    schemaId: null
                );

                $this->logger->debug(
                    message: 'Register objects deletion cache invalidation completed',
                    context: [
                        'deletedCount' => $result['deleted_count'],
                        'registerId'   => $registerId,
                    ]
                );
            } catch (Exception $e) {
                $this->logger->warning(
                    message: 'Register objects deletion cache invalidation failed',
                    context: [
                        'error'        => $e->getMessage(),
                        'registerId'   => $registerId,
                        'deletedCount' => $result['deleted_count'],
                    ]
                );
            }//end try
        }//end if

        return $result;
    }//end deleteObjectsByRegister()
}//end class
