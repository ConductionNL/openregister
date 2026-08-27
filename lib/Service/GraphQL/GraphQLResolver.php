<?php

/**
 * GraphQL resolver for OpenRegister.
 *
 * Resolves GraphQL queries, mutations, and fields by delegating
 * to OpenRegister services with RBAC enforcement and DataLoader batching.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\GraphQL
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 *
 * @spec openspec/specs/graphql-api/spec.md#requirement-graphql-must-enforce-schema-level-rbac-via-permissionhandler
 * @spec openspec/specs/graphql-api/spec.md#requirement-graphql-must-log-operations-to-the-audit-trail
 * @spec openspec/specs/graphql-api/spec.md#requirement-cross-register-schema-stitching-must-provide-a-unified-graph
 * @spec openspec/specs/graphql-api/spec.md#requirement-graphql-resolver-must-reset-state-between-requests
 */

namespace OCA\OpenRegister\Service\GraphQL;

use GraphQL\Deferred;
use GraphQL\Error\Error;
use InvalidArgumentException;
use RuntimeException;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use OCA\OpenRegister\Service\Aggregation\TimeseriesRequestValidator;
use OCA\OpenRegister\Service\Object\GetObject;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\QueryHandler;
use OCA\OpenRegister\Service\Object\RelationHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\PropertyRbacHandler;
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
 * @SuppressWarnings(PHPMD.StaticAccess)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class GraphQLResolver {

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
	 * @param GetObject $getObject Object finder
	 * @param ObjectService $objectService Object service
	 * @param PermissionHandler $permissionHandler Permission handler
	 * @param PropertyRbacHandler $propertyRbac Property RBAC handler
	 * @param RelationHandler $relationHandler Relation handler
	 * @param AuditTrailMapper $auditTrailMapper Audit trail mapper
	 * @param RegisterMapper $registerMapper Register mapper
	 * @param LoggerInterface $logger Logger
	 * @param \OCA\OpenRegister\Service\Object\TranslationHandler $translationHandler Translation handler
	 * @param AggregationRunner $aggregationRunner Ad-hoc aggregation dispatcher.
	 * @param TimeseriesRequestValidator $timeseriesValidator Validator for `groupBy` arg.
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
		private readonly \OCA\OpenRegister\Service\Object\TranslationHandler $translationHandler,
		private readonly AggregationRunner $aggregationRunner,
		private readonly TimeseriesRequestValidator $timeseriesValidator,
	) {
	}//end __construct()

	/**
	 * Resolve a single object query.
	 *
	 * @param Schema $schema The register schema
	 * @param mixed $root Root value
	 * @param array $args Query arguments (id)
	 *
	 * @return array<string, mixed>|null The resolved object data
	 *
	 * @throws Error If object not found or access denied
	 *
	 * @spec openspec/specs/graphql-api/spec.md#requirement-graphql-must-enforce-schema-level-rbac-via-permissionhandler
	 */
	public function resolveSingle(Schema $schema, mixed $root, array $args): ?array {
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
	 * @param mixed $root Root value
	 * @param array $args Query arguments (filter, sort, search, fuzzy, first, offset, after, facets, selfFilter)
	 *
	 * @return array<string, mixed> The connection result
	 *
	 * @spec openspec/specs/graphql-api/spec.md#requirement-graphql-must-enforce-schema-level-rbac-via-permissionhandler
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
	 *   The connection-build pipeline is intentionally inline:
	 *   each section (RBAC, search, filter, cursor, page-info, optional
	 *   groupBy) is a single responsibility but extracting them adds
	 *   indirection without reducing complexity.
	 */
	public function resolveList(Schema $schema, mixed $root, array $args): array {
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
		$results = ($result['results'] ?? []);
		$totalCount = ($result['total'] ?? 0);
		$limit = ($result['limit'] ?? ($args['first'] ?? 20));
		$offset = ($result['offset'] ?? ($args['offset'] ?? 0));

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
			$uuid = ($item['_uuid'] ?? $item['@self']['uuid'] ?? ($offset + $index));
			$edges[] = [
				'cursor' => $this->encodeCursor(uuid: $uuid, offset: ($offset + $index)),
				'node' => $item,
				'_relevance' => ($item['_relevance'] ?? null),
			];
		}

		// Build page info.
		$hasNextPage = (($offset + $limit) < $totalCount);
		$hasPreviousPage = ($offset > 0);

		$startCursor = null;
		$endCursor = null;
		$edgesEmpty = empty($edges);
		if ($edgesEmpty === false) {
			$startCursor = $edges[0]['cursor'];
			$lastEdge = end($edges);
			$endCursor = $lastEdge['cursor'];
		}

		$connection = [
			'edges' => $edges,
			'pageInfo' => [
				'hasNextPage' => $hasNextPage,
				'hasPreviousPage' => $hasPreviousPage,
				'startCursor' => $startCursor,
				'endCursor' => $endCursor,
			],
			'totalCount' => $totalCount,
			'facets' => ($result['facets'] ?? null),
			'facetable' => ($result['facetable'] ?? null),
			'groups' => null,
		];

		// Optional ad-hoc aggregation: client supplied `groupBy` on the
		// list query. Run through the same validator the REST endpoint
		// uses so allow-list + sub-day-interval rules stay consistent.
		// Validation errors surface as GraphQL field-errors (the
		// `groups` field is null, the rest of the connection is intact).
		$groupBy = ($args['groupBy'] ?? null);
		if (is_array($groupBy) === true && ($groupBy['field'] ?? '') !== '') {
			$connection['groups'] = $this->resolveGroupBy(
				schema: $schema,
				register: $register,
				rawArgs: $groupBy,
				filter: $this->propertyFilterFromArgs(args: $args)
			);
		}

		// Optional DECLARED aggregation, by name.
		$aggregationName = ($args['aggregation'] ?? null);
		if (is_string($aggregationName) === true && $aggregationName !== '') {
			$connection['aggregation'] = $this->resolveNamedAggregation(
				schema: $schema,
				register: $register,
				name: $aggregationName,
				filter: $this->propertyFilterFromArgs(args: $args)
			);
		}

		return $connection;
	}//end resolveList()

	/**
	 * Resolve a DECLARED aggregation by name.
	 *
	 * `groupBy` is ad-hoc — the caller describes the aggregation. This runs one
	 * the schema declares in `x-openregister-aggregations`, which was reachable
	 * only over REST, so a page wanting a declared figure had to hand-build a
	 * URL alongside its GraphQL query.
	 *
	 * The query's own property filter is passed as a NARROWING constraint. That
	 * is safe by construction: the engine refuses any request key the
	 * declaration already pins, so a caller can add a constraint and can never
	 * relax a declared scoping one.
	 *
	 * The envelope is returned as-is — the same JSON the REST endpoint emits —
	 * because its shape varies with what the aggregation declares (a scalar
	 * `value`, a `values` map, `groups[].keys`, `joined`).
	 *
	 * @param Schema                $schema   The schema being aggregated.
	 * @param Register|null         $register The register; null when the schema is unbound.
	 * @param string                $name     The declared aggregation's name.
	 * @param array<string, mixed>  $filter   The query's property filter, as a narrowing constraint.
	 *
	 * @return array<string, mixed>|null The result envelope, or null when unbound.
	 *
	 * @throws Error When the aggregation is unknown, malformed, or RBAC denies it.
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	private function resolveNamedAggregation(
		Schema $schema,
		?Register $register,
		string $name,
		array $filter = [],
	): ?array {
		if ($register === null) {
			return null;
		}

		try {
			return $this->aggregationRunner->run(
				registerRef: (string)$register->getSlug(),
				schemaRef: (string)$schema->getSlug(),
				name: $name,
				extraFilter: $filter
			);
		} catch (NotAuthorizedException $e) {
			throw new Error($e->getMessage());
		} catch (RuntimeException $e) {
			// An unknown name, an unusable spec, or an unsupported filter
			// operator. Surfaced as a GraphQL field error so the rest of the
			// connection still resolves — the caller gets its rows and a named
			// error, rather than a failed query with no data.
			throw new Error($e->getMessage());
		}
	}//end resolveNamedAggregation()

	/**
	 * Resolve the optional `groupBy` argument by dispatching to
	 * `AggregationRunner::runAdhoc()`.
	 *
	 * Returns the `groups` array on success. On validation / RBAC
	 * failure, throws a GraphQL `Error` so the field-level error
	 * surface picks it up (the rest of the connection still resolves).
	 *
	 * @param Schema $schema The schema being aggregated.
	 * @param Register|null $register The register (may be null if the schema isn't bound — defensive).
	 * @param array $rawArgs The raw groupBy arg map from the GraphQL request.
	 * @param array<string, mixed> $filter The list query's property filter, so the groups
	 *                                     describe the same rows the edges do. Was hardcoded
	 *                                     empty, which reported totals over the whole schema.
	 *
	 * @return array<int, array{key: string, value: int|float}>|null Bucket array, or null when register/runner missing.
	 *
	 * @throws Error When validation fails or RBAC denies the request.
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	private function resolveGroupBy(
		Schema $schema,
		?Register $register,
		array $rawArgs,
		array $filter = [],
	): ?array {
		if ($register === null) {
			// Defensive: a schema without a register has nothing to
			// aggregate against. Return null so the field stays
			// queryable but empty.
			return null;
		}

		// Normalise the GraphQL arg shape into the validator's input
		// shape. The validator returns a fully-built AggregationQuery
		// (or throws InvalidArgumentException with a 400-grade message).
		$input = [
			'field' => (string)($rawArgs['field'] ?? ''),
			'interval' => ($rawArgs['interval'] ?? null),
			'from' => ($rawArgs['from'] ?? null),
			'to' => ($rawArgs['to'] ?? null),
			'metric' => strtolower((string)($rawArgs['metric'] ?? 'count')),
			'metricField' => ($rawArgs['metricField'] ?? null),
			// THE QUERY'S OWN FILTER, not an empty array.
			//
			// This was hardcoded `[]`, so a filtered list returned group totals
			// computed over the WHOLE schema: the edges honoured the filter and
			// the groups silently did not. Nothing errored — the caller simply
			// got a bigger number than the rows it was shown, which is the
			// hardest kind of wrong answer to notice on a dashboard.
			'filter' => $filter,
		];

		try {
			$query = $this->timeseriesValidator->validate(input: $input, schema: $schema);
		} catch (InvalidArgumentException $e) {
			throw new Error($e->getMessage());
		}

		try {
			$result = $this->aggregationRunner->runAdhoc(
				register: $register,
				schema: $schema,
				query: $query
			);
		} catch (NotAuthorizedException $e) {
			throw new Error($e->getMessage());
		}

		$groups = ($result['groups'] ?? []);
		// Coerce values to float to match the GraphQL `value: Float!` type.
		$normalised = [];
		foreach ($groups as $bucket) {
			$normalised[] = [
				'key' => (string)($bucket['key'] ?? ''),
				'value' => (float)($bucket['value'] ?? 0),
			];
		}

		return $normalised;
	}//end resolveGroupBy()

	/**
	 * Resolve a create mutation.
	 *
	 * @param Schema $schema The register schema
	 * @param array $args Mutation arguments (input)
	 * @param string|null $operationName GraphQL operation name
	 *
	 * @return array<string, mixed> The created object data
	 *
	 * @throws Error If access denied or validation fails
	 *
	 * @spec openspec/specs/graphql-api/spec.md#requirement-graphql-must-log-operations-to-the-audit-trail
	 */
	public function resolveCreate(Schema $schema, array $args, ?string $operationName = null): array {
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
				'Not authorized to write fields: ' . implode(separator: ', ', array: $unauthorizedProps),
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
	 * @param Schema $schema The register schema
	 * @param array $args Mutation arguments (id, input)
	 * @param string|null $operationName GraphQL operation name
	 *
	 * @return array<string, mixed> The updated object data
	 *
	 * @throws Error If access denied, not found, or validation fails
	 *
	 * @spec openspec/specs/graphql-api/spec.md#requirement-graphql-must-log-operations-to-the-audit-trail
	 */
	public function resolveUpdate(Schema $schema, array $args, ?string $operationName = null): array {
		$this->checkSchemaPermission(schema: $schema, action: 'update');

		$id = $args['id'];
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
				'Not authorized to write fields: ' . implode(separator: ', ', array: $unauthorizedProps),
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
	 * @param array $args Mutation arguments (id)
	 *
	 * @return bool True if deleted
	 *
	 * @throws Error If access denied or not found
	 *
	 * @spec openspec/specs/graphql-api/spec.md#requirement-graphql-must-log-operations-to-the-audit-trail
	 */
	public function resolveDelete(Schema $schema, array $args): bool {
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
	 * @param string $uuid The UUID of the related object
	 * @param Schema $parentSchema The parent schema (for RBAC context)
	 * @param array $path The field path for error reporting
	 *
	 * @return Deferred A deferred value that resolves after batching
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	public function resolveRelation(string $uuid, Schema $parentSchema, array $path): Deferred {
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
	 * @param int $last Number of entries to return
	 *
	 * @return array<array<string, mixed>> The audit trail entries
	 *
	 * @spec openspec/specs/graphql-api/spec.md#requirement-graphql-must-log-operations-to-the-audit-trail
	 */
	public function resolveAuditTrail(string $objectUuid, int $last = 10): array {
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
	 *
	 * @spec openspec/specs/graphql-api/spec.md#requirement-graphql-must-enforce-schema-level-rbac-via-permissionhandler
	 */
	public function resolveUsedBy(string $objectUuid): array {
		$result = $this->relationHandler->getUsedBy($objectUuid);
		return $result['results'];
	}//end resolveUsedBy()

	/**
	 * Flush the DataLoader buffer — batch-load all buffered relation UUIDs.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	private function flushRelationBuffer(): void {
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
			$this->logger->warning('GraphQL relation batch load failed: ' . $e->getMessage());
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
	 *
	 * @spec openspec/specs/graphql-api/spec.md#requirement-graphql-must-enforce-schema-level-rbac-via-permissionhandler
	 */
	private function checkSchemaPermission(Schema $schema, string $action): void {
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
	 * @param Schema $schema The schema
	 * @param array<string, mixed> $data The object data
	 *
	 * @return array<string, mixed> The filtered data
	 */
	private function filterProperties(Schema $schema, array $data): array {
		// Apply property-level RBAC first (drops fields the caller can't read).
		$data = $this->propertyRbac->filterReadableProperties($schema, $data);

		// Apply translation resolution: language-keyed JSONB property
		// values collapse to a single string per the request-scoped
		// LanguageService chain (Decision 2 → per-property fallback).
		// The register lookup is best-effort; null register falls back
		// to the [nl, en] default chain inside the handler.
		$register = null;
		try {
			$register = $this->findRegisterForSchema(schema: $schema);
		} catch (\Throwable $e) {
			$this->logger->debug(
				sprintf('[GraphQLResolver] register lookup for translation context failed: %s', $e->getMessage())
			);
		}

		return $this->translationHandler->resolveTranslationsForRender(
			objectData: $data,
			schema: $schema,
			register: $register
		);
	}//end filterProperties()

	/**
	 * Build a query array from GraphQL arguments for QueryHandler.
	 *
	 * @param array $args The GraphQL arguments
	 * @param Register $register The register
	 * @param Schema $schema The schema
	 *
	 * @return array<string, mixed> The query array
	 */

	/**
	 * The PROPERTY filter a list query carried, for reuse by its aggregation.
	 *
	 * Only `filter` — the property-value map. Deliberately NOT `search`,
	 * `sort`, `first`/`offset` or `selfFilter`:
	 *
	 *  - paging must not reach the aggregation. A group total over "the first
	 *    20 rows" is not a total, and it would change as the user paged;
	 *  - `search` is a free-text relevance query the aggregation engine does
	 *    not implement, so forwarding it would filter on a property named
	 *    `_search` and match nothing — an empty result, not an error;
	 *  - `selfFilter` addresses `@self` metadata columns, a different
	 *    namespace from the schema properties the aggregation filters on.
	 *
	 * Anything omitted here means the groups describe a WIDER population than
	 * the rows. That is the defect this method exists to fix, so each omission
	 * above is a deliberate call and not an oversight.
	 *
	 * @param array<string, mixed> $args The GraphQL arguments.
	 *
	 * @return array<string, mixed> Property filters, or an empty array.
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	private function propertyFilterFromArgs(array $args): array {
		$filter = ($args['filter'] ?? null);
		if (is_array($filter) === false) {
			return [];
		}

		$out = [];
		foreach ($filter as $field => $value) {
			if (is_string($field) === false || $field === '') {
				continue;
			}

			$out[$field] = $value;
		}

		return $out;
	}//end propertyFilterFromArgs()

	/**
	 * Convert GraphQL args to HTTP request params format for ObjectService.buildSearchQuery().
	 *
	 * @param array $args The GraphQL arguments
	 *
	 * @return array<string, mixed> Request params compatible with buildSearchQuery
	 */
	private function argsToRequestParams(array $args): array {
		$params = [];

		// Pagination.
		$params['_limit'] = ($args['first'] ?? 20);
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
						'field' => $args['sort']['field'],
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
	private function objectToArray(ObjectEntity $object): array {
		$data = $object->getObject() ?? [];

		// Add metadata fields.
		$data['_uuid'] = $object->getUuid();
		$data['_register'] = $object->getRegister();
		$data['_schema'] = $object->getSchema();

		$created = $object->getCreated();
		$data['_created'] = $this->formatDateOrPassthrough(value: $created);

		$updated = $object->getUpdated();
		$data['_updated'] = $this->formatDateOrPassthrough(value: $updated);

		$data['_owner'] = $object->getOwner();

		return $data;
	}//end objectToArray()

	/**
	 * Format a DateTimeInterface as ATOM, or pass through unchanged.
	 *
	 * @param mixed $value The value to format.
	 *
	 * @return mixed The ATOM-formatted string or the original value.
	 */
	private function formatDateOrPassthrough(mixed $value): mixed {
		if ($value instanceof \DateTimeInterface === true) {
			return $value->format(\DateTimeInterface::ATOM);
		}

		return $value;
	}//end formatDateOrPassthrough()

	/**
	 * Find the register for a schema.
	 *
	 * @param Schema $schema The schema
	 *
	 * @return Register|null The register
	 *
	 * @spec openspec/specs/graphql-api/spec.md#requirement-cross-register-schema-stitching-must-provide-a-unified-graph
	 */
	private function findRegisterForSchema(Schema $schema): ?Register {
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
				'Could not find register for schema ' . $schema->getId() . ': ' . $e->getMessage()
			);
		}

		return null;
	}//end findRegisterForSchema()

	/**
	 * Encode a pagination cursor.
	 *
	 * @param string $uuid The object UUID
	 * @param int|string $offset The offset position
	 *
	 * @return string The encoded cursor
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	private function encodeCursor(string $uuid, int|string $offset): string {
		return base64_encode(
			string: json_encode(value: ['uuid' => $uuid, 'offset' => $offset])
		);

	}//end encodeCursor()

	/**
	 * Get collected partial errors.
	 *
	 * @return Error[]
	 *
	 * @spec openspec/specs/graphql-api/spec.md#requirement-graphql-resolver-must-reset-state-between-requests
	 */
	public function getPartialErrors(): array {
		return $this->partialErrors;
	}//end getPartialErrors()

	/**
	 * Reset state for a new request.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/graphql-api/spec.md#requirement-graphql-resolver-must-reset-state-between-requests
	 */
	public function reset(): void {
		$this->relationBuffer = [];
		$this->relationCache = [];
		$this->partialErrors = [];

	}//end reset()
}//end class
