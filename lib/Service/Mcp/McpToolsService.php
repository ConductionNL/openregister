<?php

/**
 * MCP Tools Service
 *
 * Handles MCP standard tool listing and execution for the OpenRegister
 * MCP server. Provides CRUD tools for registers, schemas, and objects.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Mcp;

use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\RegisterService;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;

/**
 * McpToolsService handles MCP tool operations
 *
 * Provides tool definitions and execution for the three core
 * OpenRegister entities: registers, schemas, and objects.
 *
 * @psalm-suppress UnusedClass - Injected via DI container
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class McpToolsService
{

    /**
     * McpToolsService constructor
     *
     * @param RegisterService $registerService Register service
     * @param SchemaMapper    $schemaMapper    Schema database mapper
     * @param ObjectService   $objectService   Object service facade
     * @param LoggerInterface $logger          Logger
     */
    public function __construct(
        private readonly RegisterService $registerService,
        private readonly SchemaMapper $schemaMapper,
        private readonly ObjectService $objectService,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * List available MCP tools
     *
     * Returns tool definitions for registers, schemas, and objects.
     *
     * @return array{tools: array} MCP tools/list response
     */
    public function listTools(): array
    {
        return [
            'tools' => [
                $this->getRegistersTool(),
                $this->getSchemasTool(),
                $this->getObjectsTool(),
            ],
        ];
    }//end listTools()

    /**
     * Execute an MCP tool
     *
     * @param string $name      Tool name
     * @param array  $arguments Tool arguments
     *
     * @return array MCP tool result with content array
     *
     * @throws \InvalidArgumentException If tool name is unknown
     */
    public function callTool(string $name, array $arguments): array
    {
        $this->logger->debug(
            message: '[MCP] Tool call',
            context: ['tool' => $name, 'arguments' => $arguments]
        );

        try {
            $result = match ($name) {
                'registers' => $this->executeRegisters(arguments: $arguments),
                'schemas'   => $this->executeSchemas(arguments: $arguments),
                'objects'   => $this->executeObjects(arguments: $arguments),
                default     => throw new \InvalidArgumentException(
                    message: 'Unknown tool: '.$name
                ),
            };

            return [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => json_encode(value: $result, flags: JSON_PRETTY_PRINT),
                    ],
                ],
                'isError' => false,
            ];
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[MCP] Tool execution failed',
                context: ['tool' => $name, 'error' => $e->getMessage()]
            );

            return [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => json_encode(value: ['error' => $e->getMessage()]),
                    ],
                ],
                'isError' => true,
            ];
        }//end try
    }//end callTool()

    /**
     * Get the registers tool definition
     *
     * @return array MCP tool definition
     */
    private function getRegistersTool(): array
    {
        return [
            'name'        => 'registers',
            'description' => 'Manage registers (data containers that group schemas and objects)',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'action' => [
                        'type'        => 'string',
                        'enum'        => ['list', 'get', 'create', 'update', 'delete'],
                        'description' => 'The CRUD action to perform',
                    ],
                    'id'     => [
                        'type'        => 'integer',
                        'description' => 'Register ID (required for get, update, delete)',
                    ],
                    'data'   => [
                        'type'        => 'object',
                        'description' => 'Register fields (for create and update)',
                    ],
                    'limit'  => [
                        'type'        => 'integer',
                        'description' => 'Maximum number of results (for list)',
                    ],
                    'offset' => [
                        'type'        => 'integer',
                        'description' => 'Number of results to skip (for list)',
                    ],
                ],
                'required' => ['action'],
            ],
        ];
    }//end getRegistersTool()

    /**
     * Get the schemas tool definition
     *
     * @return array MCP tool definition
     */
    private function getSchemasTool(): array
    {
        return [
            'name'        => 'schemas',
            'description' => 'Manage schemas (data definitions that describe the structure of objects)',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'action' => [
                        'type'        => 'string',
                        'enum'        => ['list', 'get', 'create', 'update', 'delete'],
                        'description' => 'The CRUD action to perform',
                    ],
                    'id'     => [
                        'type'        => 'integer',
                        'description' => 'Schema ID (required for get, update, delete)',
                    ],
                    'data'   => [
                        'type'        => 'object',
                        'description' => 'Schema fields (for create and update)',
                    ],
                    'limit'  => [
                        'type'        => 'integer',
                        'description' => 'Maximum number of results (for list)',
                    ],
                    'offset' => [
                        'type'        => 'integer',
                        'description' => 'Number of results to skip (for list)',
                    ],
                ],
                'required' => ['action'],
            ],
        ];
    }//end getSchemasTool()

    /**
     * Get the objects tool definition
     *
     * @return array MCP tool definition
     */
    private function getObjectsTool(): array
    {
        return [
            'name'        => 'objects',
            'description' => 'Manage objects (data records stored in a register under a schema)',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'action'   => [
                        'type'        => 'string',
                        'enum'        => ['list', 'get', 'create', 'update', 'delete'],
                        'description' => 'The CRUD action to perform',
                    ],
                    'register' => [
                        'type'        => 'integer',
                        'description' => 'Register ID (required for all object actions)',
                    ],
                    'schema'   => [
                        'type'        => 'integer',
                        'description' => 'Schema ID (required for all object actions)',
                    ],
                    'id'       => [
                        'type'        => 'string',
                        'description' => 'Object UUID (required for get, update, delete)',
                    ],
                    'data'     => [
                        'type'        => 'object',
                        'description' => 'Object data fields (for create and update)',
                    ],
                    'limit'    => [
                        'type'        => 'integer',
                        'description' => 'Maximum number of results (for list)',
                    ],
                    'offset'   => [
                        'type'        => 'integer',
                        'description' => 'Number of results to skip (for list)',
                    ],
                ],
                'required' => ['action', 'register', 'schema'],
            ],
        ];
    }//end getObjectsTool()

    /**
     * Execute the registers tool
     *
     * @param array $arguments Tool arguments with action, id, data, limit, offset
     *
     * @return array Result data
     *
     * @throws \InvalidArgumentException If required parameters are missing
     */
    private function executeRegisters(array $arguments): array
    {
        $action = $arguments['action'];

        return match ($action) {
            'list'   => $this->listRegisters(arguments: $arguments),
            'get'    => $this->getRegister(arguments: $arguments),
            'create' => $this->createRegister(arguments: $arguments),
            'update' => $this->updateRegister(arguments: $arguments),
            'delete' => $this->deleteRegister(arguments: $arguments),
            default  => throw new \InvalidArgumentException(
                message: 'Unknown action: '.$action
            ),
        };
    }//end executeRegisters()

    /**
     * Execute the schemas tool
     *
     * @param array $arguments Tool arguments with action, id, data, limit, offset
     *
     * @return array Result data
     *
     * @throws \InvalidArgumentException If required parameters are missing
     */
    private function executeSchemas(array $arguments): array
    {
        $action = $arguments['action'];

        return match ($action) {
            'list'   => $this->listSchemas(arguments: $arguments),
            'get'    => $this->getSchema(arguments: $arguments),
            'create' => $this->createSchema(arguments: $arguments),
            'update' => $this->updateSchema(arguments: $arguments),
            'delete' => $this->deleteSchema(arguments: $arguments),
            default  => throw new \InvalidArgumentException(
                message: 'Unknown action: '.$action
            ),
        };
    }//end executeSchemas()

    /**
     * Execute the objects tool
     *
     * @param array $arguments Tool arguments with action, register, schema, id, data
     *
     * @return array Result data
     *
     * @throws \InvalidArgumentException If required parameters are missing
     */
    private function executeObjects(array $arguments): array
    {
        $action = $arguments['action'];

        $registerId = $arguments['register'] ?? null;
        $schemaId   = $arguments['schema'] ?? null;

        if ($registerId === null || $schemaId === null) {
            throw new \InvalidArgumentException(
                message: 'Both register and schema IDs are required for object operations'
            );
        }

        $this->objectService->setRegister($registerId);
        $this->objectService->setSchema($schemaId);

        return match ($action) {
            'list'   => $this->listObjects(arguments: $arguments),
            'get'    => $this->getObject(arguments: $arguments),
            'create' => $this->createObject(arguments: $arguments),
            'update' => $this->updateObject(arguments: $arguments),
            'delete' => $this->deleteObject(arguments: $arguments),
            default  => throw new \InvalidArgumentException(
                message: 'Unknown action: '.$action
            ),
        };
    }//end executeObjects()

    /**
     * List registers
     *
     * @param array $arguments Contains optional limit and offset
     *
     * @return array List of serialized registers
     */
    private function listRegisters(array $arguments): array
    {
        $limit  = $arguments['limit'] ?? null;
        $offset = $arguments['offset'] ?? null;

        $registers = $this->registerService->findAll(
            limit: $limit,
            offset: $offset
        );

        return array_map(
            callback: static fn($r) => $r->jsonSerialize(),
            array: $registers
        );
    }//end listRegisters()

    /**
     * Get a single register
     *
     * @param array $arguments Must contain id
     *
     * @return array Serialized register
     */
    private function getRegister(array $arguments): array
    {
        $this->requireParam(arguments: $arguments, param: 'id');
        $register = $this->registerService->find(id: $arguments['id']);
        return $register->jsonSerialize();
    }//end getRegister()

    /**
     * Create a register
     *
     * @param array $arguments Must contain data
     *
     * @return array Serialized created register
     */
    private function createRegister(array $arguments): array
    {
        $this->requireParam(arguments: $arguments, param: 'data');
        $register = $this->registerService->createFromArray(data: $arguments['data']);
        return $register->jsonSerialize();
    }//end createRegister()

    /**
     * Update a register
     *
     * @param array $arguments Must contain id and data
     *
     * @return array Serialized updated register
     */
    private function updateRegister(array $arguments): array
    {
        $this->requireParam(arguments: $arguments, param: 'id');
        $this->requireParam(arguments: $arguments, param: 'data');
        $register = $this->registerService->updateFromArray(
            id: $arguments['id'],
            data: $arguments['data']
        );
        return $register->jsonSerialize();
    }//end updateRegister()

    /**
     * Delete a register
     *
     * @param array $arguments Must contain id
     *
     * @return array Success message
     */
    private function deleteRegister(array $arguments): array
    {
        $this->requireParam(arguments: $arguments, param: 'id');
        $register = $this->registerService->find(id: $arguments['id']);
        $this->registerService->delete(register: $register);
        return ['deleted' => true, 'id' => $arguments['id']];
    }//end deleteRegister()

    /**
     * List schemas
     *
     * @param array $arguments Contains optional limit and offset
     *
     * @return array List of serialized schemas
     */
    private function listSchemas(array $arguments): array
    {
        $limit  = $arguments['limit'] ?? null;
        $offset = $arguments['offset'] ?? null;

        $schemas = $this->schemaMapper->findAll(
            limit: $limit,
            offset: $offset
        );

        return array_map(
            callback: static fn($s) => $s->jsonSerialize(),
            array: $schemas
        );
    }//end listSchemas()

    /**
     * Get a single schema
     *
     * @param array $arguments Must contain id
     *
     * @return array Serialized schema
     */
    private function getSchema(array $arguments): array
    {
        $this->requireParam(arguments: $arguments, param: 'id');
        $schema = $this->schemaMapper->find($arguments['id']);
        return $schema->jsonSerialize();
    }//end getSchema()

    /**
     * Create a schema
     *
     * @param array $arguments Must contain data
     *
     * @return array Serialized created schema
     */
    private function createSchema(array $arguments): array
    {
        $this->requireParam(arguments: $arguments, param: 'data');
        $schema = $this->schemaMapper->createFromArray(object: $arguments['data']);
        return $schema->jsonSerialize();
    }//end createSchema()

    /**
     * Update a schema
     *
     * @param array $arguments Must contain id and data
     *
     * @return array Serialized updated schema
     */
    private function updateSchema(array $arguments): array
    {
        $this->requireParam(arguments: $arguments, param: 'id');
        $this->requireParam(arguments: $arguments, param: 'data');
        $schema = $this->schemaMapper->updateFromArray(
            id: $arguments['id'],
            object: $arguments['data']
        );
        return $schema->jsonSerialize();
    }//end updateSchema()

    /**
     * Delete a schema
     *
     * @param array $arguments Must contain id
     *
     * @return array Success message
     */
    private function deleteSchema(array $arguments): array
    {
        $this->requireParam(arguments: $arguments, param: 'id');
        $schema = $this->schemaMapper->find($arguments['id']);
        $this->schemaMapper->delete($schema);
        return ['deleted' => true, 'id' => $arguments['id']];
    }//end deleteSchema()

    /**
     * List objects
     *
     * @param array $arguments Contains optional limit and offset
     *
     * @return array List of serialized objects
     */
    private function listObjects(array $arguments): array
    {
        $config = [];
        if (isset($arguments['limit']) === true) {
            $config['limit'] = $arguments['limit'];
        }

        if (isset($arguments['offset']) === true) {
            $config['offset'] = $arguments['offset'];
        }

        $objects = $this->objectService->findAll(config: $config);

        return array_map(
            callback: static fn($o) => $o->jsonSerialize(),
            array: $objects
        );
    }//end listObjects()

    /**
     * Get a single object
     *
     * @param array $arguments Must contain id (UUID)
     *
     * @return array Serialized object
     */
    private function getObject(array $arguments): array
    {
        $this->requireParam(arguments: $arguments, param: 'id');
        $object = $this->objectService->find($arguments['id']);
        return $object->jsonSerialize();
    }//end getObject()

    /**
     * Create an object
     *
     * @param array $arguments Must contain data
     *
     * @return array Serialized created object
     */
    private function createObject(array $arguments): array
    {
        $this->requireParam(arguments: $arguments, param: 'data');
        $object = $this->objectService->saveObject(object: $arguments['data']);
        return $object->jsonSerialize();
    }//end createObject()

    /**
     * Update an object
     *
     * @param array $arguments Must contain id and data
     *
     * @return array Serialized updated object
     */
    private function updateObject(array $arguments): array
    {
        $this->requireParam(arguments: $arguments, param: 'id');
        $this->requireParam(arguments: $arguments, param: 'data');
        $object = $this->objectService->saveObject(
            object: $arguments['data'],
            uuid: $arguments['id']
        );
        return $object->jsonSerialize();
    }//end updateObject()

    /**
     * Delete an object
     *
     * @param array $arguments Must contain id (UUID)
     *
     * @return array Success message
     */
    private function deleteObject(array $arguments): array
    {
        $this->requireParam(arguments: $arguments, param: 'id');
        $this->objectService->deleteObject(uuid: $arguments['id']);
        return ['deleted' => true, 'id' => $arguments['id']];
    }//end deleteObject()

    /**
     * Require a parameter exists in arguments
     *
     * @param array  $arguments Tool arguments
     * @param string $param     Required parameter name
     *
     * @return void
     *
     * @throws \InvalidArgumentException If parameter is missing
     */
    private function requireParam(array $arguments, string $param): void
    {
        if (isset($arguments[$param]) === false) {
            throw new \InvalidArgumentException(
                message: 'Missing required parameter: '.$param
            );
        }
    }//end requireParam()
}//end class
