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

namespace OCA\OpenRegister\Service\Object;

use DateTime;
use Exception;
use JsonSerializable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Schemas\SchemaCacheHandler;
use OCA\OpenRegister\Service\Schemas\FacetCacheHandler;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Service\SettingsService;
use OCP\IUserSession;
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
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
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
     * @param ObjectEntityMapper $objectEntityMapper Object entity data mapper.
     * @param CacheHandler       $cacheHandler       Object cache service for entity and query caching
     * @param IUserSession       $userSession        User session service for tracking who deletes
     * @param AuditTrailMapper   $auditTrailMapper   Audit trail mapper for logs
     * @param SettingsService    $settingsService    Settings service for accessing trail settings
     * @param LoggerInterface    $logger             Logger for error handling
     */
    public function __construct(
        private readonly ObjectEntityMapper $objectEntityMapper,
        private readonly CacheHandler $cacheHandler,
        private readonly IUserSession $userSession,
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

        // **SOFT DELETE**: Mark object as deleted instead of removing from database.
        // Set deletion metadata with user, timestamp, and organization information.
        $user = $this->userSession->getUser();
        if ($user !== null) {
            $userId = $user->getUID();
        } else {
            $userId = 'system';
        }

        // Get the active organization from session at time of deletion for audit trail.
        $activeOrganisation = null;
        if ($user !== null) {
            // Access OrganisationMapper via DI container to get active organization.
            try {
                $organisationMapper = \OC::$server->get(\OCA\OpenRegister\Db\OrganisationMapper::class);
                $activeOrganisation = $organisationMapper->getActiveOrganisationWithFallback($user->getUID());
            } catch (\Exception $e) {
                // If we can't get the active organisation, log and continue with null.
                $this->logger->warning('Failed to get active organisation during delete', ['error' => $e->getMessage()]);
                $activeOrganisation = null;
            }
        }

        $deletionData = [
            'deletedBy'    => $userId,
            'deletedAt'    => (new DateTime())->format(DateTime::ATOM),
            'objectId'     => $objectEntity->getUuid(),
            'organisation' => $activeOrganisation,
        ];

        $objectEntity->setDeleted($deletionData);

        /*
         * Update the object in database (soft delete - keeps record with deleted metadata).
         * @psalm-suppress InvalidArgument - ObjectEntity extends Entity
         */

        $result = $this->objectEntityMapper->update($objectEntity) !== null;

        // **CACHE INVALIDATION**: Clear collection and facet caches so soft-deleted objects disappear from regular queries.
        if ($result === true) {
            /*
             * ObjectEntity has getRegister() and getSchema() methods that return string|null.
             * Convert to int|null for invalidateForObjectChange which expects ?int.
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

            try {
                $this->cacheHandler->invalidateForObjectChange(
                    object: $objectEntity,
                    operation: 'soft_delete',
                    registerId: $registerIdInt,
                    schemaId: $schemaIdInt
                );
            } catch (\Exception $e) {
                // Gracefully handle cache invalidation errors (e.g., Solr not configured).
                // Soft deletion should succeed even if cache invalidation fails.
            }
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
     * @param bool                $_rbac            Whether to apply RBAC checks (default: true).
     * @param bool                $_multitenancy    Whether to apply multitenancy filtering (default: true).
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
        bool $_multitenancy=true
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
                foreach ($value as $id) {
                    $this->deleteObject(
                        register: $register,
                        schema: $schema,
                        uuid: $id,
                        originalObjectId: $originalObjectId
                    );
                }
            } else {
                $this->deleteObject(
                    register: $register,
                    schema: $schema,
                    uuid: $value,
                    originalObjectId: $originalObjectId
                );
            }
        }//end foreach
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
            // TODO: Implement folder deletion when fileService is available.
            // $folder = $this->fileService->getObjectFolder($objectEntity);
            // If ($folder !== null) {
            // $folder->delete();
            // $this->logger->info('Deleted object folder for hard deleted object: '.$objectEntity->getId());
            // }.
        } catch (\Exception $e) {
            // Log error but don't fail the deletion process.
            $objectId     = $objectEntity->getId();
            $errorMessage = $e->getMessage();
            $this->logger->warning('Failed to delete object folder for object '.$objectId.': '.$errorMessage);
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
            $this->logger->warning(
                'Failed to check audit trails setting, defaulting to enabled',
                ['error' => $e->getMessage()]
            );
            return true;
        }
    }//end isAuditTrailsEnabled()
}//end class
