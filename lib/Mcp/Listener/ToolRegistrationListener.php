<?php

/**
 * OpenRegister Tool Registration Listener
 *
 * Listens to ToolRegistrationEvent and registers OpenRegister's built-in tools.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Mcp\Listener;

use OCA\OpenRegister\Event\ToolRegistrationEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Tool Registration Listener
 *
 * Registers OpenRegister's built-in tools when the ToolRegistrationEvent is dispatched,
 * then bridges every per-app MCP provider's tools after them (built-ins first).
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @template-implements IEventListener<ToolRegistrationEvent>
 */
class ToolRegistrationListener implements IEventListener {

	/**
	 * Constructor
	 *
	 * @param BuiltinToolRegistration  $builtinTools  Registers the five built-in tools.
	 * @param ProviderToolRegistration $providerTools Bridges the MCP providers' tools.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		private readonly BuiltinToolRegistration $builtinTools,
		private readonly ProviderToolRegistration $providerTools,
	) {
	}//end __construct()

	/**
	 * Handle the event
	 *
	 * @param Event $event The event
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof ToolRegistrationEvent) === false) {
			return;
		}

		$this->builtinTools->registerInto(event: $event);
		$this->providerTools->registerInto(event: $event);
	}//end handle()
}//end class
