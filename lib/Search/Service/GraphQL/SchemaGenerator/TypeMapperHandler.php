<?php

/**
 * Handles type mapping and input type generation for GraphQL schema generation.
 *
 * Extracted from SchemaGenerator to reduce class complexity.
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
 * @spec openspec/specs/graphql-api/spec.md#requirement-graphql-must-log-operations-to-the-audit-trail
 */

namespace OCA\OpenRegister\Search\Service\GraphQL\SchemaGenerator;

use GraphQL\Type\Definition\EnumType;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use OCA\OpenRegister\Db\Schema as RegisterSchema;
use OCA\OpenRegister\Service\PropertyRbacHandlerInterface;
use OCA\OpenRegister\Service\Rbac\RbacRuleChecks;

/**
 * Maps JSON Schema properties to GraphQL types and generates input types.
 *
 * Handles scalar type resolution, $ref lookups, and creation of filter/create/update
 * InputObjectType instances for mutations and list queries. Also manages shared
 * GraphQL types (PageInfo, AuditTrail, Sort, SelfFilter, Connection).
 *
 * Complexity is inherent to the type mapping logic which must handle many
 * JSON Schema type/format combinations and input type variants.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
class TypeMapperHandler {

	/**
	 * String formats with a custom output scalar.
	 *
	 * @var array<string, string>
	 */
	private const OUTPUT_FORMATS = [
		'date-time' => 'DateTime',
		'date' => 'DateTime',
		'uuid' => 'UUID',
		'email' => 'Email',
		'uri' => 'URI',
		'url' => 'URI',
	];

	/**
	 * String formats with a custom input scalar; `url` stays a String on input.
	 *
	 * @var array<string, string>
	 */
	private const INPUT_FORMATS = [
		'date-time' => 'DateTime',
		'date' => 'DateTime',
		'uuid' => 'UUID',
		'email' => 'Email',
		'uri' => 'URI',
	];

	/**
	 * Cached input types by schema ID and purpose.
	 *
	 * @var array<string, InputObjectType>
	 */
	private array $inputTypes = [];

	/**
	 * Cached connection types by schema ID.
	 *
	 * @var array<int, ObjectType>
	 */
	private array $connectionTypes = [];

	/**
	 * Custom scalar type instances.
	 *
	 * @var array<string, Type>
	 */
	private array $scalars = [];

	/**
	 * Shared PageInfo type.
	 *
	 * @var ObjectType|null
	 */
	private ?ObjectType $pageInfoType = null;

	/**
	 * Shared SortInput type.
	 *
	 * @var InputObjectType|null
	 */
	private ?InputObjectType $sortInputType = null;

	/**
	 * Shared SelfFilter input type.
	 *
	 * @var InputObjectType|null
	 */
	private ?InputObjectType $selfFilterType = null;

	/**
	 * Audit trail type.
	 *
	 * @var ObjectType|null
	 */
	private ?ObjectType $auditTrailType = null;

	/**
	 * The five shared aggregation types, built once each.
	 *
	 * @var AggregationTypes
	 */
	private AggregationTypes $aggregationTypes;

	/**
	 * Callback to resolve a $ref string to the ObjectType of the schema it names.
	 *
	 * @var callable(string): ?ObjectType
	 */
	private $refTypeResolver;

	/**
	 * Converts a slug to a GraphQL field name and a PascalCase type name.
	 *
	 * @var GraphQLNames
	 */
	private GraphQLNames $names;

	/**
	 * Constructor.
	 *
	 * @param array<string, Type>          $scalars         Custom scalar type instances
	 * @param callable                     $refTypeResolver Resolves a $ref to the ObjectType of its schema, or null
	 * @param GraphQLNames                 $names           Converts a slug to a GraphQL field or type name
	 * @param PropertyRbacHandlerInterface $propertyRbac    Withholds a property the caller may not read
	 */
	public function __construct(
		array $scalars,
		callable $refTypeResolver,
		GraphQLNames $names,
		private readonly PropertyRbacHandlerInterface $propertyRbac,
	) {
		$this->scalars = $scalars;
		$this->refTypeResolver = $refTypeResolver;
		$this->names = $names;
		$this->aggregationTypes = new AggregationTypes();
		$this->aggregationTypes->setScalars(scalars: $scalars);

	}//end __construct()

	/**
	 * Update scalar type instances (called after initScalars).
	 *
	 * @param array<string, Type> $scalars The custom scalar types
	 *
	 * @return void
	 *
	 * @spec openspec/specs/graphql-api/spec.md#requirement-graphql-resolver-must-reset-state-between-requests
	 */
	public function setScalars(array $scalars): void {
		$this->scalars = $scalars;
		$this->aggregationTypes->setScalars(scalars: $scalars);

	}//end setScalars()

	/**
	 * Map a JSON Schema property to a GraphQL type.
	 *
	 * @param array<string, mixed> $property The property definition
	 *
	 * @return Type The GraphQL type
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 */
	public function mapPropertyToGraphQLType(array $property): Type {
		$type = ($property['type'] ?? 'string');
		$format = ($property['format'] ?? null);

		// Handle object references.
		if ($type === 'object' && isset($property['$ref']) === true) {
			$refType = ($this->refTypeResolver)($property['$ref']);
			if ($refType !== null) {
				return $refType;
			}

			return $this->scalars['JSON'];
		}

		// Handle arrays of references.
		if ($type === 'array' && isset($property['items']) === true) {
			$itemType = $this->mapPropertyToGraphQLType(property: $property['items']);
			return Type::listOf($itemType);
		}

		// Map by type and format.
		if ($type === 'object') {
			return $this->scalars['JSON'];
		}

		if ($type === 'array') {
			return Type::listOf(Type::string());
		}

		return $this->primitiveType(type: $type, format: $format, formats: self::OUTPUT_FORMATS);

	}//end mapPropertyToGraphQLType()

	/**
	 * Map a JSON Schema property to a GraphQL input type.
	 *
	 * Object references become ID inputs instead of full objects.
	 *
	 * @param array<string, mixed> $property The property definition
	 *
	 * @return Type The GraphQL input type
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 */
	public function mapPropertyToInputType(array $property): Type {
		$type = ($property['type'] ?? 'string');
		$format = ($property['format'] ?? null);

		// Object references accept IDs.
		if ($type === 'object' && isset($property['$ref']) === true) {
			return Type::id();
		}

		// Arrays of references accept lists of IDs.
		if ($type === 'array' && isset($property['items']['$ref']) === true) {
			return Type::listOf(Type::id());
		}

		if ($type === 'array') {
			return $this->scalars['JSON'];
		}

		if ($type === 'object') {
			return $this->scalars['JSON'];
		}

		return $this->primitiveType(type: $type, format: $format, formats: self::INPUT_FORMATS);

	}//end mapPropertyToInputType()

	/**
	 * The GraphQL type of a scalar JSON Schema type: a string whose format
	 * is listed maps to that custom scalar, the other primitives to their
	 * built-in type, anything else to String.
	 *
	 * @param mixed                 $type    The JSON Schema type.
	 * @param mixed                 $format  The JSON Schema format.
	 * @param array<string, string> $formats Format => custom scalar name.
	 *
	 * @return Type The GraphQL type
	 */
	private function primitiveType(mixed $type, mixed $format, array $formats): Type {
		if ($type === 'string' && is_string($format) === true && isset($formats[$format]) === true) {
			return $this->scalars[$formats[$format]];
		}

		return match ($type) {
			'integer' => Type::int(),
			'number' => Type::float(),
			'boolean' => Type::boolean(),
			default => Type::string(),
		};
	}//end primitiveType()

	/**
	 * Get a filter input type for a schema.
	 *
	 * @param RegisterSchema $schema The register schema
	 *
	 * @return InputObjectType The filter input type
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	public function getFilterInputType(RegisterSchema $schema): InputObjectType {
		$key = 'filter_' . $schema->getId();

		if (isset($this->inputTypes[$key]) === true) {
			return $this->inputTypes[$key];
		}

		$filterSlug = $schema->getSlug();
		if ($filterSlug === null || $filterSlug === '') {
			$filterSlug = 'Schema' . $schema->getId();
		}

		$typeName = $this->names->toTypeName($filterSlug, $schema->getId());
		$fields = $this->filterFieldsFor(schema: $schema);

		if (empty($fields) === true) {
			$fields['_empty'] = [
				'type' => Type::boolean(),
				'description' => 'Placeholder for schemas with no filterable properties',
			];
		}

		$inputType = new InputObjectType(
			[
				'name' => $typeName . 'Filter',
				'fields' => $fields,
			]
		);

		$this->inputTypes[$key] = $inputType;
		return $inputType;
	}//end getFilterInputType()

	/**
	 * The filterable fields of one schema, keyed by GraphQL field name.
	 *
	 * A GraphQL type IS a description of the shape, and a field name is
	 * information. A governed property named here can be introspected by anyone
	 * who can reach the endpoint, and the governed names are the ones worth
	 * protecting: a property carries an authorization block or a scope
	 * precisely because it is sensitive.
	 *
	 * @param RegisterSchema $schema The register schema
	 *
	 * @return array<string, mixed> The fields
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	private function filterFieldsFor(RegisterSchema $schema): array {
		$fields = [];
		foreach ($this->describedProperties(schema: $schema) as $fieldName => $property) {
			// Each filter field accepts the base type or a comparison object.
			// Simple types use the base type; complex types use JSON.
			$baseType = $this->mapPropertyToGraphQLType(property: $property);
			$fields[$fieldName] = $baseType;
			if ($baseType instanceof ObjectType || $baseType instanceof \GraphQL\Type\Definition\ListOfType) {
				$fields[$fieldName] = $this->scalars['JSON'];
			}
		}

		return $fields;
	}//end filterFieldsFor()

	/**
	 * Get a create input type for a schema.
	 *
	 * @param RegisterSchema $schema The register schema
	 *
	 * @return InputObjectType The create input type
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 */
	public function getCreateInputType(RegisterSchema $schema): InputObjectType {
		$key = 'create_' . $schema->getId();

		if (isset($this->inputTypes[$key]) === true) {
			return $this->inputTypes[$key];
		}

		$createSlug = $schema->getSlug();
		if ($createSlug === null || $createSlug === '') {
			$createSlug = 'Schema' . $schema->getId();
		}

		$typeName = $this->names->toTypeName($createSlug, $schema->getId());
		$fields = $this->buildInputFields(schema: $schema);
		$required = $schema->getRequired() ?? [];

		// Mark required fields (sanitize names to match field keys).
		foreach ($required as $reqField) {
			$reqField = $this->names->toFieldName($reqField);
			if (isset($fields[$reqField]) === true) {
				$fieldType = $fields[$reqField];
				if ($fieldType instanceof Type) {
					$fields[$reqField] = Type::nonNull($fieldType);
				} elseif (is_array(value: $fieldType) === true && isset($fieldType['type']) === true) {
					$fieldType['type'] = Type::nonNull($fieldType['type']);
					$fields[$reqField] = $fieldType;
				}
			}
		}

		if (empty($fields) === true) {
			$fields['_placeholder'] = Type::string();
		}

		$inputType = new InputObjectType(
			[
				'name' => 'Create' . $typeName . 'Input',
				'fields' => $fields,
			]
		);

		$this->inputTypes[$key] = $inputType;
		return $inputType;
	}//end getCreateInputType()

	/**
	 * Get an update input type for a schema.
	 *
	 * @param RegisterSchema $schema The register schema
	 *
	 * @return InputObjectType The update input type
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	public function getUpdateInputType(RegisterSchema $schema): InputObjectType {
		$key = 'update_' . $schema->getId();

		if (isset($this->inputTypes[$key]) === true) {
			return $this->inputTypes[$key];
		}

		$updateSlug = $schema->getSlug();
		if ($updateSlug === null || $updateSlug === '') {
			$updateSlug = 'Schema' . $schema->getId();
		}

		$typeName = $this->names->toTypeName($updateSlug, $schema->getId());
		$fields = $this->buildInputFields(schema: $schema);

		if (empty($fields) === true) {
			$fields['_placeholder'] = Type::string();
		}

		$inputType = new InputObjectType(
			[
				'name' => 'Update' . $typeName . 'Input',
				'fields' => $fields,
			]
		);

		$this->inputTypes[$key] = $inputType;
		return $inputType;
	}//end getUpdateInputType()

	/**
	 * Build input fields from schema properties.
	 *
	 * @param RegisterSchema $schema The register schema
	 *
	 * @return array<string, Type|array<string,mixed>> The input fields
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	private function buildInputFields(RegisterSchema $schema): array {
		$fields = [];
		foreach ($this->describedProperties(schema: $schema) as $fieldName => $property) {
			$fields[$fieldName] = $this->mapPropertyToInputType(property: $property);
		}

		return $fields;
	}//end buildInputFields()

	/**
	 * The schema's array-shaped properties this caller may see described,
	 * keyed by GraphQL field name.
	 *
	 * A GraphQL type IS a description of the shape, and a field name is
	 * information. A governed property named here can be introspected by
	 * anyone who can reach the endpoint, and the governed names are the
	 * ones worth protecting: a property carries an authorization block
	 * or a scope precisely because it is sensitive.
	 *
	 * @param RegisterSchema $schema The register schema
	 *
	 * @return array<string, array<string, mixed>> Field name => property definition.
	 */
	private function describedProperties(RegisterSchema $schema): array {
		$described  = [];
		$properties = $schema->getProperties() ?? [];
		foreach ($properties as $name => $property) {
			if (is_array(value: $property) === false) {
				continue;
			}

			if ($this->mayDescribe(schema: $schema, property: (string)$name) === false) {
				continue;
			}

			$described[$this->names->toFieldName($name)] = $property;
		}

		return $described;
	}//end describedProperties()

	/**
	 * Get the Relay connection type for a schema.
	 *
	 * @param RegisterSchema $schema The register schema
	 * @param ObjectType $objectType The object type for the schema
	 *
	 * @return ObjectType The connection type
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	public function getConnectionType(RegisterSchema $schema, ObjectType $objectType): ObjectType {
		$schemaId = $schema->getId();

		if (isset($this->connectionTypes[$schemaId]) === true) {
			return $this->connectionTypes[$schemaId];
		}

		$typeName = $objectType->name;

		$edgeType = new ObjectType(
			[
				'name' => $typeName . 'Edge',
				'fields' => [
					'cursor' => Type::nonNull(Type::string()),
					'node' => Type::nonNull($objectType),
					'_relevance' => [
						'type' => Type::float(),
						'description' => 'Fuzzy search relevance score (0-100)',
					],
				],
			]
		);

		$connectionType = new ObjectType(
			[
				'name' => $typeName . 'Connection',
				'fields' => [
					'edges' => Type::nonNull(Type::listOf(Type::nonNull($edgeType))),
					'pageInfo' => Type::nonNull($this->getPageInfoType()),
					'totalCount' => Type::nonNull(Type::int()),
					'facets' => $this->scalars['JSON'],
					'facetable' => Type::listOf(Type::string()),
					'groups' => [
						'type' => Type::listOf(Type::nonNull($this->aggregationTypes->getGroupBucketType())),
						'description' => 'Ad-hoc bucket aggregation result; null unless `groupBy` was supplied.',
					],
					// JSON rather than a typed shape, deliberately.
					//
					// A declared aggregation's envelope varies with what it
					// declares: a scalar `value`, a `values` map for `metrics`,
					// `groups[].keys` for a composite groupBy, `joined` when it
					// joins. Typing that today would either flatten it — the
					// mistake GroupBucket already makes, where `value: Float!`
					// cannot carry a values map and a null key coerces to "" —
					// or invent a union per declaration shape.
					//
					// JSON keeps the envelope identical to the REST one, which
					// every existing consumer already parses. A typed
					// AggregationResult is the right next step once a consumer
					// needs introspection over it.
					'aggregation' => [
						'type' => $this->scalars['JSON'],
						'description' => 'Declared-aggregation result envelope; null unless `aggregation` was supplied.',
					],
				],
			]
		);

		$this->connectionTypes[$schemaId] = $connectionType;
		return $connectionType;
	}//end getConnectionType()

	/**
	 * Get the shared PageInfo type.
	 *
	 * @return ObjectType The PageInfo type
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	private function getPageInfoType(): ObjectType {
		if ($this->pageInfoType !== null) {
			return $this->pageInfoType;
		}

		$this->pageInfoType = new ObjectType(
			[
				'name' => 'PageInfo',
				'fields' => [
					'hasNextPage' => Type::nonNull(Type::boolean()),
					'hasPreviousPage' => Type::nonNull(Type::boolean()),
					'startCursor' => Type::string(),
					'endCursor' => Type::string(),
				],
			]
		);

		return $this->pageInfoType;
	}//end getPageInfoType()

	/**
	 * Get the shared AuditTrail type.
	 *
	 * @return ObjectType The AuditTrail type
	 *
	 * @spec openspec/specs/graphql-api/spec.md#requirement-graphql-must-log-operations-to-the-audit-trail
	 */
	public function getAuditTrailType(): ObjectType {
		if ($this->auditTrailType !== null) {
			return $this->auditTrailType;
		}

		$this->auditTrailType = new ObjectType(
			[
				'name' => 'AuditTrailEntry',
				'fields' => [
					'action' => Type::string(),
					'user' => Type::string(),
					'userName' => Type::string(),
					'changed' => $this->scalars['JSON'],
					'created' => $this->scalars['DateTime'],
					'ipAddress' => Type::string(),
					'processingActivityId' => Type::string(),
					'confidentiality' => Type::string(),
					'retentionPeriod' => Type::string(),
				],
			]
		);

		return $this->auditTrailType;
	}//end getAuditTrailType()

	/**
	 * Get the list query arguments for a schema.
	 *
	 * @param RegisterSchema $schema The register schema
	 *
	 * @return array<string, array<string, mixed>> The argument definitions
	 *
	 * @spec openspec/specs/graphql-api/spec.md#requirement-graphql-resolver-must-reset-state-between-requests
	 */
	public function getListArgs(RegisterSchema $schema): array {
		return [
			'filter' => [
				'type' => $this->getFilterInputType(schema: $schema),
				'description' => 'Filter criteria',
			],
			'sort' => ['type' => $this->getSortInputType(), 'description' => 'Sort configuration'],
			'selfFilter' => ['type' => $this->getSelfFilterType(), 'description' => 'Metadata filter (@self)'],
			'search' => ['type' => Type::string(), 'description' => 'Full-text search query'],
			'fuzzy' => ['type' => Type::boolean(), 'defaultValue' => false, 'description' => 'Enable fuzzy matching'],
			'facets' => ['type' => Type::listOf(Type::string()), 'description' => 'Fields to calculate facets for'],
			'first' => ['type' => Type::int(), 'defaultValue' => 20, 'description' => 'Number of items to return'],
			'offset' => ['type' => Type::int(), 'description' => 'Offset for pagination'],
			'after' => ['type' => Type::string(), 'description' => 'Cursor for forward pagination'],
			'groupBy' => [
				'type' => $this->aggregationTypes->getGroupByInputType(),
				'description' => 'Optional ad-hoc aggregation; when supplied, the connection emits a `groups` field.',
			],
			// A DECLARED aggregation, by name.
			//
			// `groupBy` above is ad-hoc: the caller describes the aggregation.
			// This one names one the SCHEMA declares in
			// `x-openregister-aggregations`, which until now was reachable only
			// over REST — so a page wanting a declared figure had to hand-build
			// a URL alongside its GraphQL query.
			//
			// The name is the whole input: the declaration already carries the
			// metric, grouping, filter and join, and has been validated at save
			// time. The query's own `filter` is applied on top as a NARROWING
			// constraint, which the engine accepts but can never use to relax
			// what the declaration pins.
			'aggregation' => [
				'type' => Type::string(),
				'description' => 'Name of a declared aggregation on this schema '
					. '(x-openregister-aggregations); emits an `aggregation` field on the connection.',
			],
		];

	}//end getListArgs()

	/**
	 * Get the shared sort input type.
	 *
	 * @return InputObjectType The sort input type
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	private function getSortInputType(): InputObjectType {
		if ($this->sortInputType !== null) {
			return $this->sortInputType;
		}

		$this->sortInputType = new InputObjectType(
			[
				'name' => 'SortInput',
				'fields' => [
					'field' => Type::nonNull(Type::string()),
					'order' => [
						'type' => Type::string(),
						'defaultValue' => 'ASC',
						'description' => 'ASC or DESC',
					],
				],
			]
		);

		return $this->sortInputType;
	}//end getSortInputType()

	/**
	 * Get the shared self-filter input type for metadata columns.
	 *
	 * @return InputObjectType The self-filter input type
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	private function getSelfFilterType(): InputObjectType {
		if ($this->selfFilterType !== null) {
			return $this->selfFilterType;
		}

		$this->selfFilterType = new InputObjectType(
			[
				'name' => 'SelfFilter',
				'fields' => [
					'owner' => Type::string(),
					'organisation' => Type::string(),
					'register' => Type::int(),
					'schema' => Type::int(),
					'uuid' => $this->scalars['UUID'],
				],
			]
		);

		return $this->selfFilterType;
	}//end getSelfFilterType()

	/**
	 * Get property-level authorization descriptions for annotation.
	 *
	 * @param RegisterSchema $schema The register schema
	 *
	 * @return array<string, string> Map of property name to auth description
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 */
	public function getPropertyAuthDescriptions(RegisterSchema $schema): array {
		if ($this->propertyRbac->isAdmin() === false) {
			return [];
		}

		$result = [];
		// Schema declares getPropertiesWithAuthorization(), so the early return
		// that used to guard this call was unreachable.
		$authProps = $schema->getPropertiesWithAuthorization();
		foreach ($authProps as $propName => $authConfig) {
			$groups = [];
			foreach (['read', 'update'] as $action) {
				if (isset($authConfig[$action]) === false) {
					continue;
				}

				foreach ($authConfig[$action] as $rule) {
					if (is_string(value: $rule) === true) {
						$groups[] = $rule;
					} elseif (is_array(value: $rule) === true && isset($rule['group']) === true) {
						$groups[] = $rule['group'];
					}
				}
			}

			if (empty($groups) === false) {
				$result[$propName] = 'Requires group: ' . implode(
					separator: ', ',
					array: array_unique(array: $groups)
				);
			}
		}//end foreach

		return $result;
	}//end getPropertyAuthDescriptions()
	/**
	 * Whether the object type of this caller carries a field for a property.
	 *
	 * @param RegisterSchema $schema   The schema.
	 * @param string         $property The property name.
	 *
	 * @return bool Whether the field stays.
	 *
	 * @spec openspec/changes/schema-shape-exposure/specs/rbac-scopes/spec.md
	 */
	public function mayCarryField(RegisterSchema $schema, string $property): bool {
		return RbacRuleChecks::aggregateVisibility(rbac: $this->propertyRbac)->mayReadOnSomeRow(
			schema: $schema,
			property: $property
		);
	}//end mayCarryField()

	/**
	 * Whether this caller may be told that a property exists.
	 *
	 * Asks the same `AggregateVisibility` the OpenAPI description asks, which in
	 * turn asks `PropertyRbacHandler`. One answer to "may this person see this
	 * field", asked in more places; this class holds no rule of its own.
	 *
	 * @param RegisterSchema $schema   The schema.
	 * @param string         $property The property name.
	 *
	 * @return bool Whether it may be described.
	 *
	 * @spec openspec/changes/schema-shape-exposure/specs/rbac-scopes/spec.md
	 */
	private function mayDescribe(RegisterSchema $schema, string $property): bool {
		return RbacRuleChecks::aggregateVisibility(rbac: $this->propertyRbac)->maySummarise(
			schema: $schema,
			property: $property
		);
	}//end mayDescribe()

}//end class
