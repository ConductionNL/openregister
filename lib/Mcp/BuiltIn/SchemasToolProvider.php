<?php

/**
 * Built-in schemas MCP tool provider.
 *
 * Exposes CRUD operations on OpenRegister schemas as an MCP tool
 * under the namespaced id `openregister.schemas`.
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
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Mcp\IMcpToolProvider;

/**
 * SchemasToolProvider
 *
 * Built-in IMcpToolProvider for schema CRUD operations. All tool logic is
 * relocated from McpToolsService::executeSchemas() into invokeTool().
 *
 * @category Mcp
 * @package  OCA\OpenRegister\Mcp\BuiltIn
 *
 * @psalm-suppress UnusedClass - Injected via DI container
 */
class SchemasToolProvider implements IMcpToolProvider
{

    /**
     * Tool id for the schemas tool
     */
    public const TOOL_ID = 'openregister.schemas';

    /**
     * Constructor
     *
     * @param SchemaMapper $schemaMapper Schema database mapper
     */
    public function __construct(
        private readonly SchemaMapper $schemaMapper
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
            ],
        ];
    }//end getTools()

    /**
     * Invoke the schemas tool.
     *
     * @param string               $toolId    Must be "openregister.schemas"
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
            'list'   => $this->listSchemas(arguments: $arguments),
            'get'    => $this->getSchema(arguments: $arguments),
            'create' => $this->createSchema(arguments: $arguments),
            'update' => $this->updateSchema(arguments: $arguments),
            'delete' => $this->deleteSchema(arguments: $arguments),
            default  => throw new InvalidArgumentException('Unknown action: '.$action),
        };
    }//end invokeTool()

    /**
     * List schemas.
     *
     * @param array<string, mixed> $arguments Contains optional limit and offset
     *
     * @return array<int, mixed> List of serialized schemas
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
     * Get a single schema.
     *
     * @param array<string, mixed> $arguments Must contain id
     *
     * @return array<string, mixed> Serialized schema
     */
    private function getSchema(array $arguments): array
    {
        $this->requireParam(arguments: $arguments, param: 'id');
        $schema = $this->schemaMapper->find($arguments['id']);
        return $schema->jsonSerialize();
    }//end getSchema()

    /**
     * Create a schema.
     *
     * @param array<string, mixed> $arguments Must contain data
     *
     * @return array<string, mixed> Serialized created schema
     */
    private function createSchema(array $arguments): array
    {
        $this->requireParam(arguments: $arguments, param: 'data');
        $schema = $this->schemaMapper->createFromArray(object: $arguments['data']);
        return $schema->jsonSerialize();
    }//end createSchema()

    /**
     * Update a schema.
     *
     * @param array<string, mixed> $arguments Must contain id and data
     *
     * @return array<string, mixed> Serialized updated schema
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
     * Delete a schema.
     *
     * @param array<string, mixed> $arguments Must contain id
     *
     * @return array<string, mixed> Success message
     */
    private function deleteSchema(array $arguments): array
    {
        $this->requireParam(arguments: $arguments, param: 'id');
        $schema = $this->schemaMapper->find($arguments['id']);
        $this->schemaMapper->delete($schema);
        return ['deleted' => true, 'id' => $arguments['id']];
    }//end deleteSchema()

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
