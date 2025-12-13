<?php
/**
 * OpenRegister Schema Tool
 *
 * LLphant function tool for managing schemas through natural language.
 * Provides CRUD operations on schemas with RBAC and multi-tenancy support.
 *
 * @category Tool
 * @package  OCA\OpenRegister\Tool
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Tool;

use OCA\OpenRegister\Db\SchemaMapper;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Schema Tool
 *
 * Allows agents to manage schemas in OpenRegister.
 * Use this tool when users ask to:
 * - View available schemas
 * - Get details about a specific schema
 * - Create a new schema
 * - Update schema properties
 * - Delete a schema
 *
 * All operations respect user permissions and organization boundaries.
 *
 * @category Tool
 * @package  OCA\OpenRegister\Tool
 */
class SchemaTool extends AbstractTool
{

    /**
     * Schema mapper
     *
     * @var SchemaMapper
     */
    private SchemaMapper $schemaMapper;


    /**
     * Constructor
     *
     * @param IUserSession    $userSession  User session service
     * @param LoggerInterface $logger       Logger service
     * @param SchemaMapper    $schemaMapper Schema mapper
     */
    public function __construct(
        IUserSession $userSession,
        LoggerInterface $logger,
        SchemaMapper $schemaMapper
    ) {
        parent::__construct($userSession, $logger);
        $this->schemaMapper = $schemaMapper;

    }//end __construct()


    /**
     * Get tool name
     *
     * @return string Tool name
     *
     * @psalm-return 'schema'
     */
    public function getName(): string
    {
        return 'schema';

    }//end getName()


    /**
     * Get tool description
     *
     * @return string Tool description
     *
     * @psalm-return 'Manage schemas: list, view, create, update, or delete schemas. Schemas define structure and validation rules.'
     */
    public function getDescription(): string
    {
        return 'Manage schemas: list, view, create, update, or delete schemas. Schemas define structure and validation rules.';

    }//end getDescription()


    /**
     * Get function definitions for LLphant
     *
     * @return (((string|string[])[]|string)[]|string)[][]
     *
     * @psalm-return list{array{name: 'list_schemas', description: 'Get a list of all accessible schemas', parameters: array{type: 'object', properties: array{limit: array{type: 'integer', description: 'Maximum number of schemas to return (default: 100)'}, offset: array{type: 'integer', description: 'Number of schemas to skip for pagination (default: 0)'}, register: array{type: 'string', description: 'Filter schemas by register ID (optional)'}}, required: array<never, never>}}, array{name: 'get_schema', description: 'Get details about a specific schema by ID', parameters: array{type: 'object', properties: array{id: array{type: 'string', description: 'The schema ID to retrieve'}}, required: list{'id'}}}, array{name: 'create_schema', description: 'Create a new schema with properties definition', parameters: array{type: 'object', properties: array{title: array{type: 'string', description: 'The title of the schema'}, description: array{type: 'string', description: 'A description of what this schema represents'}, properties: array{type: 'object', description: 'JSON Schema properties definition'}, required: array{type: 'array', description: 'Array of required property names'}}, required: list{'title', 'properties'}}}, array{name: 'update_schema', description: 'Update an existing schema', parameters: array{type: 'object', properties: array{id: array{type: 'string', description: 'The schema ID to update'}, title: array{type: 'string', description: 'New title for the schema'}, description: array{type: 'string', description: 'New description for the schema'}, properties: array{type: 'object', description: 'Updated JSON Schema properties definition'}}, required: list{'id'}}}, array{name: 'delete_schema', description: 'Delete a schema (only if it has no objects)', parameters: array{type: 'object', properties: array{id: array{type: 'string', description: 'The schema ID to delete'}}, required: list{'id'}}}}
     */
    public function getFunctions(): array
    {
        return [
            [
                'name'        => 'list_schemas',
                'description' => 'Get a list of all accessible schemas',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'limit'    => [
                            'type'        => 'integer',
                            'description' => 'Maximum number of schemas to return (default: 100)',
                        ],
                        'offset'   => [
                            'type'        => 'integer',
                            'description' => 'Number of schemas to skip for pagination (default: 0)',
                        ],
                        'register' => [
                            'type'        => 'string',
                            'description' => 'Filter schemas by register ID (optional)',
                        ],
                    ],
                    'required'   => [],
                ],
            ],
            [
                'name'        => 'get_schema',
                'description' => 'Get details about a specific schema by ID',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'id' => [
                            'type'        => 'string',
                            'description' => 'The schema ID to retrieve',
                        ],
                    ],
                    'required'   => ['id'],
                ],
            ],
            [
                'name'        => 'create_schema',
                'description' => 'Create a new schema with properties definition',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'title'       => [
                            'type'        => 'string',
                            'description' => 'The title of the schema',
                        ],
                        'description' => [
                            'type'        => 'string',
                            'description' => 'A description of what this schema represents',
                        ],
                        'properties'  => [
                            'type'        => 'object',
                            'description' => 'JSON Schema properties definition',
                        ],
                        'required'    => [
                            'type'        => 'array',
                            'description' => 'Array of required property names',
                        ],
                    ],
                    'required'   => ['title', 'properties'],
                ],
            ],
            [
                'name'        => 'update_schema',
                'description' => 'Update an existing schema',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'id'          => [
                            'type'        => 'string',
                            'description' => 'The schema ID to update',
                        ],
                        'title'       => [
                            'type'        => 'string',
                            'description' => 'New title for the schema',
                        ],
                        'description' => [
                            'type'        => 'string',
                            'description' => 'New description for the schema',
                        ],
                        'properties'  => [
                            'type'        => 'object',
                            'description' => 'Updated JSON Schema properties definition',
                        ],
                    ],
                    'required'   => ['id'],
                ],
            ],
            [
                'name'        => 'delete_schema',
                'description' => 'Delete a schema (only if it has no objects)',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'id' => [
                            'type'        => 'string',
                            'description' => 'The schema ID to delete',
                        ],
                    ],
                    'required'   => ['id'],
                ],
            ],
        ];

    }//end getFunctions()


    /**
     * Execute a function
     *
     * @param string      $functionName Function name
     * @param array       $parameters   Function parameters
     * @param string|null $userId       User ID for context
     *
     * @return array Function result
     *
     * @throws \Exception If function execution fails
     */
    public function executeFunction(string $functionName, array $parameters, ?string $userId=null): array
    {
            $this->log(functionName: $functionName, parameters: $parameters);

        if ($this->hasUserContext($userId) === false) {
            return $this->formatError(message: 'No user context available. Tool cannot execute without user session.');
        }

        try {
            // Convert snake_case to camelCase for PSR compliance.
            $methodName = lcfirst(str_replace('_', '', ucwords($functionName, '_')));

            // Call the method directly (LLPhant-compatible).
            return $this->$methodName(...array_values($parameters));
        } catch (\Exception $e) {
            $this->log(functionName: $functionName, parameters: $parameters, level: 'error', message: $e->getMessage());
            return $this->formatError(message: $e->getMessage());
        }

    }//end executeFunction()


    /**
     * List schemas
     *
     * @param int         $limit    Result limit
     * @param int         $offset   Result offset
     * @param string|null $register Register filter
     *
     * @return (mixed|string|true)[] Result with list of schemas
     *
     * @psalm-return array{success: true, message: string, data: mixed}
     */
    public function listSchemas(int $limit=100, int $offset=0, ?string $register=null): array
    {
        $filters = [];
        if ($register !== null) {
            $filters['register'] = $register;
        }

        $filters = $this->applyViewFilters($filters);

        $schemas = $this->schemaMapper->findAll(limit: $limit, offset: $offset, filters: $filters);

        $schemaList = array_map(
                function ($schema) {
                    return [
                        'id'          => $schema->getId(),
                        'uuid'        => $schema->getUuid(),
                        'title'       => $schema->getTitle(),
                        'description' => $schema->getDescription(),
                        'version'     => $schema->getVersion(),
                    ];
                },
                $schemas
                );

        return $this->formatSuccess(data: $schemaList, message: sprintf('Found %d schemas', count($schemaList)));

    }//end listSchemas()


    /**
     * Get a specific schema
     *
     * @param string $id Schema ID
     *
     * @return (mixed|string|true)[] Result with schema details
     *
     * @throws \Exception If schema not found
     *
     * @psalm-return array{success: true, message: string, data: mixed}
     */
    public function getSchema(string $id): array
    {
        $schema = $this->schemaMapper->find(id: $id);

        return $this->formatSuccess(
            data: [
                'id'           => $schema->getId(),
                'uuid'         => $schema->getUuid(),
                'title'        => $schema->getTitle(),
                'description'  => $schema->getDescription(),
                'version'      => $schema->getVersion(),
                'properties'   => $schema->getProperties(),
                'required'     => $schema->getRequired(),
                'allOf'        => $schema->getAllOf(),
                'oneOf'        => $schema->getOneOf(),
                'anyOf'        => $schema->getAnyOf(),
                'organisation' => $schema->getOrganisation(),
                'created'      => $schema->getCreated()?->format('Y-m-d H:i:s'),
                'updated'      => $schema->getUpdated()?->format('Y-m-d H:i:s'),
            ],
            message: 'Schema retrieved successfully'
        );

    }//end getSchema()


    /**
     * Create a new schema
     *
     * @param string     $title       Schema title
     * @param array      $properties  Schema properties
     * @param string     $description Schema description
     * @param array|null $required    Required properties
     *
     * @return (mixed|string|true)[] Result with created schema
     *
     * @throws \Exception If creation fails
     *
     * @psalm-return array{success: true, message: string, data: mixed}
     */
    public function createSchema(string $title, array $properties, string $description='', ?array $required=null): array
    {
        $data = [
            'title'       => $title,
            'description' => $description,
            'properties'  => $properties,
        ];

        if ($required !== null) {
            $data['required'] = $required;
        }

        $schema = $this->schemaMapper->createFromArray(object: $data);

        return $this->formatSuccess(
            data: [
                'id'          => $schema->getId(),
                'uuid'        => $schema->getUuid(),
                'title'       => $schema->getTitle(),
                'description' => $schema->getDescription(),
                'version'     => $schema->getVersion(),
                'properties'  => $schema->getProperties(),
            ],
            message: 'Schema created successfully'
        );

    }//end createSchema()


    /**
     * Update an existing schema
     *
     * @param string      $id          Schema ID
     * @param string|null $title       Schema title
     * @param string|null $description Schema description
     * @param array|null  $properties  Schema properties
     * @param array|null  $required    Required properties
     *
     * @return (mixed|string|true)[] Result with updated schema
     *
     * @throws \Exception If update fails
     *
     * @psalm-return array{success: true, message: string, data: mixed}
     */
    public function updateSchema(string $id, ?string $title=null, ?string $description=null, ?array $properties=null, ?array $required=null): array
    {
        $schema = $this->schemaMapper->find(id: $id);

        if ($title !== null) {
            $schema->setTitle($title);
        }

        if ($description !== null) {
            $schema->setDescription($description);
        }

        if ($properties !== null) {
            $schema->setProperties($properties);
        }

        if ($required !== null) {
            $schema->setRequired($required);
        }

        $schema = $this->schemaMapper->update(entity: $schema);

        return $this->formatSuccess(
            data: [
                'id'          => $schema->getId(),
                'uuid'        => $schema->getUuid(),
                'title'       => $schema->getTitle(),
                'description' => $schema->getDescription(),
                'version'     => $schema->getVersion(),
                'properties'  => $schema->getProperties(),
            ],
            message: 'Schema updated successfully'
        );

    }//end updateSchema()


    /**
     * Delete a schema
     *
     * @param string $id Schema ID
     *
     * @return (mixed|string|true)[] Result of deletion
     *
     * @throws \Exception If deletion fails
     *
     * @psalm-return array{success: true, message: string, data: mixed}
     */
    public function deleteSchema(string $id): array
    {
        $schema = $this->schemaMapper->find(id: $id);
        $this->schemaMapper->delete(entity: $schema);

        return $this->formatSuccess(
            data: ['id' => $id],
            message: 'Schema deleted successfully'
        );

    }//end deleteSchema()


}//end class
