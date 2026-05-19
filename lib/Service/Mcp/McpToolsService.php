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

                // MCP `tools/list` requires the `name` field to be a valid
                // MCP identifier (no spaces, no dots — matches OpenAI's
                // function-name regex `^[a-zA-Z0-9_-]{1,64}$`). Many of our
                // providers historically used a display-style `name` like
                // "List recent meetings". Replace `name` on the wire with a
                // slugified form of the canonical `id` (`.` → `_`) so MCP
                // clients see a valid identifier. The original display name
                // moves into `title` per MCP 2025-03-26's optional title
                // hint. callTool() below accepts both forms.
                $canonicalId = (string) $descriptor['id'];
                $wireName    = str_replace('.', '_', $canonicalId);
                $tools[]     = array_merge(
                    $descriptor,
                    [
                        'name'  => $wireName,
                        'title' => $descriptor['name'] ?? $wireName,
                    ]
                );
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
     */
    public function callTool(string $name, array $arguments): array
    {
        $this->logger->debug(
            message: '[MCP] Tool call',
            context: ['tool' => $name, 'arguments' => $arguments]
        );

        // Find a provider that owns this tool id (accepts both wire-format
        // slug `appId_toolName` and canonical dotted `appId.toolName`).
        $provider = $this->findProviderForTool(toolId: $name);

        if ($provider === null) {
            throw new InvalidArgumentException('Unknown tool: '.$name);
        }

        // Providers expect the canonical dotted id when invoking. Reverse
        // the wire-format slug if necessary so providers see what they
        // emitted in getTools().
        $canonicalId = $name;
        if (str_contains($canonicalId, '.') === false && str_contains($canonicalId, '_') === true) {
            $canonicalId = preg_replace('/_/', '.', $canonicalId, 1);
        }

        try {
            $result = $provider->invokeTool(toolId: $canonicalId, arguments: $arguments);

            return [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => json_encode(value: $result, flags: JSON_PRETTY_PRINT),
                    ],
                ],
                'isError' => false,
            ];
        } catch (\Throwable $e) {
            // Catch \Throwable, not \Exception: TypeError / ArgumentCountError
            // and friends are \Error subclasses. If we only caught \Exception
            // those would propagate to the framework as fatal 500s without
            // the SSE / JSON envelope the caller is parsing.
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
        // MCP wire format uses slugified names (`appId_toolName`). The
        // canonical id stored on each provider's descriptor is the dotted
        // form (`appId.toolName`). Accept either by trying both: dotted
        // first (cheapest, matches direct MCP calls + agent-stored ids),
        // then the slugified form (Claude-style clients).
        $candidates = [$toolId];
        if (str_contains($toolId, '.') === false && str_contains($toolId, '_') === true) {
            // Only replace the FIRST underscore — provider ids may contain
            // underscores in their tool-name half (e.g. "openregister.tool_name").
            $candidates[] = preg_replace('/_/', '.', $toolId, 1);
        }

        foreach ($this->providers as $provider) {
            $appId = $provider->getAppId();
            foreach ($candidates as $candidate) {
                if (str_starts_with($candidate, $appId.'.') === false) {
                    continue;
                }

                foreach ($provider->getTools() as $descriptor) {
                    if (($descriptor['id'] ?? '') === $candidate) {
                        return $provider;
                    }
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
}//end class
