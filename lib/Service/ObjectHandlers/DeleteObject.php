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

namespace OCA\OpenRegister\Service\ObjectHandlers;

use Exception;
use JsonSerializable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Db\AuditTrailMapper;

/**
 * Handler class for deleting objects in the OpenRegister application.
 *
 * This handler is responsible for deleting objects from the database,
 * including handling cascading deletes and file cleanup.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\ObjectHandlers
 * @author    Conduction b.v. <info@conduction.nl>
 * @license   AGPL-3.0-or-later
 * @link      https://github.com/OpenCatalogi/OpenRegister
 * @version   1.0.0
 * @copyright 2024 Conduction b.v.
 */
class DeleteObject
{
    /**
     * @var AuditTrailMapper
     */
    private AuditTrailMapper $auditTrailMapper;

    /**
     * Constructor for DeleteObject handler.
     *
     * @param ObjectEntityMapper $objectEntityMapper Object entity data mapper.
     * @param FileService        $fileService        File service for managing files.
     * @param AuditTrailMapper   $auditTrailMapper   Audit trail mapper for logs.
     */
    public function __construct(
        private readonly ObjectEntityMapper $objectEntityMapper,
        private readonly FileService $fileService,
        AuditTrailMapper $auditTrailMapper
    ) {
        $this->auditTrailMapper = $auditTrailMapper;
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
        if ($object instanceof JsonSerializable) {
            $objectEntity = $object;
            $object       = $object->jsonSerialize();
        } else {
            $objectEntity = $this->objectEntityMapper->find($object['id']);
        }

        // Delete associated files from storage.
        $files = $this->fileService->getFiles($object['id']);
        foreach ($files as $file) {
            $this->fileService->deleteFile($object['id'], $file->getName());
        }

        // Delete the object from database.
        $result = $this->objectEntityMapper->delete($objectEntity) !== null;

        // Create audit trail for delete and set lastLog
        $log = $this->auditTrailMapper->createAuditTrail(old: $objectEntity, new: null, action: 'delete');
//        $result->setLastLog($log->jsonSerialize());

        return $result;

    }//end delete()


    /**
     * Deletes an object by its UUID with optional cascading.
     *
     * @param Register|int|string $register         The register containing the object.
     * @param Schema|int|string   $schema           The schema of the object.
     * @param string              $uuid             The UUID of the object to delete.
     * @param string|null         $originalObjectId The ID of original object for cascading.
     *
     * @return bool Whether the deletion was successful.
     *
     * @throws Exception If there is an error during deletion.
     */
    public function deleteObject(
        Register | int | string $register,
        Schema | int | string $schema,
        string $uuid,
        ?string $originalObjectId=null
    ): bool {
        try {
            $object = $this->objectEntityMapper->findByUuid($uuid);

            // Handle cascading deletes if this is the root object.
            if ($originalObjectId === null) {
                $this->cascadeDeleteObjects($register, $schema, $object, $uuid);
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
        foreach ($properties as $propertyName => $property) {
            if (isset($property['cascade']) === false || $property['cascade'] !== true) {
                continue;
            }

            $value = $object->getObject()[$propertyName] ?? null;
            if ($value === null) {
                continue;
            }

            if (is_array($value) === true) {
                foreach ($value as $id) {
                    $this->deleteObject($register, $schema, $id, $originalObjectId);
                }
            } else {
                $this->deleteObject($register, $schema, $value, $originalObjectId);
            }
        }

    }//end cascadeDeleteObjects()


}//end class
