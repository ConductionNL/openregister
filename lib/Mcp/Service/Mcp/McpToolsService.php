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
 * @spec openspec/specs/chat-ai/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Mcp\Service\Mcp;

use Generator;
use InvalidArgumentException;
use OCA\OpenRegister\Mcp\IMcpToolProvider;
use Psr\Log\LoggerInterface;
use stdClass;

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
class McpToolsService implements McpToolCatalogueInterface {

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
	 * @param LoggerInterface $logger Logger
	 */
	public function __construct(
		array $providers,
		private readonly LoggerInterface $logger,
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
	public function getProviders(): array {
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
	public function listTools(): array {
		$tools = [];
		foreach ($this->providers as $provider) {
			$tools = array_merge($tools, $this->providerTools(provider: $provider));
		}

		return ['tools' => $tools];
	}//end listTools()

	/**
	 * The conforming tool descriptors of one provider, with their input schema normalised.
	 *
	 * Namespace enforcement: descriptors with the wrong prefix are dropped and logged.
	 *
	 * @param IMcpToolProvider $provider The provider.
	 *
	 * @return list<array<string, mixed>> The provider's descriptors whose id starts with its app id.
	 */
	private function providerTools(IMcpToolProvider $provider): array {
		$appId = $provider->getAppId();
		$tools = [];
		foreach ($provider->getTools() as $descriptor) {
			$toolId = $descriptor['id'] ?? '';
			if (str_starts_with($toolId, $appId . '.') === false) {
				$this->logDroppedTool(provider: $provider, toolId: $toolId);
				continue;
			}
			$descriptor['inputSchema'] = $this->normaliseInputSchema(inputSchema: ($descriptor['inputSchema'] ?? []));
			$tools[] = $descriptor;
		}

		return $tools;
	}//end providerTools()

	/**
	 * Log a dropped descriptor whose id does not start with its provider's app id.
	 *
	 * @param IMcpToolProvider $provider The provider of the descriptor.
	 * @param string           $toolId   The descriptor's id.
	 *
	 * @return void
	 */
	private function logDroppedTool(IMcpToolProvider $provider, string $toolId): void {
		$this->logger->warning(
			message: '[McpToolsService] Dropping tool descriptor with non-conforming namespace prefix',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'providerClass' => get_class($provider),
				'appId' => $provider->getAppId(),
				'toolId' => $toolId,
			]
		);
	}//end logDroppedTool()

	/**
	 * Force `inputSchema.properties` to serialise as a JSON object.
	 *
	 * MCP requires `inputSchema` to be a JSON Schema object whose `properties`
	 * is itself an object. A tool with no parameters has an empty properties
	 * map, and PHP's `json_encode` renders an empty PHP array as `[]` (a JSON
	 * array), not `{}`. Strict MCP clients — notably the Claude CLI's MCP client
	 * — reject the whole `tools/list` with
	 * "Invalid input: expected record, received array (at tools.N.inputSchema.properties)",
	 * so a single argument-less tool breaks every tool the server exposes.
	 *
	 * Force an empty or absent `properties` (and an absent `type`) to a
	 * `stdClass`/`'object'` so the schema always serialises validly. Providers
	 * that already supply a non-empty properties map are left untouched.
	 *
	 * @param mixed $inputSchema The descriptor's `inputSchema` (usually an array).
	 *
	 * @return array<string, mixed> The normalised input schema.
	 */
	private function normaliseInputSchema(mixed $inputSchema): array {
		if (is_array($inputSchema) === false) {
			return ['type' => 'object', 'properties' => new stdClass()];
		}

		$inputSchema['type'] ??= 'object';
		if ($this->hasProperties(inputSchema: $inputSchema) === false) {
			$inputSchema['properties'] = new stdClass();
		}

		return $inputSchema;
	}//end normaliseInputSchema()

	/**
	 * Whether an input schema has a properties map that is neither absent, null nor empty.
	 *
	 * @param array<string, mixed> $inputSchema The input schema.
	 *
	 * @return bool True when the schema's own properties can be kept.
	 */
	private function hasProperties(array $inputSchema): bool {
		return ($inputSchema['properties'] ?? null) !== null && $inputSchema['properties'] !== [];
	}//end hasProperties()

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
	 * @param string $name Tool identifier — descriptor `name` (e.g. "registers")
	 *                     or namespaced `id` (e.g. "openregister.registers").
	 * @param array<string, mixed> $arguments Tool arguments
	 *
	 * @return array<string, mixed> MCP tool result with content array
	 *
	 * @throws InvalidArgumentException If no provider handles the tool id
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid2/tasks.md#task-6
	 */
	public function callTool(string $name, array $arguments): array {
		$this->logger->debug(message: '[MCP] Tool call', context: ['tool' => $name, 'arguments' => $arguments]);
		$match = $this->findProviderForTool(toolId: $name);
		if ($match === null) {
			throw new InvalidArgumentException('Unknown tool: ' . $name);
		}

		$outcome = $this->invokeMatch(match: $match, arguments: $arguments);
		if ($outcome['error'] !== null) {
			$this->logger->error(message: '[MCP] Tool execution failed', context: ['tool' => $name, 'error' => $outcome['error']->getMessage()]);
			return $this->textContent(text: json_encode(value: $outcome['result'])) + ['isError' => true];
		}

		return $this->textContent(text: json_encode(value: $outcome['result'], flags: JSON_PRETTY_PRINT)) + ['isError' => false];
	}//end callTool()

	/**
	 * An MCP tool result whose content is one text item.
	 *
	 * @param string|false $text The text (false when the result could not be encoded).
	 *
	 * @return array{content: list<array{type: string, text: string|false}>}
	 */
	private function textContent(string|false $text): array {
		return ['content' => [['type' => 'text', 'text' => $text]]];
	}//end textContent()

	/**
	 * Invoke a tool by namespaced id or short name, returning a flat result array.
	 *
	 * Used by ChatStreamController to invoke tools in the LLM pipeline
	 * and emit tool_result SSE events. Accepts both descriptor `id`
	 * ("openregister.registers") and descriptor `name` ("registers")
	 * forms; the resolved descriptor's namespaced id is forwarded to
	 * the owning provider.
	 *
	 * @param string $toolId Tool identifier — descriptor `name` or namespaced `id`.
	 * @param array<string, mixed> $arguments Tool arguments
	 *
	 * @return array{result: array<string, mixed>, isError: bool} Result envelope
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid2/tasks.md#task-6
	 */
	public function invokeTool(string $toolId, array $arguments): array {
		$match = $this->findProviderForTool(toolId: $toolId);
		if ($match === null) {
			return ['result' => ['error' => 'Unknown tool: ' . $toolId], 'isError' => true];
		}

		$outcome = $this->invokeMatch(match: $match, arguments: $arguments);
		if ($outcome['error'] !== null) {
			$this->logger->error(message: '[MCP] invokeTool failed', context: ['tool' => $toolId, 'error' => $outcome['error']->getMessage()]);
		}

		return ['result' => $outcome['result'], 'isError' => $outcome['error'] !== null];
	}//end invokeTool()

	/**
	 * Invoke the matched tool on its provider, catching whatever it throws.
	 *
	 * Forwards the descriptor's namespaced id, not the raw client-supplied
	 * identifier — providers that multiplex on $toolId must see the canonical
	 * form even when the client addressed the tool by short name.
	 *
	 * Catches \Throwable, not \Exception: TypeError / ArgumentCountError and
	 * friends are \Error subclasses. If only \Exception were caught those would
	 * propagate to the framework as fatal 500s without the SSE / JSON envelope
	 * the caller is parsing.
	 *
	 * @param array{provider: IMcpToolProvider, descriptor: array<string, mixed>, requested: string} $match     The match.
	 * @param array<string, mixed>                                                                     $arguments Tool arguments.
	 *
	 * @return array{result: array<string, mixed>, error: \Throwable|null} The result, or the error and its message as result.
	 */
	private function invokeMatch(array $match, array $arguments): array {
		$providerToolId = (string)($match['descriptor']['id'] ?? $match['requested']);
		try {
			return ['result' => $match['provider']->invokeTool(toolId: $providerToolId, arguments: $arguments), 'error' => null];
		} catch (\Throwable $e) {
			return ['result' => ['error' => $e->getMessage()], 'error' => $e];
		}
	}//end invokeMatch()

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
	 * The first matching provider + its descriptor is returned, or null if not found.
	 *
	 * @param string $toolId Tool identifier as sent by the client.
	 *
	 * @return array{provider: IMcpToolProvider, descriptor: array<string, mixed>, byId: bool, requested: string}|null
	 */
	private function findProviderForTool(string $toolId): ?array {
		$matches = $this->matchesFor(toolId: $toolId);
		if ($matches->valid() === false) {
			return null;
		}

		// An exact id-match is canonical (listTools() enforces the prefix); only a short name can collide.
		$first = $matches->current();
		if ($first['byId'] === false) {
			$this->warnOnCollisions(first: $first, matches: $matches);
		}

		return $first;
	}//end findProviderForTool()

	/**
	 * The descriptors whose id or short name is the tool id, in provider order, found lazily.
	 *
	 * @param string $toolId Tool identifier as sent by the client.
	 *
	 * @return Generator<int, array{provider: IMcpToolProvider, descriptor: array<string, mixed>, byId: bool, requested: string}>
	 */
	private function matchesFor(string $toolId): Generator {
		foreach ($this->providers as $provider) {
			foreach ($provider->getTools() as $descriptor) {
				$byId = (($descriptor['id'] ?? '') === $toolId);
				if ($byId === true || ($descriptor['name'] ?? '') === $toolId) {
					yield ['provider' => $provider, 'descriptor' => $descriptor, 'byId' => $byId, 'requested' => $toolId];
				}
			}
		}
	}//end matchesFor()

	/**
	 * Count the matches after the first and log the short-name collision once when there are any.
	 *
	 * @param array{provider: IMcpToolProvider, requested: string} $first   The first match, which wins.
	 * @param Generator                                            $matches The matches, positioned on the first.
	 *
	 * @return void
	 */
	private function warnOnCollisions(array $first, Generator $matches): void {
		$collisions = 0;
		for ($matches->next(); $matches->valid() === true; $matches->next()) {
			++$collisions;
		}

		if ($collisions > 0) {
			$this->logger->warning(
				message: '[MCP] Short-name tool collision — first provider wins; use namespaced id to disambiguate.',
				context: ['tool' => $first['requested'], 'winningAppId' => $first['provider']->getAppId(), 'collisionCount' => $collisions]
			);
		}
	}//end warnOnCollisions()

	/**
	 * Add a provider to the list at runtime (e.g. from external apps).
	 *
	 * Idempotent: if a provider with the same `getAppId()` is already
	 * registered, the call is a no-op. This handles the dual-path case
	 * where (a) OR's factory in `McpProviderDiscovery::register`
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
	public function addProvider(IMcpToolProvider $provider): void {
		$existing = $this->providerWithAppId(appId: $provider->getAppId());
		if ($existing === null) {
			$this->providers[] = $provider;
			return;
		}

		$this->logger->debug(
			message: '[McpToolsService] addProvider() skipped — provider with same appId already registered',
			context: ['appId' => $existing->getAppId(), 'incomingClass' => get_class($provider), 'existingClass' => get_class($existing)]
		);
	}//end addProvider()

	/**
	 * The registered provider with the given app id, if any.
	 *
	 * @param string $appId The app id.
	 *
	 * @return IMcpToolProvider|null The first provider with that app id, or null.
	 */
	private function providerWithAppId(string $appId): ?IMcpToolProvider {
		foreach ($this->providers as $existing) {
			if ($existing->getAppId() === $appId) {
				return $existing;
			}
		}

		return null;
	}//end providerWithAppId()
}//end class
