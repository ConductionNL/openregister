<?php

/**
 * McpProviderBridge — wraps an IMcpToolProvider so the chat orchestrator's
 * ToolRegistry can expose its tools as LLphant function definitions.
 *
 * The chat orchestrator (ResponseGenerationHandler) feeds the LLM tool
 * definitions from ToolRegistry, not from McpToolsService. Per-app MCP tool
 * providers (DecideskToolProvider, PipelinqToolProvider, …) therefore
 * never reach the LLM via the chat path even though they are discoverable
 * via the MCP JSON-RPC endpoint.
 *
 * This adapter closes that gap. ToolRegistry registers one McpProviderBridge
 * per IMcpToolProvider; each provider's tool descriptors become individual
 * LLphant functions, and executeFunction() forwards the call back through
 * the provider's invokeTool().
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tool;

use OCA\OpenRegister\Mcp\IMcpToolProvider;
use Psr\Log\LoggerInterface;

/**
 * Adapter from IMcpToolProvider to ToolInterface.
 */
class McpProviderBridge implements ToolInterface
{

    private ?\OCA\OpenRegister\Db\Agent $agent = null;

    public function __construct(
        private readonly IMcpToolProvider $provider,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getName(): string
    {
        // ToolInterface getName is used as the LLM-facing identifier of the
        // tool group. Use the appId so all MCP tools under one app cluster.
        return $this->provider->getAppId();
    }

    public function getDescription(): string
    {
        return 'MCP-bridged tools from the '.$this->provider->getAppId().' app.';
    }

    /**
     * Each MCP descriptor becomes one LLphant function definition.
     */
    public function getFunctions(): array
    {
        $functions = [];
        foreach ($this->provider->getTools() as $descriptor) {
            $rawId = (string) ($descriptor['id'] ?? '');
            if ($rawId === '') {
                continue;
            }

            // LLphant / OpenAI function names disallow dots in some models;
            // expose the raw MCP id as the function name AND rewrite a
            // safe alias (underscore) so both forms route back the same way.
            $functions[] = [
                'name'        => $this->safeFunctionName($rawId),
                'mcpId'       => $rawId,
                'description' => (string) ($descriptor['description'] ?? $descriptor['name'] ?? $rawId),
                'parameters'  => $this->sanitiseSchema($descriptor['inputSchema'] ?? ['type' => 'object', 'properties' => []]),
            ];
        }

        return $functions;
    }

    /**
     * Coerce JSON-Schema-style nullable types (`['string','null']`) into
     * single-string types — LLPhant's Parameter constructor only accepts a
     * scalar string type, so otherwise function-info construction fails with
     * a TypeError before the LLM ever sees the tool.
     */
    private function sanitiseSchema(array $schema): array
    {
        if (isset($schema['type']) === true && is_array($schema['type']) === true) {
            $schema['type'] = $this->collapseType($schema['type']);
        }

        if (isset($schema['properties']) === true && is_array($schema['properties']) === true) {
            foreach ($schema['properties'] as $name => $prop) {
                if (is_array($prop) === true && isset($prop['type']) === true && is_array($prop['type']) === true) {
                    $schema['properties'][$name]['type'] = $this->collapseType($prop['type']);
                }
            }
        }

        return $schema;
    }

    private function collapseType(array $types): string
    {
        foreach ($types as $t) {
            if (is_string($t) === true && $t !== 'null') {
                return $t;
            }
        }

        return 'string';
    }

    public function executeFunction(string $functionName, array $parameters, ?string $userId=null): array
    {
        // Resolve the safe function name back to the original MCP id.
        $mcpId = $this->resolveMcpId($functionName);
        if ($mcpId === null) {
            return [
                'isError' => true,
                'error'   => 'unknown_function',
                'message' => "No MCP tool registered for function: {$functionName}",
            ];
        }

        $this->logger->debug(
            '[McpProviderBridge] Forwarding LLM call to MCP provider',
            ['function' => $functionName, 'mcpId' => $mcpId, 'appId' => $this->provider->getAppId()]
        );

        try {
            return $this->provider->invokeTool($mcpId, $parameters);
        } catch (\Throwable $e) {
            $this->logger->error(
                '[McpProviderBridge] Provider invocation failed',
                ['function' => $functionName, 'mcpId' => $mcpId, 'error' => $e->getMessage()]
            );
            return [
                'isError' => true,
                'error'   => 'internal_error',
                'message' => $e->getMessage(),
            ];
        }
    }

    public function setAgent(?\OCA\OpenRegister\Db\Agent $agent): void
    {
        $this->agent = $agent;
    }

    /**
     * LLPhant calls `$toolInstance->{$functionName}(...$args)` directly on
     * the tool object when the LLM returns a tool_call (see ToolManagementHandler
     * → new FunctionInfo($name, $toolInstance, ...) → LLPhant's call site).
     * Our bridge doesn't have a real PHP method per MCP tool, so funnel every
     * dynamic call through executeFunction(). Args may come in as an
     * associative-args array (single param) or as positional values; either
     * way we forward them as the MCP arguments object.
     */
    public function __call(string $functionName, array $args): mixed
    {
        $parameters = [];
        if (count($args) === 1 && is_array($args[0]) === true) {
            $parameters = $args[0];
        } elseif (count($args) > 0) {
            // Fall back: positional → numbered keys. LLPhant typically calls
            // with a single assoc-array arg, so this branch is just defensive.
            $parameters = $args;
        }

        return $this->executeFunction($functionName, $parameters);
    }

    /**
     * Convert `decidesk.createMeeting` → `decidesk_createMeeting` for OpenAI/
     * Ollama function-name compatibility. Round-trippable via resolveMcpId().
     */
    private function safeFunctionName(string $mcpId): string
    {
        return str_replace('.', '_', $mcpId);
    }

    /**
     * Inverse of safeFunctionName: walk the provider's descriptors and find
     * the one whose safe name matches. Accepts the raw mcpId too so callers
     * who already namespace correctly aren't penalised.
     */
    private function resolveMcpId(string $functionName): ?string
    {
        foreach ($this->provider->getTools() as $descriptor) {
            $rawId = (string) ($descriptor['id'] ?? '');
            if ($rawId === $functionName || $this->safeFunctionName($rawId) === $functionName) {
                return $rawId;
            }
        }

        return null;
    }

}
