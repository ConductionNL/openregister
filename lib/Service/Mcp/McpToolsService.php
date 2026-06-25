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
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
     * Execute an MCP tool by its namespaced id or short name
     *
     * Routes the invocation to the provider whose tool descriptor matches
     * the given identifier (either descriptor `id` like "openregister.registers"
     * or descriptor `name` like "registers"). The first matching provider wins.
     * The resolved descriptor's namespaced `id` is forwarded to the provider's
     * `invokeTool()` so providers always see the canonical form regardless of
     * how the client addressed the tool.
     *
     * @param string               $name      Tool identifier — descriptor `name` (e.g. "registers")
     *                                        or namespaced `id` (e.g. "openregister.registers").
     * @param array<string, mixed> $arguments Tool arguments
     *
     * @return array<string, mixed> MCP tool result with content array
     *
     * @throws InvalidArgumentException If no provider handles the tool id
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid2/tasks.md#task-6
     */
    public function callTool(string $name, array $arguments): array
    {
        $this->logger->debug(
            message: '[MCP] Tool call',
            context: ['tool' => $name, 'arguments' => $arguments]
        );

        // Find a provider + descriptor that owns this tool id.
        $match = $this->findProviderForTool(toolId: $name);

        if ($match === null) {
            throw new InvalidArgumentException('Unknown tool: '.$name);
        }

        // Forward the descriptor's namespaced id, not the raw client-supplied
        // identifier — providers that multiplex on $toolId must see the
        // canonical form even when the client addressed the tool by short name.
        $providerToolId = (string) ($match['descriptor']['id'] ?? $name);

        try {
            $result = $match['provider']->invokeTool(toolId: $providerToolId, arguments: $arguments);

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
     * Invoke a tool by namespaced id or short name, returning a flat result array.
     *
     * Used by ChatStreamController to invoke tools in the LLM pipeline
     * and emit tool_result SSE events. Accepts both descriptor `id`
     * ("openregister.registers") and descriptor `name` ("registers")
     * forms; the resolved descriptor's namespaced id is forwarded to
     * the owning provider.
     *
     * @param string               $toolId    Tool identifier — descriptor `name` or namespaced `id`.
     * @param array<string, mixed> $arguments Tool arguments
     *
     * @return array{result: array<string, mixed>, isError: bool} Result envelope
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid2/tasks.md#task-6
     */
    public function invokeTool(string $toolId, array $arguments): array
    {
        $match = $this->findProviderForTool(toolId: $toolId);

        if ($match === null) {
            return [
                'result'  => ['error' => 'Unknown tool: '.$toolId],
                'isError' => true,
            ];
        }

        $providerToolId = (string) ($match['descriptor']['id'] ?? $toolId);

        try {
            $result = $match['provider']->invokeTool(toolId: $providerToolId, arguments: $arguments);
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
     * Find the first provider + descriptor that owns the given tool id.
     *
     * Resolves against the descriptor's full namespaced `id`
     * (e.g. "openregister.registers") OR its short MCP `name`
     * (e.g. "registers"). The MCP protocol's tools/call uses
     * the descriptor's `name`, so accepting both keeps spec-
     * compliant clients and any chat-side caller that already
     * uses the namespaced id working through the same path.
     *
     * Returns the descriptor alongside the provider so callers
     * can forward the canonical namespaced id to `invokeTool()`
     * regardless of how the client addressed the tool.
     *
     * Collision warning: when a short-name lookup matches descriptors in
     * more than one provider, only the first match is returned (first-wins)
     * and a warning is logged once so the ambiguity surfaces in the
     * application log. External providers should namespace any short name
     * that could collide.
     *
     * @param string $toolId Tool identifier as sent by the client.
     *
     * @return array{provider: IMcpToolProvider, descriptor: array<string, mixed>}|null
     *         The first matching provider + its descriptor, or null if not found.
     */
    private function findProviderForTool(string $toolId): ?array
    {
        $first      = null;
        $collisions = 0;

        foreach ($this->providers as $provider) {
            foreach ($provider->getTools() as $descriptor) {
                $matchesId   = (($descriptor['id'] ?? '') === $toolId);
                $matchesName = (($descriptor['name'] ?? '') === $toolId);

                if ($matchesId === false && $matchesName === false) {
                    continue;
                }

                if ($first === null) {
                    $first = ['provider' => $provider, 'descriptor' => $descriptor];

                    // An exact id-match is the canonical form — no ambiguity
                    // is possible because listTools() already enforces the
                    // namespace prefix uniqueness. Stop scanning for collisions.
                    if ($matchesId === true) {
                        return $first;
                    }

                    continue;
                }

                // Short-name collision across providers — log once below.
                ++$collisions;
            }//end foreach
        }//end foreach

        if ($collisions > 0 && $first !== null) {
            $this->logger->warning(
                message: '[MCP] Short-name tool collision — first provider wins; use namespaced id to disambiguate.',
                context: [
                    'tool'           => $toolId,
                    'winningAppId'   => $first['provider']->getAppId(),
                    'collisionCount' => $collisions,
                ]
            );
        }

        return $first;
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
}//end class
