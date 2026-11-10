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

use OCA\OpenRegister\Service\PropertyAccessRequest;
use OCA\OpenRegister\Service\PropertyWriteCheck;
use OCA\OpenRegister\Service\Object\TranslationScope;
use OCA\OpenRegister\Service\Rbac\ExplicitReveal;
use GraphQL\Deferred;
use GraphQL\Error\Error;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use OCA\OpenRegister\Search\Service\Aggregation\TimeseriesRequestValidatorInterface;
use OCA\OpenRegister\Service\Object\GetObject;
use OCA\OpenRegister\Service\Object\ObjectFindRequest;
use OCA\OpenRegister\Service\Object\ReadScope;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCA\OpenRegister\Service\Object\RelationHandler;
use OCA\OpenRegister\Service\Object\RelationLookup;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\PropertyRbacHandlerInterface;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Search\Service\GraphQL\GraphQLErrorFormatter;

/**
 * Resolves GraphQL queries, mutations, and fields by delegating to OpenRegister services.
 *
 * Handles RBAC enforcement, property-level filtering, DataLoader batching for relations,
 * pagination (offset + cursor), and audit trail integration.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
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
	 * @param GraphQLResolverObjects $objects The object and register collaborators the GraphQL resolver reads and writes through.
	 * @param GraphQLResolverRights  $rights  The schema-level and property-level RBAC collaborators of the GraphQL resolver.
	 * @param GraphQLResolverShaping $shaping The translation and aggregation collaborators that shape a GraphQL result.
	 * @param LoggerInterface        $logger  Logger
	 */
	public function __construct(
		private readonly GraphQLResolverObjects $objects,
		private readonly GraphQLResolverRights $rights,
		private readonly GraphQLResolverShaping $shaping,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Resolve a single object query.
	 *
	 * @param Schema $schema The register schema
	 * @param array $args Query arguments (id)
	 *
	 * @return array<string, mixed>|null The resolved object data
	 *
	 * @throws Error If object not found or access denied
	 *
	 * @spec openspec/specs/graphql-api/spec.md#requirement-graphql-must-enforce-schema-level-rbac-via-permissionhandler
	 */
	public function resolveSingle(Schema $schema, array $args): ?array {
		$id = $args['id'];

		// Check schema-level RBAC.
		$this->checkSchemaPermission(schema: $schema, action: 'read');

		try {
			$register = $this->findRegisterForSchema(schema: $schema);

			// Set register/schema context on ObjectService (required for query routing).
			$this->objects->objectService->setRegister($register);
			$this->objects->objectService->setSchema($schema);

			$object = $this->objects->getObject->find(
				request: new ObjectFindRequest(id: $id, extend: [], files: false, rbac: true, multitenancy: true, audit: true),
				scope: new ReadScope(register: $register, schema: $schema)
			);

			// Apply property-level RBAC filtering.
			return $this->filterProperties(schema: $schema, item: $object);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			throw $this->notFoundError(schema: $schema, id: $id);
		}//end try

	}//end resolveSingle()

	/**
	 * Resolve a list query with pagination, filtering, and facets.
	 *
	 * @param Schema $schema The register schema
	 * @param array $args Query arguments (filter, sort, search, fuzzy, first, offset, after, facets, selfFilter)
	 *
	 * @return array<string, mixed> The connection result
	 *
	 * @spec openspec/specs/graphql-api/spec.md#requirement-graphql-must-enforce-schema-level-rbac-via-permissionhandler
	 */
	public function resolveList(Schema $schema, array $args): array {
		// Check schema-level RBAC.
		$this->checkSchemaPermission(schema: $schema, action: 'read');

		$register = $this->findRegisterForSchema(schema: $schema);
		$request = new ListRequest(schema: $schema, register: $register, args: $args);
		$query = $this->searchQuery(request: $request);

		// Multitenancy is handled by the query context (ObjectService checks active org).
		// RBAC is handled by checkSchemaPermission above.
		$result = $this->objects->objectService->searchObjectsPaginated(query: $query, _rbac: true, _multitenancy: true);

		// Convert results to arrays and apply property-level RBAC.
		$filteredResults = [];
		foreach (($result['results'] ?? []) as $item) {
			if ($item instanceof ObjectEntity === true || is_array(value: $item) === true) {
				$filteredResults[] = $this->filterProperties(schema: $schema, item: $item);
			}
		}

		$connection = new ListConnection(result: $result, request: $request);

		return $this->aggregations()->addTo(connection: $connection->build(rows: $filteredResults), request: $request);
	}//end resolveList()

	/**
	 * Point ObjectService at the register and schema, and build the search query.
	 *
	 * @param ListRequest $request The list query.
	 *
	 * @return array<string, mixed> The search query.
	 */
	private function searchQuery(ListRequest $request): array {
		// Set register/schema context on ObjectService (required for QueryHandler routing).
		if ($request->register !== null) {
			$this->objects->objectService->setRegister($request->register);
		}

		$this->objects->objectService->setSchema($request->schema);

		return $this->objects->objectService->buildSearchQuery(
			requestParams: $request->requestParams(),
			register: $request->registerId(),
			schema: $request->schema->getId()
		);
	}//end searchQuery()

	/**
	 * The collaborator running a list query's aggregations.
	 *
	 * @return ListAggregations The aggregations.
	 */
	private function aggregations(): ListAggregations {
		return new ListAggregations(runner: $this->shaping->aggregationRunner, validator: $this->shaping->timeseriesValidator);
	}//end aggregations()

	/**
	 * Resolve a create mutation.
	 *
	 * @param Schema $schema The register schema
	 * @param array $args Mutation arguments (input)
	 *
	 * @return array<string, mixed> The created object data
	 *
	 * @throws Error If access denied or validation fails
	 *
	 * @spec openspec/specs/graphql-api/spec.md#requirement-graphql-must-log-operations-to-the-audit-trail
	 */
	public function resolveCreate(Schema $schema, array $args): array {
		$this->checkSchemaPermission(schema: $schema, action: 'create');

		$input = $args['input'];
		$this->refuseForbiddenFields(
			fields: $this->rights->propertyRbac->getUnauthorizedProperties(new PropertyWriteCheck($schema, new PropertyAccessRequest([], true), $input))
		);

		$register = $this->findRegisterForSchema(schema: $schema);

		try {
			return $this->objectToArray(object: $this->objects->objectService->saveObject($input, [], $register, $schema));
		} catch (\OCA\OpenRegister\Exception\ValidationException $e) {
			throw $this->validationError(cause: $e);
		}

	}//end resolveCreate()

	/**
	 * Resolve an update mutation.
	 *
	 * @param Schema $schema The register schema
	 * @param array $args Mutation arguments (id, input)
	 *
	 * @return array<string, mixed> The updated object data
	 *
	 * @throws Error If access denied, not found, or validation fails
	 *
	 * @spec openspec/specs/graphql-api/spec.md#requirement-graphql-must-log-operations-to-the-audit-trail
	 */
	public function resolveUpdate(Schema $schema, array $args): array {
		$this->checkSchemaPermission(schema: $schema, action: 'update');

		$id = $args['id'];
		$input = $args['input'];
		$register = $this->findRegisterForSchema(schema: $schema);

		try {
			// Check property-level write RBAC against the stored record.
			$stored = $this->objects->getObject->find(
				request: new ObjectFindRequest(id: $id, extend: [], files: false, rbac: true, multitenancy: true, audit: false),
				scope: new ReadScope(register: $register, schema: $schema)
			);
			$this->refuseForbiddenFields(
				fields: $this->rights->propertyRbac->getUnauthorizedProperties(
					new PropertyWriteCheck($schema, new PropertyAccessRequest($stored->getMatchEnvelope(), false), $input)
				)
			);

			return $this->objectToArray(object: $this->objects->objectService->saveObject($input, [], $register, $schema, $id));
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			throw $this->notFoundError(schema: $schema, id: $id);
		} catch (\OCA\OpenRegister\Exception\ValidationException $e) {
			throw $this->validationError(cause: $e);
		}//end try

	}//end resolveUpdate()

	/**
	 * Refuse a write when the property check named fields the caller may not write.
	 *
	 * @param array<int, string> $fields The refused fields.
	 *
	 * @return void
	 *
	 * @throws Error With code FIELD_FORBIDDEN when any field is refused.
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	private function refuseForbiddenFields(array $fields): void {
		if (empty($fields) === true) {
			return;
		}

		throw new Error(
			'Not authorized to write fields: ' . implode(separator: ', ', array: $fields),
			null,
			null,
			[],
			null,
			null,
			['code' => 'FIELD_FORBIDDEN']
		);
	}//end refuseForbiddenFields()

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
			return $this->objects->objectService->deleteObject($args['id']);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			throw $this->notFoundError(schema: $schema, id: $args['id']);
		}

	}//end resolveDelete()

	/**
	 * Resolve a relation field using deferred batching (DataLoader pattern).
	 *
	 * @param string $uuid The UUID of the related object
	 *
	 * @return Deferred A deferred value that resolves after batching
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	public function resolveRelation(string $uuid): Deferred {
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
	 * Resolve the _usedBy field for an object.
	 *
	 * @param string $objectUuid The object UUID
	 *
	 * @return array<array<string, mixed>> The referencing objects
	 *
	 * @spec openspec/specs/graphql-api/spec.md#requirement-graphql-must-enforce-schema-level-rbac-via-permissionhandler
	 */
	public function resolveUsedBy(string $objectUuid): array {
		$result = $this->objects->relationHandler->getUsedBy(lookup: new RelationLookup(objectId: $objectUuid));
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
			$loaded = $this->objects->relationHandler->bulkLoadRelationshipsBatched($uuids);

			foreach ($loaded as $key => $object) {
				$this->relationCache[$key] = $this->objectToArray(object: $object);
			}
		} catch (\Exception $e) {
			$this->logger->warning('GraphQL relation batch load failed: ' . $e->getMessage());
		}

	}//end flushRelationBuffer()

	/**
	 * The NOT_FOUND error for a record of a schema, named by its title or else its slug.
	 *
	 * @param Schema $schema The schema.
	 * @param string $id     The requested id.
	 *
	 * @return Error The error.
	 */
	private function notFoundError(Schema $schema, string $id): Error {
		$schemaTitle = $schema->getTitle();
		if ($schemaTitle === null || $schemaTitle === '') {
			$schemaTitle = $schema->getSlug();
		}

		return GraphQLErrorFormatter::notFound($schemaTitle, $id);
	}//end notFoundError()

	/**
	 * The VALIDATION_ERROR error for a failed save.
	 *
	 * @param ValidationException $cause The validation failure.
	 *
	 * @return Error The error.
	 */
	private function validationError(ValidationException $cause): Error {
		return new Error($cause->getMessage(), null, null, [], null, $cause, ['code' => 'VALIDATION_ERROR']);
	}//end validationError()

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
			$this->rights->permissionHandler->checkPermission($schema, $action);
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
	 * A record is filtered with the rules reading its own metadata; a row that is
	 * already an array is filtered as it is.
	 *
	 * @param Schema                             $schema The schema
	 * @param ObjectEntity|array<string, mixed> $item   The record, or a row
	 *
	 * @return array<string, mixed> The filtered data
	 */
	private function filterProperties(Schema $schema, ObjectEntity|array $item): array {
		// Apply property-level RBAC first (drops fields the caller can't read).
		$readable = fn (array $probe): array => $this->rights->propertyRbac->filterReadableProperties($schema, $probe);
		$data = $item;
		if ($item instanceof ObjectEntity === true) {
			$data = $item->filterAsRecord(data: $this->objectToArray(object: $item), filter: $readable);
		}

		if (is_array($item) === true) {
			$data = $readable($item);
		}

		// Explicit-reveal values are shown only through the logged single-object reveal.
		$data = ExplicitReveal::maskAll(schema: $schema, object: $data);

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

		return $this->shaping->translationHandler->resolveTranslationsForRender(
			objectData: $data,
			scope: new TranslationScope(schema: $schema, register: $register)
		);
	}//end filterProperties()

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
			$registers = $this->objects->registerMapper->findAll();
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
