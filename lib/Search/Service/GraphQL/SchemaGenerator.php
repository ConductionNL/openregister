<?php

/**
 * GraphQL schema generator for OpenRegister.
 *
 * Generates a GraphQL schema from OpenRegister register/schema definitions,
 * mapping JSON Schema properties to GraphQL types with queries, mutations,
 * filters, and connection types.
 *
 * Delegates type mapping to TypeMapperHandler and composition logic to
 * CompositionHandler to keep class complexity manageable.
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
 * @spec openspec/specs/graphql-api/spec.md#requirement-query-complexity-analysis-must-prevent-resource-abuse
 */

namespace OCA\OpenRegister\Search\Service\GraphQL;

use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use GraphQL\Type\SchemaConfig;
use OCA\OpenRegister\Db\Schema as RegisterSchema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Search\Service\GraphQL\Scalar\DateTimeType;
use OCA\OpenRegister\Search\Service\GraphQL\Scalar\EmailType;
use OCA\OpenRegister\Search\Service\GraphQL\Scalar\JsonType;
use OCA\OpenRegister\Search\Service\GraphQL\Scalar\UriType;
use OCA\OpenRegister\Search\Service\GraphQL\Scalar\UuidType;
use OCA\OpenRegister\Search\Service\GraphQL\SchemaGenerator\CompositionHandler;
use OCA\OpenRegister\Search\Service\GraphQL\SchemaGenerator\GraphQLNames;
use OCA\OpenRegister\Search\Service\GraphQL\SchemaGenerator\ObjectPropertyFields;
use OCA\OpenRegister\Search\Service\GraphQL\SchemaGenerator\SchemaEntry;
use OCA\OpenRegister\Search\Service\GraphQL\SchemaGenerator\SchemaEntryFields;
use OCA\OpenRegister\Search\Service\GraphQL\SchemaGenerator\TypeMapperHandler;
use OCP\IUserSession;
use OCA\OpenRegister\Service\GraphQL\GraphQLResolver;

/**
 * Generates a GraphQL schema from OpenRegister register/schema definitions.
 *
 * Reads all schemas, maps JSON Schema properties to GraphQL types, and produces
 * a complete executable schema with queries, mutations, filters and connection types.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
class SchemaGenerator {

	/**
	 * Cached object types by schema ID.
	 *
	 * @var array<int, ObjectType>
	 */
	private array $objectTypes = [];

	/**
	 * Custom scalar type instances.
	 *
	 * @var array<string, Type>
	 */
	private array $scalars = [];

	/**
	 * Loaded schemas indexed by ID.
	 *
	 * @var array<int, RegisterSchema>
	 */
	private array $schemasById = [];

	/**
	 * Names of the schema being generated.
	 *
	 * @var GraphQLNames|null
	 */
	private ?GraphQLNames $names = null;

	/**
	 * Resolver instance for wiring real resolvers.
	 *
	 * @var GraphQLResolver|null
	 */
	private ?GraphQLResolver $resolver = null;

	/**
	 * Handler for type mapping and input type generation.
	 *
	 * @var TypeMapperHandler|null
	 */
	private ?TypeMapperHandler $typeMapper = null;

	/**
	 * Builds the fields of schema properties.
	 *
	 * @var ObjectPropertyFields|null
	 */
	private ?ObjectPropertyFields $propertyFields = null;

	/**
	 * Handler for allOf/oneOf/anyOf composition logic.
	 *
	 * @var CompositionHandler|null
	 */
	private ?CompositionHandler $compositionHandler = null;

	/**
	 * Constructor.
	 *
	 * @param SchemaMapperInterface $schemaMapper Schema mapper
	 * @param IUserSession $userSession The session that says who is asking
	 */
	public function __construct(
		private readonly SchemaMapperInterface $schemaMapper,
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * Set the resolver for wiring real resolvers into the schema.
	 *
	 * @param GraphQLResolver $resolver The resolver
	 *
	 * @return void
	 *
	 * @spec openspec/specs/graphql-api/spec.md#requirement-cross-register-schema-stitching-must-provide-a-unified-graph
	 */
	public function setResolver(GraphQLResolver $resolver): void {
		$this->resolver = $resolver;

	}//end setResolver()

	/**
	 * Generate a complete GraphQL schema from register definitions.
	 *
	 * @return Schema The executable GraphQL schema
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Schema generation inherently branches per register+schema
	 *
	 * @spec openspec/specs/graphql-api/spec.md#requirement-cross-register-schema-stitching-must-provide-a-unified-graph
	 */
	public function generate(): Schema {
		// Reset all caches including shared types.
		$this->objectTypes = [];
		$this->schemasById = [];
		$this->initScalars();
		$this->initHandlers();

		// Load all schemas. Bypass RBAC + multi-tenancy for
		// type generation: the GraphQL schema is system-scoped (per-request,
		// APCu-cached), and per-user authorisation happens at resolve time
		// on each query. Filtering schemas by the caller's organisation here
		// produces a different GraphQL schema per org, which silently hides
		// every cross-org schema from the type system even though those
		// schemas are readable via REST under the same session. Same
		// rationale as PermissionHandler::resolveSchemaSlug (#1454).
		$schemas = $this->schemaMapper->findAll(_rbac: false, _multitenancy: false);
		foreach ($schemas as $schema) {
			$this->schemasById[$schema->getId()] = $schema;
		}

		$entryFields = new SchemaEntryFields(typeMapper: $this->typeMapper, resolver: $this->resolver);
		foreach ($schemas as $schema) {
			$entryFields->add(entry: $this->entryFor(schema: $schema));
		}

		// Add register-scoped query.
		$queryFields = $entryFields->queryFields();
		$queryFields['register'] = [
			'type' => $this->scalars['JSON'],
			'args' => ['id' => Type::nonNull(Type::id())],
			'resolve' => fn () => null,
			'description' => 'Query within a specific register scope',
		];

		$queryType = new ObjectType(['name' => 'Query', 'fields' => $queryFields]);
		$mutationType = new ObjectType(['name' => 'Mutation', 'fields' => $entryFields->mutationFields()]);

		return new Schema(
			config: SchemaConfig::create()
				->setQuery($queryType)
				->setMutation($mutationType)
			// Types are auto-discovered from query/mutation fields.
			// Do not pass explicit setTypes() — it can cause duplicate type errors
			// when allOf composition creates overlapping type references.
		);

	}//end generate()

	/**
	 * Name a schema's root fields and give it its object type.
	 *
	 * @param RegisterSchema $schema The register schema
	 *
	 * @return SchemaEntry The schema as the root fields use it
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	private function entryFor(RegisterSchema $schema): SchemaEntry {
		$schemaSlug = $schema->getSlug();
		if ($schemaSlug === null || $schemaSlug === '') {
			$schemaSlug = 'schema_' . $schema->getId();
		}

		return new SchemaEntry(
			schema: $schema,
			singular: $this->names->toFieldName(slug: $this->names->singularize(plural: $schemaSlug)),
			plural: $this->names->toFieldName(slug: $schemaSlug),
			objectType: $this->getObjectType(schema: $schema),
		);
	}//end entryFor()

	/**
	 * Initialize custom scalar types.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	private function initScalars(): void {
		$this->scalars = [
			'DateTime' => new DateTimeType(),
			'UUID' => new UuidType(),
			'Email' => new EmailType(),
			'URI' => new UriType(),
			'JSON' => new JsonType(),
		];

	}//end initScalars()

	/**
	 * Initialize handler classes with callback dependencies.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	private function initHandlers(): void {
		$this->names = new GraphQLNames();

		$this->typeMapper = new TypeMapperHandler(
			scalars: $this->scalars,
			refResolver: $this->resolveRef(...),
			objectTypeFactory: $this->getObjectType(...),
			fieldNameConverter: $this->names->toFieldName(...),
			typeNameConverter: $this->names->toTypeName(...),
		);

		$this->compositionHandler = new CompositionHandler(
			refResolver: $this->resolveRef(...),
			objectTypeFactory: $this->getObjectType(...),
			fieldBuilder: $this->buildObjectFields(...),
			typeNameConverter: $this->names->toTypeName(...),
		);

		$this->propertyFields = new ObjectPropertyFields(typeMapper: $this->typeMapper, names: $this->names);

	}//end initHandlers()

	/**
	 * Get or create an object type for a register schema.
	 *
	 * @param RegisterSchema $schema The register schema
	 *
	 * @return ObjectType The GraphQL object type
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	public function getObjectType(RegisterSchema $schema): ObjectType {
		$schemaId = $schema->getId();

		if (isset($this->objectTypes[$schemaId]) === false) {
			$this->objectTypes[$schemaId] = new ObjectType(
				[
					'name' => $this->objectTypeName(schema: $schema),
					'description' => $this->objectDescription(schema: $schema),
					// Lazy, so circular references between schemas can resolve.
					'fields' => fn () => $this->buildObjectFields(schema: $schema),
				]
			);
		}

		return $this->objectTypes[$schemaId];
	}//end getObjectType()

	/**
	 * The type name of a schema, from its slug or else its ID.
	 *
	 * @param RegisterSchema $schema The register schema
	 *
	 * @return string The type name
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	private function objectTypeName(RegisterSchema $schema): string {
		$this->names ??= new GraphQLNames();

		$schemaSlug = $schema->getSlug();
		if ($schemaSlug === null || $schemaSlug === '') {
			$schemaSlug = 'Schema' . $schema->getId();
		}

		return $this->names->toTypeName(slug: $schemaSlug, schemaId: $schema->getId());
	}//end objectTypeName()

	/**
	 * The description of a schema, or else its summary.
	 *
	 * @param RegisterSchema $schema The register schema
	 *
	 * @return string|null The description
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	private function objectDescription(RegisterSchema $schema): ?string {
		$description = $schema->getDescription();
		if ($description === null || $description === '') {
			return $schema->getSummary();
		}

		return $description;
	}//end objectDescription()

	/**
	 * Build fields for an object type from schema properties.
	 *
	 * @param RegisterSchema $schema The register schema
	 *
	 * @return array<string, array<string, mixed>> The field configuration
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) JSON Schema composition (allOf/oneOf/anyOf) requires deep branching
	 * @SuppressWarnings(PHPMD.NPathComplexity)      Composition + property mapping creates high path count
	 */
	private function buildObjectFields(RegisterSchema $schema): array {
		$fields = [];

		// Metadata fields.
		$fields['_uuid'] = ['type' => $this->scalars['UUID'], 'description' => 'Object UUID'];
		$fields['_register'] = ['type' => Type::int(), 'description' => 'Register ID'];
		$fields['_schema'] = ['type' => Type::int(), 'description' => 'Schema ID'];
		$fields['_created'] = ['type' => $this->scalars['DateTime'], 'description' => 'Creation timestamp'];
		$fields['_updated'] = ['type' => $this->scalars['DateTime'], 'description' => 'Last update timestamp'];
		$fields['_owner'] = [
			'type' => Type::string(),
			'description' => 'Object owner user ID, null for an anonymous caller',
			'resolve' => fn (array $row): ?string => $this->ownerForCaller(row: $row),
		];

		// Audit trail field. It answers null: an object's entries may only be
		// given out through ObjectHistoryForReaders, and the explicit resolver
		// keeps the default one from reading an `_auditTrail` key off the object.
		$fields['_auditTrail'] = [
			'type' => Type::listOf($this->typeMapper->getAuditTrailType()),
			'description' => 'Audit trail entries for this object',
			'args' => [
				'last' => [
					'type' => Type::int(),
					'defaultValue' => 10,
					'description' => 'Number of most recent entries to return',
				],
			],
			'resolve' => static fn (): ?array => null,
		];

		$fields = array_merge($fields, $this->propertyFields->forSchema(schema: $schema));

		// Delegate allOf/oneOf/anyOf composition to handler.
		$this->compositionHandler->applyComposition(schema: $schema, fields: $fields);

		// _usedBy field for reverse relationship traversal.
		$fields['_usedBy'] = [
			'type' => $this->scalars['JSON'],
			'description' => 'Objects that reference this object (reverse relationships)',
		];

		return $fields;
	}//end buildObjectFields()

	/**
	 * Resolve a $ref to a schema entity.
	 *
	 * @param string $ref The reference string (slug, ID, or URI)
	 *
	 * @return RegisterSchema|null The resolved schema or null
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	private function resolveRef(string $ref): ?RegisterSchema {
		return $this->schemaNamed(ref: $ref) ?? $this->schemaNumbered(ref: $ref);
	}//end resolveRef()

	/**
	 * Find a loaded schema by slug or URI.
	 *
	 * @param string $ref The reference string
	 *
	 * @return RegisterSchema|null The schema or null
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	private function schemaNamed(string $ref): ?RegisterSchema {
		foreach ($this->schemasById as $schema) {
			if ($schema->getSlug() === $ref || $schema->getUri() === $ref) {
				return $schema;
			}
		}

		return null;
	}//end schemaNamed()

	/**
	 * Find a loaded schema by a numeric ID.
	 *
	 * @param string $ref The reference string
	 *
	 * @return RegisterSchema|null The schema or null
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	private function schemaNumbered(string $ref): ?RegisterSchema {
		if (is_numeric(value: $ref) === false) {
			return null;
		}

		return ($this->schemasById[(int)$ref] ?? null);
	}//end schemaNumbered()

	/**
	 * The `_owner` field: answered at the field, so an alias cannot get round it.
	 *
	 * The row keeps `_owner`, because a property rule on a related row reads it there.
	 *
	 * @param array<string, mixed> $row The resolved row.
	 *
	 * @return string|null The owner, or null for an anonymous caller.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	private function ownerForCaller(array $row): ?string {
		if ($this->userSession->getUser() === null) {
			return null;
		}

		return $row['_owner'] ?? null;
	}//end ownerForCaller()
}//end class
