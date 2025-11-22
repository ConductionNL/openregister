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
     * @return (((string|string[])[]|string)[]|string)[][] Array of function definitions
     *
     * @psalm-return list<array<string, mixed>>
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
        $this->log($functionName, $parameters);

        if ($this->hasUserContext($userId) === false) {
            return $this->formatError('No user context available. Tool cannot execute without user session.');
        }

        try {
            // Convert snake_case to camelCase for PSR compliance.
            $methodName = lcfirst(str_replace('_', '', ucwords($functionName, '_')));

            // Call the method directly (LLPhant-compatible).
            return $this->$methodName(...array_values($parameters));
        } catch (\Exception $e) {
            $this->log($functionName, $parameters, 'error', $e->getMessage());
            return $this->formatError($e->getMessage());
        }

    }//end executeFunction()


    /**
     * List schemas
     *
     * @param int         $limit    Result limit
     * @param int         $offset   Result offset
     * @param string|null $register Register filter
     *
     * @return array Result with list of schemas
     */
    public function listSchemas(int $limit=100, int $offset=0, ?string $register=null): array
    {
        $filters = [];
        if ($register !== null) {
            $filters['register'] = $register;
        }

        $filters = $this->applyViewFilters($filters);

        $schemas = $this->schemaMapper->findAll($limit, $offset, $filters);

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

        return $this->formatSuccess($schemaList, sprintf(format: ('Found %d schemas', count($schemaList)));

    }//end listSchemas()


    /**
     * Get a specific schema
     *
     * @param string $id Schema ID
     *
     * @return array Result with schema details
     *
     * @throws \Exception If schema not found
     */
    public function getSchema(string $id): array
    {
        $schema = $this->schemaMapper->find($id);

        return $this->formatSuccess(
            [
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
            'Schema retrieved successfully'
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
     * @return array Result with created schema
     *
     * @throws \Exception If creation fails
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

        $schema = $this->schemaMapper->createFromArray($data);

        return $this->formatSuccess(
            [
                'id'          => $schema->getId(),
                'uuid'        => $schema->getUuid(),
                'title'       => $schema->getTitle(),
                'description' => $schema->getDescription(),
                'version'     => $schema->getVersion(),
                'properties'  => $schema->getProperties(),
            ],
            'Schema created successfully'
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
     * @return array Result with updated schema
     *
     * @throws \Exception If update fails
     */
    public function updateSchema(string $id, ?string $title=null, ?string $description=null, ?array $properties=null, ?array $required=null): array
    {
        $schema = $this->schemaMapper->find($id);

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

        $schema = $this->schemaMapper->update($schema);

        return $this->formatSuccess(
            [
                'id'          => $schema->getId(),
                'uuid'        => $schema->getUuid(),
                'title'       => $schema->getTitle(),
                'description' => $schema->getDescription(),
                'version'     => $schema->getVersion(),
                'properties'  => $schema->getProperties(),
            ],
            'Schema updated successfully'
        );

    }//end updateSchema()


    /**
     * Delete a schema
     *
     * @param string $id Schema ID
     *
     * @return array Result of deletion
     *
     * @throws \Exception If deletion fails
     */
    public function deleteSchema(string $id): array
    {
        $schema = $this->schemaMapper->find($id);
        $this->schemaMapper->delete($schema);

        return $this->formatSuccess(
            ['id' => $id],
            'Schema deleted successfully'
        );

    }//end deleteSchema()


}//end class
