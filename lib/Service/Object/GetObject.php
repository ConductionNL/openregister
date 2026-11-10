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

use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Service\ObjectSource\SourcedTable;
use Exception;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\ListQuery;
use OCA\OpenRegister\Db\MagicMapper\MagicListRequest;
use OCA\OpenRegister\Db\MagicMapper\MagicObjectFinderInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Interaction\ReadHistoryService;
use OCP\AppFramework\Db\DoesNotExistException;

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
     * @param MagicObjectFinderInterface $objectMapper     Object entity data mapper.
     * @param AuditTrailMapperInterface  $auditTrailMapper Audit trail mapper for logs.
     * @param ReadHistoryService         $readHistory      The one read registration (audit trail and AVG log).
     * @param GetObjectSources           $sources          Object-source registry and the logger for its warnings.
     *
     * @spec openspec/specs/object-lifecycle/spec.md
     */
    public function __construct(
        private readonly MagicObjectFinderInterface $objectMapper,
        private readonly AuditTrailMapperInterface $auditTrailMapper,
        private readonly ReadHistoryService $readHistory,
        private readonly GetObjectSources $sources
    ) {
    }//end __construct()

    /**
     * The object source a read is served from, or null when the schema is
     * served from the magic table (no `x-openregister-object-source`).
     *
     * When a schema declares an object source whose provider is missing or
     * disabled, this logs a warning and returns a binding WITHOUT a provider,
     * so the caller degrades to an empty result instead of reading the database.
     *
     * @param ReadScope $scope The register and schema being read (no schema = magic table).
     *
     * @return ObjectSourceBinding|null The binding, or null for the magic table.
     *
     * @spec openspec/changes/object-source-providers/tasks.md#task-3.1
     */
    private function sourceBinding(ReadScope $scope): ?ObjectSourceBinding
    {
        $schema = $scope->schema;
        if ($schema === null) {
            return null;
        }

        $source = $schema->getObjectSource();
        if ($source === null) {
            return null;
        }

        return new ObjectSourceBinding(
            provider: $this->enabledProvider(schema: $schema, source: $source),
            register: $scope->register,
            schema: $schema
        );
    }//end sourceBinding()

    /**
     * The enabled provider an object-source declaration names, or null (logged) when missing or disabled.
     *
     * @param Schema $schema The schema declaring the object source.
     * @param array  $source The object-source declaration.
     *
     * @return object|null The provider
     *
     * @spec openspec/specs/object-lifecycle/spec.md
     */
    private function enabledProvider(Schema $schema, array $source): ?object
    {
        $provider = $this->sources->objectSourceRegistry->get($source['provider']);
        if ($provider === null || $provider->isEnabled() === false) {
            $this->sources->logger->warning(
                sprintf(
                    '[ObjectSource] schema "%s" declares object-source provider "%s" but it is missing or disabled — returning empty result',
                    (string) $schema->getSlug(),
                    (string) $source['provider']
                )
            );
            return null;
        }

        return $provider;
    }//end enabledProvider()

    /**
     * Gets an object by its ID with optional extensions.
     *
     * This method also creates an audit trail entry for the 'read' action,
     * unless `$_audit` says otherwise.
     *
     * @param string   $id            The ID of the object to get.
     * @param Register $register      The register containing the object.
     * @param Schema   $schema        The schema of the object.
     * @param array    $_extend       Properties to extend with.
     * @param bool     $files         Include file information.
     * @param bool     $_rbac         Whether to apply RBAC checks (default: true).
     * @param bool     $_multitenancy Whether to apply multitenancy filtering (default: true).
     * @param bool     $_audit        Whether this read is worth an audit-trail row (default: true).
     *                                Pass false for machine-to-machine reads inside one
     *                                operation - the instance setting is all-or-nothing.
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
        bool $_multitenancy=true,
        bool $_audit=true
    ): ObjectEntity {
        $request = new ObjectFindRequest(id: $id, extend: $_extend, files: $files, rbac: $_rbac, multitenancy: $_multitenancy, audit: $_audit);
        return $this->findRecorded(request: $request, scope: new ReadScope(register: $register, schema: $schema));
    }//end find()

    /**
     * Read one object and register the read on the audit trail, unless the request opts out.
     *
     * @param ObjectFindRequest $request The id and read flags, `audit` included.
     * @param ReadScope         $scope   The register and schema, either may be absent.
     *
     * @return ObjectEntity The retrieved object.
     *
     * @throws DoesNotExistException If object not found.
     *
     * @spec openspec/specs/object-lifecycle/spec.md
     */
    private function findRecorded(ObjectFindRequest $request, ReadScope $scope): ObjectEntity
    {
        // Object-source delegation: for a schema served from an external source
        // (x-openregister-object-source) the object is fetched live from the
        // provider and never read from the magic table. Absent/denied → 404.
        $source = $this->sourceBinding(scope: $scope);
        if ($source !== null) {
            return $this->findInSource(source: $source, id: $request->id);
        }

        // Register the read on the audit trail (read-history-on-audit-trail).
        //
        // `audit` is the PER-CALL opt-out. The instance setting is all or
        // nothing, so a bulk synchronisation reading thousands of objects had no
        // way to skip audit without disabling it for user-facing reads too — and
        // read logging is 91% of this instance's audit table (2,864,555 of
        // 3,153,490 rows, in a table carrying 1,596MB of indexes over 1,089MB of
        // data). Machine-to-machine reads inside one operation are not the thing
        // an audit trail exists to record; a person opening an object is.
        //
        // The same row is what "recently opened" (the `_recent` lens) reads
        // back, so this is the only place a view is recorded.
        return $this->withReadRegistered(object: $this->findInTable(request: $request, scope: $scope), audit: $request->audit);
    }//end findRecorded()

    /**
     * Fetch one object live from its object-source provider; a missing provider or object is a 404.
     *
     * @param ObjectSourceBinding $source The provider (null when missing or disabled), register and schema.
     * @param string              $id     The object id.
     *
     * @return ObjectEntity The object
     *
     * @throws DoesNotExistException When the provider is unavailable or has no such object.
     *
     * @spec openspec/specs/object-lifecycle/spec.md
     */
    private function findInSource(ObjectSourceBinding $source, string $id): ObjectEntity
    {
        $virtual = $source->provider?->find(
            source: new SourcedTable(table: new RegisterSchema(register: $source->register, schema: $source->schema), config: $source->config()),
            id: $id
        );
        if ($virtual === null) {
            throw new DoesNotExistException(sprintf('Object %s not found', $id));
        }

        return $virtual;
    }//end findInSource()

    /**
     * Read one object from the magic table, hydrating files when asked.
     *
     * @param ObjectFindRequest $request The id and read flags.
     * @param ReadScope         $scope   The register and schema, either may be absent.
     *
     * @return ObjectEntity The object
     *
     * @spec openspec/specs/object-lifecycle/spec.md
     */
    private function findInTable(ObjectFindRequest $request, ReadScope $scope): ObjectEntity
    {
        $object = $this->objectMapper->find(
            identifier: $request->id,
            register: $scope->register,
            schema: $scope->schema,
            includeDeleted: false,
            _rbac: $request->rbac,
            _multitenancy: $request->multitenancy
        );

        if ($request->files === true) {
            $object = $this->hydrateFiles(object: $object, files: []);
            // TODO.
        }

        return $object;
    }//end findInTable()

    /**
     * Register a read on the audit trail and attach the audit row as the object's last log.
     *
     * @param ObjectEntity $object The object read.
     * @param bool         $audit  The per-call audit opt-out.
     *
     * @return ObjectEntity The object
     *
     * @spec openspec/specs/object-lifecycle/spec.md
     */
    private function withReadRegistered(ObjectEntity $object, bool $audit): ObjectEntity
    {
        $log = $this->readHistory->registerAuditRead(object: $object, audit: $audit);
        if ($log !== null) {
            $object->setLastLog($log->jsonSerialize());
        }

        return $object;
    }//end withReadRegistered()

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
        $request = new ObjectFindRequest(id: $id, extend: $_extend, files: $files, rbac: $_rbac, multitenancy: $_multitenancy, audit: false);
        return $this->findUnrecorded(request: $request, scope: new ReadScope(register: $register, schema: $schema));
    }//end findSilent()

    /**
     * Read one object without registering the read: from its object source, or from the magic table.
     *
     * @param ObjectFindRequest $request The id and read flags.
     * @param ReadScope         $scope   The register and schema, either may be absent.
     *
     * @return ObjectEntity The retrieved object.
     *
     * @throws DoesNotExistException If object not found.
     *
     * @spec openspec/specs/object-lifecycle/spec.md
     */
    private function findUnrecorded(ObjectFindRequest $request, ReadScope $scope): ObjectEntity
    {
        // Object-source delegation (silent read, no audit) — see findRecorded().
        $source = $this->sourceBinding(scope: $scope);
        if ($source !== null) {
            return $this->findInSource(source: $source, id: $request->id);
        }

        // No audit trail creation - this is a silent read.
        return $this->findInTable(request: $request, scope: $scope);
    }//end findUnrecorded()

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
        $source = $this->sourceBinding(scope: new ReadScope(register: $register, schema: $schema));
        if ($source !== null) {
            $query = ['limit' => $limit, 'offset' => $offset, 'filters' => $filters, 'sort' => $sort, 'search' => $search, 'ids' => $ids];
            return $this->findAllInSource(source: $source, query: $query);
        }

        // Thread the RBAC / multitenancy posture into the filters so the search
        // handler honours them. These are read from the query array downstream;
        // passing them only as method arguments left them silently dropped, so
        // a caller's `_rbac:false` (e.g. installer/system context) had no effect.
        $listing = new ListQuery(
            limit: ($limit ?? ListQuery::UNBOUNDED),
            offset: $offset,
            filters: array_merge($filters, ['_rbac' => $_rbac, '_multitenancy' => $_multitenancy]),
            sort: $sort,
            search: $search
        );
        $table   = null;
        if ($register !== null && $schema !== null) {
            $table = new RegisterSchema(register: $register, schema: $schema);
        }

        $request = new MagicListRequest(table: $table, query: $listing, ids: $ids, uses: $uses);
        $objects = $this->objectMapper->findAllListed(request: $request);
        return $this->withFilesHydrated(objects: $objects, files: $files);
    }//end findAll()

    /**
     * List objects live from their object-source provider; a missing provider lists nothing.
     *
     * @param ObjectSourceBinding $source The provider (null when missing or disabled), register and schema.
     * @param array               $query  Limit, offset, filters, sort, search and ids.
     *
     * @return array The objects
     *
     * @spec openspec/specs/object-lifecycle/spec.md
     */
    private function findAllInSource(ObjectSourceBinding $source, array $query): array
    {
        return $source->provider?->findAll(
            source: new SourcedTable(table: new RegisterSchema(register: $source->register, schema: $source->schema), config: $source->config()),
            query: $query
        ) ?? [];
    }//end findAllInSource()

    /**
     * Hydrate files onto every listed object when asked.
     *
     * @param array $objects The objects.
     * @param bool  $files   Whether to hydrate files.
     *
     * @return array The objects
     *
     * @spec openspec/specs/object-lifecycle/spec.md
     */
    private function withFilesHydrated(array $objects, bool $files): array
    {
        if ($files === true) {
            return array_map(fn (ObjectEntity $object): ObjectEntity => $this->hydrateFiles(object: $object, files: []), $objects);
        }

        return $objects;
    }//end withFilesHydrated()

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
     * Find the whole history of an object, newest first.
     *
     * @param ObjectEntity $object        The object to find logs for
     * @param array|null   $filters       Additional filters to apply
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
        ?array $filters=[],
        bool $_rbac=true,
        bool $_multitenancy=true
    ): array {
        // The uuid is the only identity an object keeps across a move: register,
        // schema and numeric id all belong to the table it lives in now.
        unset($filters['object'], $filters['register'], $filters['schema']);
        $filters['object_uuid'] = $object->getUuid();

        return $this->auditTrailMapper->findAll(filters: $filters, sort: ['created' => 'DESC']);
    }//end findLogs()
}//end class
