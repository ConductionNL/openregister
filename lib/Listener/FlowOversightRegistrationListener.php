<?php

/**
 * Contributes OpenRegister's built-in oversight checks.
 *
 * OpenRegister registers through the same event it asks other apps to use, so
 * the contribution path is exercised by its owner and cannot rot unnoticed —
 * the same reason `FlowNodeRegistrationListener` exists for node types.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-engine-unification/specs/flow-oversight/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Service\Flow\Oversight\KillSwitchCheck;
use OCA\OpenRegister\Service\Flow\RegisterFlowOversightEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Contributes the built-in oversight checks.
 *
 * @template-implements IEventListener<RegisterFlowOversightEvent>
 *
 * @spec openspec/changes/flow-engine-unification/specs/flow-oversight/spec.md
 */
class FlowOversightRegistrationListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param KillSwitchCheck $killSwitch The instance-wide flow kill switch.
	 */
	public function __construct(
		private readonly KillSwitchCheck $killSwitch,
	) {

	}//end __construct()

	/**
	 * Register the built-ins.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-oversight/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof RegisterFlowOversightEvent) === false) {
			return;
		}

		$event->registerCheck(check: $this->killSwitch);

	}//end handle()
}//end class
