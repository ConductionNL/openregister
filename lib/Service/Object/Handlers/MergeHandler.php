<?php

/**
 * Merge Handler
 *
 * Handles object merging and migration operations.
 * Manages complex operations for combining objects and moving them between schemas/registers.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Objects\Handlers
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object\Handlers;

use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * MergeHandler
 *
 * Responsible for merging and migrating objects.
 *
 * RESPONSIBILITIES:
 * - Merge two objects into one
 * - Migrate objects between registers/schemas
 * - Handle property mapping during migration
 * - Validate merge/migration operations
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Objects\Handlers
 */
class MergeHandler
{


    /**
     * Constructor
     *
     * @param ObjectService   $objectService Object service
     * @param LoggerInterface $logger        PSR-3 logger
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly LoggerInterface $logger
    ) {

    }//end __construct()


    /**
     * Merge two objects
     *
     * Merges a source object into a target object.
     * Handles properties, files, and relations based on merge configuration.
     *
     * @param string $sourceObjectId Source object ID (object to merge from)
     * @param array  $mergeData      Merge configuration including target object ID
     *
     * @return array Merge result with statistics and details
     *
     * @throws DoesNotExistException     If source or target object not found
     * @throws \InvalidArgumentException If merge configuration invalid
     * @throws \Exception                If merge operation fails
     */
    public function merge(string $sourceObjectId, array $mergeData): array
    {
        $this->logger->info(
            message: '[MergeHandler] Starting object merge',
            context: [
                'source_object_id' => $sourceObjectId,
                'target_object_id' => $mergeData['target'] ?? null,
            ]
        );

        try {
            // Validate required parameters.
            if (isset($mergeData['target']) === false) {
                throw new \InvalidArgumentException('Target object ID is required');
            }

            if (isset($mergeData['object']) === false || empty($mergeData['object']) === true) {
                throw new \InvalidArgumentException('Object data is required');
            }

            // Delegate to ObjectService for the actual merge operation.
            $result = $this->objectService->mergeObjects(
                sourceObjectId: $sourceObjectId,
                mergeData: $mergeData
            );

            $this->logger->info(
                message: '[MergeHandler] Object merge completed',
                context: [
                    'source_object_id' => $sourceObjectId,
                    'target_object_id' => $mergeData['target'],
                    'success'          => $result['success'] ?? false,
                ]
            );

            return $result;
        } catch (DoesNotExistException $e) {
            $this->logger->error(
                message: '[MergeHandler] Object not found during merge',
                context: [
                    'source_object_id' => $sourceObjectId,
                    'error'            => $e->getMessage(),
                ]
            );
            throw $e;
        } catch (\InvalidArgumentException $e) {
            $this->logger->error(
                message: '[MergeHandler] Invalid merge configuration',
                context: [
                    'source_object_id' => $sourceObjectId,
                    'error'            => $e->getMessage(),
                ]
            );
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[MergeHandler] Failed to merge objects',
                context: [
                    'source_object_id' => $sourceObjectId,
                    'error'            => $e->getMessage(),
                ]
            );
            throw new \Exception('Failed to merge objects: '.$e->getMessage());
        }//end try

    }//end merge()


    /**
     * Migrate objects between registers and/or schemas
     *
     * Moves multiple objects from one register/schema to another with property mapping.
     *
     * @param string $sourceRegister Source register ID or slug
     * @param string $sourceSchema   Source schema ID or slug
     * @param string $targetRegister Target register ID or slug
     * @param string $targetSchema   Target schema ID or slug
     * @param array  $objectIds      Array of object IDs to migrate
     * @param array  $mapping        Property mapping configuration
     *
     * @return array Migration result with statistics and details
     *
     * @throws DoesNotExistException     If register or schema not found
     * @throws \InvalidArgumentException If migration configuration invalid
     * @throws \Exception                If migration operation fails
     */
    public function migrate(
        string $sourceRegister,
        string $sourceSchema,
        string $targetRegister,
        string $targetSchema,
        array $objectIds,
        array $mapping
    ): array {
        $this->logger->info(
            message: '[MergeHandler] Starting object migration',
            context: [
                'source_register' => $sourceRegister,
                'source_schema'   => $sourceSchema,
                'target_register' => $targetRegister,
                'target_schema'   => $targetSchema,
                'object_count'    => count($objectIds),
            ]
        );

        try {
            // Validate required parameters.
            $this->validateMigrationParams(
                $sourceRegister,
                $sourceSchema,
                $targetRegister,
                $targetSchema,
                $objectIds,
                $mapping
            );

            // Delegate to ObjectService for the actual migration operation.
            $result = $this->objectService->migrateObjects(
                sourceRegister: $sourceRegister,
                sourceSchema: $sourceSchema,
                targetRegister: $targetRegister,
                targetSchema: $targetSchema,
                objectIds: $objectIds,
                mapping: $mapping
            );

            $this->logger->info(
                message: '[MergeHandler] Object migration completed',
                context: [
                    'source_register'  => $sourceRegister,
                    'source_schema'    => $sourceSchema,
                    'target_register'  => $targetRegister,
                    'target_schema'    => $targetSchema,
                    'objects_migrated' => $result['statistics']['objectsMigrated'] ?? 0,
                    'objects_failed'   => $result['statistics']['objectsFailed'] ?? 0,
                ]
            );

            return $result;
        } catch (DoesNotExistException $e) {
            $this->logger->error(
                message: '[MergeHandler] Register or schema not found during migration',
                context: [
                    'source_register' => $sourceRegister,
                    'source_schema'   => $sourceSchema,
                    'target_register' => $targetRegister,
                    'target_schema'   => $targetSchema,
                    'error'           => $e->getMessage(),
                ]
            );
            throw $e;
        } catch (\InvalidArgumentException $e) {
            $this->logger->error(
                message: '[MergeHandler] Invalid migration configuration',
                context: [
                    'error' => $e->getMessage(),
                ]
            );
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[MergeHandler] Failed to migrate objects',
                context: [
                    'source_register' => $sourceRegister,
                    'source_schema'   => $sourceSchema,
                    'target_register' => $targetRegister,
                    'target_schema'   => $targetSchema,
                    'error'           => $e->getMessage(),
                ]
            );
            throw new \Exception('Failed to migrate objects: '.$e->getMessage());
        }//end try

    }//end migrate()


    /**
     * Validate migration parameters
     *
     * @param string $sourceRegister Source register
     * @param string $sourceSchema   Source schema
     * @param string $targetRegister Target register
     * @param string $targetSchema   Target schema
     * @param array  $objectIds      Object IDs
     * @param array  $mapping        Property mapping
     *
     * @return void
     *
     * @throws \InvalidArgumentException If parameters are invalid
     */
    private function validateMigrationParams(
        string $sourceRegister,
        string $sourceSchema,
        string $targetRegister,
        string $targetSchema,
        array $objectIds,
        array $mapping
    ): void {
        if (empty($sourceRegister) === true || empty($sourceSchema) === true) {
            throw new \InvalidArgumentException('Source register and schema are required');
        }

        if (empty($targetRegister) === true || empty($targetSchema) === true) {
            throw new \InvalidArgumentException('Target register and schema are required');
        }

        if (empty($objectIds) === true) {
            throw new \InvalidArgumentException('At least one object ID is required');
        }

        if (empty($mapping) === true) {
            throw new \InvalidArgumentException('Property mapping is required');
        }

    }//end validateMigrationParams()


}//end class
