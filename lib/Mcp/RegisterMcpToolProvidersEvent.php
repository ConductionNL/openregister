<?php

/**
 * Dispatched so apps can contribute their MCP tool providers.
 *
 * The same pattern the flow node registry uses, and the same one core's
 * workflow engine uses for operations, checks and entities. An app that already
 * writes a listener to contribute a flow node writes the same listener here.
 *
 * WHY THIS REPLACES THE SCAN
 * --------------------------
 * Discovery previously probed every installed app's `info.xml`, built candidate
 * container aliases (`OCA\OpenRegister\Mcp\IMcpToolProvider::<appId>`), resolved
 * each through the container while catching autoloader misses, and then cached
 * the resolution map with two invalidation mechanisms — an app-list hash plus a
 * clamped TTL, because an app upgrade can add a provider without changing the
 * app list.
 *
 * All of that existed because it scanned for something apps never announced.
 * Apps DO announce a listener, so none of it is needed: the event is dispatched
 * lazily, once, only when the catalogue is read.
 *
 * Nextcloud has two idioms here and the distinction matters. When CORE owns a
 * registry it adds a `registerXProvider()` to `IRegistrationContext` — there are
 * thirty-odd of those, and only core can write them. When an APP owns a
 * registry it dispatches a typed event. OpenRegister is an app.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Mcp
 * @package  OCA\OpenRegister\Mcp
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/or-mcp-registration-event/specs/mcp-discovery/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Mcp;

use OCP\EventDispatcher\Event;

/**
 * Carries the collector an app registers its MCP tool providers on.
 */
class RegisterMcpToolProvidersEvent extends Event {

	/**
	 * Providers contributed during this dispatch.
	 *
	 * @var array<int, IMcpToolProvider>
	 */
	private array $providers = [];

	/**
	 * Contribute a provider.
	 *
	 * @param IMcpToolProvider $provider The provider.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/or-mcp-registration-event/specs/mcp-discovery/spec.md
	 */
	public function registerProvider(IMcpToolProvider $provider): void {
		$this->providers[] = $provider;

	}//end registerProvider()

	/**
	 * Everything contributed during this dispatch.
	 *
	 * @return array<int, IMcpToolProvider> The providers.
	 *
	 * @spec openspec/changes/or-mcp-registration-event/specs/mcp-discovery/spec.md
	 */
	public function getProviders(): array {
		return $this->providers;
	}//end getProviders()
}//end class
