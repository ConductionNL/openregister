<?php
/**
 * OpenRegister OpenAPI Service
 *
 * This file contains the service class for generating OpenAPI documentation
 * based on schemas and CRUD operations in the OpenRegister application.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Ruben Linde <ruben@nextcloud.com>
 * @copyright Copyright (c) 2024, Ruben Linde (https://github.com/rubenlinde)
 * @license   AGPL-3.0
 * @version   1.0.0
 * @link      https://github.com/cloud-py-api/openregister
 */

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * Class OpenApiService
 *
 * Service for generating OpenAPI documentation based on schemas and CRUD operations
 *
 * @package OCA\OpenRegister\Service
 */
class OpenApiService {
    /**
     * @var SchemaMapper The schema mapper instance
     */
    private SchemaMapper $schemaMapper;

    /**
     * @var IURLGenerator The URL generator instance
     */
    private IURLGenerator $urlGenerator;

    /**
     * @var LoggerInterface The logger instance
     */
    private LoggerInterface $logger;

    /**
     * @var string The base path for API endpoints
     */
    private string $basePath;

    /**
     * Constructor
     *
     * @param SchemaMapper    $schemaMapper  The schema mapper instance
     * @param IURLGenerator   $urlGenerator  The URL generator instance
     * @param LoggerInterface $logger        The logger instance
     */
    public function __construct(
        SchemaMapper $schemaMapper,
        IURLGenerator $urlGenerator,
        LoggerInterface $logger
    ) {
        $this->schemaMapper = $schemaMapper;
        $this->urlGenerator = $urlGenerator;
        $this->logger = $logger;
        $this->basePath = '/apps/openregister/api/v1';
    }

    /**
     * Generate complete OpenAPI documentation
     *
     * @return array The complete OpenAPI documentation
     */
    public function generateOpenApi(): array {
        $schemas = $this->schemaMapper->findAll();
        
        $openApi = [
            'openapi' => '3.0.0',
            'info' => [
                'title' => 'OpenRegister API',
                'description' => 'API for managing objects in the OpenRegister application',
                'version' => '1.0.0',
                'license' => [
                    'name' => 'AGPL-3.0',
                    'url' => 'https://www.gnu.org/licenses/agpl-3.0.en.html'
                ]
            ],
            'servers' => [
                [
                    'url' => $this->urlGenerator->getAbsoluteURL($this->basePath),
                    'description' => 'OpenRegister API Server'
                ]
            ],
            'paths' => [],
            'components' => [
                'schemas' => [],
                'securitySchemes' => [
                    'basic_auth' => [
                        'type' => 'http',
                        'scheme' => 'basic'
                    ]
                ]
            ],
            'security' => [
                ['basic_auth' => []]
            ]
        ];

        foreach ($schemas as $schema) {
            $this->addSchemaComponents($openApi, $schema);
            $this->addSchemaPaths($openApi, $schema);
        }

        return $openApi;
    }

    /**
     * Add schema components to OpenAPI documentation
     *
     * @param array  $openApi The OpenAPI documentation array
     * @param Schema $schema  The schema to add
     *
     * @return void
     */
    private function addSchemaComponents(array &$openApi, Schema $schema): void {
        $schemaName = $schema->getTitle();
        
        // Add the main schema
        $openApi['components']['schemas'][$schemaName] = [
            'type' => 'object',
            'description' => $schema->getDescription(),
            'properties' => $this->convertToOpenApiProperties($schema->getProperties()),
            'required' => $schema->getRequired()
        ];

        // Add paginated response schema
        $openApi['components']['schemas'][$schemaName . 'PaginatedResponse'] = [
            'type' => 'object',
            'properties' => [
                'total' => [
                    'type' => 'integer',
                    'description' => 'Total number of items'
                ],
                'page' => [
                    'type' => 'integer',
                    'description' => 'Current page number'
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Number of items per page'
                ],
                'results' => [
                    'type' => 'array',
                    'items' => [
                        '$ref' => '#/components/schemas/' . $schemaName
                    ]
                ]
            ]
        ];

        // Add error response schema
        $openApi['components']['schemas']['Error'] = [
            'type' => 'object',
            'properties' => [
                'code' => [
                    'type' => 'integer',
                    'description' => 'HTTP status code'
                ],
                'message' => [
                    'type' => 'string',
                    'description' => 'Error message'
                ]
            ]
        ];
    }

    /**
     * Add schema paths to OpenAPI documentation
     *
     * @param array  $openApi The OpenAPI documentation array
     * @param Schema $schema  The schema to add paths for
     *
     * @return void
     */
    private function addSchemaPaths(array &$openApi, Schema $schema): void {
        $schemaName = $schema->getTitle();
        $basePath = "/objects/{register}/{schema}";
        
        // List objects
        $openApi['paths'][$basePath] = [
            'get' => [
                'summary' => "List {$schemaName} objects",
                'description' => "Retrieve a paginated list of {$schemaName} objects",
                'parameters' => [
                    [
                        'name' => 'register',
                        'in' => 'path',
                        'required' => true,
                        'schema' => ['type' => 'string']
                    ],
                    [
                        'name' => 'schema',
                        'in' => 'path',
                        'required' => true,
                        'schema' => ['type' => 'string']
                    ],
                    [
                        'name' => 'page',
                        'in' => 'query',
                        'schema' => ['type' => 'integer', 'default' => 1]
                    ],
                    [
                        'name' => 'limit',
                        'in' => 'query',
                        'schema' => ['type' => 'integer', 'default' => 10]
                    ],
                    [
                        'name' => 'search',
                        'in' => 'query',
                        'schema' => ['type' => 'string']
                    ]
                ],
                'responses' => [
                    '200' => [
                        'description' => 'Successful operation',
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    '$ref' => "#/components/schemas/{$schemaName}PaginatedResponse"
                                ]
                            ]
                        ]
                    ],
                    '400' => $this->getErrorResponse('Invalid request parameters'),
                    '401' => $this->getErrorResponse('Unauthorized'),
                    '403' => $this->getErrorResponse('Forbidden'),
                    '404' => $this->getErrorResponse('Register or schema not found')
                ]
            ],
            'post' => [
                'summary' => "Create a new {$schemaName} object",
                'description' => "Create a new {$schemaName} object with the provided data",
                'parameters' => [
                    [
                        'name' => 'register',
                        'in' => 'path',
                        'required' => true,
                        'schema' => ['type' => 'string']
                    ],
                    [
                        'name' => 'schema',
                        'in' => 'path',
                        'required' => true,
                        'schema' => ['type' => 'string']
                    ]
                ],
                'requestBody' => [
                    'required' => true,
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                '$ref' => "#/components/schemas/{$schemaName}"
                            ]
                        ]
                    ]
                ],
                'responses' => [
                    '201' => [
                        'description' => 'Object created successfully',
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    '$ref' => "#/components/schemas/{$schemaName}"
                                ]
                            ]
                        ]
                    ],
                    '400' => $this->getErrorResponse('Invalid request data'),
                    '401' => $this->getErrorResponse('Unauthorized'),
                    '403' => $this->getErrorResponse('Forbidden'),
                    '422' => $this->getErrorResponse('Validation error')
                ]
            ]
        ];

        // Individual object operations
        $openApi['paths'][$basePath . '/{id}'] = [
            'get' => [
                'summary' => "Get a specific {$schemaName} object",
                'parameters' => [
                    [
                        'name' => 'register',
                        'in' => 'path',
                        'required' => true,
                        'schema' => ['type' => 'string']
                    ],
                    [
                        'name' => 'schema',
                        'in' => 'path',
                        'required' => true,
                        'schema' => ['type' => 'string']
                    ],
                    [
                        'name' => 'id',
                        'in' => 'path',
                        'required' => true,
                        'schema' => ['type' => 'string']
                    ]
                ],
                'responses' => [
                    '200' => [
                        'description' => 'Successful operation',
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    '$ref' => "#/components/schemas/{$schemaName}"
                                ]
                            ]
                        ]
                    ],
                    '404' => $this->getErrorResponse('Object not found')
                ]
            ],
            'put' => [
                'summary' => "Update a {$schemaName} object",
                'parameters' => [
                    [
                        'name' => 'register',
                        'in' => 'path',
                        'required' => true,
                        'schema' => ['type' => 'string']
                    ],
                    [
                        'name' => 'schema',
                        'in' => 'path',
                        'required' => true,
                        'schema' => ['type' => 'string']
                    ],
                    [
                        'name' => 'id',
                        'in' => 'path',
                        'required' => true,
                        'schema' => ['type' => 'string']
                    ]
                ],
                'requestBody' => [
                    'required' => true,
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                '$ref' => "#/components/schemas/{$schemaName}"
                            ]
                        ]
                    ]
                ],
                'responses' => [
                    '200' => [
                        'description' => 'Object updated successfully',
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    '$ref' => "#/components/schemas/{$schemaName}"
                                ]
                            ]
                        ]
                    ],
                    '400' => $this->getErrorResponse('Invalid request data'),
                    '404' => $this->getErrorResponse('Object not found'),
                    '422' => $this->getErrorResponse('Validation error')
                ]
            ],
            'delete' => [
                'summary' => "Delete a {$schemaName} object",
                'parameters' => [
                    [
                        'name' => 'register',
                        'in' => 'path',
                        'required' => true,
                        'schema' => ['type' => 'string']
                    ],
                    [
                        'name' => 'schema',
                        'in' => 'path',
                        'required' => true,
                        'schema' => ['type' => 'string']
                    ],
                    [
                        'name' => 'id',
                        'in' => 'path',
                        'required' => true,
                        'schema' => ['type' => 'string']
                    ]
                ],
                'responses' => [
                    '204' => [
                        'description' => 'Object deleted successfully'
                    ],
                    '404' => $this->getErrorResponse('Object not found')
                ]
            ]
        ];

        // Add audit trail endpoint
        $openApi['paths'][$basePath . '/{id}/audit-trail'] = [
            'get' => [
                'summary' => "Get audit trail for a {$schemaName} object",
                'parameters' => [
                    [
                        'name' => 'register',
                        'in' => 'path',
                        'required' => true,
                        'schema' => ['type' => 'string']
                    ],
                    [
                        'name' => 'schema',
                        'in' => 'path',
                        'required' => true,
                        'schema' => ['type' => 'string']
                    ],
                    [
                        'name' => 'id',
                        'in' => 'path',
                        'required' => true,
                        'schema' => ['type' => 'string']
                    ],
                    [
                        'name' => 'page',
                        'in' => 'query',
                        'schema' => ['type' => 'integer', 'default' => 1]
                    ],
                    [
                        'name' => 'limit',
                        'in' => 'query',
                        'schema' => ['type' => 'integer', 'default' => 10]
                    ]
                ],
                'responses' => [
                    '200' => [
                        'description' => 'Successful operation',
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'total' => ['type' => 'integer'],
                                        'page' => ['type' => 'integer'],
                                        'limit' => ['type' => 'integer'],
                                        'results' => [
                                            'type' => 'array',
                                            'items' => [
                                                'type' => 'object',
                                                'properties' => [
                                                    'action' => ['type' => 'string'],
                                                    'user' => ['type' => 'string'],
                                                    'timestamp' => ['type' => 'string', 'format' => 'date-time'],
                                                    'changes' => ['type' => 'object']
                                                ]
                                            ]
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ],
                    '404' => $this->getErrorResponse('Object not found')
                ]
            ]
        ];
    }

    /**
     * Convert schema properties to OpenAPI format
     *
     * @param array $properties The schema properties
     *
     * @return array The OpenAPI properties
     */
    private function convertToOpenApiProperties(array $properties): array {
        $converted = [];

        foreach ($properties as $propertyName => $property) {
            $converted[$propertyName] = [
                'type' => $property['type'],
                'description' => $property['description'] ?? ''
            ];

            if (isset($property['format'])) {
                $converted[$propertyName]['format'] = $property['format'];
            }

            if (isset($property['enum'])) {
                $converted[$propertyName]['enum'] = $property['enum'];
            }

            if ($property['type'] === 'object' && isset($property['properties'])) {
                $converted[$propertyName]['properties'] = $this->convertToOpenApiProperties($property['properties']);
                if (isset($property['required'])) {
                    $converted[$propertyName]['required'] = $property['required'];
                }
            }

            if ($property['type'] === 'array' && isset($property['items'])) {
                $converted[$propertyName]['items'] = [
                    'type' => $property['items']['type']
                ];
                if (isset($property['items']['format'])) {
                    $converted[$propertyName]['items']['format'] = $property['items']['format'];
                }
            }

            // Add validation constraints
            if (isset($property['minimum'])) {
                $converted[$propertyName]['minimum'] = $property['minimum'];
            }
            if (isset($property['maximum'])) {
                $converted[$propertyName]['maximum'] = $property['maximum'];
            }
            if (isset($property['pattern'])) {
                $converted[$propertyName]['pattern'] = $property['pattern'];
            }
        }

        return $converted;
    }

    /**
     * Get a standard error response object
     *
     * @param string $description The error description
     *
     * @return array The error response object
     */
    private function getErrorResponse(string $description): array {
        return [
            'description' => $description,
            'content' => [
                'application/json' => [
                    'schema' => [
                        '$ref' => '#/components/schemas/Error'
                    ]
                ]
            ]
        ];
    }
} 