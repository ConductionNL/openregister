<?php
/**
 * OpenRegister GetObject Handler
 *
 * Handler class responsible for retrieving objects from the system.
 * This handler provides methods for:
 * - Finding objects by UUID or criteria
 * - Retrieving multiple objects with pagination
 * - Hydrating objects with file information
 * - Filtering and sorting results
 * - Handling search operations
 * - Managing object extensions
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\ObjectHandlers
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Service\ObjectHandlers;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\FileService;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Handler class for retrieving objects in the OpenRegister application.
 *
 * This handler is responsible for retrieving objects from the database,
 * including handling relations, files, and pagination.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\ObjectHandlers
 * @author    Conduction b.v. <info@conduction.nl>
 * @license   AGPL-3.0-or-later
 * @link      https://github.com/OpenCatalogi/OpenRegister
 * @version   1.0.0
 * @copyright 2024 Conduction b.v.
 */
class GetObject
{


    /**
     * Constructor for GetObject handler.
     *
     * @param ObjectEntityMapper $objectEntityMapper Object entity data mapper.
     * @param FileService        $fileService        File service for managing files.
     */
    public function __construct(
        private readonly ObjectEntityMapper $objectEntityMapper,
        private readonly FileService $fileService
    ) {

    }//end __construct()


    /**
     * Finds an object by UUID.
     *
     * @param string $uuid The UUID of the object to find.
     *
     * @return ObjectEntity|null The found object or null.
     */
    public function findByUuid(string $uuid): ?ObjectEntity
    {
        try {
            return $this->objectEntityMapper->findByUuid($uuid);
        } catch (DoesNotExistException $e) {
            return null;
        }

    }//end findByUuid()


    /**
     * Gets an object by its UUID with optional extensions.
     *
     * @param Register $register The register containing the object.
     * @param Schema   $schema   The schema of the object.
     * @param string   $uuid     The UUID of the object to get.
     * @param array    $extend   Properties to extend with.
     * @param bool     $files    Include file information.
     *
     * @return ObjectEntity The retrieved object.
     *
     * @throws DoesNotExistException If object not found.
     */
    public function getObject(
        Register $register,
        Schema $schema,
        string $uuid,
        ?array $extend=[],
        bool $files=false
    ): ObjectEntity {
        $object = $this->objectEntityMapper->findByUuid($uuid);

        if ($files === true) {
            $object = $this->hydrateFiles($object, $this->fileService->getFiles($object));
        }

        return $object;

    }//end getObject()


    /**
     * Finds multiple objects by their IDs.
     *
     * @param array      $ids    The IDs of the objects to find.
     * @param array|null $extend Properties to extend the objects with.
     * @param bool       $files  Whether to include file information.
     *
     * @return array The found objects.
     */
    public function findMultiple(array $ids, ?array $extend=[], bool $files=false): array
    {
        $objects = [];
        foreach ($ids as $id) {
            try {
                $object = $this->objectEntityMapper->findByUuid($id);
                if ($files === true) {
                    $object = $this->hydrateFiles($object, $this->fileService->getFiles($object));
                }

                $objects[] = $object;
            } catch (Exception $e) {
                continue;
            }
        }

        return $objects;

    }//end findMultiple()


    /**
     * Finds all objects matching the given criteria.
     *
     * @param int|null    $limit   Maximum number of objects to return.
     * @param int|null    $offset  Number of objects to skip.
     * @param array       $filters Filter criteria.
     * @param array       $sort    Sort criteria.
     * @param string|null $search  Search term.
     * @param array|null  $extend  Properties to extend the objects with.
     * @param bool        $files   Whether to include file information.
     * @param string|null $uses    Filter by object usage.
     *
     * @return array The found objects.
     */
    public function findAll(
        ?int $limit=null,
        ?int $offset=null,
        array $filters=[],
        array $sort=[],
        ?string $search=null,
        ?array $extend=[],
        bool $files=false,
        ?string $uses=null
    ): array {
        $objects = $this->objectEntityMapper->findAll($limit, $offset, $filters, $sort, $search, $uses);

        if ($files === true) {
            foreach ($objects as &$object) {
                $object = $this->hydrateFiles($object, $this->fileService->getFiles($object));
            }
        }

        return $objects;

    }//end findAll()


    /**
     * Counts the number of objects matching the given criteria.
     *
     * @param array       $filters Filter criteria.
     * @param string|null $search  Search term.
     *
     * @return int The number of matching objects.
     */
    public function count(array $filters=[], ?string $search=null): int
    {
        return $this->objectEntityMapper->count($filters, $search);

    }//end count()


    /**
     * Hydrates an object with its file information.
     *
     * @param ObjectEntity $object The object to hydrate.
     * @param array        $files  The files to add to the object.
     *
     * @return ObjectEntity The hydrated object.
     */
    private function hydrateFiles(ObjectEntity $object, array $files): ObjectEntity
    {
        $objectData = $object->getObject();
        foreach ($files as $file) {
            $propertyName = explode('_', $file->getName())[0];
            if (isset($objectData[$propertyName]) === false) {
                continue;
            }

            $objectData[$propertyName] = [
                'name' => $file->getName(),
                'type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'url'  => $file->getPath(),
            ];
        }

        $object->setObject($objectData);

        return $object;

    }//end hydrateFiles()


    /**
     * Find related objects for a given object.
     *
     * @param ObjectEntity $object The object to find relations for
     *
     * @return array Array of related objects
     */
    public function findRelated(ObjectEntity $object): array
    {
        $relatedObjects = [];
        $objectData = $object->getObject();

        foreach ($objectData as $propertyName => $value) {
            if (is_array($value) && isset($value['$ref'])) {
                try {
                    $relatedObject = $this->findByUuid($value['$ref']);
                    if ($relatedObject !== null) {
                        $relatedObjects[] = $relatedObject;
                    }
                } catch (Exception $e) {
                    continue;
                }
            }
        }

        return $relatedObjects;
    }

    /**
     * Find logs for a given object.
     *
     * @param ObjectEntity $object The object to find logs for
     *
     * @return array Array of log entries
     */
    public function findLogs(ObjectEntity $object): array
    {
        // Implementation for fetching object logs
        // This would typically query an audit log or history table
        return [
            [
                'timestamp' => $object->getCreatedAt()->format('Y-m-d H:i:s'),
                'action' => 'created',
                'user' => $object->getCreatedBy(),
            ],
            [
                'timestamp' => $object->getUpdatedAt()->format('Y-m-d H:i:s'),
                'action' => 'updated',
                'user' => $object->getUpdatedBy(),
            ],
        ];
    }

}//end class
