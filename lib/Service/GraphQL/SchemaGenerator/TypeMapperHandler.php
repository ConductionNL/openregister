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
 * @package   OCA\OpenRegister\Service\GraphQL\SchemaGenerator
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 *
 * @spec openspec/specs/graphql-api/spec.md#requirement-graphql-must-log-operations-to-the-audit-trail
 */

namespace OCA\OpenRegister\Service\GraphQL\SchemaGenerator;

use GraphQL\Type\Definition\EnumType;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use OCA\OpenRegister\Db\Schema as RegisterSchema;

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
	 * Shared GroupByInput input type. Backs the optional `groupBy`
	 * argument on every auto-generated list query. See the
	 * `add-time-bucket-aggregation` change for the spec contract.
	 *
	 * @var InputObjectType|null
	 */
	private ?InputObjectType $groupByInputType = null;

	/**
	 * Shared TimeInterval enum (MINUTE..YEAR). Used inside GroupByInput.
	 *
	 * @var EnumType|null
	 */
	private ?EnumType $timeIntervalType = null;

	/**
	 * Shared AggregationMetric enum (COUNT|SUM|AVG|MIN|MAX). Used
	 * inside GroupByInput.
	 *
	 * @var EnumType|null
	 */
	private ?EnumType $aggMetricType = null;

	/**
	 * Shared GroupBucket object type. Element shape of the `groups`
	 * field on every Connection.
	 *
	 * @var ObjectType|null
	 */
	private ?ObjectType $groupBucketType = null;

	/**
	 * Callback to resolve a $ref string to a RegisterSchema.
	 *
	 * @var callable(string): ?RegisterSchema
	 */
	private $refResolver;

	/**
	 * Callback to get/create an ObjectType for a schema.
	 *
	 * @var callable(RegisterSchema): ObjectType
	 */
	private $objectTypeFactory;

	/**
	 * Callback to convert a slug to a valid GraphQL field name.
	 *
	 * @var callable(string): string
	 */
	private $fieldNameConverter;

	/**
	 * Callback to convert a slug to a PascalCase type name.
	 *
	 * @var callable(string, ?int): string
	 */
	private $typeNameConverter;

	/**
	 * Constructor.
	 *
	 * @param array<string, Type> $scalars Custom scalar type instances
	 * @param callable $refResolver Resolves a $ref to a RegisterSchema
	 * @param callable $objectTypeFactory Gets/creates an ObjectType for a schema
	 * @param callable $fieldNameConverter Converts a slug to a GraphQL field name
	 * @param callable $typeNameConverter Converts a slug to a PascalCase type name
	 */
	public function __construct(
		array $scalars,
		callable $refResolver,
		callable $objectTypeFactory,
		callable $fieldNameConverter,
		callable $typeNameConverter,
	) {
		$this->scalars = $scalars;
		$this->refResolver = $refResolver;
		$this->objectTypeFactory = $objectTypeFactory;
		$this->fieldNameConverter = $fieldNameConverter;
		$this->typeNameConverter = $typeNameConverter;

	}//end __construct()

	/**
	 * Reset cached input types (called when regenerating schema).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/graphql-api/spec.md#requirement-graphql-resolver-must-reset-state-between-requests
	 */
	public function resetCache(): void {
		$this->inputTypes = [];
		$this->connectionTypes = [];
		$this->pageInfoType = null;
		$this->sortInputType = null;
		$this->selfFilterType = null;
		$this->auditTrailType = null;

	}//end resetCache()

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
			$refSchema = ($this->refResolver)($property['$ref']);
			if ($refSchema !== null) {
				return ($this->objectTypeFactory)($refSchema);
			}

			return $this->scalars['JSON'];
		}

		// Handle arrays of references.
		if ($type === 'array' && isset($property['items']) === true) {
			$itemType = $this->mapPropertyToGraphQLType(property: $property['items']);
			return Type::listOf($itemType);
		}

		// Map by type and format.
		return match (true) {
			$type === 'string' && $format === 'date-time' => $this->scalars['DateTime'],
			$type === 'string' && $format === 'date' => $this->scalars['DateTime'],
			$type === 'string' && $format === 'uuid' => $this->scalars['UUID'],
			$type === 'string' && $format === 'email' => $this->scalars['Email'],
			$type === 'string' && $format === 'uri' => $this->scalars['URI'],
			$type === 'string' && $format === 'url' => $this->scalars['URI'],
			$type === 'string' => Type::string(),
			$type === 'integer' => Type::int(),
			$type === 'number' => Type::float(),
			$type === 'boolean' => Type::boolean(),
			$type === 'object' => $this->scalars['JSON'],
			$type === 'array' => Type::listOf(Type::string()),
			default => Type::string(),
		};

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

		return match (true) {
			$type === 'string' && $format === 'date-time' => $this->scalars['DateTime'],
			$type === 'string' && $format === 'date' => $this->scalars['DateTime'],
			$type === 'string' && $format === 'uuid' => $this->scalars['UUID'],
			$type === 'string' && $format === 'email' => $this->scalars['Email'],
			$type === 'string' && $format === 'uri' => $this->scalars['URI'],
			$type === 'string' => Type::string(),
			$type === 'integer' => Type::int(),
			$type === 'number' => Type::float(),
			$type === 'boolean' => Type::boolean(),
			default => Type::string(),
		};

	}//end mapPropertyToInputType()

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

		$typeName = ($this->typeNameConverter)($filterSlug, $schema->getId());
		$fields = [];

		$properties = $schema->getProperties() ?? [];
		foreach ($properties as $name => $property) {
			if (is_array(value: $property) === false) {
				continue;
			}

			$fieldName = ($this->fieldNameConverter)($name);

			// Each filter field accepts the base type or a comparison object.
			$baseType = $this->mapPropertyToGraphQLType(property: $property);
			// Simple types use the base type; complex types use JSON for filtering.
			$fields[$fieldName] = $baseType;
			if ($baseType instanceof ObjectType || $baseType instanceof \GraphQL\Type\Definition\ListOfType) {
				$fields[$fieldName] = $this->scalars['JSON'];
			}
		}

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

		$typeName = ($this->typeNameConverter)($createSlug, $schema->getId());
		$fields = $this->buildInputFields(schema: $schema);
		$required = $schema->getRequired() ?? [];

		// Mark required fields (sanitize names to match field keys).
		foreach ($required as $reqField) {
			$reqField = ($this->fieldNameConverter)($reqField);
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

		$typeName = ($this->typeNameConverter)($updateSlug, $schema->getId());
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
		$properties = $schema->getProperties() ?? [];

		foreach ($properties as $name => $property) {
			if (is_array(value: $property) === false) {
				continue;
			}

			$fieldName = ($this->fieldNameConverter)($name);
			$type = $this->mapPropertyToInputType(property: $property);
			$fields[$fieldName] = $type;
		}

		return $fields;
	}//end buildInputFields()

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
						'type' => Type::listOf(Type::nonNull($this->getGroupBucketType())),
						'description' => 'Ad-hoc bucket aggregation result; null unless `groupBy` was supplied.',
					],
				],
			]
		);

		$this->connectionTypes[$schemaId] = $connectionType;
		return $connectionType;
	}//end getConnectionType()

	/**
	 * Get (or lazily build) the shared GroupBucket object type.
	 *
	 * @return ObjectType The GroupBucket type.
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	public function getGroupBucketType(): ObjectType {
		if ($this->groupBucketType !== null) {
			return $this->groupBucketType;
		}

		$this->groupBucketType = new ObjectType(
			[
				'name' => 'GroupBucket',
				'description' => 'A single bucket in an ad-hoc aggregation result.',
				'fields' => [
					'key' => Type::nonNull(Type::string()),
					'value' => Type::nonNull(Type::float()),
				],
			]
		);

		return $this->groupBucketType;
	}//end getGroupBucketType()

	/**
	 * Get (or lazily build) the shared TimeInterval enum.
	 *
	 * @return EnumType The TimeInterval enum.
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	public function getTimeIntervalType(): EnumType {
		if ($this->timeIntervalType !== null) {
			return $this->timeIntervalType;
		}

		$this->timeIntervalType = new EnumType(
			[
				'name' => 'TimeInterval',
				'description' => 'Bucketing interval for ad-hoc time-bucket aggregations.',
				'values' => [
					'MINUTE' => ['value' => 'MINUTE'],
					'HOUR' => ['value' => 'HOUR'],
					'DAY' => ['value' => 'DAY'],
					'WEEK' => ['value' => 'WEEK'],
					'MONTH' => ['value' => 'MONTH'],
					'QUARTER' => ['value' => 'QUARTER'],
					'YEAR' => ['value' => 'YEAR'],
				],
			]
		);

		return $this->timeIntervalType;
	}//end getTimeIntervalType()

	/**
	 * Get (or lazily build) the shared AggregationMetric enum.
	 *
	 * @return EnumType The AggregationMetric enum.
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	public function getAggregationMetricType(): EnumType {
		if ($this->aggMetricType !== null) {
			return $this->aggMetricType;
		}

		$this->aggMetricType = new EnumType(
			[
				'name' => 'AggregationMetric',
				'description' => 'Metric for ad-hoc aggregations.',
				'values' => [
					'COUNT' => ['value' => 'COUNT'],
					'SUM' => ['value' => 'SUM'],
					'AVG' => ['value' => 'AVG'],
					'MIN' => ['value' => 'MIN'],
					'MAX' => ['value' => 'MAX'],
				],
			]
		);

		return $this->aggMetricType;
	}//end getAggregationMetricType()

	/**
	 * Get (or lazily build) the shared GroupByInput input type.
	 *
	 * @return InputObjectType The GroupByInput type.
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	public function getGroupByInputType(): InputObjectType {
		if ($this->groupByInputType !== null) {
			return $this->groupByInputType;
		}

		$this->groupByInputType = new InputObjectType(
			[
				'name' => 'GroupByInput',
				'description' => 'Ad-hoc aggregation arg; `interval` set => time-bucketed, otherwise categorical groupBy.',
				'fields' => [
					'field' => [
						'type' => Type::nonNull(Type::string()),
						'description' => 'Field to group on. Must be a declared schema property or magic metadata column.',
					],
					'interval' => [
						'type' => $this->getTimeIntervalType(),
						'description' => 'Optional bucketing interval. When supplied, requires `from` + `to`.',
					],
					'from' => [
						'type' => Type::string(),
						'description' => 'ISO-8601 lower bound, inclusive. Required when `interval` is set.',
					],
					'to' => [
						'type' => Type::string(),
						'description' => 'ISO-8601 upper bound, exclusive. Required when `interval` is set.',
					],
					'metric' => [
						'type' => $this->getAggregationMetricType(),
						'defaultValue' => 'COUNT',
						'description' => 'Aggregation metric. Default COUNT.',
					],
					'metricField' => [
						'type' => Type::string(),
						'description' => 'Field to aggregate over. Required when metric != COUNT.',
					],
				],
			]
		);

		return $this->groupByInputType;
	}//end getGroupByInputType()

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
				'type' => $this->getGroupByInputType(),
				'description' => 'Optional ad-hoc aggregation; when supplied, the connection emits a `groups` field.',
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
}//end class
