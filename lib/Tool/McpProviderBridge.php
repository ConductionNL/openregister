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
 * @category Tool
 * @package  OCA\OpenRegister\Tool
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
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

    /**
     * Optional agent context attached by the registry.
     *
     * @var \OCA\OpenRegister\Db\Agent|null
     */
    private ?\OCA\OpenRegister\Db\Agent $agent = null;

    /**
     * Optional whitelist — when set, getFunctions() returns ONLY the
     * descriptor whose MCP id matches this name. Used by
     * ToolRegistrationListener so each (provider, function) pair can be
     * registered as a separate ToolRegistry entry under its full
     * `appId.functionName` id (the registry enforces a two-part format
     * and won't accept the bare appId).
     *
     * @var string|null
     */
    private ?string $onlyMcpId = null;

    /**
     * Build the bridge around an MCP provider.
     *
     * @param IMcpToolProvider $provider Per-app MCP tool provider.
     * @param LoggerInterface  $logger   PSR logger.
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-05-24-ai-mcp/tasks.md#task-4
     */
    public function __construct(
        private readonly IMcpToolProvider $provider,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Restrict this bridge instance to one specific MCP function id.
     *
     * @param string $mcpId MCP function id to whitelist.
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-05-24-ai-mcp/tasks.md#task-4
     */
    public function setOnlyMcpId(string $mcpId): void
    {
        $this->onlyMcpId = $mcpId;
    }//end setOnlyMcpId()

    /**
     * LLM-facing identifier for the tool group (the app id).
     *
     * @return string The provider's appId, used as the tool-group name.
     *
     * @spec openspec/changes/retrofit-2026-05-24-ai-mcp/tasks.md#task-4
     */
    public function getName(): string
    {
        // ToolInterface getName is used as the LLM-facing identifier of the
        // tool group. Use the appId so all MCP tools under one app cluster.
        return $this->provider->getAppId();
    }//end getName()

    /**
     * Short description shown in tool listings.
     *
     * @return string Human-readable description of the bridged tool group.
     *
     * @spec openspec/changes/retrofit-2026-05-24-ai-mcp/tasks.md#task-4
     */
    public function getDescription(): string
    {
        return 'MCP-bridged tools from the '.$this->provider->getAppId().' app.';
    }//end getDescription()

    /**
     * Each MCP descriptor becomes one LLphant function definition.
     *
     * @return array<int,array<string,mixed>> LLphant-shaped function descriptors.
     *
     * @spec openspec/changes/retrofit-2026-05-24-ai-mcp/tasks.md#task-4
     */
    public function getFunctions(): array
    {
        $functions = [];
        foreach ($this->provider->getTools() as $descriptor) {
            $rawId = (string) ($descriptor['id'] ?? '');
            if ($rawId === '') {
                continue;
            }

            if ($this->onlyMcpId !== null && $rawId !== $this->onlyMcpId) {
                continue;
            }

            // LLphant / OpenAI function names disallow dots in some models;
            // expose the raw MCP id as the function name AND rewrite a
            // safe alias (underscore) so both forms route back the same way.
            $inputSchema = $descriptor['inputSchema'] ?? ['type' => 'object', 'properties' => []];
            $functions[] = [
                'name'        => $this->safeFunctionName(mcpId: $rawId),
                'mcpId'       => $rawId,
                'description' => (string) ($descriptor['description'] ?? $descriptor['name'] ?? $rawId),
                'parameters'  => $this->sanitiseSchema(schema: $inputSchema),
            ];
        }//end foreach

        return $functions;
    }//end getFunctions()

    /**
     * Coerce JSON-Schema-style nullable types (`['string','null']`) into
     * single-string types — LLPhant's Parameter constructor only accepts a
     * scalar string type, so otherwise function-info construction fails with
     * a TypeError before the LLM ever sees the tool.
     *
     * @param array<string,mixed> $schema JSON-Schema-shaped parameter schema.
     *
     * @return array<string,mixed> Sanitised schema.
     *
     * @spec openspec/changes/retrofit-2026-05-24-ai-mcp/tasks.md#task-4
     */
    private function sanitiseSchema(array $schema): array
    {
        if (isset($schema['type']) === true && is_array($schema['type']) === true) {
            $schema['type'] = $this->collapseType(types: $schema['type']);
        }

        if (isset($schema['properties']) === true && is_array($schema['properties']) === true) {
            foreach ($schema['properties'] as $name => $prop) {
                if (is_array($prop) === true && isset($prop['type']) === true && is_array($prop['type']) === true) {
                    $schema['properties'][$name]['type'] = $this->collapseType(types: $prop['type']);
                }
            }
        }

        return $schema;
    }//end sanitiseSchema()

    /**
     * Collapse a JSON-Schema nullable type array into a single string.
     *
     * @param array<int,mixed> $types JSON-Schema type-list.
     *
     * @return string The first non-null string type, or `string` as fallback.
     *
     * @spec openspec/changes/retrofit-2026-05-24-ai-mcp/tasks.md#task-4
     */
    private function collapseType(array $types): string
    {
        foreach ($types as $t) {
            if (is_string($t) === true && $t !== 'null') {
                return $t;
            }
        }

        return 'string';
    }//end collapseType()

    /**
     * Invoke an MCP tool by its (safe or raw) function name.
     *
     * @param string              $functionName LLphant-side function name.
     * @param array<string,mixed> $parameters   Decoded MCP arguments object.
     * @param string|null         $userId       Optional acting user id.
     *
     * @return array<string,mixed> MCP-shaped response or error envelope.
     *
     * @spec openspec/changes/retrofit-2026-05-24-ai-mcp/tasks.md#task-5
     */
    public function executeFunction(string $functionName, array $parameters, ?string $userId=null): array
    {
        // Resolve the safe function name back to the original MCP id.
        $mcpId = $this->resolveMcpId(functionName: $functionName);
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
    }//end executeFunction()

    /**
     * Attach the active agent context.
     *
     * @param \OCA\OpenRegister\Db\Agent|null $agent Acting agent or null.
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-05-24-ai-mcp/tasks.md#task-4
     */
    public function setAgent(?\OCA\OpenRegister\Db\Agent $agent): void
    {
        $this->agent = $agent;
    }//end setAgent()

    /**
     * LLPhant calls `$toolInstance->{$functionName}(...$args)` directly on
     * the tool object when the LLM returns a tool_call (see ToolManagementHandler
     * → new FunctionInfo($name, $toolInstance, ...) → LLPhant's call site).
     * Our bridge doesn't have a real PHP method per MCP tool, so funnel every
     * dynamic call through executeFunction(). Args may come in as an
     * associative-args array (single param) or as positional values; either
     * way we forward them as the MCP arguments object.
     *
     * @param string           $functionName The function LLPhant resolved.
     * @param array<int,mixed> $args         Positional or single-array argument list.
     *
     * @return string executeFunction()'s result JSON-encoded (LLPhant requires a string
     *                tool result — see OR#269).
     *
     * @spec openspec/changes/retrofit-2026-05-24-ai-mcp/tasks.md#task-5
     */
    public function __call(string $functionName, array $args): mixed
    {
        $parameters = [];
        if (count($args) === 1 && is_array($args[0]) === true) {
            $parameters = $args[0];
        } else if (count($args) > 0) {
            // Fall back: positional → numbered keys. LLPhant typically calls
            // with a single assoc-array arg, so this branch is just defensive.
            $parameters = $args;
        }

        $result = $this->executeFunction(functionName: $functionName, parameters: $parameters);

        // LLPhant's tool-call handling requires the tool RESULT as a ?string, not an array
        // (OllamaChat::callFunction → new CalledFunction(..., $return) type-hints ?string,
        // and OpenAI tool messages are strings too). MCP tools return a structured array, so
        // encode it to a JSON string here — the LLM reads the tool output as text. This is
        // the fix for OR#269 (array given to CalledFunction $return → agent tool-calls 500).
        // executeFunction() still returns the array for direct callers (the MCP server).
        $encoded = json_encode($result);
        if ($encoded === false) {
            return '{"isError":true,"error":"encode_failed","message":"Tool result could not be encoded."}';
        }

        return $encoded;
    }//end __call()

    /**
     * Convert `decidesk.createMeeting` → `decidesk_createMeeting` for OpenAI/
     * Ollama function-name compatibility. Round-trippable via resolveMcpId().
     *
     * @param string $mcpId Raw MCP function id (dotted).
     *
     * @return string Safe function name with dots replaced by underscores.
     *
     * @spec openspec/changes/retrofit-2026-05-24-ai-mcp/tasks.md#task-5
     */
    private function safeFunctionName(string $mcpId): string
    {
        return str_replace('.', '_', $mcpId);
    }//end safeFunctionName()

    /**
     * Inverse of safeFunctionName: walk the provider's descriptors and find
     * the one whose safe name matches. Accepts the raw mcpId too so callers
     * who already namespace correctly aren't penalised.
     *
     * @param string $functionName LLphant-side function name (safe or raw).
     *
     * @return string|null Original MCP id, or null when no match is found.
     *
     * @spec openspec/changes/retrofit-2026-05-24-ai-mcp/tasks.md#task-5
     */
    private function resolveMcpId(string $functionName): ?string
    {
        foreach ($this->provider->getTools() as $descriptor) {
            $rawId = (string) ($descriptor['id'] ?? '');
            if ($rawId === $functionName || $this->safeFunctionName(mcpId: $rawId) === $functionName) {
                return $rawId;
            }
        }

        return null;
    }//end resolveMcpId()
}//end class
