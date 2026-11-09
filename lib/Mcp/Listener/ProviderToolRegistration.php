<?php

/**
 * Bridges every per-app IMcpToolProvider's functions into a ToolRegistrationEvent.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Mcp\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

namespace OCA\OpenRegister\Mcp\Listener;

use OCA\OpenRegister\Event\ToolRegistrationEvent;
use OCA\OpenRegister\Mcp\Service\Mcp\McpToolsService;
use OCA\OpenRegister\Mcp\Tool\McpProviderBridge;
use Psr\Log\LoggerInterface;

/**
 * The MCP-provider half of {@see ToolRegistrationListener}.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
class ProviderToolRegistration {

	/**
	 * Constructor
	 *
	 * @param McpToolsService $mcpToolsService MCP tools service used to register MCP-sourced tools.
	 * @param LoggerInterface $logger          PSR logger.
	 */
	public function __construct(
		private readonly McpToolsService $mcpToolsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Bridge every per-app IMcpToolProvider's function into the ToolRegistrationEvent.
	 *
	 * ToolRegistry enforces the id format `app_name.tool_name` so we register ONE
	 * bridge instance per (provider, function) pair under its full MCP id (e.g.
	 * `openbuild.createApp`). The bridge is configured via setOnlyMcpId() so each
	 * entry's getFunctions() returns just that one descriptor — preventing the LLM
	 * from seeing the same provider's tool list duplicated across N registry entries.
	 *
	 * @param ToolRegistrationEvent $event The tool registration event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function registerInto(ToolRegistrationEvent $event): void {
		foreach ($this->mcpToolsService->getProviders() as $provider) {
			$appId = $provider->getAppId();
			// Skip the built-in MCP providers (registers/schemas/objects);
			// their functionality is already covered by the 5 hardcoded
			// ToolRegistry entries. Avoids the LLM seeing two parallel sets
			// with subtly different shapes.
			if (in_array($appId, ['registers', 'schemas', 'objects', 'openregister'], true) === true) {
				continue;
			}

			try {
				$this->bridgeProviderDescriptors(event: $event, provider: $provider);
			} catch (\Throwable $e) {
				$this->logger->warning(
					'[ToolRegistrationListener] Failed to bridge MCP provider',
					['appId' => $appId, 'error' => $e->getMessage()]
				);
			}//end try
		}//end foreach
	}//end registerInto()

	/**
	 * Iterate a single provider's tool descriptors and register each as a bridge.
	 *
	 * The provider's app id is read from the provider itself rather than passed
	 * alongside it: it is the same value the caller already filtered on.
	 *
	 * @param ToolRegistrationEvent $event    The tool registration event.
	 * @param object                $provider The MCP tool provider.
	 *
	 * @return void
	 */
	private function bridgeProviderDescriptors(ToolRegistrationEvent $event, object $provider): void {
		$appId = $provider->getAppId();
		foreach ($provider->getTools() as $descriptor) {
			$mcpId = (string)($descriptor['id'] ?? '');
			if ($mcpId === '' || preg_match('/^[a-z0-9_]+(\.[a-zA-Z0-9_]+)+$/', $mcpId) === 0) {
				// Tool id is non-conforming (camelCase or missing dot). Skip
				// — the LLM-visible id MUST match the ToolRegistry regex
				// (app_name.tool_name, or app_name.schema.verb for ADR-063
				// chain-2 schema-derived tools), and the agent's tools array
				// stores MCP ids verbatim.
				continue;
			}

			$bridge = new McpProviderBridge(provider: $provider, logger: $this->logger);
			$bridge->setOnlyMcpId($mcpId);

			$event->registerTool(
				id: $mcpId,
				tool: $bridge,
				metadata: [
					'name' => (string)($descriptor['name'] ?? $mcpId),
					'description' => (string)($descriptor['description'] ?? ''),
					'icon' => 'icon-category-integration',
					'app' => $appId,
				]
			);
		}//end foreach
	}//end bridgeProviderDescriptors()
}//end class
