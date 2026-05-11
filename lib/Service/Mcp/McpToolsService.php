<?php

/**
 * MCP Tools Service
 *
 * Handles MCP standard tool listing and execution for the OpenRegister
 * MCP server. Enumerates all registered IMcpToolProvider implementations
 * (built-ins first, then externally registered providers) and aggregates
 * their tool descriptors. Namespace enforcement (ADR-034 D5) rejects any
 * descriptor whose id does not start with `{provider->getAppId()}.`.
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
 *
 * @spec openspec/changes/ai-chat-companion-orchestrator/specs/chat-ai/spec.md#mcptoolsservice-provider-discovery-refactor
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Mcp;

use InvalidArgumentException;
use OCA\OpenRegister\Mcp\IMcpToolProvider;
use Psr\Log\LoggerInterface;

/**
 * McpToolsService handles MCP tool operations
 *
 * Enumerates all registered IMcpToolProvider implementations (built-ins
 * first) and aggregates their tool descriptors into a single list for the
 * LLM tool-loop. Non-conforming tool ids (where the prefix does not match
 * the provider's app id) are silently dropped with a warning-level log.
 *
 * @psalm-suppress UnusedClass - Injected via DI container
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class McpToolsService
{

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
     * List available MCP tools
     *
     * Aggregates tool descriptors from all registered providers. Descriptors
     * whose id does not start with `{provider->getAppId()}.` are dropped and
     * a warning is logged per D5 of the design.
     *
     * @return array{tools: array} MCP tools/list response
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
        }

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
     */
    public function callTool(string $name, array $arguments): array
    {
        $this->logger->debug(
            message: '[MCP] Tool call',
            context: ['tool' => $name, 'arguments' => $arguments]
        );

        // Find a provider that owns this tool id.
        $provider = $this->findProviderForTool(toolId: $name);

        if ($provider === null) {
            throw new InvalidArgumentException('Unknown tool: '.$name);
        }

        try {
            $result = $provider->invokeTool(toolId: $name, arguments: $arguments);

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
     * Invoke a tool by namespaced id, returning a flat result array.
     *
     * Used by ChatStreamController to invoke tools in the LLM pipeline
     * and emit tool_result SSE events.
     *
     * @param string               $toolId    Namespaced tool id
     * @param array<string, mixed> $arguments Tool arguments
     *
     * @return array{result: array<string, mixed>, isError: bool} Result envelope
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
        } catch (\Exception $e) {
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
     * @param IMcpToolProvider $provider The provider to add
     *
     * @return void
     */
    public function addProvider(IMcpToolProvider $provider): void
    {
        $this->providers[] = $provider;
    }//end addProvider()
}//end class
