<?php

/**
 * MCP Tools Service
 *
 * Handles MCP standard tool listing and execution for the OpenRegister
<<<<<<< HEAD
 * MCP server. Provides CRUD tools for registers, schemas, and objects.
=======
 * MCP server. Enumerates all registered IMcpToolProvider implementations
 * (built-ins first, then externally registered providers) and aggregates
 * their tool descriptors. Namespace enforcement (ADR-034 D5) rejects any
 * descriptor whose id does not start with `{provider->getAppId()}.`.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
>>>>>>> origin/development
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
 * @spec openspec/changes/ai-chat-companion-orchestrator/specs/chat-ai/spec.md#mcptoolsservice-provider-discovery-refactor
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Mcp;

<<<<<<< HEAD
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\RegisterService;
use OCA\OpenRegister\Service\ObjectService;
use InvalidArgumentException;
=======
use InvalidArgumentException;
use OCA\OpenRegister\Mcp\IMcpToolProvider;
>>>>>>> origin/development
use Psr\Log\LoggerInterface;

/**
 * McpToolsService handles MCP tool operations
 *
<<<<<<< HEAD
 * Provides tool definitions and execution for the three core
 * OpenRegister entities: registers, schemas, and objects.
=======
 * Enumerates all registered IMcpToolProvider implementations (built-ins
 * first) and aggregates their tool descriptors into a single list for the
 * LLM tool-loop. Non-conforming tool ids (where the prefix does not match
 * the provider's app id) are silently dropped with a warning-level log.
>>>>>>> origin/development
 *
 * @psalm-suppress UnusedClass - Injected via DI container
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class McpToolsService
{
<<<<<<< HEAD
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
     * @throws InvalidArgumentException If tool name is unknown
=======

    /**
     * Registered tool providers.
     *
     * Built-ins are prepended first by Application.php registration order.
     *
     * @var list<IMcpToolProvider>
     */
    private array $providers;

    /**
     * McpToolsService constructor
     *
     * @param list<IMcpToolProvider> $providers Ordered list of tool providers (built-ins first)
     * @param LoggerInterface        $logger    Logger
     */
    public function __construct(
        array $providers,
        private readonly LoggerInterface $logger
    ) {
        $this->providers = $providers;
    }//end __construct()

    /**
     * Return the registered providers in order so the chat-side
     * ToolRegistrationListener can wrap each one in an McpProviderBridge
     * and register it on the ToolRegistry. Read-only accessor; the
     * underlying list cannot be mutated post-construction (use
     * addProvider() for that).
     *
     * @return list<IMcpToolProvider>
     */
    public function getProviders(): array
    {
        return $this->providers;

    }//end getProviders()

    /**
     * List available MCP tools
     *
     * Aggregates tool descriptors from all registered providers. Descriptors
     * whose id does not start with `{provider->getAppId()}.` are dropped and
     * a warning is logged per D5 of the design.
     *
     * @return array{tools: array} MCP tools/list response
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid2/tasks.md#task-6
     */
    public function listTools(): array
    {
        $tools = [];

        foreach ($this->providers as $provider) {
            $appId = $provider->getAppId();

            foreach ($provider->getTools() as $descriptor) {
                $toolId = $descriptor['id'] ?? '';

                // Namespace enforcement: drop descriptors with wrong prefix.
                if (str_starts_with($toolId, $appId.'.') === false) {
                    $this->logger->warning(
                        message: '[McpToolsService] Dropping tool descriptor with non-conforming namespace prefix',
                        context: [
                            'file'          => __FILE__,
                            'line'          => __LINE__,
                            'providerClass' => get_class($provider),
                            'appId'         => $appId,
                            'toolId'        => $toolId,
                        ]
                    );
                    continue;
                }

                $tools[] = $descriptor;
            }
        }//end foreach

        return ['tools' => $tools];
    }//end listTools()

    /**
     * Execute an MCP tool by its namespaced id
     *
     * Routes the invocation to the provider whose app id prefix matches
     * the given tool id. The first matching provider wins.
     *
     * @param string               $name      Namespaced tool id (e.g. "openregister.registers")
     * @param array<string, mixed> $arguments Tool arguments
     *
     * @return array<string, mixed> MCP tool result with content array
     *
     * @throws InvalidArgumentException If no provider handles the tool id
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid2/tasks.md#task-6
>>>>>>> origin/development
     */
    public function callTool(string $name, array $arguments): array
    {
        $this->logger->debug(
            message: '[MCP] Tool call',
            context: ['tool' => $name, 'arguments' => $arguments]
        );

<<<<<<< HEAD
        try {
            $result = match ($name) {
                'registers' => $this->executeRegisters(arguments: $arguments),
                'schemas'   => $this->executeSchemas(arguments: $arguments),
                'objects'   => $this->executeObjects(arguments: $arguments),
                default     => throw new InvalidArgumentException(
                    message: 'Unknown tool: '.$name
                ),
            };
=======
        // Find a provider that owns this tool id.
        $provider = $this->findProviderForTool(toolId: $name);

        if ($provider === null) {
            throw new InvalidArgumentException('Unknown tool: '.$name);
        }

        try {
            $result = $provider->invokeTool(toolId: $name, arguments: $arguments);
>>>>>>> origin/development

            return [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => json_encode(value: $result, flags: JSON_PRETTY_PRINT),
                    ],
                ],
                'isError' => false,
            ];
<<<<<<< HEAD
        } catch (\Exception $e) {
=======
        } catch (\Throwable $e) {
            // Catch \Throwable, not \Exception: TypeError / ArgumentCountError
            // and friends are \Error subclasses. If we only caught \Exception
            // those would propagate to the framework as fatal 500s without
            // the SSE / JSON envelope the caller is parsing.
>>>>>>> origin/development
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
<<<<<<< HEAD
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
                'required'   => ['action'],
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
                'required'   => ['action'],
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
                'required'   => ['action', 'register', 'schema'],
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
     * @throws InvalidArgumentException If required parameters are missing
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
            default  => throw new InvalidArgumentException(
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
     * @throws InvalidArgumentException If required parameters are missing
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
            default  => throw new InvalidArgumentException(
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
     * @throws InvalidArgumentException If required parameters are missing
     */
    private function executeObjects(array $arguments): array
    {
        $action = $arguments['action'];

        $registerId = $arguments['register'] ?? null;
        $schemaId   = $arguments['schema'] ?? null;

        if ($registerId === null || $schemaId === null) {
            throw new InvalidArgumentException(
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
            default  => throw new InvalidArgumentException(
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
            callback: static fn($schema) => $schema->jsonSerialize(),
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
            callback: static fn($obj) => $obj->jsonSerialize(),
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
     * @throws InvalidArgumentException If parameter is missing
     */
    private function requireParam(array $arguments, string $param): void
    {
        if (isset($arguments[$param]) === false) {
            throw new InvalidArgumentException(
                message: 'Missing required parameter: '.$param
            );
        }
    }//end requireParam()
=======
     * Invoke a tool by namespaced id, returning a flat result array.
     *
     * Used by ChatStreamController to invoke tools in the LLM pipeline
     * and emit tool_result SSE events.
     *
     * @param string               $toolId    Namespaced tool id
     * @param array<string, mixed> $arguments Tool arguments
     *
     * @return array{result: array<string, mixed>, isError: bool} Result envelope
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid2/tasks.md#task-6
     */
    public function invokeTool(string $toolId, array $arguments): array
    {
        $provider = $this->findProviderForTool(toolId: $toolId);

        if ($provider === null) {
            return [
                'result'  => ['error' => 'Unknown tool: '.$toolId],
                'isError' => true,
            ];
        }

        try {
            $result = $provider->invokeTool(toolId: $toolId, arguments: $arguments);
            return [
                'result'  => $result,
                'isError' => false,
            ];
        } catch (\Throwable $e) {
            // See callTool(): catching \Throwable (not \Exception) prevents
            // \Error subclasses from escaping the MCP envelope.
            $this->logger->error(
                message: '[MCP] invokeTool failed',
                context: ['tool' => $toolId, 'error' => $e->getMessage()]
            );

            return [
                'result'  => ['error' => $e->getMessage()],
                'isError' => true,
            ];
        }//end try
    }//end invokeTool()

    /**
     * Find the first provider that owns the given tool id.
     *
     * A provider owns a tool id when the tool's id starts with
     * `{provider->getAppId()}.` AND the provider lists that tool in getTools().
     *
     * @param string $toolId Namespaced tool id
     *
     * @return IMcpToolProvider|null The matching provider, or null if not found
     */
    private function findProviderForTool(string $toolId): ?IMcpToolProvider
    {
        foreach ($this->providers as $provider) {
            $appId = $provider->getAppId();

            if (str_starts_with($toolId, $appId.'.') === false) {
                continue;
            }

            // Confirm the provider actually lists this tool.
            foreach ($provider->getTools() as $descriptor) {
                if (($descriptor['id'] ?? '') === $toolId) {
                    return $provider;
                }
            }
        }

        return null;
    }//end findProviderForTool()

    /**
     * Add a provider to the list at runtime (e.g. from external apps).
     *
     * Idempotent: if a provider with the same `getAppId()` is already
     * registered, the call is a no-op. This handles the dual-path case
     * where (a) OR's factory in `Application::registerMcpToolProviders`
     * discovers the provider by walking `IAppManager::getInstalledApps()`
     * AND (b) the consumer app's own `Application::boot()` also calls
     * `addProvider()` to be self-sufficient when OR's discovery path
     * misses the app (e.g. alias not registered on OR's container scope).
     *
     * @param IMcpToolProvider $provider The provider to add
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid2/tasks.md#task-6
     */
    public function addProvider(IMcpToolProvider $provider): void
    {
        $appId = $provider->getAppId();
        foreach ($this->providers as $existing) {
            if ($existing->getAppId() === $appId) {
                $this->logger->debug(
                    message: '[McpToolsService] addProvider() skipped — provider with same appId already registered',
                    context: [
                        'appId'         => $appId,
                        'incomingClass' => get_class($provider),
                        'existingClass' => get_class($existing),
                    ]
                );
                return;
            }
        }

        $this->providers[] = $provider;
    }//end addProvider()
>>>>>>> origin/development
}//end class
