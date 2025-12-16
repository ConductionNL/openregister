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

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCP\AppFramework\Db\DoesNotExistException;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Service\SettingsService;

/**
 * Handler class for retrieving objects in the OpenRegister application.
 *
 * This handler is responsible for retrieving objects from the database,
 * including handling relations, files, and pagination.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Objects
 * @author    Conduction b.v. <info@conduction.nl>
 * @license   AGPL-3.0-or-later
 * @link      https://github.com/OpenCatalogi/OpenRegister
 * @version   GIT: <git_id>
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
     * @param SettingsService    $settingsService    Settings service for accessing trail settings.
     */
    public function __construct(
        private readonly ObjectEntityMapper $objectEntityMapper,
        private readonly AuditTrailMapper $auditTrailMapper,
        private readonly SettingsService $settingsService
    ) {

    }//end __construct()


    /**
     * Gets an object by its ID with optional extensions.
     *
     * This method also creates an audit trail entry for the 'read' action.
     *
     * @param string   $id            The ID of the object to get.
     * @param Register $register      The register containing the object.
     * @param Schema   $schema        The schema of the object.
     * @param array    $_extend       Properties to extend with.
     * @param bool     $files         Include file information.
     * @param bool     $_rbac         Whether to apply RBAC checks (default: true).
     * @param bool     $_multitenancy Whether to apply multitenancy filtering (default: true).
     *
     * @return ObjectEntity The retrieved object.
     *
     * @throws DoesNotExistException If object not found.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function find(
        string $id,
        ?Register $register=null,
        ?Schema $schema=null,
        ?array $_extend=[],
        bool $files=false,
        bool $_rbac=true,
        bool $_multitenancy=true
    ): ObjectEntity {
        $object = $this->objectEntityMapper->find(identifier: $id, register: $register, schema: $schema, includeDeleted: false, _rbac: $_rbac, _multitenancy: $_multitenancy);

        if ($files === true) {
            $object = $this->hydrateFiles(object: $object, files: []);
            // TODO
        }

        // Create an audit trail for the 'read' action if audit trails are enabled.
        if ($this->isAuditTrailsEnabled() === true) {
            $log = $this->auditTrailMapper->createAuditTrail(old: null, new: $object, action: 'read');
            $object->setLastLog($log->jsonSerialize());
        }

        return $object;

    }//end find()


    /**
     * Gets an object by its ID without creating an audit trail.
     *
     * This method is used internally by other operations (like UPDATE) that need to
     * retrieve an object without logging the read action.
     *
     * @param string   $id            The ID of the object to get.
     * @param Register $register      The register containing the object.
     * @param Schema   $schema        The schema of the object.
     * @param array    $_extend       Properties to extend with.
     * @param bool     $files         Include file information.
     * @param bool     $_rbac         Whether to apply RBAC checks (default: true).
     * @param bool     $_multitenancy Whether to apply multitenancy filtering (default: true).
     *
     * @return ObjectEntity The retrieved object.
     *
     * @throws DoesNotExistException If object not found.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function findSilent(
        string $id,
        ?Register $register=null,
        ?Schema $schema=null,
        ?array $_extend=[],
        bool $files=false,
        bool $_rbac=true,
        bool $_multitenancy=true
    ): ObjectEntity {
        $object = $this->objectEntityMapper->find(identifier: $id, register: $register, schema: $schema, includeDeleted: false, _rbac: $_rbac, _multitenancy: $_multitenancy);

        if ($files === true) {
            $object = $this->hydrateFiles(object: $object, files: []);
            // TODO
        }

        // No audit trail creation - this is a silent read.
        return $object;

    }//end findSilent()


    /**
     * Finds all objects matching the given criteria.
     *
     * @param int|null      $limit         Maximum number of objects to return.
     * @param int|null      $offset        Number of objects to skip.
     * @param array         $filters       Filter criteria.
     * @param array         $sort          Sort criteria.
     * @param string|null   $search        Search term.
     * @param array|null    $_extend       Properties to extend the objects with.
     * @param bool          $files         Whether to include file information.
     * @param string|null   $uses          Filter by object usage.
     * @param Register|null $register      Optional register to filter objects.
     * @param Schema|null   $schema        Optional schema to filter objects.
     * @param array|null    $ids           Array of IDs or UUIDs to filter by.
     * @param bool|null     $published     Whether to filter by published status.
     * @param bool          $_rbac         Whether to apply RBAC checks (default: true).
     * @param bool          $_multitenancy Whether to apply multitenancy filtering (default: true).
     *
     * @return \\OCA\OpenRegister\Db\ObjectEntity[]
     *
     * @psalm-return list<\OCA\OpenRegister\Db\ObjectEntity>
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function findAll(
        ?int $limit=null,
        ?int $offset=null,
        array $filters=[],
        array $sort=[],
        ?string $search=null,
        ?array $_extend=[],
        bool $files=false,
        ?string $uses=null,
        ?Register $register=null,
        ?Schema $schema=null,
        ?array $ids=null,
        ?bool $published=false,
        bool $_rbac=true,
        bool $_multitenancy=true
    ): array {
        // Retrieve objects using the objectEntityMapper with optional register, schema, and ids.
        $objects = $this->objectEntityMapper->findAll(
            limit: $limit,
            offset: $offset,
            filters: $filters,
            sort: $sort,
            search: $search,
            ids: $ids,
            uses: $uses,
            register: $register,
            schema: $schema,
            published: $published,
            _rbac: $_rbac,
            _multitenancy: $_multitenancy
        );

        // If files are to be included, hydrate each object with its file information.
        if ($files === true) {
            foreach ($objects as &$object) {
                $object = $this->hydrateFiles(object: $object, files: []);
                // TODO
            }
        }

        return $objects;

    }//end findAll()


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
     * Find logs for a given object.
     *
     * @param ObjectEntity $object        The object to find logs for
     * @param int|null     $limit         Maximum number of logs to return
     * @param int|null     $offset        Number of logs to skip
     * @param array|null   $filters       Additional filters to apply
     * @param array|null   $sort          Sort criteria ['field' => 'ASC|DESC']
     * @param string|null  $search        Optional search term
     * @param bool         $_rbac         Whether to apply RBAC checks (default: true).
     * @param bool         $_multitenancy Whether to apply multitenancy filtering (default: true).
     *
     * @return \OCA\OpenRegister\Db\AuditTrail[] Array of log entries
     *
     * @psalm-return array<\OCA\OpenRegister\Db\AuditTrail>
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function findLogs(
        ObjectEntity $object,
        ?int $limit=null,
        ?int $offset=null,
        ?array $filters=[],
        ?array $sort=['created' => 'DESC'],
        ?string $search=null,
        bool $_rbac=true,
        bool $_multitenancy=true
    ): array {
        // Ensure object ID is always included in filters.
        $filters['object'] = $object->getId();

        // Get audit trails using all available options.
        return $this->auditTrailMapper->findAll(
            limit: $limit,
            offset: $offset,
            filters: $filters,
            sort: $sort,
            search: $search
        );

    }//end findLogs()


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
            return true;
        }

    }//end isAuditTrailsEnabled()


}//end class
