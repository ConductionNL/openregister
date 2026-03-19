<?php

/**
 * GraphQL resolver for OpenRegister.
 *
 * Resolves GraphQL queries, mutations, and fields by delegating
 * to OpenRegister services with RBAC enforcement and DataLoader batching.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\GraphQL
 * @author   Conduction B.V. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://OpenRegister.app
 */

namespace OCA\OpenRegister\Service\GraphQL;

use GraphQL\Deferred;
use GraphQL\Error\Error;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Object\GetObject;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\QueryHandler;
use OCA\OpenRegister\Service\Object\RelationHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCP\DB\Exception as DbException;
use Psr\Log\LoggerInterface;

/**
 * Resolves GraphQL queries, mutations, and fields by delegating to OpenRegister services.
 *
 * Handles RBAC enforcement, property-level filtering, DataLoader batching for relations,
 * pagination (offset + cursor), and audit trail integration.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.StaticAccess) — GraphQLErrorFormatter uses static factory methods by design
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class GraphQLResolver
{

    /**
     * DataLoader buffer for batching relation UUIDs.
     *
     * @var array<string, true>
     */
    private array $relationBuffer = [];

    /**
     * Loaded relation objects indexed by UUID.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $relationCache = [];

    /**
     * Collected partial errors for the current execution.
     *
     * @var Error[]
     */
    private array $partialErrors = [];

    /**
     * Constructor.
     *
     * @param GetObject           $getObject         Object finder
     * @param ObjectService       $objectService     Object service
     * @param PermissionHandler   $permissionHandler Permission handler
     * @param PropertyRbacHandler $propertyRbac      Property RBAC handler
     * @param RelationHandler     $relationHandler   Relation handler
     * @param AuditTrailMapper    $auditTrailMapper  Audit trail mapper
     * @param RegisterMapper      $registerMapper    Register mapper
     * @param LoggerInterface     $logger            Logger
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        private readonly GetObject $getObject,
        private readonly ObjectService $objectService,
        private readonly PermissionHandler $permissionHandler,
        private readonly PropertyRbacHandler $propertyRbac,
        private readonly RelationHandler $relationHandler,
        private readonly AuditTrailMapper $auditTrailMapper,
        private readonly RegisterMapper $registerMapper,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Resolve a single object query.
     *
     * @param Schema $schema The register schema
     * @param mixed  $root   Root value
     * @param array  $args   Query arguments (id)
     *
     * @return array<string, mixed>|null The resolved object data
     *
     * @throws Error If object not found or access denied
     */
    public function resolveSingle(Schema $schema, mixed $root, array $args): ?array
    {
        $id = $args['id'];

        // Check schema-level RBAC.
        $this->checkSchemaPermission(schema: $schema, action: 'read');

        try {
            $register = $this->findRegisterForSchema(schema: $schema);

            // Set register/schema context on ObjectService (required for query routing).
            $this->objectService->setRegister($register);
            $this->objectService->setSchema($schema);

            $object = $this->getObject->find(
                $id,
                $register,
                $schema
            );

            $data = $this->objectToArray(object: $object);

            // Apply property-level RBAC filtering.
            $data = $this->filterProperties(schema: $schema, data: $data);

            return $data;
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            $schemaTitle = $schema->getTitle();
            if ($schemaTitle === null || $schemaTitle === '') {
                $schemaTitle = $schema->getSlug();
            }

            throw GraphQLErrorFormatter::notFound(
                $schemaTitle,
                $id
            );
        }//end try

    }//end resolveSingle()

    /**
     * Resolve a list query with pagination, filtering, and facets.
     *
     * @param Schema $schema The register schema
     * @param mixed  $root   Root value
     * @param array  $args   Query arguments (filter, sort, search, fuzzy, first, offset, after, facets, selfFilter)
     *
     * @return array<string, mixed> The connection result
     */
    public function resolveList(Schema $schema, mixed $root, array $args): array
    {
        // Check schema-level RBAC.
        $this->checkSchemaPermission(schema: $schema, action: 'read');

        $register = $this->findRegisterForSchema(schema: $schema);

        // Set register/schema context on ObjectService (required for QueryHandler routing).
        if ($register !== null) {
            $this->objectService->setRegister($register);
        }

        $this->objectService->setSchema($schema);

        // Build request params from GraphQL args.
        $requestParams = $this->argsToRequestParams(args: $args);

        // Use ObjectService.buildSearchQuery which properly routes register/schema.
        $registerId = null;
        if ($register !== null) {
            $registerId = $register->getId();
        }

        $query = $this->objectService->buildSearchQuery(
            requestParams: $requestParams,
            register: $registerId,
            schema: $schema->getId()
        );

        // Multitenancy is handled by the query context (ObjectService checks active org).
        // RBAC is handled by checkSchemaPermission above.
        $result = $this->objectService->searchObjectsPaginated(
            query: $query,
            _rbac: true,
            _multitenancy: true
        );

        // Build connection response.
        $results    = ($result['results'] ?? []);
        $totalCount = ($result['total'] ?? 0);
        $limit      = ($result['limit'] ?? ($args['first'] ?? 20));
        $offset     = ($result['offset'] ?? ($args['offset'] ?? 0));

        // Convert results to arrays and apply property-level RBAC.
        $filteredResults = [];
        foreach ($results as $item) {
            if ($item instanceof \OCA\OpenRegister\Db\ObjectEntity) {
                $item = $this->objectToArray(object: $item);
            }

            if (is_array(value: $item) === true) {
                $filteredResults[] = $this->filterProperties(schema: $schema, data: $item);
            }
        }

        // Build edges with cursors.
        $edges = [];
        foreach ($filteredResults as $index => $item) {
            $uuid    = ($item['_uuid'] ?? $item['@self']['uuid'] ?? ($offset + $index));
            $edges[] = [
                'cursor'     => $this->encodeCursor(uuid: $uuid, offset: ($offset + $index)),
                'node'       => $item,
                '_relevance' => ($item['_relevance'] ?? null),
            ];
        }

        // Build page info.
        $hasNextPage     = (($offset + $limit) < $totalCount);
        $hasPreviousPage = ($offset > 0);

        $startCursor = null;
        $endCursor   = null;
        $edgesEmpty  = empty($edges);
        if ($edgesEmpty === false) {
            $startCursor = $edges[0]['cursor'];
            $lastEdge    = end($edges);
            $endCursor   = $lastEdge['cursor'];
        }

        return [
            'edges'      => $edges,
            'pageInfo'   => [
                'hasNextPage'     => $hasNextPage,
                'hasPreviousPage' => $hasPreviousPage,
                'startCursor'     => $startCursor,
                'endCursor'       => $endCursor,
            ],
            'totalCount' => $totalCount,
            'facets'     => ($result['facets'] ?? null),
            'facetable'  => ($result['facetable'] ?? null),
        ];

    }//end resolveList()

    /**
     * Resolve a create mutation.
     *
     * @param Schema      $schema        The register schema
     * @param array       $args          Mutation arguments (input)
     * @param string|null $operationName GraphQL operation name
     *
     * @return array<string, mixed> The created object data
     *
     * @throws Error If access denied or validation fails
     */
    public function resolveCreate(Schema $schema, array $args, ?string $operationName=null): array
    {
        $this->checkSchemaPermission(schema: $schema, action: 'create');

        // Check property-level write RBAC.
        $input = $args['input'];
        $unauthorizedProps = $this->propertyRbac->getUnauthorizedProperties(
            $schema,
            [],
            $input,
            true
        );

        if (empty($unauthorizedProps) === false) {
            throw new Error(
                'Not authorized to write fields: '.implode(separator: ', ', array: $unauthorizedProps),
                null,
                null,
                [],
                null,
                null,
                ['code' => 'FIELD_FORBIDDEN']
            );
        }

        $register = $this->findRegisterForSchema(schema: $schema);

        try {
            $object = $this->objectService->saveObject(
                $input,
                [],
                $register,
                $schema
            );

            return $this->objectToArray(object: $object);
        } catch (\OCA\OpenRegister\Exception\ValidationException $e) {
            throw new Error(
                $e->getMessage(),
                null,
                null,
                [],
                null,
                $e,
                ['code' => 'VALIDATION_ERROR']
            );
        }

    }//end resolveCreate()

    /**
     * Resolve an update mutation.
     *
     * @param Schema      $schema        The register schema
     * @param array       $args          Mutation arguments (id, input)
     * @param string|null $operationName GraphQL operation name
     *
     * @return array<string, mixed> The updated object data
     *
     * @throws Error If access denied, not found, or validation fails
     */
    public function resolveUpdate(Schema $schema, array $args, ?string $operationName=null): array
    {
        $this->checkSchemaPermission(schema: $schema, action: 'update');

        $id    = $args['id'];
        $input = $args['input'];

        // Check property-level write RBAC.
        $unauthorizedProps = $this->propertyRbac->getUnauthorizedProperties(
            $schema,
            [],
            $input,
            false
        );

        if (empty($unauthorizedProps) === false) {
            throw new Error(
                'Not authorized to write fields: '.implode(separator: ', ', array: $unauthorizedProps),
                null,
                null,
                [],
                null,
                null,
                ['code' => 'FIELD_FORBIDDEN']
            );
        }

        $register = $this->findRegisterForSchema(schema: $schema);

        try {
            $object = $this->objectService->saveObject(
                $input,
                [],
                $register,
                $schema,
                $id
            );

            return $this->objectToArray(object: $object);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            $schemaTitle = $schema->getTitle();
            if ($schemaTitle === null || $schemaTitle === '') {
                $schemaTitle = $schema->getSlug();
            }

            throw GraphQLErrorFormatter::notFound(
                $schemaTitle,
                $id
            );
        } catch (\OCA\OpenRegister\Exception\ValidationException $e) {
            throw new Error(
                $e->getMessage(),
                null,
                null,
                [],
                null,
                $e,
                ['code' => 'VALIDATION_ERROR']
            );
        }//end try

    }//end resolveUpdate()

    /**
     * Resolve a delete mutation.
     *
     * @param Schema $schema The register schema
     * @param array  $args   Mutation arguments (id)
     *
     * @return bool True if deleted
     *
     * @throws Error If access denied or not found
     */
    public function resolveDelete(Schema $schema, array $args): bool
    {
        $this->checkSchemaPermission(schema: $schema, action: 'delete');

        try {
            return $this->objectService->deleteObject($args['id']);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            $schemaTitle = $schema->getTitle();
            if ($schemaTitle === null || $schemaTitle === '') {
                $schemaTitle = $schema->getSlug();
            }

            throw GraphQLErrorFormatter::notFound(
                $schemaTitle,
                $args['id']
            );
        }

    }//end resolveDelete()

    /**
     * Resolve a relation field using deferred batching (DataLoader pattern).
     *
     * @param string $uuid         The UUID of the related object
     * @param Schema $parentSchema The parent schema (for RBAC context)
     * @param array  $path         The field path for error reporting
     *
     * @return Deferred A deferred value that resolves after batching
     */
    public function resolveRelation(string $uuid, Schema $parentSchema, array $path): Deferred
    {
        // Add to buffer for batch loading.
        $this->relationBuffer[$uuid] = true;

        return new Deferred(
                function () use ($uuid) {
                    // Flush the buffer if not yet loaded.
                    if (isset($this->relationCache[$uuid]) === false) {
                        $this->flushRelationBuffer();
                    }

                    return ($this->relationCache[$uuid] ?? null);
                }
                );

    }//end resolveRelation()

    /**
     * Resolve the _auditTrail field for an object.
     *
     * @param string $objectUuid The object UUID
     * @param int    $last       Number of entries to return
     *
     * @return array<array<string, mixed>> The audit trail entries
     */
    public function resolveAuditTrail(string $objectUuid, int $last=10): array
    {
        $entries = $this->auditTrailMapper->findAll(
            $last,
            0,
            ['object_uuid' => $objectUuid],
            ['created' => 'DESC']
        );

        return array_map(
            fn ($entry) => $entry->jsonSerialize(),
            $entries
        );

    }//end resolveAuditTrail()

    /**
     * Resolve the _usedBy field for an object.
     *
     * @param string $objectUuid The object UUID
     *
     * @return array<array<string, mixed>> The referencing objects
     */
    public function resolveUsedBy(string $objectUuid): array
    {
        $result = $this->relationHandler->getUsedBy($objectUuid);
        return $result['results'];

    }//end resolveUsedBy()

    /**
     * Flush the DataLoader buffer — batch-load all buffered relation UUIDs.
     *
     * @return void
     */
    private function flushRelationBuffer(): void
    {
        $uuids = array_keys(array: $this->relationBuffer);
        $this->relationBuffer = [];

        if (empty($uuids) === true) {
            return;
        }

        try {
            $loaded = $this->relationHandler->bulkLoadRelationshipsBatched($uuids);

            foreach ($loaded as $key => $object) {
                $this->relationCache[$key] = $this->objectToArray(object: $object);
            }
        } catch (\Exception $e) {
            $this->logger->warning('GraphQL relation batch load failed: '.$e->getMessage());
        }

    }//end flushRelationBuffer()

    /**
     * Check schema-level RBAC permission.
     *
     * @param Schema $schema The schema to check
     * @param string $action The action (read, create, update, delete)
     *
     * @return void
     *
     * @throws Error If permission denied
     */
    private function checkSchemaPermission(Schema $schema, string $action): void
    {
        try {
            $this->permissionHandler->checkPermission($schema, $action);
        } catch (NotAuthorizedException $e) {
            throw new Error(
                $e->getMessage(),
                null,
                null,
                [],
                null,
                $e,
                ['code' => 'FORBIDDEN']
            );
        }

    }//end checkSchemaPermission()

    /**
     * Apply property-level RBAC filtering to an object.
     *
     * @param Schema               $schema The schema
     * @param array<string, mixed> $data   The object data
     *
     * @return array<string, mixed> The filtered data
     */
    private function filterProperties(Schema $schema, array $data): array
    {
        return $this->propertyRbac->filterReadableProperties($schema, $data);

    }//end filterProperties()

    /**
     * Build a query array from GraphQL arguments for QueryHandler.
     *
     * @param array    $args     The GraphQL arguments
     * @param Register $register The register
     * @param Schema   $schema   The schema
     *
     * @return array<string, mixed> The query array
     */

    /**
     * Convert GraphQL args to HTTP request params format for ObjectService.buildSearchQuery().
     *
     * @param array $args The GraphQL arguments
     *
     * @return array<string, mixed> Request params compatible with buildSearchQuery
     */
    private function argsToRequestParams(array $args): array
    {
        $params = [];

        // Pagination.
        $params['_limit']  = ($args['first'] ?? 20);
        $params['_offset'] = ($args['offset'] ?? 0);

        // Search.
        if (isset($args['search']) === true) {
            $params['_search'] = $args['search'];
        }

        if (isset($args['fuzzy']) === true && $args['fuzzy'] === true) {
            $params['_fuzzy'] = 'true';
        }

        // Sort.
        if (isset($args['sort']) === true) {
            $params['_order'] = json_encode(
                    value: [
                        [
                            'field'     => $args['sort']['field'],
                            'direction' => strtoupper(string: ($args['sort']['order'] ?? 'ASC')),
                        ],
                    ]
                    );
        }

        // Facets.
        if (isset($args['facets']) === true && empty($args['facets']) === false) {
            $params['_facets'] = implode(separator: ',', array: $args['facets']);
        }

        // Filter (property values).
        if (isset($args['filter']) === true && is_array(value: $args['filter']) === true) {
            foreach ($args['filter'] as $field => $value) {
                $params[$field] = $value;
            }
        }

        // Self filter (metadata columns).
        if (isset($args['selfFilter']) === true && is_array(value: $args['selfFilter']) === true) {
            foreach ($args['selfFilter'] as $field => $value) {
                if ($value !== null) {
                    $params['@self'][$field] = $value;
                }
            }
        }

        return $params;

    }//end argsToRequestParams()

    /**
     * Convert an ObjectEntity to an array for GraphQL output.
     *
     * @param ObjectEntity $object The object entity
     *
     * @return array<string, mixed> The array representation
     */
    private function objectToArray(ObjectEntity $object): array
    {
        $data = $object->getObject() ?? [];

        // Add metadata fields.
        $data['_uuid']     = $object->getUuid();
        $data['_register'] = $object->getRegister();
        $data['_schema']   = $object->getSchema();

        $created          = $object->getCreated();
        $data['_created'] = ($created instanceof \DateTimeInterface === true)
            ? $created->format(\DateTimeInterface::ATOM) : $created;

        $updated          = $object->getUpdated();
        $data['_updated'] = ($updated instanceof \DateTimeInterface === true)
            ? $updated->format(\DateTimeInterface::ATOM) : $updated;

        $data['_owner'] = $object->getOwner();

        return $data;

    }//end objectToArray()

    /**
     * Find the register for a schema.
     *
     * @param Schema $schema The schema
     *
     * @return Register|null The register
     */
    private function findRegisterForSchema(Schema $schema): ?Register
    {
        try {
            // Schemas have a register property, but it may be null.
            // Try to find a register that contains this schema.
            $registers = $this->registerMapper->findAll();
            foreach ($registers as $register) {
                $schemaIds = $register->getSchemas() ?? [];
                if (in_array(needle: $schema->getId(), haystack: $schemaIds) === true) {
                    return $register;
                }
            }
        } catch (\Exception $e) {
            $this->logger->warning(
                'Could not find register for schema '.$schema->getId().': '.$e->getMessage()
            );
        }

        return null;

    }//end findRegisterForSchema()

    /**
     * Encode a pagination cursor.
     *
     * @param string     $uuid   The object UUID
     * @param int|string $offset The offset position
     *
     * @return string The encoded cursor
     */
    private function encodeCursor(string $uuid, int|string $offset): string
    {
        return base64_encode(
            string: json_encode(value: ['uuid' => $uuid, 'offset' => $offset])
        );

    }//end encodeCursor()

    /**
     * Get collected partial errors.
     *
     * @return Error[]
     */
    public function getPartialErrors(): array
    {
        return $this->partialErrors;

    }//end getPartialErrors()

    /**
     * Reset state for a new request.
     *
     * @return void
     */
    public function reset(): void
    {
        $this->relationBuffer = [];
        $this->relationCache  = [];
        $this->partialErrors  = [];

    }//end reset()
}//end class
