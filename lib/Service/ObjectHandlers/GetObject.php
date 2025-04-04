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
use OCA\OpenRegister\Db\AuditTrailMapper;

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
     * @param AuditTrailMapper   $auditTrailMapper   Audit trail mapper for logs.
     */
    public function __construct(
        private readonly ObjectEntityMapper $objectEntityMapper,
        private readonly FileService $fileService,
        private readonly AuditTrailMapper $auditTrailMapper
    ) {

    }//end __construct()

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
        $object = $this->objectEntityMapper->find($uuid, $register, $schema);

        if ($files === true) {
            $object = $this->hydrateFiles($object, $this->fileService->getFiles($object));
        }

        return $object;

    }//end getObject()

    /**
     * Finds all objects matching the given criteria.
     *
     * @param int|null    $limit    Maximum number of objects to return.
     * @param int|null    $offset   Number of objects to skip.
     * @param array       $filters  Filter criteria.
     * @param array       $sort     Sort criteria.
     * @param string|null $search   Search term.
     * @param array|null  $extend   Properties to extend the objects with.
     * @param bool        $files    Whether to include file information.
     * @param string|null $uses     Filter by object usage.
     * @param Register|null $register Optional register to filter objects.
     * @param Schema|null $schema   Optional schema to filter objects.
     * @param array|null  $ids      Array of IDs or UUIDs to filter by.
     *
     * @return array The found objects.
     */
    public function findAll(
        ?int $limit = null,
        ?int $offset = null,
        array $filters = [],
        array $sort = [],
        ?string $search = null,
        ?array $extend = [],
        bool $files = false,
        ?string $uses = null,
        ?Register $register = null,
        ?Schema $schema = null,
        ?array $ids = null
    ): array {
        // Retrieve objects using the objectEntityMapper with optional register, schema, and ids
        $objects = $this->objectEntityMapper->findAll($limit, $offset, $filters, $sort, $search, $uses, $register, $schema, $ids);

        // If files are to be included, hydrate each object with its file information
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
     * @param array       $filters  Filter criteria.
     * @param string|null $search   Search term.
     * @param Register|null $register Optional register to filter objects.
     * @param Schema|null $schema   Optional schema to filter objects.
     *
     * @return int The number of matching objects.
     */
    public function count(array $filters = [], ?string $search = null, ?Register $register = null, ?Schema $schema = null): int
    {
        return $this->objectEntityMapper->countAll($filters, $search, false, $register, $schema);

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
        // Get the relations of the object
        $relatedObjects = $object->getObject()->getRelations();

        // Iterate over each related object
        foreach ($relatedObjects as $propertyName => $id) {
            // Check if the ID is an array (indicating multiple related objects)
            if (is_array($id) === true){
                // Find multiple related objects by their IDs
                $value = $this->objectEntityMapper->findMultiple(ids: $id);
            }
            else {
                // Find a single related object by its ID
                $value = $this->objectEntityMapper->find(id: $id);
            }          
            // Update the related objects array with the found value(s)
            $relatedObjects[$propertyName] = $value;
        }

        // Return the array of related objects
        return $relatedObjects;
    }

    /**
     * Find objects that use/reference a specific object.
     *
     * @param ObjectEntity $object         The object to find references to
     * @param bool        $partialMatch    Whether to search for partial matches in relations
     * @param array|null  $filters         Additional filters to apply
     * @param array|null  $searchConditions Search conditions to apply
     * @param array|null  $searchParams     Search parameters to apply
     * @param array|null  $sort            Sort criteria ['field' => 'ASC|DESC']
     * @param string|null $search          Optional search term
     * @param int|null    $limit           Maximum number of objects to return
     * @param int|null    $offset          Number of objects to skip
     * @param array|null  $extend          Properties to extend the objects with
     * @param bool        $files           Whether to include file information
     * @param string|null $uses            Filter by object usage
     * @param Register|null $register      Optional register to filter objects
     * @param Schema|null $schema          Optional schema to filter objects
     *
     * @return array Array of objects that reference this object
     */
    public function findUsed(
        ObjectEntity $object,
        bool $partialMatch = false,
        ?array $filters = [],
        ?array $searchConditions = [],
        ?array $searchParams = [],
        ?array $sort = ['created' => 'DESC'],
        ?string $search = null,
        ?int $limit = null,
        ?int $offset = null,
        ?array $extend = [],
        bool $files = false,
        ?string $uses = null,
        ?Register $register = null,
        ?Schema $schema = null
    ): array {
        // First find all objects that reference this object's URI or UUID
        $referencingObjects = $this->objectEntityMapper->findByRelationUri(
            search: $object->getUri() ?? $object->getUuid(),
            partialMatch: $partialMatch
        );

        // If additional parameters are set, filter the IDs from $referencingObjects
        if (!empty($filters) || !empty($searchConditions) || !empty($searchParams) || !empty($sort) || $search !== null || $limit !== null || $offset !== null || !empty($extend) || $files !== false || $uses !== null || $register !== null || $schema !== null) {
            $ids = array_map(fn($obj) => $obj->getId(), $referencingObjects);
            $filters['id'] = $ids;

            // Use findAll to apply additional filters and return the response
            return $this->objectEntityMapper->findAll(
                limit: $limit,
                offset: $offset,
                filters: $filters,
                searchConditions: $searchConditions,
                searchParams: $searchParams,
                sort: $sort,
                search: $search,
                ids: $ids,
                uses: $uses,
                register: $register,
                schema: $schema
            );
        }

        return $referencingObjects;
    }

    /**
     * Find logs for a given object.
     *
     * @param ObjectEntity $object           The object to find logs for
     * @param int|null    $limit            Maximum number of logs to return
     * @param int|null    $offset           Number of logs to skip
     * @param array|null  $filters          Additional filters to apply
     * @param array|null  $searchConditions Search conditions to apply
     * @param array|null  $searchParams     Search parameters to apply
     * @param array|null  $sort             Sort criteria ['field' => 'ASC|DESC']
     * @param string|null $search           Optional search term
     *
     * @return array Array of log entries
     */
    public function findLogs(
        ObjectEntity $object,
        ?int $limit = null,
        ?int $offset = null,
        ?array $filters = [],
        ?array $searchConditions = [],
        ?array $searchParams = [],
        ?array $sort = ['created' => 'DESC'],
        ?string $search = null
    ): array {
        // Ensure object ID is always included in filters
        $filters['object'] = $object->getId();

        // Get audit trails using all available options
        return $this->auditTrailMapper->findAll(
            limit: $limit,
            offset: $offset,
            filters: $filters,
            searchConditions: $searchConditions,
            searchParams: $searchParams,
            sort: $sort,
            search: $search
        );
    }

}//end class
