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
use OCA\OpenRegister\Service\Mcp\McpAnnotationValidator;
use Psr\Log\LoggerInterface;

/**
 * Adapter from IMcpToolProvider to ToolInterface.
 *
 * @spec openspec/specs/ai-mcp/spec.md
 *   (Requirement: REQ-DERIVED-002 — Both serving surfaces are fed from one derivation)
 */
class McpProviderBridge implements ToolInterface {

	/**
	 * Non-boolean provider-descriptor annotations copied verbatim onto the
	 * LLphant function descriptor when present, alongside the boolean
	 * `McpAnnotationValidator::HINT_KEYS`.
	 *
	 * `scope` is the ADR-063 CRUD verb. `reach` is the orthogonal blast-radius
	 * axis (`self` < `user` < `instance` < `external`) that consuming apps gate
	 * on: it answers "who can observe or be affected by this call", which
	 * `scope` does not — a `read` tool that fetches a model-chosen URL leaves
	 * the instance, and a `delete` confined to an agent's own memory does not.
	 *
	 * Neither value is interpreted here. The bridge's job is to lose nothing;
	 * classification belongs to the consumer.
	 *
	 * @var array<int, string>
	 */
	private const PASSTHROUGH_KEYS = ['scope', 'reach'];

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
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
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
	 * @spec openspec/specs/ai-mcp/spec.md
	 */
	public function setOnlyMcpId(string $mcpId): void {
		$this->onlyMcpId = $mcpId;
	}//end setOnlyMcpId()

	/**
	 * LLM-facing identifier for the tool group (the app id).
	 *
	 * @return string The provider's appId, used as the tool-group name.
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
	 */
	public function getName(): string {
		// ToolInterface getName is used as the LLM-facing identifier of the
		// tool group. Use the appId so all MCP tools under one app cluster.
		return $this->provider->getAppId();
	}//end getName()

	/**
	 * Short description shown in tool listings.
	 *
	 * @return string Human-readable description of the bridged tool group.
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
	 */
	public function getDescription(): string {
		return 'MCP-bridged tools from the ' . $this->provider->getAppId() . ' app.';
	}//end getDescription()

	/**
	 * Each MCP descriptor becomes one LLphant function definition.
	 *
	 * Additively forwards the ADR-063 annotation hints
	 * (`readOnlyHint` / `destructiveHint` / `idempotentHint`) and the
	 * `PASSTHROUGH_KEYS` (`scope`, `reach`) onto the LLphant descriptor when the
	 * provider set them, so chat-surface consumers reached through
	 * {@see \OCA\OpenRegister\Service\Mcp\ToolRegistryFacade::listTools()}
	 * see the same annotations the JSON-RPC `tools/list` surface already
	 * carries (previously dropped here — OR#369). A key is omitted entirely
	 * when the provider descriptor didn't set it; no defaults are invented.
	 * These hints are ADVISORY UX metadata only — OpenRegister's
	 * `ObjectService` RBAC enforcement remains the sole authoritative
	 * invoke-time gate, unaffected by this or any hint value (ADR-063).
	 *
	 * 🔴 A key this method does not copy is a key the consuming app cannot see,
	 * however carefully the provider declared it. That is not hypothetical:
	 * Hermiq annotated all fourteen of its native tools with a `reach` and its
	 * resolver fails closed on an absent one, so before `reach` was forwarded
	 * here every one of those tools would have arrived unannotated, resolved to
	 * `external`, and been stripped from every agent's catalogue — an app-wide
	 * outage produced entirely by a silent drop at this boundary. Anything added
	 * to a provider descriptor from now on belongs in `PASSTHROUGH_KEYS`.
	 *
	 * @return array<int,array<string,mixed>> LLphant-shaped function descriptors.
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
	 *   (Requirement: REQ-DERIVED-002 — Both serving surfaces are fed from one derivation)
	 */
	public function getFunctions(): array {
		$functions = [];
		foreach ($this->provider->getTools() as $descriptor) {
			$rawId = (string)($descriptor['id'] ?? '');
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
			$function = [
				'name' => $this->safeFunctionName(mcpId: $rawId),
				'mcpId' => $rawId,
				'description' => (string)($descriptor['description'] ?? $descriptor['name'] ?? $rawId),
				'parameters' => $this->sanitiseSchema(schema: $inputSchema),
			];

			foreach (McpAnnotationValidator::HINT_KEYS as $hintKey) {
				if (array_key_exists($hintKey, $descriptor) === true) {
					$function[$hintKey] = $descriptor[$hintKey];
				}
			}

			foreach (self::PASSTHROUGH_KEYS as $passthroughKey) {
				if (array_key_exists($passthroughKey, $descriptor) === true) {
					$function[$passthroughKey] = $descriptor[$passthroughKey];
				}
			}

			$functions[] = $function;
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
	 * @spec openspec/specs/ai-mcp/spec.md
	 */
	private function sanitiseSchema(array $schema): array {
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
	 * @spec openspec/specs/ai-mcp/spec.md
	 */
	private function collapseType(array $types): string {
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
	 * @param string $functionName LLphant-side function name.
	 * @param array<string,mixed> $parameters Decoded MCP arguments object.
	 * @param string|null $userId Optional acting user id.
	 *
	 * @return array<string,mixed> MCP-shaped response or error envelope.
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
	 */
	public function executeFunction(string $functionName, array $parameters, ?string $userId = null): array {
		// Resolve the safe function name back to the original MCP id.
		$mcpId = $this->resolveMcpId(functionName: $functionName);
		if ($mcpId === null) {
			return [
				'isError' => true,
				'error' => 'unknown_function',
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
				'error' => 'internal_error',
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
	 * @spec openspec/specs/ai-mcp/spec.md
	 */
	public function setAgent(?\OCA\OpenRegister\Db\Agent $agent): void {
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
	 * @param string $functionName The function LLPhant resolved.
	 * @param array<int,mixed> $args Positional or single-array argument list.
	 *
	 * @return string executeFunction()'s result JSON-encoded (LLPhant requires a string
	 *                tool result — see OR#269).
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
	 */
	public function __call(string $functionName, array $args): mixed {
		$parameters = [];
		if (count($args) === 1 && is_array($args[0]) === true) {
			$parameters = $args[0];
		} elseif (count($args) > 0) {
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
	 * @spec openspec/specs/ai-mcp/spec.md
	 */
	private function safeFunctionName(string $mcpId): string {
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
	 * @spec openspec/specs/ai-mcp/spec.md
	 */
	private function resolveMcpId(string $functionName): ?string {
		foreach ($this->provider->getTools() as $descriptor) {
			$rawId = (string)($descriptor['id'] ?? '');
			if ($rawId === $functionName || $this->safeFunctionName(mcpId: $rawId) === $functionName) {
				return $rawId;
			}
		}

		return null;
	}//end resolveMcpId()
}//end class
