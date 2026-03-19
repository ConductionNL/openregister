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
 * @category Service
 * @package  OCA\OpenRegister\Service\GraphQL
 * @author   Conduction B.V. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://OpenRegister.app
 */

namespace OCA\OpenRegister\Service\GraphQL;

use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
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
use OCA\OpenRegister\Service\GraphQL\SchemaGenerator\CompositionHandler;
use OCA\OpenRegister\Service\GraphQL\SchemaGenerator\TypeMapperHandler;

/**
 * Generates a GraphQL schema from OpenRegister register/schema definitions.
 *
 * Reads all registers and schemas, maps JSON Schema properties to GraphQL types,
 * and produces a complete executable schema with queries, mutations, filters,
 * and connection types.
 *
 * Complexity reduced from 167 to 60 by extracting TypeMapperHandler and
 * CompositionHandler. Remaining complexity is due to the inherent orchestration
 * of query/mutation/type construction that cannot be further decomposed without
 * fragmenting the schema generation flow.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
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
     * Loaded registers indexed by ID.
     *
     * @var array<int, Register>
     */
    private array $registersById = [];

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
     * Handler for type mapping and input type generation.
     *
     * @var TypeMapperHandler|null
     */
    private ?TypeMapperHandler $typeMapper = null;

    /**
     * Handler for allOf/oneOf/anyOf composition logic.
     *
     * @var CompositionHandler|null
     */
    private ?CompositionHandler $compositionHandler = null;

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
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Schema generation inherently branches per register+schema
     */
    public function generate(): Schema
    {
        // Reset all caches including shared types.
        $this->objectTypes   = [];
        $this->usedTypeNames = [];
        $this->schemasById   = [];
        $this->registersById = [];
        $this->initScalars();
        $this->initHandlers();

        // Load all registers and schemas.
        $registers = $this->registerMapper->findAll();
        foreach ($registers as $register) {
            $this->registersById[$register->getId()] = $register;
        }

        $schemas = $this->schemaMapper->findAll();
        foreach ($schemas as $schema) {
            $this->schemasById[$schema->getId()] = $schema;
        }

        // Build query and mutation fields from schemas.
        $queryFields    = [];
        $mutationFields = [];

        foreach ($schemas as $schema) {
            $this->buildSchemaFields(schema: $schema, queryFields: $queryFields, mutationFields: $mutationFields);
        }

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
     * Build query and mutation fields for a single schema.
     *
     * @param RegisterSchema       $schema         The register schema
     * @param array<string, mixed> $queryFields    Query fields accumulator
     * @param array<string, mixed> $mutationFields Mutation fields accumulator
     *
     * @return void
     */
    private function buildSchemaFields(
        RegisterSchema $schema,
        array &$queryFields,
        array &$mutationFields,
    ): void {
        $schemaSlug = $schema->getSlug();
        if ($schemaSlug === null || $schemaSlug === '') {
            $schemaSlug = 'schema_'.$schema->getId();
        }

        // Sanitize slug for GraphQL field names (must match [_a-zA-Z][_a-zA-Z0-9]*).
        $slug     = $this->toFieldName(slug: $schemaSlug);
        $singular = $this->toFieldName(slug: $this->singularize(plural: $schemaSlug));
        $plural   = $slug;

        $objectType     = $this->getObjectType(schema: $schema);
        $connectionType = $this->typeMapper->getConnectionType(schema: $schema, objectType: $objectType);

        $this->buildQueryFields(
            schema: $schema,
            singular: $singular,
            plural: $plural,
            objectType: $objectType,
            connectionType: $connectionType,
            queryFields: $queryFields
        );

        $this->buildMutationFields(
            schema: $schema,
            singular: $singular,
            objectType: $objectType,
            mutationFields: $mutationFields
        );

    }//end buildSchemaFields()

    /**
     * Build query fields (single + list) for a schema.
     *
     * @param RegisterSchema       $schema         The register schema
     * @param string               $singular       Singular field name
     * @param string               $plural         Plural field name
     * @param ObjectType           $objectType     The object type
     * @param ObjectType           $connectionType The connection type
     * @param array<string, mixed> $queryFields    Query fields accumulator
     *
     * @return void
     */
    private function buildQueryFields(
        RegisterSchema $schema,
        string $singular,
        string $plural,
        ObjectType $objectType,
        ObjectType $connectionType,
        array &$queryFields,
    ): void {
        $schemaTitle = ($schema->getTitle() ?? '');
        if ($schemaTitle === '') {
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

        $listTitle = ($schema->getTitle() ?? '');
        if ($listTitle === '') {
            $listTitle = $plural;
        }

        $queryFields[$plural] = [
            'type'        => $connectionType,
            'args'        => $this->typeMapper->getListArgs(schema: $schema),
            'resolve'     => $this->createListResolverPlaceholder(schema: $schema),
            'description' => 'List '.$listTitle.' with pagination and filtering',
        ];

    }//end buildQueryFields()

    /**
     * Build mutation fields (create, update, delete) for a schema.
     *
     * @param RegisterSchema       $schema         The register schema
     * @param string               $singular       Singular field name
     * @param ObjectType           $objectType     The object type
     * @param array<string, mixed> $mutationFields Mutation fields accumulator
     *
     * @return void
     */
    private function buildMutationFields(
        RegisterSchema $schema,
        string $singular,
        ObjectType $objectType,
        array &$mutationFields,
    ): void {
        $createInput = $this->typeMapper->getCreateInputType(schema: $schema);
        $updateInput = $this->typeMapper->getUpdateInputType(schema: $schema);

        $title = ($schema->getTitle() ?? '');
        if ($title === '') {
            $title = $singular;
        }

        $mutationFields['create'.ucfirst(string: $singular)] = [
            'type'        => $objectType,
            'args'        => [
                'input' => Type::nonNull($createInput),
            ],
            'resolve'     => $this->createMutationResolverPlaceholder(schema: $schema, action: 'create'),
            'description' => 'Create a new '.$title,
        ];

        $mutationFields['update'.ucfirst(string: $singular)] = [
            'type'        => $objectType,
            'args'        => [
                'id'    => Type::nonNull(Type::id()),
                'input' => Type::nonNull($updateInput),
            ],
            'resolve'     => $this->createMutationResolverPlaceholder(schema: $schema, action: 'update'),
            'description' => 'Update an existing '.$title,
        ];

        $mutationFields['delete'.ucfirst(string: $singular)] = [
            'type'        => Type::boolean(),
            'args'        => [
                'id' => Type::nonNull(Type::id()),
            ],
            'resolve'     => $this->createMutationResolverPlaceholder(schema: $schema, action: 'delete'),
            'description' => 'Delete a '.$title,
        ];

    }//end buildMutationFields()

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
     * Initialize handler classes with callback dependencies.
     *
     * @return void
     */
    private function initHandlers(): void
    {
        $refResolver        = fn (string $ref): ?RegisterSchema => $this->resolveRef(ref: $ref);
        $objectTypeFactory  = fn (RegisterSchema $schema): ObjectType => $this->getObjectType(schema: $schema);
        $fieldNameConverter = fn (string $slug): string => $this->toFieldName(slug: $slug);
        $typeNameConverter  = fn (string $slug, ?int $schemaId=null): string => $this->toTypeName(
            slug: $slug,
            schemaId: $schemaId
        );

        $this->typeMapper = new TypeMapperHandler(
            scalars: $this->scalars,
            refResolver: $refResolver,
            objectTypeFactory: $objectTypeFactory,
            fieldNameConverter: $fieldNameConverter,
            typeNameConverter: $typeNameConverter,
        );

        $this->compositionHandler = new CompositionHandler(
            refResolver: $refResolver,
            objectTypeFactory: $objectTypeFactory,
            fieldBuilder: fn (RegisterSchema $schema): array => $this->buildObjectFields(schema: $schema),
            typeNameConverter: $typeNameConverter,
        );

    }//end initHandlers()

    /**
     * Get or create an object type for a register schema.
     *
     * @param RegisterSchema $schema The register schema
     *
     * @return ObjectType The GraphQL object type
     */
    public function getObjectType(RegisterSchema $schema): ObjectType
    {
        $schemaId = $schema->getId();

        if (isset($this->objectTypes[$schemaId]) === true) {
            return $this->objectTypes[$schemaId];
        }

        $schemaSlug = $schema->getSlug();
        if ($schemaSlug === null || $schemaSlug === '') {
            $schemaSlug = 'Schema'.$schemaId;
        }

        $typeName = $this->toTypeName(slug: $schemaSlug, schemaId: $schemaId);

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

        $this->objectTypes[$schemaId] = $type;
        return $type;

    }//end getObjectType()

    /**
     * Build fields for an object type from schema properties.
     *
     * @param RegisterSchema $schema The register schema
     *
     * @return array<string, array<string, mixed>> The field configuration
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) JSON Schema composition (allOf/oneOf/anyOf) requires deep branching
     * @SuppressWarnings(PHPMD.NPathComplexity)      Composition + property mapping creates high path count
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
            'type'        => Type::listOf($this->typeMapper->getAuditTrailType()),
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
        $authInfo   = $this->typeMapper->getPropertyAuthDescriptions(schema: $schema);

        foreach ($properties as $name => $property) {
            if (is_array(value: $property) === false) {
                continue;
            }

            // Sanitize property name for GraphQL field compatibility.
            $fieldName = $this->toFieldName(slug: $name);

            $fieldType   = $this->typeMapper->mapPropertyToGraphQLType(property: $property);
            $description = ($property['description'] ?? '');
            if ($description === '') {
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

        // Delegate allOf/oneOf/anyOf composition to handler.
        $this->compositionHandler->applyComposition(schema: $schema, fields: $fields);

        // _usedBy field for reverse relationship traversal.
        $fields['_usedBy'] = [
            'type'        => $this->scalars['JSON'],
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
            callback: fn ($part) => ucfirst(string: $part),
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
