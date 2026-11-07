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
use OCA\OpenRegister\Search\Service\GraphQL\SchemaGenerator\ObjectTypes;
use OCA\OpenRegister\Search\Service\GraphQL\SchemaGenerator\SchemaCaller;
use OCA\OpenRegister\Search\Service\GraphQL\SchemaGenerator\SchemaEntryFields;
use OCA\OpenRegister\Service\GraphQL\GraphQLResolver;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCP\IUserSession;

/**
 * Generates a GraphQL schema from OpenRegister register/schema definitions.
 *
 * Reads all schemas, maps JSON Schema properties to GraphQL types, and produces
 * a complete executable schema with queries, mutations, filters and connection types.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.StaticAccess) webonyx exposes its built-in types statically
 */
class SchemaGenerator {

	/**
	 * The object types of the last generated schema.
	 *
	 * @var ObjectTypes|null
	 */
	private ?ObjectTypes $objectTypes = null;

	/**
	 * Resolver instance for wiring real resolvers.
	 *
	 * @var GraphQLResolver|null
	 */
	private ?GraphQLResolver $resolver = null;

	/**
	 * Constructor.
	 *
	 * @param SchemaMapperInterface $schemaMapper Schema mapper
	 * @param IUserSession $userSession The session that says who is asking
	 * @param PropertyRbacHandler $propertyRbac Decides which properties that caller may read
	 */
	public function __construct(
		private readonly SchemaMapperInterface $schemaMapper,
		private readonly IUserSession $userSession,
		private readonly PropertyRbacHandler $propertyRbac,
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
	 * @spec openspec/specs/graphql-api/spec.md#requirement-cross-register-schema-stitching-must-provide-a-unified-graph
	 */
	public function generate(): Schema {
		$schemas = $this->loadSchemas();
		$this->objectTypes = new ObjectTypes(schemas: $schemas, caller: $this->caller());

		$entryFields = new SchemaEntryFields(typeMapper: $this->objectTypes->typeMapper(), resolver: $this->resolver);
		foreach ($schemas as $schema) {
			$entryFields->add(entry: $this->objectTypes->entryFor(schema: $schema));
		}

		$queryFields = $entryFields->queryFields();
		$queryFields['register'] = $this->registerField();

		// Types are auto-discovered from query/mutation fields. Do not pass explicit
		// setTypes(): it can cause duplicate type errors when allOf composition
		// creates overlapping type references.
		return new Schema(
			config: SchemaConfig::create()
				->setQuery(new ObjectType(['name' => 'Query', 'fields' => $queryFields]))
				->setMutation(new ObjectType(['name' => 'Mutation', 'fields' => $entryFields->mutationFields()]))
		);
	}//end generate()

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
		$this->objectTypes ??= new ObjectTypes(schemas: [], caller: $this->caller());

		return $this->objectTypes->forSchema(schema: $schema);
	}//end getObjectType()

	/**
	 * Who the generated schema is for.
	 *
	 * @return SchemaCaller The caller
	 *
	 * @spec openspec/changes/schema-shape-exposure/specs/rbac-scopes/spec.md
	 */
	private function caller(): SchemaCaller {
		return new SchemaCaller(session: $this->userSession, propertyRbac: $this->propertyRbac);
	}//end caller()

	/**
	 * Load all schemas of the graph.
	 *
	 * Bypasses RBAC and multi-tenancy for type generation: the GraphQL schema is
	 * system-scoped (per-request, APCu-cached), and per-user authorisation happens at
	 * resolve time on each query. Filtering schemas by the caller's organisation here
	 * produces a different GraphQL schema per org, which silently hides every cross-org
	 * schema from the type system even though those schemas are readable via REST under
	 * the same session. Same rationale as PermissionHandlerInterface::resolveSchemaSlug (#1454).
	 *
	 * @return array<int, RegisterSchema> The schemas
	 *
	 * @spec openspec/specs/graphql-api/spec.md#requirement-cross-register-schema-stitching-must-provide-a-unified-graph
	 */
	private function loadSchemas(): array {
		return $this->schemaMapper->findAll(_rbac: false, _multitenancy: false);
	}//end loadSchemas()

	/**
	 * The register-scoped query field.
	 *
	 * @return array<string, mixed> The field
	 *
	 * @spec openspec/specs/graphql-api/spec.md#requirement-cross-register-schema-stitching-must-provide-a-unified-graph
	 */
	private function registerField(): array {
		return [
			'type' => $this->objectTypes->jsonScalar(),
			'args' => ['id' => Type::nonNull(Type::id())],
			'resolve' => fn () => null,
			'description' => 'Query within a specific register scope',
		];
	}//end registerField()
}//end class
