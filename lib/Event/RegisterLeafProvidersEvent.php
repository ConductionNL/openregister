<?php

/**
 * Dispatched so sibling apps can contribute their leaves to OpenRegister.
 *
 * A leaf is how an app hooks itself onto OpenRegister objects: it contributes a
 * render surface (a tab/widget on an object), app-local data (notes, records,
 * annotations backed by the app's own store), or both. This event is the ONLY
 * server-side seam for a sibling app to contribute a leaf.
 *
 * It mirrors `RegisterMcpToolProvidersEvent` line for line — the accepted typed
 * collect-event idiom OpenRegister already uses for MCP tool providers and flow
 * nodes. Nextcloud has two idioms here and the distinction matters: when CORE
 * owns a registry it adds a `registerXProvider()` to `IRegistrationContext`;
 * when an APP owns a registry it dispatches a typed event. OpenRegister is an
 * app, so a sibling contributes a leaf by writing one `IEventListener` for this
 * event and calling `registerLeaf()` — the same shape an app that already ships
 * an MCP or flow-node listener writes.
 *
 * The event is dispatched once, lazily, when the leaf catalogue is first read in
 * a request (see `LeafRegistry`). A throwing listener costs its own leaf and
 * nothing else.
 *
 * RENDER-AND-READ BOUNDARY (ADR-066)
 * ----------------------------------
 * This event is render-and-read only by construction. A `LeafDescriptor` and an
 * `IntegrationProvider` expose no verb, command, or handler — the registry can
 * never become a command bus, and gate-27 (`no-phantom-cross-app-rpc`) still
 * forbids `getLeaf()->call(...)` patterns. Cross-app COMMANDS stay ADR-041 typed
 * `*RequestedEvent` contracts; this seam lifts the ADR-041 moratorium strictly
 * for the collect / render / read case.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/app-leaf-provider-registration/specs/leaf-provider-registration/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Service\Integration\IntegrationProvider;
use OCA\OpenRegister\Service\Integration\LeafDescriptor;
use OCP\EventDispatcher\Event;

/**
 * Carries the collector a sibling app registers its leaves on.
 */
class RegisterLeafProvidersEvent extends Event {

	/**
	 * Leaves contributed during this dispatch.
	 *
	 * Each entry is a `['descriptor' => LeafDescriptor, 'provider' => ?IntegrationProvider]`
	 * tuple. The provider is present when the descriptor declares the
	 * `data-provider` kind and null otherwise.
	 *
	 * @var array<int, array{descriptor: LeafDescriptor, provider: ?IntegrationProvider}>
	 */
	private array $leaves = [];

	/**
	 * Contribute a leaf.
	 *
	 * A descriptor declaring the `data-provider` kind MUST pass an
	 * `IntegrationProvider` here; a render-only leaf passes null. The provider
	 * runs in the CONTRIBUTING app's DI context because the listener constructed
	 * it there — the same property ADR-041 relies on for command listeners.
	 *
	 * Validation (non-empty kinds, data-provider-requires-provider, kebab-case
	 * id, first-wins on duplicate id) is applied by `LeafRegistry` when the
	 * catalogue is collected, so a bad contribution costs only its own leaf.
	 *
	 * @param LeafDescriptor $descriptor The leaf declaration.
	 * @param IntegrationProvider|null $provider The data provider, or null for a render-only leaf.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/app-leaf-provider-registration/specs/leaf-provider-registration/spec.md
	 */
	public function registerLeaf(LeafDescriptor $descriptor, ?IntegrationProvider $provider = null): void {
		$this->leaves[] = [
			'descriptor' => $descriptor,
			'provider' => $provider,
		];

	}//end registerLeaf()

	/**
	 * Every leaf contributed during this dispatch.
	 *
	 * @return array<int, array{descriptor: LeafDescriptor, provider: ?IntegrationProvider}> The leaves.
	 *
	 * @spec openspec/changes/app-leaf-provider-registration/specs/leaf-provider-registration/spec.md
	 */
	public function getLeaves(): array {
		return $this->leaves;
	}//end getLeaves()
}//end class
