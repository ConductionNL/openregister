<?php

/**
 * The object types of one generated GraphQL schema.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Search\Service\GraphQL\SchemaGenerator
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 *
 * @spec openspec/specs/graphql-api/spec.md
 */

namespace OCA\OpenRegister\Search\Service\GraphQL\SchemaGenerator;

use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use OCA\OpenRegister\Db\Schema as RegisterSchema;

/**
 * Builds and caches the object type of each schema, and resolves references between them.
 */
class ObjectTypes {

	/**
	 * Cached object types by schema ID.
	 *
	 * @var array<int, ObjectType>
	 */
	private array $objectTypes = [];

	/**
	 * Loaded schemas indexed by ID.
	 *
	 * @var array<int, RegisterSchema>
	 */
	private array $schemasById = [];

	/**
	 * Custom scalar type instances.
	 *
	 * @var array<string, Type>
	 */
	private array $scalars;

	/**
	 * Names of this set of types.
	 *
	 * @var GraphQLNames
	 */
	private GraphQLNames $names;

	/**
	 * Handler for type mapping and input type generation.
	 *
	 * @var TypeMapperHandler
	 */
	private TypeMapperHandler $typeMapper;

	/**
	 * Handler for allOf/oneOf/anyOf composition logic.
	 *
	 * @var CompositionHandler
	 */
	private CompositionHandler $compositionHandler;

	/**
	 * Builds the fields of schema properties.
	 *
	 * @var ObjectPropertyFields
	 */
	private ObjectPropertyFields $propertyFields;

	/**
	 * Builds the metadata fields.
	 *
	 * @var ObjectMetadataFields
	 */
	private ObjectMetadataFields $metadataFields;

	/**
	 * Constructor.
	 *
	 * @param array<int, RegisterSchema> $schemas The schemas of the graph
	 * @param SchemaCaller $caller Who the schema is generated for
	 */
	public function __construct(array $schemas, private readonly SchemaCaller $caller) {
		foreach ($schemas as $schema) {
			$this->schemasById[$schema->getId()] = $schema;
		}

		$this->scalars = (new GraphQLScalars())->all();
		$this->names = new GraphQLNames();
		$this->metadataFields = new ObjectMetadataFields(scalars: $this->scalars, userSession: $this->caller->session);
		$this->typeMapper = $this->newTypeMapper();
		$this->compositionHandler = $this->newCompositionHandler();
		$this->propertyFields = new ObjectPropertyFields(typeMapper: $this->typeMapper, names: $this->names);
	}//end __construct()

	/**
	 * Get or create the object type of a register schema.
	 *
	 * @param RegisterSchema $schema The register schema
	 *
	 * @return ObjectType The GraphQL object type
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	public function forSchema(RegisterSchema $schema): ObjectType {
		$schemaId = $schema->getId();

		if (isset($this->objectTypes[$schemaId]) === false) {
			$this->objectTypes[$schemaId] = new ObjectType(
				[
					'name' => $this->objectTypeName(schema: $schema),
					'description' => $this->objectDescription(schema: $schema),
					// Lazy, so circular references between schemas can resolve.
					'fields' => fn () => $this->fieldsOf(schema: $schema),
				]
			);
		}

		return $this->objectTypes[$schemaId];
	}//end forSchema()

	/**
	 * Name a schema's root fields and give it its object type.
	 *
	 * @param RegisterSchema $schema The register schema
	 *
	 * @return SchemaEntry The schema as the root fields use it
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	public function entryFor(RegisterSchema $schema): SchemaEntry {
		$schemaSlug = $schema->getSlug();
		if ($schemaSlug === null || $schemaSlug === '') {
			$schemaSlug = 'schema_' . $schema->getId();
		}

		return new SchemaEntry(
			schema: $schema,
			singular: $this->names->toFieldName(slug: $this->names->singularize(plural: $schemaSlug)),
			plural: $this->names->toFieldName(slug: $schemaSlug),
			objectType: $this->forSchema(schema: $schema),
		);
	}//end entryFor()

	/**
	 * The handler for type mapping and input types.
	 *
	 * @return TypeMapperHandler The handler
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	public function typeMapper(): TypeMapperHandler {
		return $this->typeMapper;
	}//end typeMapper()

	/**
	 * The JSON scalar.
	 *
	 * @return Type The scalar
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	public function jsonScalar(): Type {
		return $this->scalars['JSON'];
	}//end jsonScalar()

	/**
	 * Create the type mapper on this set of types.
	 *
	 * @return TypeMapperHandler The handler
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	private function newTypeMapper(): TypeMapperHandler {
		return new TypeMapperHandler(
			scalars: $this->scalars,
			refResolver: $this->resolveRef(...),
			objectTypeFactory: $this->forSchema(...),
			fieldNameConverter: $this->names->toFieldName(...),
			typeNameConverter: $this->names->toTypeName(...),
			propertyRbac: $this->caller->propertyRbac,
		);
	}//end newTypeMapper()

	/**
	 * Create the allOf/oneOf/anyOf handler on this set of types.
	 *
	 * @return CompositionHandler The handler
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	private function newCompositionHandler(): CompositionHandler {
		return new CompositionHandler(
			refResolver: $this->resolveRef(...),
			objectTypeFactory: $this->forSchema(...),
			fieldBuilder: $this->fieldsOf(...),
			typeNameConverter: $this->names->toTypeName(...),
		);
	}//end newCompositionHandler()

	/**
	 * Build the fields of an object type: metadata, properties, composition, reverse relations.
	 *
	 * @param RegisterSchema $schema The register schema
	 *
	 * @return array<string, array<string, mixed>> The field configuration
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	private function fieldsOf(RegisterSchema $schema): array {
		// Union, not merge: a property whose name maps onto a metadata field must not replace it.
		$fields = $this->metadataFields->leading(auditTrailType: $this->typeMapper->getAuditTrailType())
			+ $this->propertyFields->forSchema(schema: $schema);

		$this->compositionHandler->applyComposition(schema: $schema, fields: $fields);

		$fields['_usedBy'] = $this->metadataFields->usedBy();

		return $fields;
	}//end fieldsOf()

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
}//end class
