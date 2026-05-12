<?php

/**
 * Built-in registers MCP tool provider.
 *
 * Exposes CRUD operations on OpenRegister registers as an MCP tool
 * under the namespaced id `openregister.registers`.
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
use OCA\OpenRegister\Service\RegisterService;

/**
 * RegistersToolProvider
 *
 * Built-in IMcpToolProvider for register CRUD operations. All tool logic is
 * relocated from McpToolsService::executeRegisters() into invokeTool().
 *
 * @category Mcp
 * @package  OCA\OpenRegister\Mcp\BuiltIn
 *
 * @psalm-suppress UnusedClass - Injected via DI container
 */
class RegistersToolProvider implements IMcpToolProvider
{

    /**
     * Tool id for the registers tool
     */
    public const TOOL_ID = 'openregister.registers';

    /**
     * Constructor
     *
     * @param RegisterService $registerService Register service for CRUD operations
     */
    public function __construct(
        private readonly RegisterService $registerService
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
            ],
        ];
    }//end getTools()

    /**
     * Invoke the registers tool.
     *
     * @param string               $toolId    Must be "openregister.registers"
     * @param array<string, mixed> $arguments Tool arguments with action, id, data, limit, offset
     *
     * @return array<string, mixed> JSON-encodable result
     *
     * @throws InvalidArgumentException If action is unknown or required params missing
     */
    public function invokeTool(string $toolId, array $arguments): array
    {
        $action = $arguments['action'] ?? null;

        return match ($action) {
            'list'   => $this->listRegisters(arguments: $arguments),
            'get'    => $this->getRegister(arguments: $arguments),
            'create' => $this->createRegister(arguments: $arguments),
            'update' => $this->updateRegister(arguments: $arguments),
            'delete' => $this->deleteRegister(arguments: $arguments),
            default  => throw new InvalidArgumentException('Unknown action: '.$action),
        };
    }//end invokeTool()

    /**
     * List registers.
     *
     * @param array<string, mixed> $arguments Contains optional limit and offset
     *
     * @return array<int, mixed> List of serialized registers
     */
    private function listRegisters(array $arguments): array
    {
        $limit  = $arguments['limit'] ?? null;
        $offset = $arguments['offset'] ?? null;

        // IDOR boundary (IMcpToolProvider contract): RegisterService::findAll
        // applies RBAC + multi-tenancy filtering by default — the underlying
        // mapper joins on the active organisation and filters by RBAC role.
        // We pass the flags explicitly so a future refactor of the service
        // defaults cannot silently widen this query to all-tenants. Do NOT
        // disable either flag here.
        $registers = $this->registerService->findAll(
            limit: $limit,
            offset: $offset,
            _multitenancy: true
        );

        return array_map(
            callback: static fn($r) => $r->jsonSerialize(),
            array: $registers
        );
    }//end listRegisters()

    /**
     * Get a single register.
     *
     * @param array<string, mixed> $arguments Must contain id
     *
     * @return array<string, mixed> Serialized register
     */
    private function getRegister(array $arguments): array
    {
        $this->requireParam(arguments: $arguments, param: 'id');
        $register = $this->registerService->find(id: $arguments['id']);
        return $register->jsonSerialize();
    }//end getRegister()

    /**
     * Create a register.
     *
     * @param array<string, mixed> $arguments Must contain data
     *
     * @return array<string, mixed> Serialized created register
     */
    private function createRegister(array $arguments): array
    {
        $this->requireParam(arguments: $arguments, param: 'data');
        $register = $this->registerService->createFromArray(data: $arguments['data']);
        return $register->jsonSerialize();
    }//end createRegister()

    /**
     * Update a register.
     *
     * @param array<string, mixed> $arguments Must contain id and data
     *
     * @return array<string, mixed> Serialized updated register
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
     * Delete a register.
     *
     * @param array<string, mixed> $arguments Must contain id
     *
     * @return array<string, mixed> Success message
     */
    private function deleteRegister(array $arguments): array
    {
        $this->requireParam(arguments: $arguments, param: 'id');
        $register = $this->registerService->find(id: $arguments['id']);
        $this->registerService->delete(register: $register);
        return ['deleted' => true, 'id' => $arguments['id']];
    }//end deleteRegister()

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
