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

        // If specific register, update info.
        if ($registerId !== null) {
            $register          = $registers[0];
            $this->oas['info'] = [
                'title'       => $register->getTitle().' API',
                'version'     => $register->getVersion(),
                'description' => $register->getDescription(),
            ];
        }

        // Initialize tags array.
        $this->oas['tags'] = [];

        // Add schemas to components and create tags.
        foreach ($schemas as $schema) {
            // Add schema to components.
            $schemaDefinition = $this->enrichSchema($schema);
            $this->oas['components']['schemas'][$schema->getTitle()] = $schemaDefinition;

            // Add tag for the schema.
            $this->oas['tags'][] = [
                'name'        => $schema->getTitle(),
                'description' => $schema->getDescription() ?? 'Operations for '.$schema->getTitle(),
            ];
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
     * Enrich a schema with @self property and x-tags.
     *
     * @param object $schema The schema object
     *
     * @return array The enriched schema definition
     */
    private function enrichSchema(object $schema): array
    {
        $schemaDefinition = $schema->getProperties();

        // Add @self reference, id, lastLog, and x-tags for schema categorization.
        return [
            'type'       => 'object',
            'x-tags'     => [$schema->getTitle()],
            'properties' => [
                '@self' => [
                    '$ref'        => '#/components/schemas/@self',
                    'readOnly'    => true,
                    'description' => 'The metadata of the object e.g. owner, created, modified, etc.',
                ],
                'id'    => [
                    'type'        => 'string',
                    'format'      => 'uuid',
                    'readOnly'    => true,
                    'example'     => '123e4567-e89b-12d3-a456-426614174000',
                    'description' => 'The unique identifier for the object.',
                ],
                'lastLog' => [
                    'type'        => 'object',
                    'nullable'    => true,
                    'description' => 'The most recent log entry for this object (runtime only, not persisted in the database).',
                ],
            ] + $schemaDefinition,
        ];

    }//end enrichSchema()


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

        // Collection endpoints with path-level tags.
        $this->oas['paths'][$basePath] = [
            'tags' => [$schema->getTitle()],
            // Add tags at path level.
            'get'  => $this->createGetCollectionOperation($schema),
            'post' => $this->createPostOperation($schema),
        ];

        // Individual resource endpoints with path-level tags.
        $this->oas['paths'][$basePath.'/{id}'] = [
            'tags'   => [$schema->getTitle()],
            // Add tags at path level.
            'get'    => $this->createGetOperation($schema),
            'put'    => $this->createPutOperation($schema),
            'delete' => $this->createDeleteOperation($schema),
        ];

    }//end addCrudPaths()


    /**
     * Add extended paths for a schema (logs, files, lock, unlock).
     *
     * @param object $register The register object
     * @param object $schema   The schema object
     *
     * @return void
     */
    private function addExtendedPaths(object $register, object $schema): void
    {
        $basePath = '/'.$this->slugify($register->getTitle()).'/'.$this->slugify($schema->getTitle());

        // Logs endpoint with path-level tags.
        $this->oas['paths'][$basePath.'/{id}/audit-trails'] = [
            'tags' => [$schema->getTitle()],
            // Add tags at path level.
            'get'  => $this->createLogsOperation($schema),
        ];

        // Files endpoints with path-level tags.
        $this->oas['paths'][$basePath.'/{id}/files'] = [
            'tags' => [$schema->getTitle()],
            // Add tags at path level.
            'get'  => $this->createGetFilesOperation($schema),
            'post' => $this->createPostFileOperation($schema),
        ];

        // Lock/Unlock endpoints with path-level tags.
        $this->oas['paths'][$basePath.'/{id}/lock'] = [
            'tags' => [$schema->getTitle()],
            // Add tags at path level.
            'post' => $this->createLockOperation($schema),
        ];
        $this->oas['paths'][$basePath.'/{id}/unlock'] = [
            'tags' => [$schema->getTitle()],
            // Add tags at path level.
            'post' => $this->createUnlockOperation($schema),
        ];

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
                    // Skip internal properties and metadata.
                    if (str_starts_with($propertyName, '@') === true || $propertyName === 'id') {
                        continue;
                    }

                    // Get property type from definition.
                    $propertyType = $this->getPropertyType($propertyDefinition);

                    $parameters[] = [
                        'name'        => $propertyName,
                        'in'          => 'query',
                        'required'    => false,
                        'description' => 'Filter results by '.$propertyName,
                        'schema'      => [
                            'type' => $propertyType,
                        ],
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
        return [
            'summary'     => 'Get all '.$schema->getTitle().' objects',
            'operationId' => 'getAll'.$this->pascalCase($schema->getTitle()),
            'tags'        => [$schema->getTitle()],
            'description' => 'Retrieve a list of all '.$schema->getTitle().' objects',
            'parameters'  => $this->createCommonQueryParameters(true, $schema),
            'responses'   => [
                '200' => [
                    'description' => 'List of '.$schema->getTitle().' objects',
                    'content'     => [
                        'application/json' => [
                            'schema' => [
                                'type'  => 'array',
                                'items' => [
                                    '$ref' => '#/components/schemas/'.$schema->getTitle(),
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
                                '$ref' => '#/components/schemas/'.$schema->getTitle(),
                            ],
                        ],
                    ],
                ],
                '404' => [
                    'description' => $schema->getTitle().' not found.',
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
                            '$ref' => '#/components/schemas/'.$schema->getTitle(),
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
                                '$ref' => '#/components/schemas/'.$schema->getTitle(),
                            ],
                        ],
                    ],
                ],
                '404' => [
                    'description' => $schema->getTitle().' not found',
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
                            '$ref' => '#/components/schemas/'.$schema->getTitle(),
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
                                '$ref' => '#/components/schemas/'.$schema->getTitle(),
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


}//end class
