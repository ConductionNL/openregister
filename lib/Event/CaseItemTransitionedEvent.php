<?php

/**
 * A plan item changed state and the transition has COMMITTED.
 *
 * Dispatched by the case layer AFTER the transaction that moved the item
 * closes, never inside it. For the three terminal states the event carries
 * a catalog trigger id (`case.item.completed`, `case.item.terminated`,
 * `case.item.disabled`) so {@see \OCA\OpenRegister\Listener\EventCatalogListener}
 * can fire it against the anchoring object like any other catalog event:
 * a catalog entry that nothing dispatches would be the "declared but never
 * fired" trigger the catalog's own docblock forbids.
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
 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-sentries-are-entry-and-exit-criteria-over-existing-engine-primitives
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\CaseItem;
use OCP\EventDispatcher\Event;

/**
 * Carries the plan item as persisted, and the state it left.
 *
 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-sentries-are-entry-and-exit-criteria-over-existing-engine-primitives
 */
class CaseItemTransitionedEvent extends Event {

	/**
	 * Constructor.
	 *
	 * @param CaseItem $item The item, already persisted in its new state.
	 * @param string $fromState The state it left.
	 */
	public function __construct(
		private readonly CaseItem $item,
		private readonly string $fromState,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * The transitioned item.
	 *
	 * @return CaseItem The item as persisted.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-sentries-are-entry-and-exit-criteria-over-existing-engine-primitives
	 */
	public function getItem(): CaseItem {
		return $this->item;
	}//end getItem()

	/**
	 * The state the item left.
	 *
	 * @return string The from-state.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-sentries-are-entry-and-exit-criteria-over-existing-engine-primitives
	 */
	public function getFromState(): string {
		return $this->fromState;
	}//end getFromState()

	/**
	 * The catalog trigger this transition corresponds to, or null when the
	 * new state is not one of the three terminal ones the catalog names.
	 *
	 * @return string|null `case.item.completed` | `case.item.terminated` | `case.item.disabled` | null.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-sentries-are-entry-and-exit-criteria-over-existing-engine-primitives
	 */
	public function getCatalogTrigger(): ?string {
		$state = (string)$this->item->getState();
		if (in_array($state, CaseItem::TERMINAL_STATES, true) === false) {
			return null;
		}

		return 'case.item.' . $state;
	}//end getCatalogTrigger()

	/**
	 * The anchoring object as a trigger subject: the same shape
	 * `EventCatalogListener::dispatch()` builds from an ObjectEntity.
	 *
	 * @return array{uuid: string, register: string, schema: string} The subject.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
	 */
	public function getSubject(): array {
		return [
			'uuid' => (string)$this->item->getObjectUuid(),
			'register' => (string)$this->item->getRegisterId(),
			'schema' => (string)$this->item->getSchemaId(),
		];
	}//end getSubject()
}//end class
