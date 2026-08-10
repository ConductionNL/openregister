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
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCP\AppFramework\Db\DoesNotExistException;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\ObjectSource\ObjectSourceRegistry;
use Psr\Log\LoggerInterface;

/**
 * Handler class for retrieving objects in the OpenRegister application.
 *
 * This handler is responsible for retrieving objects from the database,
 * including handling relations, files, and pagination.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Objects
 * @author    Conduction b.v. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/OpenCatalogi/OpenRegister
 * @version   GIT: <git_id>
 * @copyright 2024 Conduction b.v.
 */
class GetObject
{
    /**
     * Constructor for GetObject handler.
     *
     * @param MagicMapper          $objectMapper         Object entity data mapper.
     * @param AuditTrailMapper     $auditTrailMapper     Audit trail mapper for logs.
     * @param SettingsService      $settingsService      Settings service for accessing trail settings.
     * @param ObjectSourceRegistry $objectSourceRegistry Registry of object-source providers (virtual schemas).
     * @param LoggerInterface      $logger               Logger for object-source delegation warnings.
     *
     * @spec openspec/specs/object-lifecycle/spec.md
     */
    public function __construct(
        private readonly MagicMapper $objectMapper,
        private readonly AuditTrailMapper $auditTrailMapper,
        private readonly SettingsService $settingsService,
        private readonly ObjectSourceRegistry $objectSourceRegistry,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Resolve the ObjectSourceProvider for a schema, or null when the schema is
     * served from the magic table (no `x-openregister-object-source`).
     *
     * When a schema declares an object source whose provider is missing or
     * disabled, this logs a warning and returns null WITH `$sourced` set true,
     * so the caller degrades to an empty result instead of reading the database.
     *
     * @param Schema|null $schema  The schema being read (null = magic table).
     * @param bool        $sourced Set true when the schema declares an object source.
     *
     * @return \OCA\OpenRegister\Service\ObjectSource\ObjectSourceProvider|null
     *         An enabled provider, or null (see $sourced).
     *
     * @spec openspec/changes/object-source-providers/tasks.md#task-3.1
     */
    private function resolveObjectSource(?Schema $schema, bool &$sourced): ?object
    {
        $sourced = false;
        if ($schema === null) {
            return null;
        }

        $source = $schema->getObjectSource();
        if ($source === null) {
            return null;
        }

        $sourced  = true;
        $provider = $this->objectSourceRegistry->get($source['provider']);
        if ($provider === null || $provider->isEnabled() === false) {
            $this->logger->warning(
                sprintf(
                    '[ObjectSource] schema "%s" declares object-source provider "%s" but it is missing or disabled — returning empty result',
                    (string) $schema->getSlug(),
                    (string) $source['provider']
                )
            );
            return null;
        }

        return $provider;
    }//end resolveObjectSource()

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
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)   Boolean flags required for flexible API filtering
     *
     * @spec openspec/specs/object-lifecycle/spec.md
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
        // Object-source delegation: for a schema served from an external source
        // (x-openregister-object-source) the object is fetched live from the
        // provider and never read from the magic table. Absent/denied → 404.
        $sourced  = false;
        $provider = $this->resolveObjectSource(schema: $schema, sourced: $sourced);
        if ($sourced === true) {
            $config  = ($schema->getObjectSource()['config'] ?? []);
            $virtual = $provider?->find(register: $register, schema: $schema, id: $id, config: $config);
            if ($virtual === null) {
                throw new DoesNotExistException(sprintf('Object %s not found', $id));
            }

            return $virtual;
        }

        $object = $this->objectMapper->find(
            identifier: $id,
            register: $register,
            schema: $schema,
            includeDeleted: false,
            _rbac: $_rbac,
            _multitenancy: $_multitenancy
        );

        if ($files === true) {
            $object = $this->hydrateFiles(object: $object, files: []);
            // TODO.
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
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)   Boolean flags required for flexible API filtering
     *
     * @spec openspec/specs/object-lifecycle/spec.md
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
        // Object-source delegation (silent read, no audit) — see find().
        $sourced  = false;
        $provider = $this->resolveObjectSource(schema: $schema, sourced: $sourced);
        if ($sourced === true) {
            $config  = ($schema->getObjectSource()['config'] ?? []);
            $virtual = $provider?->find(register: $register, schema: $schema, id: $id, config: $config);
            if ($virtual === null) {
                throw new DoesNotExistException(sprintf('Object %s not found', $id));
            }

            return $virtual;
        }

        $object = $this->objectMapper->find(
            identifier: $id,
            register: $register,
            schema: $schema,
            includeDeleted: false,
            _rbac: $_rbac,
            _multitenancy: $_multitenancy
        );

        if ($files === true) {
            $object = $this->hydrateFiles(object: $object, files: []);
            // TODO.
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
     * @param bool          $_rbac         Whether to apply RBAC checks (default: true).
     * @param bool          $_multitenancy Whether to apply multitenancy filtering (default: true).
     *
     * @return ObjectEntity[]
     *
     * @psalm-return                                   list<ObjectEntity>
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Required for flexible query interface
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)    Boolean flags required for flexible API filtering
     *
     * @spec openspec/specs/object-lifecycle/spec.md
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
        bool $_rbac=true,
        bool $_multitenancy=true
    ): array {
        // Object-source delegation: a schema served from an external source
        // lists live from the provider, never the magic table. A missing/disabled
        // provider degrades to an empty list (resolveObjectSource logs it).
        $sourced  = false;
        $provider = $this->resolveObjectSource(schema: $schema, sourced: $sourced);
        if ($sourced === true) {
            if ($provider === null) {
                return [];
            }

            $source = $schema->getObjectSource();
            $query  = [
                'limit'   => $limit,
                'offset'  => $offset,
                'filters' => $filters,
                'sort'    => $sort,
                'search'  => $search,
                'ids'     => $ids,
            ];

            return $provider->findAll(
                register: $register,
                schema: $schema,
                query: $query,
                config: ($source['config'] ?? [])
            );
        }//end if

        // Thread the RBAC / multitenancy posture into the filters so the search
        // handler honours them. These are read from the query array downstream;
        // passing them only as method arguments left them silently dropped, so
        // a caller's `_rbac:false` (e.g. installer/system context) had no effect.
        $filters['_rbac']         = $_rbac;
        $filters['_multitenancy'] = $_multitenancy;

        // Retrieve objects using the objectEntityMapper with optional register, schema, and ids.
        $objects = $this->objectMapper->findAll(
            limit: $limit,
            offset: $offset,
            filters: $filters,
            sort: $sort,
            search: $search,
            ids: $ids,
            uses: $uses,
            register: $register,
            schema: $schema
        );

        // If files are to be included, hydrate each object with its file information.
        if ($files === true) {
            foreach ($objects as &$object) {
                $object = $this->hydrateFiles(object: $object, files: []);
                // TODO.
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
     *
     * @spec openspec/specs/object-lifecycle/spec.md
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
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)   Boolean flags required for flexible API filtering
     *
     * @spec openspec/specs/object-lifecycle/spec.md
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
     *
     * @spec openspec/specs/object-lifecycle/spec.md
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
