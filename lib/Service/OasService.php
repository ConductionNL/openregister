<?php
/**
 * OpenAPI Specification (OAS) Service
 *
 * This service generates OpenAPI Specification (OAS) documentation for registers and schemas.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\IURLGenerator;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Class OasService
 *
 * Service for generating OpenAPI Specification documentation.
 */
class OasService
{
    /**
     * Base path to OAS resources.
     *
     * @var string
     */
    private const OAS_RESOURCE_PATH = __DIR__.'/Resources/BaseOas.json';

    /**
     * The OpenAPI specification being built
     *
     * @var array<string, mixed>
     */
    private array $oas = [];


    /**
     * Constructor for OasService
     *
     * @param RegisterMapper  $registerMapper The register mapper for fetching registers
     * @param SchemaMapper    $schemaMapper   The schema mapper for fetching schemas
     * @param IURLGenerator   $urlGenerator   The URL generator for creating paths
     * @param IConfig         $config         The config service for app settings
     * @param LoggerInterface $logger         The logger interface
     *
     * @return void
     */
    public function __construct(
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly IURLGenerator $urlGenerator,
        private readonly IConfig $config,
        private readonly LoggerInterface $logger
    ) {
        // Initialize the OAS array with the base OAS.
        $this->oas = $this->getBaseOas();

    }//end __construct()


    /**
     * Create OpenAPI Specification for register(s)
     *
     * @param string|null $registerId Optional register ID to generate OAS for specific register
     *
     * @return array The complete OpenAPI specification
     *
     * @throws \Exception When base OAS file cannot be read or parsed
     */
    public function createOas(?string $registerId=null): array
    {
        // Reset OAS to base state.
        $this->oas = $this->getBaseOas();

        // Get registers.
        if ($registerId === null) {
            $registers = $this->registerMapper->findAll();
        } else {
            $registers = [$this->registerMapper->find($registerId)];
        }

        // Extract unique schema IDs from registers.
        $schemaIds = [];
        foreach ($registers as $register) {
            $schemaIds = array_merge($schemaIds, $register->getSchemas());
        }

        $uniqueSchemaIds = array_unique($schemaIds);

        // Get all schemas using the unique schema IDs and index them by schema slug.
        $schemas = [];
        foreach ($this->schemaMapper->findMultiple($uniqueSchemaIds) as $schema) {
            $schemas[$schema->getId()] = $schema;
        }

        // Update servers configuration.
        $this->oas['servers'] = [
            [
                'url'         => $this->urlGenerator->getAbsoluteURL('/apps/openregister/api'),
                'description' => 'OpenRegister API Server',
            ],
        ];

        // If specific register, update info while preserving contact and license.
        if ($registerId !== null) {
            $register = $registers[0];
            
            // Build enhanced description.
            $description = $register->getDescription();
            if (empty($description)) {
                $description = 'API for '.$register->getTitle().' register providing CRUD operations, filtering, and search capabilities.';
            }
            
            // Update info while preserving base contact and license.
            $this->oas['info'] = array_merge($this->oas['info'], [
                'title'       => $register->getTitle().' API',
                'version'     => $register->getVersion(),
                'description' => $description,
            ]);
        }

        // Initialize tags array.
        $this->oas['tags'] = [];

        // Add schemas to components and create tags.
        foreach ($schemas as $schema) {
            // Ensure schema has valid title.
            $schemaTitle = $schema->getTitle();
            if (empty($schemaTitle)) {
                continue;
            }
            
            // Add schema to components with sanitized name.
            $schemaDefinition = $this->enrichSchema($schema);
            $sanitizedSchemaName = $this->sanitizeSchemaName($schemaTitle);
            
            // Validate schema definition before adding.
            if (!empty($schemaDefinition) && is_array($schemaDefinition)) {
                $this->oas['components']['schemas'][$sanitizedSchemaName] = $schemaDefinition;

                // Add tag for the schema (keep original title for display).
                $this->oas['tags'][] = [
                    'name'        => $schemaTitle,
                    'description' => $schema->getDescription() ?? 'Operations for '.$schemaTitle,
                ];
            } else {
            }
        }

        // Initialize paths array.
        $this->oas['paths'] = [];

        // Add paths for each register.
        foreach ($registers as $register) {
            // Get schema slugs for the current register.
            $schemaIds = $register->getSchemas();

            // Loop through each schema slug to get the schema from the schemas array.
            foreach ($schemaIds as $schemaId) {
                if (isset($schemas[$schemaId]) === true) {
                    $schema = $schemas[$schemaId];
                    $this->addCrudPaths($register, $schema);
                    $this->addExtendedPaths($register, $schema);
                }
            }
        }

        // Validate the final OpenAPI specification before returning.
        $this->validateOasIntegrity();
        
        return $this->oas;

    }//end createOas()


    /**
     * Get the base OAS file as array
     *
     * @return array The base OAS array
     *
     * @throws \Exception When file cannot be read or parsed
     */
    private function getBaseOas(): array
    {
        $content = file_get_contents(self::OAS_RESOURCE_PATH);
        if ($content === false) {
            throw new \Exception('Could not read base OAS file');
        }

        $oas = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Could not parse base OAS file: '.json_last_error_msg());
        }

        return $oas;

    }//end getBaseOas()


    /**
     * Extended endpoints that should be included in OAS generation
     * This whitelist ensures only stable, public-facing endpoints are documented
     * 
     * @var array<string>
     */
    private const INCLUDED_EXTENDED_ENDPOINTS = [
        // Only include stable, public-facing endpoints.
        // 'audit-trails' - Internal audit functionality, not for public API.
        // 'files' - File management, may be too complex for basic API consumers.  
        // 'lock' - Locking mechanism, typically used internally.
        // 'unlock' - Unlocking mechanism, typically used internally.
    ];

    /**
     * Enrich a schema with valid OpenAPI schema definitions
     *
     * This method includes legitimate API properties like @self but ensures
     * property definitions conform to OpenAPI schema standards.
     *
     * @param object $schema The schema object
     *
     * @return array The valid OpenAPI schema definition
     */
    private function enrichSchema(object $schema): array
    {
        $schemaProperties = $schema->getProperties();
        
        // Start with core API properties.
        $cleanProperties = [
            '@self' => [
                '$ref'        => '#/components/schemas/@self',
                'readOnly'    => true,
                'description' => 'Object metadata including timestamps, ownership, and system information',
            ],
            'id' => [
                'type'        => 'string',
                'format'      => 'uuid',
                'readOnly'    => true,
                'example'     => '123e4567-e89b-12d3-a456-426614174000',
                'description' => 'The unique identifier for the object.',
            ],
        ];

        // Process schema-defined properties and ensure they're valid OAS.
        foreach ($schemaProperties as $propertyName => $propertyDefinition) {
            $cleanProperties[$propertyName] = $this->sanitizePropertyDefinition($propertyDefinition);
        }

        return [
            'type'       => 'object',
            'x-tags'     => [$schema->getTitle()],
            'properties' => $cleanProperties,
        ];

    }//end enrichSchema()


    /**
     * Sanitize property definition to be valid OpenAPI schema
     * 
     * This method ensures property definitions conform to OpenAPI 3.1 standards
     * by removing invalid properties and normalizing the structure.
     *
     * @param mixed $propertyDefinition The property definition to sanitize
     *
     * @return array Valid OpenAPI property definition
     */
    private function sanitizePropertyDefinition($propertyDefinition): array
    {
        // If it's not an array, convert to basic string type.
        if (!is_array($propertyDefinition)) {
            return [
                'type' => 'string',
                'description' => 'Property value',
            ];
        }

        // Start with a clean definition.
        $cleanDef = [];

        // Standard OpenAPI schema keywords that are allowed.
        $allowedSchemaKeywords = [
            'type', 'format', 'description', 'example', 'examples', 
            'default', 'enum', 'const', 'multipleOf', 'maximum', 
            'exclusiveMaximum', 'minimum', 'exclusiveMinimum', 
            'maxLength', 'minLength', 'pattern', 'maxItems', 
            'minItems', 'uniqueItems', 'maxProperties', 'minProperties',
            'required', 'properties', 'items', 'additionalProperties',
            'allOf', 'anyOf', 'oneOf', 'not', '$ref', 'nullable',
            'readOnly', 'writeOnly', 'title'
        ];

        // Copy only valid OpenAPI schema keywords.
        foreach ($allowedSchemaKeywords as $keyword) {
            if (isset($propertyDefinition[$keyword])) {
                $cleanDef[$keyword] = $propertyDefinition[$keyword];
            }
        }

        // Remove invalid/empty values that violate OpenAPI spec.
        // oneOf must have at least 1 item, remove if empty.
        if (isset($cleanDef['oneOf']) && (empty($cleanDef['oneOf']) || !is_array($cleanDef['oneOf']))) {
            unset($cleanDef['oneOf']);
        }

        // anyOf must have at least 1 item, remove if empty.  
        if (isset($cleanDef['anyOf']) && (empty($cleanDef['anyOf']) || !is_array($cleanDef['anyOf']))) {
            unset($cleanDef['anyOf']);
        }

        // allOf must have at least 1 item, remove if empty or invalid.
        if (isset($cleanDef['allOf'])) {
            if (!is_array($cleanDef['allOf']) || empty($cleanDef['allOf'])) {
                unset($cleanDef['allOf']);
            } else {
                // Validate each allOf element.
                $validAllOfItems = [];
                foreach ($cleanDef['allOf'] as $item) {
                    // Each allOf item must be an object/array.
                    if (is_array($item) && !empty($item)) {
                        $validAllOfItems[] = $item;
                    }
                }
                
                // If no valid items remain, remove allOf.
                if (empty($validAllOfItems)) {
                    unset($cleanDef['allOf']);
                } else {
                    $cleanDef['allOf'] = $validAllOfItems;
                }
            }
        }

        // $ref must be a non-empty string, remove if empty
        if (isset($cleanDef['$ref']) && (empty($cleanDef['$ref']) || !is_string($cleanDef['$ref']))) {
            unset($cleanDef['$ref']);
        }

        // enum must have at least 1 item, remove if empty.
        if (isset($cleanDef['enum']) && (empty($cleanDef['enum']) || !is_array($cleanDef['enum']))) {
            unset($cleanDef['enum']);
        }

        // Ensure we have at least a type.
        if (!isset($cleanDef['type']) && !isset($cleanDef['$ref'])) {
            $cleanDef['type'] = 'string';
        }

        // Add basic description if missing.
        if (!isset($cleanDef['description']) && !isset($cleanDef['$ref'])) {
            $cleanDef['description'] = 'Property value';
        }

        return $cleanDef;

    }//end sanitizePropertyDefinition()


    /**
     * Add CRUD paths for a schema.
     *
     * @param object $register The register object
     * @param object $schema   The schema object
     *
     * @return void
     */
    private function addCrudPaths(object $register, object $schema): void
    {
        $basePath = '/'.$this->slugify($register->getTitle()).'/'.$this->slugify($schema->getTitle());

        // Collection endpoints (tags are inside individual operations).
        $this->oas['paths'][$basePath] = [
            'get'  => $this->createGetCollectionOperation($schema),
            'post' => $this->createPostOperation($schema),
        ];

        // Individual resource endpoints (tags are inside individual operations).
        $this->oas['paths'][$basePath.'/{id}'] = [
            'get'    => $this->createGetOperation($schema),
            'put'    => $this->createPutOperation($schema),
            'delete' => $this->createDeleteOperation($schema),
        ];

    }//end addCrudPaths()


    /**
     * Add extended paths for a schema using whitelist approach
     *
     * Only adds endpoints that are explicitly whitelisted in INCLUDED_EXTENDED_ENDPOINTS.
     * This prevents internal/complex endpoints from being exposed in the public API spec.
     *
     * @param object $register The register object
     * @param object $schema   The schema object
     *
     * @return void
     */
    private function addExtendedPaths(object $register, object $schema): void
    {
        $basePath = '/'.$this->slugify($register->getTitle()).'/'.$this->slugify($schema->getTitle());

        // Only add whitelisted extended endpoints.
        foreach (self::INCLUDED_EXTENDED_ENDPOINTS as $endpoint) {
            switch ($endpoint) {
                case 'audit-trails':
                    $this->oas['paths'][$basePath.'/{id}/audit-trails'] = [
                        'get'  => $this->createLogsOperation($schema),
                    ];
                    break;

                case 'files':
                    $this->oas['paths'][$basePath.'/{id}/files'] = [
                        'get'  => $this->createGetFilesOperation($schema),
                        'post' => $this->createPostFileOperation($schema),
                    ];
                    break;

                case 'lock':
                    $this->oas['paths'][$basePath.'/{id}/lock'] = [
                        'post' => $this->createLockOperation($schema),
                    ];
                    break;

                case 'unlock':
                    $this->oas['paths'][$basePath.'/{id}/unlock'] = [
                        'post' => $this->createUnlockOperation($schema),
                    ];
                    break;
            }
        }

        // Note: By default, NO extended endpoints are included.
        // To include them, add them to INCLUDED_EXTENDED_ENDPOINTS constant.
        // This ensures a clean, minimal API specification focused on core CRUD operations.

    }//end addExtendedPaths()


    /**
     * Create common query parameters for object operations
     *
     * @param bool   $isCollection Whether this is for a collection endpoint
     * @param object $schema       The schema object for generating dynamic filter parameters (only used for collection endpoints)
     *
     * @return array Array of common query parameters
     */
    private function createCommonQueryParameters(bool $isCollection=false, ?object $schema=null): array
    {
        $parameters = [
            [
                'name'        => '_extend',
                'in'          => 'query',
                'required'    => false,
                'description' => 'Comma-separated list of properties to extend.',
                'schema'      => [
                    'type' => 'string',
                ],
                'example'     => 'property1,property2,property3',
            ],
            [
                'name'        => '_filter',
                'in'          => 'query',
                'required'    => false,
                'description' => 'Comma-separated list of properties to include in the response. ',
                'schema'      => [
                    'type' => 'string',
                ],
                'example'     => 'id,name,description',
            ],
            [
                'name'        => '_unset',
                'in'          => 'query',
                'required'    => false,
                'description' => 'Comma-separated list of properties to remove from the response.',
                'schema'      => [
                    'type' => 'string',
                ],
                'example'     => 'internalField1,internalField2',
            ],
        ];

        // Add collection-specific parameters.
        if ($isCollection === true) {
            // Add _search parameter.
            $parameters[] = [
                'name'        => '_search',
                'in'          => 'query',
                'required'    => false,
                'description' => 'Full-text search query to filter objects in the collection.',
                'schema'      => [
                    'type' => 'string',
                ],
                'example'     => 'search term',
            ];

            // Add dynamic filter parameters based on schema properties.
            if ($schema !== null) {
                $schemaProperties = $schema->getProperties();
                foreach ($schemaProperties as $propertyName => $propertyDefinition) {
                    // Skip metadata properties and internal system properties.
                    if (str_starts_with($propertyName, '@')) {
                        continue;
                    }

                    // Skip the id property as it's already handled as a path parameter.
                    if ($propertyName === 'id') {
                        continue;
                    }

                    // Get property type from definition.
                    $propertyType = $this->getPropertyType($propertyDefinition);

                    // Build schema for parameter.
                    $paramSchema = [
                        'type' => $propertyType,
                    ];

                    // Array types require an items field.
                    if ($propertyType === 'array') {
                        $paramSchema['items'] = [
                            'type' => 'string', // Default array item type for query parameters
                        ];
                    }

                    $parameters[] = [
                        'name'        => $propertyName,
                        'in'          => 'query',
                        'required'    => false,
                        'description' => 'Filter results by '.$propertyName,
                        'schema'      => $paramSchema,
                    ];
                }
            }//end if
        }//end if

        return $parameters;

    }//end createCommonQueryParameters()


    /**
     * Get OpenAPI type for a property definition
     *
     * @param mixed $propertyDefinition The property definition from the schema
     *
     * @return string The OpenAPI type for the property
     */
    private function getPropertyType($propertyDefinition): string
    {
        // If the property definition is an array, look for the type key.
        if (is_array($propertyDefinition) === true && isset($propertyDefinition['type']) === true) {
            return $propertyDefinition['type'];
        }

        // If the property definition is a string, assume it's the type.
        if (is_string($propertyDefinition) === true) {
            // Map common types to OpenAPI types.
            $typeMap = [
                'int'    => 'integer',
                'float'  => 'number',
                'bool'   => 'boolean',
                'string' => 'string',
                'array'  => 'array',
                'object' => 'object',
            ];

            return $typeMap[$propertyDefinition] ?? 'string';
        }

        // Default to string if type cannot be determined.
        return 'string';

    }//end getPropertyType()


    /**
     * Create GET collection operation.
     *
     * @param object $schema The schema object
     *
     * @return array The operation definition
     */
    private function createGetCollectionOperation(object $schema): array
    {
        // Ensure schema has a valid title before proceeding.
        $schemaTitle = $schema->getTitle();
        if (empty($schemaTitle)) {
            $schemaTitle = 'UnknownSchema';
        }
        
        $sanitizedSchemaName = $this->sanitizeSchemaName($schemaTitle);
        
        // Validate that we have a proper schema reference.
        if (empty($sanitizedSchemaName)) {
            $sanitizedSchemaName = 'UnknownSchema';
        }
        
        return [
            'summary'     => 'Get all '.$schemaTitle.' objects',
            'operationId' => 'getAll'.$this->pascalCase($schemaTitle),
            'tags'        => [$schemaTitle],
            'description' => 'Retrieve a list of all '.$schemaTitle.' objects',
            'parameters'  => $this->createCommonQueryParameters(true, $schema),
            'responses'   => [
                '200' => [
                    'description' => 'List of '.$schemaTitle.' objects with pagination metadata',
                    'content'     => [
                        'application/json' => [
                            'schema' => [
                                'allOf' => [
                                    [
                                        '$ref' => '#/components/schemas/PaginatedResponse',
                                    ],
                                    [
                                        'type' => 'object',
                                        'properties' => [
                                            'results' => [
                                                'type' => 'array',
                                                'items' => [
                                                    '$ref' => '#/components/schemas/'.$sanitizedSchemaName,
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

    }//end createGetCollectionOperation()


    /**
     * Create GET operation.
     *
     * @param object $schema The schema object
     *
     * @return array The operation definition
     */
    private function createGetOperation(object $schema): array
    {
        return [
            'summary'     => 'Get a '.$schema->getTitle().' object by ID',
            'operationId' => 'get'.$this->pascalCase($schema->getTitle()),
            'tags'        => [$schema->getTitle()],
            'description' => 'Retrieve a specific '.$schema->getTitle().' object by its unique identifier',
            'parameters'  => array_merge(
                [
                    [
                        'name'        => 'id',
                        'in'          => 'path',
                        'required'    => true,
                        'description' => 'Unique identifier of the '.$schema->getTitle().' object',
                        'schema'      => [
                            'type'   => 'string',
                            'format' => 'uuid',
                        ],
                    ],
                ],
                $this->createCommonQueryParameters()
            ),
            'responses'   => [
                '200' => [
                    'description' => $schema->getTitle().' found.',
                    'content'     => [
                        'application/json' => [
                            'schema' => [
                                '$ref' => '#/components/schemas/'.$this->sanitizeSchemaName($schema->getTitle() ?: 'UnknownSchema'),
                            ],
                        ],
                    ],
                ],
                '404' => [
                    'description' => $schema->getTitle().' not found',
                    'content'     => [
                        'application/json' => [
                            'schema' => [
                                '$ref' => '#/components/schemas/Error',
                            ],
                        ],
                    ],
                ],
            ],
        ];

    }//end createGetOperation()


    /**
     * Create PUT operation
     *
     * @param object $schema The schema object
     *
     * @return array The operation definition
     */
    private function createPutOperation(object $schema): array
    {
        return [
            'summary'     => 'Update a '.$schema->getTitle().' object',
            'operationId' => 'update'.$this->pascalCase($schema->getTitle()),
            'tags'        => [$schema->getTitle()],
            'description' => 'Update an existing '.$schema->getTitle().' object with the provided data',
            'parameters'  => array_merge(
                [
                    [
                        'name'        => 'id',
                        'in'          => 'path',
                        'required'    => true,
                        'description' => 'Unique identifier of the '.$schema->getTitle().' object to update',
                        'schema'      => [
                            'type'   => 'string',
                            'format' => 'uuid',
                        ],
                    ],
                ],
                $this->createCommonQueryParameters()
            ),
            'requestBody' => [
                'required' => true,
                'content'  => [
                    'application/json' => [
                        'schema' => [
                            '$ref' => '#/components/schemas/'.$this->sanitizeSchemaName($schema->getTitle() ?: 'UnknownSchema'),
                        ],
                    ],
                ],
            ],
            'responses'   => [
                '200' => [
                    'description' => $schema->getTitle().' updated successfully',
                    'content'     => [
                        'application/json' => [
                            'schema' => [
                                '$ref' => '#/components/schemas/'.$this->sanitizeSchemaName($schema->getTitle() ?: 'UnknownSchema'),
                            ],
                        ],
                    ],
                ],
                '404' => [
                    'description' => $schema->getTitle().' not found',
                    'content'     => [
                        'application/json' => [
                            'schema' => [
                                '$ref' => '#/components/schemas/Error',
                            ],
                        ],
                    ],
                ],
            ],
        ];

    }//end createPutOperation()


    /**
     * Create POST operation.
     *
     * @param object $schema The schema object
     *
     * @return array The operation definition
     */
    private function createPostOperation(object $schema): array
    {
        return [
            'summary'     => 'Create a new '.$schema->getTitle().' object',
            'operationId' => 'create'.$this->pascalCase($schema->getTitle()),
            'tags'        => [$schema->getTitle()],
            'description' => 'Create a new '.$schema->getTitle().' object with the provided data',
            'parameters'  => $this->createCommonQueryParameters(),
            'requestBody' => [
                'required' => true,
                'content'  => [
                    'application/json' => [
                        'schema' => [
                            '$ref' => '#/components/schemas/'.$this->sanitizeSchemaName($schema->getTitle() ?: 'UnknownSchema'),
                        ],
                    ],
                ],
            ],
            'responses'   => [
                '201' => [
                    'description' => $schema->getTitle().' created successfully.',
                    'content'     => [
                        'application/json' => [
                            'schema' => [
                                '$ref' => '#/components/schemas/'.$this->sanitizeSchemaName($schema->getTitle() ?: 'UnknownSchema'),
                            ],
                        ],
                    ],
                ],
            ],
        ];

    }//end createPostOperation()


    /**
     * Create DELETE operation
     *
     * @param object $schema The schema object
     *
     * @return array The operation definition
     */
    private function createDeleteOperation(object $schema): array
    {
        return [
            'summary'     => 'Delete a '.$schema->getTitle().' object',
            'operationId' => 'delete'.$this->pascalCase($schema->getTitle()),
            'tags'        => [$schema->getTitle()],
            'description' => 'Delete a specific '.$schema->getTitle().' object by its unique identifier',
            'parameters'  => [
                [
                    'name'        => 'id',
                    'in'          => 'path',
                    'required'    => true,
                    'description' => 'Unique identifier of the '.$schema->getTitle().' object to delete',
                    'schema'      => [
                        'type'   => 'string',
                        'format' => 'uuid',
                    ],
                ],
            ],
            'responses'   => [
                '204' => [
                    'description' => $schema->getTitle().' deleted successfully',
                ],
                '404' => [
                    'description' => $schema->getTitle().' not found',
                    'content'     => [
                        'application/json' => [
                            'schema' => [
                                '$ref' => '#/components/schemas/Error',
                            ],
                        ],
                    ],
                ],
            ],
        ];

    }//end createDeleteOperation()


    /**
     * Create logs operation
     *
     * @param object $schema The schema object
     *
     * @return array The operation definition
     */
    private function createLogsOperation(object $schema): array
    {
        return [
            'summary'     => 'Get audit logs for a '.$schema->getTitle().' object',
            'operationId' => 'getLogs'.$this->pascalCase($schema->getTitle()),
            'tags'        => [$schema->getTitle()],
            'description' => 'Retrieve the audit trail for a specific '.$schema->getTitle().' object',
            'parameters'  => [
                [
                    'name'        => 'id',
                    'in'          => 'path',
                    'required'    => true,
                    'description' => 'Unique identifier of the '.$schema->getTitle().' object',
                    'schema'      => [
                        'type'   => 'string',
                        'format' => 'uuid',
                    ],
                ],
            ],
            'responses'   => [
                '200' => [
                    'description' => 'Audit logs retrieved successfully',
                    'content'     => [
                        'application/json' => [
                            'schema' => [
                                'type'  => 'array',
                                'items' => [
                                    '$ref' => '#/components/schemas/AuditTrail',
                                ],
                            ],
                        ],
                    ],
                ],
                '404' => [
                    'description' => $schema->getTitle().' not found',
                    'content'     => [
                        'application/json' => [
                            'schema' => [
                                '$ref' => '#/components/schemas/Error',
                            ],
                        ],
                    ],
                ],
            ],
        ];

    }//end createLogsOperation()


    /**
     * Create get files operation
     *
     * @param object $schema The schema object
     *
     * @return array The operation definition
     */
    private function createGetFilesOperation(object $schema): array
    {
        return [
            'summary'     => 'Get files for a '.$schema->getTitle().' object',
            'operationId' => 'getFiles'.$this->pascalCase($schema->getTitle()),
            'tags'        => [$schema->getTitle()],
            'description' => 'Retrieve all files associated with a specific '.$schema->getTitle().' object',
            'parameters'  => [
                [
                    'name'        => 'id',
                    'in'          => 'path',
                    'required'    => true,
                    'description' => 'Unique identifier of the '.$schema->getTitle().' object',
                    'schema'      => [
                        'type'   => 'string',
                        'format' => 'uuid',
                    ],
                ],
            ],
            'responses'   => [
                '200' => [
                    'description' => 'Files retrieved successfully',
                    'content'     => [
                        'application/json' => [
                            'schema' => [
                                'type'  => 'array',
                                'items' => [
                                    '$ref' => '#/components/schemas/File',
                                ],
                            ],
                        ],
                    ],
                ],
                '404' => [
                    'description' => $schema->getTitle().' not found',
                    'content'     => [
                        'application/json' => [
                            'schema' => [
                                '$ref' => '#/components/schemas/Error',
                            ],
                        ],
                    ],
                ],
            ],
        ];

    }//end createGetFilesOperation()


    /**
     * Create post file operation
     *
     * @param object $schema The schema object
     *
     * @return array The operation definition
     */
    private function createPostFileOperation(object $schema): array
    {
        return [
            'summary'     => 'Upload a file for a '.$schema->getTitle().' object',
            'operationId' => 'uploadFile'.$this->pascalCase($schema->getTitle()),
            'tags'        => [$schema->getTitle()],
            'description' => 'Upload a new file and associate it with a specific '.$schema->getTitle().' object',
            'parameters'  => [
                [
                    'name'        => 'id',
                    'in'          => 'path',
                    'required'    => true,
                    'description' => 'Unique identifier of the '.$schema->getTitle().' object',
                    'schema'      => [
                        'type'   => 'string',
                        'format' => 'uuid',
                    ],
                ],
            ],
            'requestBody' => [
                'required' => true,
                'content'  => [
                    'multipart/form-data' => [
                        'schema' => [
                            'type'       => 'object',
                            'properties' => [
                                'file' => [
                                    'type'        => 'string',
                                    'format'      => 'binary',
                                    'description' => 'The file to upload',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'responses'   => [
                '201' => [
                    'description' => 'File uploaded successfully',
                    'content'     => [
                        'application/json' => [
                            'schema' => [
                                '$ref' => '#/components/schemas/File',
                            ],
                        ],
                    ],
                ],
                '404' => [
                    'description' => $schema->getTitle().' not found',
                    'content'     => [
                        'application/json' => [
                            'schema' => [
                                '$ref' => '#/components/schemas/Error',
                            ],
                        ],
                    ],
                ],
            ],
        ];

    }//end createPostFileOperation()


    /**
     * Create lock operation
     *
     * @param object $schema The schema object
     *
     * @return array The operation definition
     */
    private function createLockOperation(object $schema): array
    {
        return [
            'summary'     => 'Lock a '.$schema->getTitle().' object',
            'operationId' => 'lock'.$this->pascalCase($schema->getTitle()),
            'tags'        => [$schema->getTitle()],
            'description' => 'Lock a specific '.$schema->getTitle().' object to prevent concurrent modifications',
            'parameters'  => [
                [
                    'name'        => 'id',
                    'in'          => 'path',
                    'required'    => true,
                    'description' => 'Unique identifier of the '.$schema->getTitle().' object to lock',
                    'schema'      => [
                        'type'   => 'string',
                        'format' => 'uuid',
                    ],
                ],
            ],
            'responses'   => [
                '200' => [
                    'description' => 'Object locked successfully',
                    'content'     => [
                        'application/json' => [
                            'schema' => [
                                '$ref' => '#/components/schemas/Lock',
                            ],
                        ],
                    ],
                ],
                '404' => [
                    'description' => $schema->getTitle().' not found',
                    'content'     => [
                        'application/json' => [
                            'schema' => [
                                '$ref' => '#/components/schemas/Error',
                            ],
                        ],
                    ],
                ],
                '409' => [
                    'description' => 'Object is already locked',
                ],
            ],
        ];

    }//end createLockOperation()


    /**
     * Create unlock operation
     *
     * @param object $schema The schema object
     *
     * @return array The operation definition
     */
    private function createUnlockOperation(object $schema): array
    {
        return [
            'summary'     => 'Unlock a '.$schema->getTitle().' object',
            'operationId' => 'unlock'.$this->pascalCase($schema->getTitle()),
            'tags'        => [$schema->getTitle()],
            'description' => 'Remove the lock from a specific '.$schema->getTitle().' object',
            'parameters'  => [
                [
                    'name'        => 'id',
                    'in'          => 'path',
                    'required'    => true,
                    'description' => 'Unique identifier of the '.$schema->getTitle().' object to unlock',
                    'schema'      => [
                        'type'   => 'string',
                        'format' => 'uuid',
                    ],
                ],
            ],
            'responses'   => [
                '200' => [
                    'description' => 'Object unlocked successfully',
                ],
                '404' => [
                    'description' => $schema->getTitle().' not found',
                    'content'     => [
                        'application/json' => [
                            'schema' => [
                                '$ref' => '#/components/schemas/Error',
                            ],
                        ],
                    ],
                ],
                '409' => [
                    'description' => 'Object is not locked or locked by another user',
                ],
            ],
        ];

    }//end createUnlockOperation()


    /**
     * Convert string to slug
     *
     * @param string $string The string to convert
     *
     * @return string The slugified string
     */
    private function slugify(string $string): string
    {
        return strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $string), '-'));

    }//end slugify()


    /**
     * Convert string to PascalCase
     *
     * @param string $string The string to convert
     *
     * @return string The PascalCase string
     */
    private function pascalCase(string $string): string
    {
        return str_replace(' ', '', ucwords(str_replace('-', ' ', $this->slugify($string))));

    }//end pascalCase()


    /**
     * Sanitize schema names to be OpenAPI compliant
     *
     * OpenAPI schema names must match pattern ^[a-zA-Z0-9._-]+$ 
     * This method converts titles with spaces and special characters to valid schema names.
     *
     * @param string|null $title The schema title to sanitize
     *
     * @return string The sanitized schema name
     */
    private function sanitizeSchemaName(?string $title): string
    {
        // Handle null or empty titles.
        if (empty($title)) {
            return 'UnknownSchema';
        }

        // Replace spaces and invalid characters with underscores.
        $sanitized = preg_replace('/[^a-zA-Z0-9._-]/', '_', $title);
        
        // Remove multiple consecutive underscores.
        $sanitized = preg_replace('/_+/', '_', $sanitized);
        
        // Remove leading/trailing underscores.
        $sanitized = trim($sanitized, '_');
        
        // Handle edge case where sanitization results in empty string.
        if (empty($sanitized)) {
            return 'UnknownSchema';
        }
        
        // Ensure it starts with a letter (prepend 'Schema_' if it starts with number).
        if (preg_match('/^[0-9]/', $sanitized)) {
            $sanitized = 'Schema_' . $sanitized;
        }
        
        return $sanitized;
    }//end sanitizeSchemaName()

    
    /**
     * Validate OpenAPI specification integrity
     * 
     * This method checks for common issues that could cause ReDoc or other
     * OpenAPI tools to fail when parsing the specification.
     *
     * @return void
     */
    private function validateOasIntegrity(): void
    {
        // Check for invalid $ref references in schemas.
        if (isset($this->oas['components']['schemas'])) {
            foreach ($this->oas['components']['schemas'] as $schemaName => &$schema) {
                if (is_array($schema)) {
                    $this->validateSchemaReferences($schema, $schemaName);
                }
            }
        }
        
        // Check for invalid allOf constructs in paths.
        if (isset($this->oas['paths'])) {
            foreach ($this->oas['paths'] as $pathName => &$path) {
                foreach ($path as $method => &$operation) {
                    if (isset($operation['responses'])) {
                        foreach ($operation['responses'] as $statusCode => &$response) {
                            if (isset($response['content']['application/json']['schema'])) {
                                $this->validateSchemaReferences($response['content']['application/json']['schema'], "path:{$pathName}:{$method}:response:{$statusCode}");
                            }
                        }
                    }
                }
            }
        }
    }//end validateOasIntegrity()
    
    
    /**
     * Validate schema references recursively
     *
     * @param array  &$schema The schema to validate (passed by reference for modifications)
     * @param string $context Context information for debugging
     *
     * @return void
     */
    private function validateSchemaReferences(array &$schema, string $context): void
    {
        // Check allOf constructs.
        if (isset($schema['allOf'])) {
            if (!is_array($schema['allOf']) || empty($schema['allOf'])) {
                unset($schema['allOf']);
            } else {
                $validAllOfItems = [];
                foreach ($schema['allOf'] as $index => $item) {
                    if (!is_array($item) || empty($item)) {
                    } else {
                        // Validate each allOf item has required structure.
                        if (isset($item['$ref']) && !empty($item['$ref']) && is_string($item['$ref'])) {
                            $validAllOfItems[] = $item;
                        } elseif (isset($item['type']) || isset($item['properties'])) {
                            $validAllOfItems[] = $item;
                        } else {
                        }
                    }
                }
                
                // If no valid items remain, remove allOf.
                if (empty($validAllOfItems)) {
                    unset($schema['allOf']);
                } else {
                    $schema['allOf'] = $validAllOfItems;
                }
            }
        }
        
        // Check $ref validity.
        if (isset($schema['$ref'])) {
            if (empty($schema['$ref']) || !is_string($schema['$ref'])) {
                unset($schema['$ref']);
            } else {
                // Check if reference points to existing schema.
                $refPath = str_replace('#/components/schemas/', '', $schema['$ref']);
                if (strpos($schema['$ref'], '#/components/schemas/') === 0 && 
                    !isset($this->oas['components']['schemas'][$refPath])) {
                }
            }
        }
        
        // Recursively check nested schemas.
        if (isset($schema['properties'])) {
            foreach ($schema['properties'] as $propName => $property) {
                if (is_array($property)) {
                    $this->validateSchemaReferences($property, "{$context}.properties.{$propName}");
                }
            }
        }
        
        if (isset($schema['items']) && is_array($schema['items'])) {
            $this->validateSchemaReferences($schema['items'], "{$context}.items");
        }
    }//end validateSchemaReferences()


}//end class
