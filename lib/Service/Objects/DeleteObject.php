<?php
/**
 * OpenRegister DeleteObject Handler
 *
 * Handler class responsible for removing objects from the system.
 * This handler provides methods for:
 * - Deleting objects from the database
 * - Handling cascading deletes for related objects
 * - Cleaning up associated files and resources
 * - Managing deletion dependencies
 * - Maintaining referential integrity
 * - Tracking deletion operations
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Service\Objects;

use Exception;
use JsonSerializable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectCacheService;
use OCA\OpenRegister\Service\SchemaCacheService;
use OCA\OpenRegister\Service\SchemaFacetCacheService;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Service\SettingsService;
use Psr\Log\LoggerInterface;

/**
 * Handler class for deleting objects in the OpenRegister application.
 *
 * This handler is responsible for deleting objects from the database,
 * including handling cascading deletes and file cleanup.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Objects
 * @author    Conduction b.v. <info@conduction.nl>
 * @license   AGPL-3.0-or-later
 * @link      https://github.com/OpenCatalogi/OpenRegister
 * @version   GIT: <git_id>
 * @copyright 2024 Conduction b.v.
 */
class DeleteObject
{

    /**
     * Audit trail mapper
     *
     * @var AuditTrailMapper
     */
    private AuditTrailMapper $auditTrailMapper;

    /**
     * Settings service
     *
     * @var SettingsService
     */
    private SettingsService $settingsService;

    /**
     * Logger interface
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;


    /**
     * Constructor for DeleteObject handler.
     *
     * @param ObjectEntityMapper      $objectEntityMapper      Object entity data mapper.
     * @param FileService             $fileService             File service for managing files.
     * @param ObjectCacheService      $objectCacheService      Object cache service for entity and query caching.
     * @param SchemaCacheService      $schemaCacheService      Schema cache service for schema entity caching.
     * @param SchemaFacetCacheService $schemaFacetCacheService Schema facet cache service for facet caching.
     * @param AuditTrailMapper        $auditTrailMapper        Audit trail mapper for logs.
     * @param SettingsService         $settingsService         Settings service for accessing trail settings.
     * @param LoggerInterface         $logger                  Logger for error handling.
     */
    public function __construct(
        private readonly ObjectEntityMapper $objectEntityMapper,
        private readonly FileService $fileService,
        private readonly ObjectCacheService $objectCacheService,
        AuditTrailMapper $auditTrailMapper,
        SettingsService $settingsService,
        LoggerInterface $logger
    ) {
        $this->auditTrailMapper = $auditTrailMapper;
        $this->settingsService  = $settingsService;
        $this->logger           = $logger;

    }//end __construct()


    /**
     * Deletes an object and its associated files.
     *
     * @param array|JsonSerializable $object The object to delete.
     *
     * @return bool Whether the deletion was successful.
     *
     * @throws Exception If there is an error during deletion.
     */
    public function delete(array | JsonSerializable $object): bool
    {
        if ($object instanceof JsonSerializable === true) {
            $objectEntity = $object;
            $object->jsonSerialize();
        } else {
            $objectEntity = $this->objectEntityMapper->find($object['id']);
        }

        // Delete associated files from storage.
        $files = $this->fileService->getFiles($objectEntity);
        foreach ($files ?? [] as $file) {
            $this->fileService->deleteFile(file: $file->getName(), object: $objectEntity);
        }

        // Delete the object folder if it exists (for hard deletes).
        $this->deleteObjectFolder($objectEntity);

        // Delete the object from database.
        /*
         * @psalm-suppress InvalidArgument - ObjectEntity extends Entity
         */
        $result = $this->objectEntityMapper->delete($objectEntity) !== null;

        // **CACHE INVALIDATION**: Clear collection and facet caches so deleted objects disappear immediately.
        if ($result === true) {
            // ObjectEntity has getRegister() and getSchema() methods that return string|null.
            // Convert to int|null for invalidateForObjectChange which expects ?int.
            /*
             * @var ObjectEntity $objectEntity
             */
            $registerId = $objectEntity->getRegister();
            $schemaId   = $objectEntity->getSchema();

            // Convert register ID to int if numeric.
            if ($registerId !== null && is_numeric($registerId) === true) {
                $registerIdInt = (int) $registerId;
            } else {
                $registerIdInt = null;
            }

            // Convert schema ID to int if numeric.
            if ($schemaId !== null && is_numeric($schemaId) === true) {
                $schemaIdInt = (int) $schemaId;
            } else {
                $schemaIdInt = null;
            }

            $this->objectCacheService->invalidateForObjectChange(
                object: $objectEntity,
                operation: 'delete',
                registerId: $registerIdInt,
                schemaId: $schemaIdInt
            );
        }//end if

        // Create audit trail for delete if audit trails are enabled.
        if ($this->isAuditTrailsEnabled() === true) {
            $this->auditTrailMapper->createAuditTrail(old: $objectEntity, new: null, action: 'delete');
            // $result->setLastLog($log->jsonSerialize());
        }

        return $result;

    }//end delete()


    /**
     * Deletes an object by its UUID with optional cascading.
     *
     * @param Register|int|string $register         The register containing the object.
     * @param Schema|int|string   $schema           The schema of the object.
     * @param string              $uuid             The UUID of the object to delete.
     * @param string|null         $originalObjectId The ID of original object for cascading.
     * @param bool                $rbac             Whether to apply RBAC checks (default: true).
     * @param bool                $multi            Whether to apply multitenancy filtering (default: true).
     *
     * @return bool Whether the deletion was successful.
     *
     * @throws Exception If there is an error during deletion.
     */
    public function deleteObject(
        Register | int | string $register,
        Schema | int | string $schema,
        string $uuid,
        ?string $originalObjectId=null,
        bool $_rbac=true,
        bool $_multi=true
    ): bool {
        try {
            $object = $this->objectEntityMapper->find($uuid, null, null, true);

            // Handle cascading deletes if this is the root object.
            if ($originalObjectId === null) {
                $this->cascadeDeleteObjects(register: $register, schema: $schema, object: $object, originalObjectId: $uuid);
            }

            return $this->delete($object);
        } catch (Exception $e) {
            return false;
        }

    }//end deleteObject()


    /**
     * Handles cascading deletes for related objects.
     *
     * @param Register     $register         The register containing the object.
     * @param Schema       $schema           The schema of the object.
     * @param ObjectEntity $object           The object being deleted.
     * @param string       $originalObjectId The ID of original object for cascading.
     *
     * @return void
     */
    private function cascadeDeleteObjects(
        Register $register,
        Schema $schema,
        ObjectEntity $object,
        string $originalObjectId
    ): void {
        $properties = $schema->getProperties();
        foreach ($properties ?? [] as $propertyName => $property) {
            if (isset($property['cascade']) === false || $property['cascade'] !== true) {
                continue;
            }

            $value = $object->getObject()[$propertyName] ?? null;
            if ($value === null) {
                continue;
            }

            if (is_array($value) === true) {
                foreach ($value ?? [] as $id) {
                    $this->deleteObject(register: $register, schema: $schema, uuid: $id, originalObjectId: $originalObjectId);
                }
            } else {
                $this->deleteObject(register: $register, schema: $schema, uuid: $value, originalObjectId: $originalObjectId);
            }
        }

    }//end cascadeDeleteObjects()


    /**
     * Delete the object folder when performing hard delete
     *
     * @param ObjectEntity $objectEntity The object entity to delete folder for
     *
     * @return void
     */
    private function deleteObjectFolder(ObjectEntity $objectEntity): void
    {
        try {
            $folder = $this->fileService->getObjectFolder($objectEntity);
            if ($folder !== null) {
                $folder->delete();
                $this->logger->info('Deleted object folder for hard deleted object: '.$objectEntity->getId());
            }
        } catch (\Exception $e) {
            // Log error but don't fail the deletion process.
            $this->logger->warning('Failed to delete object folder for object '.$objectEntity->getId().': '.$e->getMessage());
        }

    }//end deleteObjectFolder()


    /**
     * Check if audit trails are enabled in the settings
     *
     * @return bool True if audit trails are enabled, false otherwise
     */
    private function isAuditTrailsEnabled(): bool
    {
        try {
            $retentionSettings = $this->settingsService->getRetentionSettingsOnly();
            return $retentionSettings['auditTrailsEnabled'] ?? true;
        } catch (\Exception $e) {
            // If we can't get settings, default to enabled for safety.
            $this->logger->warning('Failed to check audit trails setting, defaulting to enabled', ['error' => $e->getMessage()]);
            return true;
        }

    }//end isAuditTrailsEnabled()


}//end class
