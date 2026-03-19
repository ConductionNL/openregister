<?php

/**
 * GraphQL schema generator for OpenRegister.
 *
 * Generates a GraphQL schema from OpenRegister register/schema definitions,
 * mapping JSON Schema properties to GraphQL types with queries, mutations,
 * filters, and connection types.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\GraphQL
 * @author   Conduction B.V. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://OpenRegister.app
 */

namespace OCA\OpenRegister\Service\GraphQL;

use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\UnionType;
use GraphQL\Type\Schema;
use GraphQL\Type\SchemaConfig;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema as RegisterSchema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\GraphQL\Scalar\DateTimeType;
use OCA\OpenRegister\Service\GraphQL\Scalar\EmailType;
use OCA\OpenRegister\Service\GraphQL\Scalar\JsonType;
use OCA\OpenRegister\Service\GraphQL\Scalar\UriType;
use OCA\OpenRegister\Service\GraphQL\Scalar\UuidType;

/**
 * Generates a GraphQL schema from OpenRegister register/schema definitions.
 *
 * Reads all registers and schemas, maps JSON Schema properties to GraphQL types,
 * and produces a complete executable schema with queries, mutations, filters,
 * and connection types.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.ElseExpression) Schema type generation uses else for object vs scalar field handling
 */
class SchemaGenerator
{

    /**
     * Cached object types by schema ID.
     *
     * @var array<int, ObjectType>
     */
    private array $objectTypes = [];

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
     * Shared File output type.
     *
     * @var ObjectType|null
     */
    private ?ObjectType $fileType = null;

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
     * Loaded schemas indexed by ID.
     *
     * @var array<int, RegisterSchema>
     */
    private array $schemasById = [];

    /**
     * Loaded registers indexed by ID.
     *
     * @var array<int, Register>
     */
    private array $registersById = [];

    /**
     * Audit trail type.
     *
     * @var ObjectType|null
     */
    private ?ObjectType $auditTrailType = null;

    /**
     * Used type names to detect collisions.
     *
     * @var array<string, int>
     */
    private array $usedTypeNames = [];

    /**
     * Resolver instance for wiring real resolvers.
     *
     * @var GraphQLResolver|null
     */
    private ?GraphQLResolver $resolver = null;

    /**
     * Constructor.
     *
     * @param RegisterMapper $registerMapper Register mapper
     * @param SchemaMapper   $schemaMapper   Schema mapper
     */
    public function __construct(
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
    ) {
    }//end __construct()

    /**
     * Set the resolver for wiring real resolvers into the schema.
     *
     * @param GraphQLResolver $resolver The resolver
     *
     * @return void
     */
    public function setResolver(GraphQLResolver $resolver): void
    {
        $this->resolver = $resolver;

    }//end setResolver()

    /**
     * Generate a complete GraphQL schema from register definitions.
     *
     * @return Schema The executable GraphQL schema
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function generate(): Schema
    {
        // Reset all caches including shared types.
        $this->objectTypes     = [];
        $this->inputTypes      = [];
        $this->connectionTypes = [];
        $this->usedTypeNames   = [];
        $this->schemasById     = [];
        $this->registersById   = [];
        $this->pageInfoType    = null;
        $this->fileType        = null;
        $this->auditTrailType  = null;
        $this->sortInputType   = null;
        $this->selfFilterType  = null;
        $this->initScalars();

        // Load all registers and schemas.
        $registers = $this->registerMapper->findAll();
        foreach ($registers as $register) {
            $this->registersById[$register->getId()] = $register;
        }

        $schemas = $this->schemaMapper->findAll();
        foreach ($schemas as $schema) {
            $this->schemasById[$schema->getId()] = $schema;
        }

        // Build query and mutation fields.
        $queryFields    = [];
        $mutationFields = [];

        foreach ($schemas as $schema) {
            $schemaSlug = $schema->getSlug();
            if ($schemaSlug === null || $schemaSlug === '') {
                $schemaSlug = 'schema_'.$schema->getId();
            }

            // Sanitize slug for GraphQL field names (must match [_a-zA-Z][_a-zA-Z0-9]*).
            $slug     = $this->toFieldName(slug: $schemaSlug);
            $singular = $this->toFieldName(slug: $this->singularize(plural: $schemaSlug));
            $plural   = $slug;

            $objectType     = $this->getObjectType(schema: $schema);
            $connectionType = $this->getConnectionType(schema: $schema, objectType: $objectType);

            // Single object query.
            $schemaTitle = $schema->getTitle();
            if ($schemaTitle === null || $schemaTitle === '') {
                $schemaTitle = $singular;
            }

            $queryFields[$singular] = [
                'type'        => $objectType,
                'args'        => [
                    'id' => Type::nonNull(Type::id()),
                ],
                'resolve'     => $this->createSingleResolverPlaceholder(schema: $schema),
                'description' => 'Fetch a single '.$schemaTitle,
            ];

            // List query with pagination, filtering, search, facets.
            $listTitle = $schema->getTitle();
            if ($listTitle === null || $listTitle === '') {
                $listTitle = $plural;
            }

            $queryFields[$plural] = [
                'type'        => $connectionType,
                'args'        => $this->getListArgs(schema: $schema),
                'resolve'     => $this->createListResolverPlaceholder(schema: $schema),
                'description' => 'List '.$listTitle.' with pagination and filtering',
            ];

            // Mutations.
            $createInput = $this->getCreateInputType(schema: $schema);
            $updateInput = $this->getUpdateInputType(schema: $schema);

            $createTitle = $schema->getTitle();
            if ($createTitle === null || $createTitle === '') {
                $createTitle = $singular;
            }

            $mutationFields['create'.ucfirst(string: $singular)] = [
                'type'        => $objectType,
                'args'        => [
                    'input' => Type::nonNull($createInput),
                ],
                'resolve'     => $this->createMutationResolverPlaceholder(schema: $schema, action: 'create'),
                'description' => 'Create a new '.$createTitle,
            ];

            $updateTitle = $schema->getTitle();
            if ($updateTitle === null || $updateTitle === '') {
                $updateTitle = $singular;
            }

            $mutationFields['update'.ucfirst(string: $singular)] = [
                'type'        => $objectType,
                'args'        => [
                    'id'    => Type::nonNull(Type::id()),
                    'input' => Type::nonNull($updateInput),
                ],
                'resolve'     => $this->createMutationResolverPlaceholder(schema: $schema, action: 'update'),
                'description' => 'Update an existing '.$updateTitle,
            ];

            $deleteTitle = $schema->getTitle();
            if ($deleteTitle === null || $deleteTitle === '') {
                $deleteTitle = $singular;
            }

            $mutationFields['delete'.ucfirst(string: $singular)] = [
                'type'        => Type::boolean(),
                'args'        => [
                    'id' => Type::nonNull(Type::id()),
                ],
                'resolve'     => $this->createMutationResolverPlaceholder(schema: $schema, action: 'delete'),
                'description' => 'Delete a '.$deleteTitle,
            ];
        }//end foreach

        // Add register-scoped query.
        $queryFields['register'] = [
            'type'        => $this->scalars['JSON'],
            'args'        => ['id' => Type::nonNull(Type::id())],
            'resolve'     => fn () => null,
            'description' => 'Query within a specific register scope',
        ];

        $queryType    = new ObjectType(['name' => 'Query', 'fields' => $queryFields]);
        $mutationType = new ObjectType(['name' => 'Mutation', 'fields' => $mutationFields]);

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
     * Initialize custom scalar types.
     *
     * @return void
     */
    private function initScalars(): void
    {
        $this->scalars = [
            'DateTime' => new DateTimeType(),
            'UUID'     => new UuidType(),
            'Email'    => new EmailType(),
            'URI'      => new UriType(),
            'JSON'     => new JsonType(),
        ];

    }//end initScalars()

    /**
     * Get or create an object type for a register schema.
     *
     * @param RegisterSchema $schema The register schema
     *
     * @return ObjectType The GraphQL object type
     */
    public function getObjectType(RegisterSchema $schema): ObjectType
    {
        $id = $schema->getId();

        if (isset($this->objectTypes[$id]) === true) {
            return $this->objectTypes[$id];
        }

        $schemaSlug = $schema->getSlug();
        if ($schemaSlug === null || $schemaSlug === '') {
            $schemaSlug = 'Schema'.$id;
        }

        $typeName = $this->toTypeName(slug: $schemaSlug, schemaId: $id);

        // Create type with lazy field resolution to handle circular references.
        $schemaDesc = $schema->getDescription();
        if ($schemaDesc === null || $schemaDesc === '') {
            $schemaDesc = $schema->getSummary();
        }

        $type = new ObjectType(
                [
                    'name'        => $typeName,
                    'description' => $schemaDesc,
                    'fields'      => fn () => $this->buildObjectFields(schema: $schema),
                ]
                );

        $this->objectTypes[$id] = $type;
        return $type;

    }//end getObjectType()

    /**
     * Build fields for an object type from schema properties.
     *
     * @param RegisterSchema $schema The register schema
     *
     * @return array<string, array<string, mixed>> The field configuration
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    private function buildObjectFields(RegisterSchema $schema): array
    {
        $fields = [];

        // Metadata fields.
        $fields['_uuid']     = ['type' => $this->scalars['UUID'], 'description' => 'Object UUID'];
        $fields['_register'] = ['type' => Type::int(), 'description' => 'Register ID'];
        $fields['_schema']   = ['type' => Type::int(), 'description' => 'Schema ID'];
        $fields['_created']  = ['type' => $this->scalars['DateTime'], 'description' => 'Creation timestamp'];
        $fields['_updated']  = ['type' => $this->scalars['DateTime'], 'description' => 'Last update timestamp'];
        $fields['_owner']    = ['type' => Type::string(), 'description' => 'Object owner user ID'];

        // Audit trail field.
        $fields['_auditTrail'] = [
            'type'        => Type::listOf($this->getAuditTrailType()),
            'description' => 'Audit trail entries for this object',
            'args'        => [
                'last' => [
                    'type'         => Type::int(),
                    'defaultValue' => 10,
                    'description'  => 'Number of most recent entries to return',
                ],
            ],
        ];

        // Schema property fields.
        $properties = $schema->getProperties() ?? [];
        $authInfo   = $this->getPropertyAuthDescriptions(schema: $schema);

        foreach ($properties as $name => $property) {
            if (is_array(value: $property) === false) {
                continue;
            }

            // Sanitize property name for GraphQL field compatibility.
            $fieldName = $this->toFieldName(slug: $name);

            $fieldType   = $this->mapPropertyToGraphQLType(property: $property);
            $description = ($property['description'] ?? '');
            if ($description === null || $description === '') {
                $description = null;
            }

            // Annotate authorization requirements in description.
            if (isset($authInfo[$name]) === true) {
                if ($description !== null && $description !== '') {
                    $description = $description.'. '.$authInfo[$name];
                } else {
                    $description = $authInfo[$name];
                }
            }

            $fields[$fieldName] = [
                'type'        => $fieldType,
                'description' => $description,
            ];
        }//end foreach

        // AllOf composition: merge fields from referenced schemas.
        $allOf = null;
        if (method_exists(object_or_class: $schema, method: 'getAllOf') === true) {
            $allOf = $schema->getAllOf();
        }

        if (is_array(value: $allOf) === true) {
            foreach ($allOf as $ref) {
                $refSlug = null;
                if (is_array(value: $ref) === true) {
                    $refSlug = ($ref['$ref'] ?? null);
                } else {
                    $refSlug = $ref;
                }

                $refSchema = null;
                if ($refSlug !== null) {
                    $refSchema = $this->resolveRef(ref: $refSlug);
                }

                if ($refSchema !== null) {
                    $refFields = $this->buildObjectFields(schema: $refSchema);
                    // Merge, giving priority to the current schema's fields.
                    $fields = array_merge($refFields, $fields);
                }
            }
        }//end if

        // OneOf composition: create a union type field.
        $oneOf = null;
        if (method_exists(object_or_class: $schema, method: 'getOneOf') === true) {
            $oneOf = $schema->getOneOf();
        }

        if (is_array(value: $oneOf) === true && empty($oneOf) === false) {
            $unionTypes = $this->resolveCompositionRefs(refs: $oneOf);
            if (empty($unionTypes) === false) {
                $oneOfSlug = $schema->getSlug();
                if ($oneOfSlug === null || $oneOfSlug === '') {
                    $oneOfSlug = 'Schema'.$schema->getId();
                }

                $typeName         = $this->toTypeName(slug: $oneOfSlug);
                $unionType        = new UnionType(
                        [
                            'name'  => $typeName.'Union',
                            'types' => $unionTypes,
                        ]
                        );
                $fields['_oneOf'] = [
                    'type'        => $unionType,
                    'description' => 'One of the composed types',
                ];
            }
        }//end if

        // AnyOf composition: create an interface type with shared fields.
        $anyOf = null;
        if (method_exists(object_or_class: $schema, method: 'getAnyOf') === true) {
            $anyOf = $schema->getAnyOf();
        }

        if (is_array(value: $anyOf) === true && empty($anyOf) === false) {
            $anyOfTypes = $this->resolveCompositionRefs(refs: $anyOf);
            if (empty($anyOfTypes) === false) {
                // Build interface from shared fields across all anyOf types.
                $sharedFields = $this->extractSharedFields(types: $anyOfTypes);
                if (empty($sharedFields) === false) {
                    $anyOfSlug = $schema->getSlug();
                    if ($anyOfSlug === null || $anyOfSlug === '') {
                        $anyOfSlug = 'Schema'.$schema->getId();
                    }

                    $typeName         = $this->toTypeName(slug: $anyOfSlug);
                    $interfaceType    = new InterfaceType(
                            [
                                'name'   => $typeName.'Interface',
                                'fields' => $sharedFields,
                            ]
                            );
                    $fields['_anyOf'] = [
                        'type'        => $interfaceType,
                        'description' => 'Any of the composed types (shared fields)',
                    ];
                }
            }//end if
        }//end if

        // _usedBy field for reverse relationship traversal.
        $fields['_usedBy'] = [
            'type'        => $this->scalars['JSON'],
            'description' => 'Objects that reference this object (reverse relationships)',
        ];

        return $fields;

    }//end buildObjectFields()

    /**
     * Map a JSON Schema property to a GraphQL type.
     *
     * @param array<string, mixed> $property The property definition
     *
     * @return Type The GraphQL type
     */
    private function mapPropertyToGraphQLType(array $property): Type
    {
        $type   = ($property['type'] ?? 'string');
        $format = ($property['format'] ?? null);

        // Handle object references.
        if ($type === 'object' && isset($property['$ref']) === true) {
            $refSchema = $this->resolveRef(ref: $property['$ref']);
            if ($refSchema !== null) {
                return $this->getObjectType(schema: $refSchema);
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
            $type === 'string' && $format === 'date'      => $this->scalars['DateTime'],
            $type === 'string' && $format === 'uuid'      => $this->scalars['UUID'],
            $type === 'string' && $format === 'email'     => $this->scalars['Email'],
            $type === 'string' && $format === 'uri'       => $this->scalars['URI'],
            $type === 'string' && $format === 'url'       => $this->scalars['URI'],
            $type === 'string'                            => Type::string(),
            $type === 'integer'                           => Type::int(),
            $type === 'number'                            => Type::float(),
            $type === 'boolean'                           => Type::boolean(),
            $type === 'object'                            => $this->scalars['JSON'],
            $type === 'array'                             => Type::listOf(Type::string()),
            default                                       => Type::string(),
        };

    }//end mapPropertyToGraphQLType()

    /**
     * Resolve a $ref to a schema entity.
     *
     * @param string $ref The reference string (slug, ID, or URI)
     *
     * @return RegisterSchema|null The resolved schema or null
     */
    private function resolveRef(string $ref): ?RegisterSchema
    {
        // Try by slug.
        foreach ($this->schemasById as $schema) {
            if ($schema->getSlug() === $ref || $schema->getUri() === $ref) {
                return $schema;
            }
        }

        // Try by ID.
        if (is_numeric(value: $ref) === true && isset($this->schemasById[(int) $ref]) === true) {
            return $this->schemasById[(int) $ref];
        }

        return null;

    }//end resolveRef()

    /**
     * Resolve an array of composition references ($ref strings) to ObjectType instances.
     *
     * @param array<mixed> $refs The composition references
     *
     * @return ObjectType[] The resolved object types
     */
    private function resolveCompositionRefs(array $refs): array
    {
        $types = [];
        foreach ($refs as $ref) {
            $refSlug = null;
            if (is_array(value: $ref) === true) {
                $refSlug = ($ref['$ref'] ?? null);
            } else if (is_string(value: $ref) === true) {
                $refSlug = $ref;
            }

            if ($refSlug === null) {
                continue;
            }

            $refSchema = $this->resolveRef(ref: $refSlug);
            if ($refSchema !== null) {
                $types[] = $this->getObjectType(schema: $refSchema);
            }
        }

        return $types;

    }//end resolveCompositionRefs()

    /**
     * Extract shared fields across multiple ObjectTypes for interface generation.
     *
     * @param ObjectType[] $types The object types to intersect
     *
     * @return array<string, array<string, mixed>> Shared field configs
     */
    private function extractSharedFields(array $types): array
    {
        if (empty($types) === true) {
            return [];
        }

        // Get field names from first type.
        $firstType  = $types[0];
        $firstNames = $firstType->getFieldNames();
        $shared     = [];

        foreach ($firstNames as $fieldName) {
            // Skip metadata fields — they're always present.
            if (str_starts_with(haystack: $fieldName, needle: '_') === true) {
                continue;
            }

            // Check if all other types also have this field.
            $allHave = true;
            foreach ($types as $type) {
                if ($type->hasField(name: $fieldName) === false) {
                    $allHave = false;
                    break;
                }
            }

            if ($allHave === true) {
                $field = $firstType->getField(name: $fieldName);
                $shared[$fieldName] = [
                    'type'        => $field->getType(),
                    'description' => $field->description,
                ];
            }
        }//end foreach

        return $shared;

    }//end extractSharedFields()

    /**
     * Get the Relay connection type for a schema.
     *
     * @param RegisterSchema $schema     The register schema
     * @param ObjectType     $objectType The object type for the schema
     *
     * @return ObjectType The connection type
     */
    private function getConnectionType(RegisterSchema $schema, ObjectType $objectType): ObjectType
    {
        $id = $schema->getId();

        if (isset($this->connectionTypes[$id]) === true) {
            return $this->connectionTypes[$id];
        }

        $typeName = $objectType->name;

        $edgeType = new ObjectType(
                [
                    'name'   => $typeName.'Edge',
                    'fields' => [
                        'cursor'     => Type::nonNull(Type::string()),
                        'node'       => Type::nonNull($objectType),
                        '_relevance' => [
                            'type'        => Type::float(),
                            'description' => 'Fuzzy search relevance score (0-100)',
                        ],
                    ],
                ]
                );

        $connectionType = new ObjectType(
                [
                    'name'   => $typeName.'Connection',
                    'fields' => [
                        'edges'      => Type::nonNull(Type::listOf(Type::nonNull($edgeType))),
                        'pageInfo'   => Type::nonNull($this->getPageInfoType()),
                        'totalCount' => Type::nonNull(Type::int()),
                        'facets'     => $this->scalars['JSON'],
                        'facetable'  => Type::listOf(Type::string()),
                    ],
                ]
                );

        $this->connectionTypes[$id] = $connectionType;
        return $connectionType;

    }//end getConnectionType()

    /**
     * Get the shared PageInfo type.
     *
     * @return ObjectType The PageInfo type
     */
    private function getPageInfoType(): ObjectType
    {
        if ($this->pageInfoType !== null) {
            return $this->pageInfoType;
        }

        $this->pageInfoType = new ObjectType(
                [
                    'name'   => 'PageInfo',
                    'fields' => [
                        'hasNextPage'     => Type::nonNull(Type::boolean()),
                        'hasPreviousPage' => Type::nonNull(Type::boolean()),
                        'startCursor'     => Type::string(),
                        'endCursor'       => Type::string(),
                    ],
                ]
                );

        return $this->pageInfoType;

    }//end getPageInfoType()

    /**
     * Get the shared File output type.
     *
     * @return ObjectType The File type
     */
    private function getFileType(): ObjectType
    {
        if ($this->fileType !== null) {
            return $this->fileType;
        }

        $this->fileType = new ObjectType(
                [
                    'name'   => 'File',
                    'fields' => [
                        'filename' => Type::string(),
                        'mimeType' => Type::string(),
                        'size'     => Type::int(),
                        'url'      => Type::string(),
                    ],
                ]
                );

        return $this->fileType;

    }//end getFileType()

    /**
     * Get the shared AuditTrail type.
     *
     * @return ObjectType The AuditTrail type
     */
    private function getAuditTrailType(): ObjectType
    {
        if ($this->auditTrailType !== null) {
            return $this->auditTrailType;
        }

        $this->auditTrailType = new ObjectType(
                [
                    'name'   => 'AuditTrailEntry',
                    'fields' => [
                        'action'               => Type::string(),
                        'user'                 => Type::string(),
                        'userName'             => Type::string(),
                        'changed'              => $this->scalars['JSON'],
                        'created'              => $this->scalars['DateTime'],
                        'ipAddress'            => Type::string(),
                        'processingActivityId' => Type::string(),
                        'confidentiality'      => Type::string(),
                        'retentionPeriod'      => Type::string(),
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
     */
    private function getListArgs(RegisterSchema $schema): array
    {
        return [
            'filter'     => [
                'type'        => $this->getFilterInputType(schema: $schema),
                'description' => 'Filter criteria',
            ],
            'sort'       => ['type' => $this->getSortInputType(), 'description' => 'Sort configuration'],
            'selfFilter' => ['type' => $this->getSelfFilterType(), 'description' => 'Metadata filter (@self)'],
            'search'     => ['type' => Type::string(), 'description' => 'Full-text search query'],
            'fuzzy'      => ['type' => Type::boolean(), 'defaultValue' => false, 'description' => 'Enable fuzzy matching'],
            'facets'     => ['type' => Type::listOf(Type::string()), 'description' => 'Fields to calculate facets for'],
            'first'      => ['type' => Type::int(), 'defaultValue' => 20, 'description' => 'Number of items to return'],
            'offset'     => ['type' => Type::int(), 'description' => 'Offset for pagination'],
            'after'      => ['type' => Type::string(), 'description' => 'Cursor for forward pagination'],
        ];

    }//end getListArgs()

    /**
     * Get a filter input type for a schema.
     *
     * @param RegisterSchema $schema The register schema
     *
     * @return InputObjectType The filter input type
     */
    private function getFilterInputType(RegisterSchema $schema): InputObjectType
    {
        $key = 'filter_'.$schema->getId();

        if (isset($this->inputTypes[$key]) === true) {
            return $this->inputTypes[$key];
        }

        $filterSlug = $schema->getSlug();
        if ($filterSlug === null || $filterSlug === '') {
            $filterSlug = 'Schema'.$schema->getId();
        }

        $typeName = $this->toTypeName(slug: $filterSlug, schemaId: $schema->getId());
        $fields   = [];

        $properties = $schema->getProperties() ?? [];
        foreach ($properties as $name => $property) {
            if (is_array(value: $property) === false) {
                continue;
            }

            $fieldName = $this->toFieldName(slug: $name);

            // Each filter field accepts the base type or a comparison object.
            $baseType = $this->mapPropertyToGraphQLType(property: $property);
            if ($baseType instanceof ObjectType || $baseType instanceof \GraphQL\Type\Definition\ListOfType) {
                // Complex types use JSON for filtering.
                $fields[$fieldName] = $this->scalars['JSON'];
            } else {
                $fields[$fieldName] = $baseType;
            }
        }

        if (empty($fields) === true) {
            $fields['_empty'] = [
                'type'        => Type::boolean(),
                'description' => 'Placeholder for schemas with no filterable properties',
            ];
        }

        $inputType = new InputObjectType(
                [
                    'name'   => $typeName.'Filter',
                    'fields' => $fields,
                ]
                );

        $this->inputTypes[$key] = $inputType;
        return $inputType;

    }//end getFilterInputType()

    /**
     * Get the shared sort input type.
     *
     * @return InputObjectType The sort input type
     */
    private function getSortInputType(): InputObjectType
    {
        if ($this->sortInputType !== null) {
            return $this->sortInputType;
        }

        $this->sortInputType = new InputObjectType(
                [
                    'name'   => 'SortInput',
                    'fields' => [
                        'field' => Type::nonNull(Type::string()),
                        'order' => [
                            'type'         => Type::string(),
                            'defaultValue' => 'ASC',
                            'description'  => 'ASC or DESC',
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
     */
    private function getSelfFilterType(): InputObjectType
    {
        if ($this->selfFilterType !== null) {
            return $this->selfFilterType;
        }

        $this->selfFilterType = new InputObjectType(
                [
                    'name'   => 'SelfFilter',
                    'fields' => [
                        'owner'        => Type::string(),
                        'organisation' => Type::string(),
                        'register'     => Type::int(),
                        'schema'       => Type::int(),
                        'uuid'         => $this->scalars['UUID'],
                    ],
                ]
                );

        return $this->selfFilterType;

    }//end getSelfFilterType()

    /**
     * Get a create input type for a schema.
     *
     * @param RegisterSchema $schema The register schema
     *
     * @return InputObjectType The create input type
     */
    private function getCreateInputType(RegisterSchema $schema): InputObjectType
    {
        $key = 'create_'.$schema->getId();

        if (isset($this->inputTypes[$key]) === true) {
            return $this->inputTypes[$key];
        }

        $createSlug = $schema->getSlug();
        if ($createSlug === null || $createSlug === '') {
            $createSlug = 'Schema'.$schema->getId();
        }

        $typeName = $this->toTypeName(slug: $createSlug, schemaId: $schema->getId());
        $fields   = $this->buildInputFields(schema: $schema);
        $required = $schema->getRequired() ?? [];

        // Mark required fields (sanitize names to match field keys).
        foreach ($required as $reqField) {
            $reqField = $this->toFieldName(slug: $reqField);
            if (isset($fields[$reqField]) === true) {
                $fieldType = $fields[$reqField];
                if ($fieldType instanceof Type) {
                    $fields[$reqField] = Type::nonNull($fieldType);
                } else if (is_array(value: $fieldType) === true && isset($fieldType['type']) === true) {
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
                    'name'   => 'Create'.$typeName.'Input',
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
     */
    private function getUpdateInputType(RegisterSchema $schema): InputObjectType
    {
        $key = 'update_'.$schema->getId();

        if (isset($this->inputTypes[$key]) === true) {
            return $this->inputTypes[$key];
        }

        $updateSlug = $schema->getSlug();
        if ($updateSlug === null || $updateSlug === '') {
            $updateSlug = 'Schema'.$schema->getId();
        }

        $typeName = $this->toTypeName(slug: $updateSlug, schemaId: $schema->getId());
        $fields   = $this->buildInputFields(schema: $schema);

        if (empty($fields) === true) {
            $fields['_placeholder'] = Type::string();
        }

        $inputType = new InputObjectType(
                [
                    'name'   => 'Update'.$typeName.'Input',
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
     */
    private function buildInputFields(RegisterSchema $schema): array
    {
        $fields     = [];
        $properties = $schema->getProperties() ?? [];

        foreach ($properties as $name => $property) {
            if (is_array(value: $property) === false) {
                continue;
            }

            $fieldName = $this->toFieldName(slug: $name);
            $type      = $this->mapPropertyToInputType(property: $property);
            $fields[$fieldName] = $type;
        }

        return $fields;

    }//end buildInputFields()

    /**
     * Map a JSON Schema property to a GraphQL input type.
     *
     * Object references become ID inputs instead of full objects.
     *
     * @param array<string, mixed> $property The property definition
     *
     * @return Type The GraphQL input type
     */
    private function mapPropertyToInputType(array $property): Type
    {
        $type   = ($property['type'] ?? 'string');
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
            $type === 'string' && $format === 'date'      => $this->scalars['DateTime'],
            $type === 'string' && $format === 'uuid'      => $this->scalars['UUID'],
            $type === 'string' && $format === 'email'     => $this->scalars['Email'],
            $type === 'string' && $format === 'uri'       => $this->scalars['URI'],
            $type === 'string'                            => Type::string(),
            $type === 'integer'                           => Type::int(),
            $type === 'number'                            => Type::float(),
            $type === 'boolean'                           => Type::boolean(),
            default                                       => Type::string(),
        };

    }//end mapPropertyToInputType()

    /**
     * Get property-level authorization descriptions for annotation.
     *
     * @param RegisterSchema $schema The register schema
     *
     * @return array<string, string> Map of property name to auth description
     */
    private function getPropertyAuthDescriptions(RegisterSchema $schema): array
    {
        $result = [];
        if (method_exists(object_or_class: $schema, method: 'getPropertiesWithAuthorization') === false) {
            return $result;
        }

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
                    } else if (is_array(value: $rule) === true && isset($rule['group']) === true) {
                        $groups[] = $rule['group'];
                    }
                }
            }

            if (empty($groups) === false) {
                $result[$propName] = 'Requires group: '.implode(
                    separator: ', ',
                    array: array_unique(array: $groups)
                );
            }
        }//end foreach

        return $result;

    }//end getPropertyAuthDescriptions()

    /**
     * Convert a slug to a PascalCase GraphQL type name.
     *
     * @param string   $slug     The kebab-case or camelCase slug
     * @param int|null $schemaId Schema ID for deduplication (optional)
     *
     * @return string The PascalCase type name
     */
    private function toTypeName(string $slug, ?int $schemaId=null): string
    {
        $name = str_replace(search: ['-', '_'], replace: ' ', subject: $slug);
        $name = ucwords(string: $name);
        $name = str_replace(search: ' ', replace: '', subject: $name);

        // Ensure starts with letter.
        if (preg_match(pattern: '/^[A-Za-z]/', subject: $name) !== 1) {
            $name = 'Type'.$name;
        }

        // Disambiguate if the base name collides with another schema's type.
        if ($schemaId !== null
            && isset($this->usedTypeNames[$name]) === true
            && $this->usedTypeNames[$name] !== $schemaId
        ) {
            $name = $name.$schemaId;
        }

        if ($schemaId !== null) {
            $this->usedTypeNames[$name] = $schemaId;
        }

        return $name;

    }//end toTypeName()

    /**
     * Convert a slug to a valid GraphQL field name (camelCase).
     *
     * GraphQL names must match [_a-zA-Z][_a-zA-Z0-9]*.
     * Converts kebab-case and snake_case to camelCase.
     *
     * @param string $slug The slug to convert
     *
     * @return string A valid GraphQL field name
     */
    private function toFieldName(string $slug): string
    {
        // Replace hyphens/underscores with spaces, then camelCase.
        $parts = preg_split(pattern: '/[-_]/', subject: $slug);
        $first = array_shift($parts);
        $rest  = array_map(
            callback: fn ($p) => ucfirst(string: $p),
            array: $parts
        );

        $name = $first.implode(separator: '', array: $rest);

        // Ensure starts with letter or underscore.
        if (preg_match(pattern: '/^[_a-zA-Z]/', subject: $name) !== 1) {
            $name = '_'.$name;
        }

        // Remove any remaining invalid characters.
        $name = preg_replace(pattern: '/[^_a-zA-Z0-9]/', replacement: '', subject: $name);

        return $name;

    }//end toFieldName()

    /**
     * Naive singularization for Dutch/English schema slugs.
     *
     * @param string $plural The plural form
     *
     * @return string The singular form
     */
    private function singularize(string $plural): string
    {
        // Dutch: -en suffix.
        if (str_ends_with(haystack: $plural, needle: 'en') === true
            && strlen(string: $plural) > 4
        ) {
            return substr(string: $plural, offset: 0, length: -2);
        }

        // English: -ies -> -y.
        if (str_ends_with(haystack: $plural, needle: 'ies') === true) {
            return substr(string: $plural, offset: 0, length: -3).'y';
        }

        // English: -es -> remove.
        if (str_ends_with(haystack: $plural, needle: 'es') === true
            && strlen(string: $plural) > 4
        ) {
            return substr(string: $plural, offset: 0, length: -2);
        }

        // English: -s -> remove.
        if (str_ends_with(haystack: $plural, needle: 's') === true
            && str_ends_with(haystack: $plural, needle: 'ss') === false
        ) {
            return substr(string: $plural, offset: 0, length: -1);
        }

        return $plural;

    }//end singularize()

    /**
     * Create a resolver for single object queries.
     *
     * @param RegisterSchema $schema The register schema
     *
     * @return callable The resolver function
     */
    private function createSingleResolverPlaceholder(RegisterSchema $schema): callable
    {
        $resolver = $this->resolver;
        return function ($root, $args) use ($schema, $resolver) {
            if ($resolver !== null) {
                return $resolver->resolveSingle($schema, $root, $args);
            }

            return null;
        };

    }//end createSingleResolverPlaceholder()

    /**
     * Create a resolver for list queries.
     *
     * @param RegisterSchema $schema The register schema
     *
     * @return callable The resolver function
     */
    private function createListResolverPlaceholder(RegisterSchema $schema): callable
    {
        $resolver = $this->resolver;
        return function ($root, $args) use ($schema, $resolver) {
            if ($resolver !== null) {
                return $resolver->resolveList($schema, $root, $args);
            }

            return null;
        };

    }//end createListResolverPlaceholder()

    /**
     * Create a resolver for mutations.
     *
     * @param RegisterSchema $schema The register schema
     * @param string         $action The mutation action (create, update, delete)
     *
     * @return callable The resolver function
     */
    private function createMutationResolverPlaceholder(RegisterSchema $schema, string $action): callable
    {
        $resolver = $this->resolver;
        return function ($root, $args) use ($schema, $action, $resolver) {
            if ($resolver === null) {
                return null;
            }

            return match ($action) {
                'create' => $resolver->resolveCreate($schema, $args),
                'update' => $resolver->resolveUpdate($schema, $args),
                'delete' => $resolver->resolveDelete($schema, $args),
                default  => null,
            };
        };

    }//end createMutationResolverPlaceholder()

    /**
     * Get schemas indexed by ID.
     *
     * @return array<int, RegisterSchema>
     */
    public function getSchemasById(): array
    {
        return $this->schemasById;

    }//end getSchemasById()

    /**
     * Get registers indexed by ID.
     *
     * @return array<int, Register>
     */
    public function getRegistersById(): array
    {
        return $this->registersById;

    }//end getRegistersById()
}//end class
