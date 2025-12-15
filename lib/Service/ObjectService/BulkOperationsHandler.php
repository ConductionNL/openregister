<?php

/**
 * BulkOperationsHandler
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0
 * @link     https://github.com/ConductionNL/openregister
 */

namespace OCA\OpenRegister\Service\ObjectService;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Objects\CacheHandler;
use OCA\OpenRegister\Service\Objects\PermissionHandler;
use OCA\OpenRegister\Service\Objects\SaveObjects;
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
 * @license  AGPL-3.0
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
     * @return array Bulk operation result.
     *
     * @psalm-param    array<int, array<string, mixed>> $objects
     * @phpstan-param  array<int, array<string, mixed>> $objects
     * @psalm-param    Register|null $currentRegister
     * @phpstan-param  Register|null $currentRegister
     * @psalm-param    Schema|null $currentSchema
     * @phpstan-param  Schema|null $currentSchema
     * @psalm-return   array<string, mixed>
     * @phpstan-return array<string, mixed>
     */
    public function saveObjects(
        array $objects,
        ?Register $currentRegister = null,
        ?Schema $currentSchema = null,
        bool $_rbac = true,
        bool $_multitenancy = true,
        bool $validation = false,
        bool $events = false
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
        // to ensure collection queries immediately reflect the new/updated data.
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
     * @param array $uuids         Array of UUIDs to delete.
     * @param bool  $_rbac         Whether to apply RBAC filtering.
     * @param bool  $_multitenancy Whether to apply multitenancy filtering.
     *
     * @return array Array of deleted object IDs.
     *
     * @psalm-param    array<int, string> $uuids
     * @phpstan-param  array<int, string> $uuids
     * @psalm-return   array<int, int>
     * @phpstan-return array<int, int>
     */
    public function deleteObjects(array $uuids = [], bool $_rbac = true, bool $_multitenancy = true): array
    {
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

        // Use the mapper's bulk delete operation.
        $deletedObjectIds = $this->objectEntityMapper->deleteObjects($filteredUuids);

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
     *
     * @return array Array of UUIDs of published objects.
     *
     * @psalm-param    array<int, string> $uuids
     * @phpstan-param  array<int, string> $uuids
     * @psalm-return   array<int, string>
     * @phpstan-return array<int, string>
     */
    public function publishObjects(array $uuids = [], DateTime|bool $datetime = true, bool $_rbac = true, bool $_multitenancy = true): array
    {
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

        // Use the mapper's bulk publish operation.
        $publishedObjectIds = $this->objectEntityMapper->publishObjects(uuids: $filteredUuids, datetime: $datetime);

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
     *
     * @return array Array of UUIDs of depublished objects.
     *
     * @psalm-param    array<int, string> $uuids
     * @phpstan-param  array<int, string> $uuids
     * @psalm-return   array<int, string>
     * @phpstan-return array<int, string>
     */
    public function depublishObjects(array $uuids = [], DateTime|bool $datetime = true, bool $_rbac = true, bool $_multitenancy = true): array
    {
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

        // Use the mapper's bulk depublish operation.
        $depublishedObjectIds = $this->objectEntityMapper->depublishObjects(uuids: $filteredUuids, datetime: $datetime);

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
}//end class
