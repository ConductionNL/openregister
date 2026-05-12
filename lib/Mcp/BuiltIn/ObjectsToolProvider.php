<?php

/**
 * Built-in objects MCP tool provider.
 *
 * Exposes CRUD operations on OpenRegister objects as an MCP tool
 * under the namespaced id `openregister.objects`.
 *
 * @category Mcp
 * @package  OCA\OpenRegister\Mcp\BuiltIn
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction BV
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/ai-chat-companion-orchestrator/specs/chat-ai/spec.md#imcptoolprovider-built-in-migration
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Mcp\BuiltIn;

use InvalidArgumentException;
use OCA\OpenRegister\Mcp\IMcpToolProvider;
use OCA\OpenRegister\Service\ObjectService;

/**
 * ObjectsToolProvider
 *
 * Built-in IMcpToolProvider for object CRUD operations. All tool logic is
 * relocated from McpToolsService::executeObjects() into invokeTool().
 *
 * @category Mcp
 * @package  OCA\OpenRegister\Mcp\BuiltIn
 *
 * @psalm-suppress UnusedClass - Injected via DI container
 */
class ObjectsToolProvider implements IMcpToolProvider
{

    /**
     * Tool id for the objects tool
     */
    public const TOOL_ID = 'openregister.objects';

    /**
     * Constructor
     *
     * @param ObjectService $objectService Object service facade
     */
    public function __construct(
        private readonly ObjectService $objectService
    ) {
    }//end __construct()

    /**
     * Returns the owning app id.
     *
     * @return string Always "openregister"
     */
    public function getAppId(): string
    {
        return 'openregister';
    }//end getAppId()

    /**
     * Returns tool descriptors.
     *
     * @return list<array{id: string, name: string, description: string, inputSchema: array}>
     */
    public function getTools(): array
    {
        return [
            [
                'id'          => self::TOOL_ID,
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
            ],
        ];
    }//end getTools()

    /**
     * Invoke the objects tool.
     *
     * @param string               $toolId    Must be "openregister.objects"
     * @param array<string, mixed> $arguments Tool arguments with action, register, schema, id, data
     *
     * @return array<string, mixed> JSON-encodable result
     *
     * @throws InvalidArgumentException If action is unknown or required params missing
     */
    public function invokeTool(string $toolId, array $arguments): array
    {
        $registerId = $arguments['register'] ?? null;
        $schemaId   = $arguments['schema'] ?? null;

        if ($registerId === null || $schemaId === null) {
            throw new InvalidArgumentException(
                'Both register and schema IDs are required for object operations'
            );
        }

        $this->objectService->setRegister($registerId);
        $this->objectService->setSchema($schemaId);

        $action = $arguments['action'] ?? null;

        return match ($action) {
            'list'   => $this->listObjects(arguments: $arguments),
            'get'    => $this->getObject(arguments: $arguments),
            'create' => $this->createObject(arguments: $arguments),
            'update' => $this->updateObject(arguments: $arguments),
            'delete' => $this->deleteObject(arguments: $arguments),
            default  => throw new InvalidArgumentException('Unknown action: '.$action),
        };
    }//end invokeTool()

    /**
     * List objects.
     *
     * @param array<string, mixed> $arguments Contains optional limit and offset
     *
     * @return array<int, mixed> List of serialized objects
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

        // IDOR boundary (IMcpToolProvider contract): ObjectService::findAll
        // applies RBAC + multi-tenancy filtering by default; we rely on the
        // service-default behaviour because the object pipeline has many
        // filtering knobs and toggling them per-call would be fragile.
        // Do NOT pass _rbac: false or _multitenancy: false from this path.
        $objects = $this->objectService->findAll(config: $config);

        return array_map(
            callback: static fn($obj) => $obj->jsonSerialize(),
            array: $objects
        );
    }//end listObjects()

    /**
     * Get a single object.
     *
     * @param array<string, mixed> $arguments Must contain id (UUID)
     *
     * @return array<string, mixed> Serialized object
     */
    private function getObject(array $arguments): array
    {
        $this->requireParam(arguments: $arguments, param: 'id');
        $object = $this->objectService->find($arguments['id']);
        return $object->jsonSerialize();
    }//end getObject()

    /**
     * Create an object.
     *
     * @param array<string, mixed> $arguments Must contain data
     *
     * @return array<string, mixed> Serialized created object
     */
    private function createObject(array $arguments): array
    {
        $this->requireParam(arguments: $arguments, param: 'data');
        $object = $this->objectService->saveObject(object: $arguments['data']);
        return $object->jsonSerialize();
    }//end createObject()

    /**
     * Update an object.
     *
     * @param array<string, mixed> $arguments Must contain id and data
     *
     * @return array<string, mixed> Serialized updated object
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
     * Delete an object.
     *
     * @param array<string, mixed> $arguments Must contain id (UUID)
     *
     * @return array<string, mixed> Success message
     */
    private function deleteObject(array $arguments): array
    {
        $this->requireParam(arguments: $arguments, param: 'id');
        $this->objectService->deleteObject(uuid: $arguments['id']);
        return ['deleted' => true, 'id' => $arguments['id']];
    }//end deleteObject()

    /**
     * Assert a parameter is present in arguments.
     *
     * @param array<string, mixed> $arguments Tool arguments
     * @param string               $param     Required parameter name
     *
     * @return void
     *
     * @throws InvalidArgumentException If parameter is missing
     */
    private function requireParam(array $arguments, string $param): void
    {
        if (isset($arguments[$param]) === false) {
            throw new InvalidArgumentException('Missing required parameter: '.$param);
        }
    }//end requireParam()
}//end class
