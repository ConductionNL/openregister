<?php

/**
 * OpenRegister RegisterFlowTriggersEvent
 *
 * Dispatched once per request when the flow event catalog is first read. An app
 * that fires its own events into FlowTriggerService::fire() listens for it and
 * adds each trigger id with a label and a palette group, so the flow builder
 * lists them next to OpenRegister's own triggers instead of making an
 * administrator type an id.
 *
 * Contributions are collected, not validated, here: EventCatalogService refuses
 * an empty field and an id that is already in the catalog.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/clients-vault-event-flow-triggers/specs/flow-triggers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCP\EventDispatcher\Event;

/**
 * Collects the flow triggers other apps contribute to the event catalog.
 *
 * @spec openspec/changes/clients-vault-event-flow-triggers/specs/flow-triggers/spec.md
 */
class RegisterFlowTriggersEvent extends Event {

	/**
	 * The contributed triggers, in the order they were added.
	 *
	 * @var list<array{id: string, label: string, group: string}>
	 */
	private array $triggers = [];

	/**
	 * Add one trigger to the catalog.
	 *
	 * @param string $id    The trigger id the app fires, e.g. `keepiq.share.granted`.
	 * @param string $label The palette label, e.g. "A share is granted".
	 * @param string $group The palette group, e.g. "Vault".
	 *
	 * @return void
	 *
	 * @spec openspec/changes/clients-vault-event-flow-triggers/specs/flow-triggers/spec.md
	 */
	public function addTrigger(string $id, string $label, string $group): void {
		$this->triggers[] = ['id' => $id, 'label' => $label, 'group' => $group];
	}//end addTrigger()

	/**
	 * The contributed triggers, in the order they were added.
	 *
	 * @return list<array{id: string, label: string, group: string}>
	 *
	 * @spec openspec/changes/clients-vault-event-flow-triggers/specs/flow-triggers/spec.md
	 */
	public function getTriggers(): array {
		return $this->triggers;
	}//end getTriggers()
}//end class
