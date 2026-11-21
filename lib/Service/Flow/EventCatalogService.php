<?php

/**
 * OpenRegister flow event catalog.
 *
 * Single source of truth for the triggers a declarative flow (`x-openregister-flows`)
 * may subscribe to. The visual flow builder reads this catalog (via
 * `GET /api/flow/event-catalog`) to populate its trigger palette, and
 * {@see \OCA\OpenRegister\Listener\FlowTriggerListener} fires the same ids
 * into {@see \OCA\OpenRegister\Service\Flow\FlowTriggerService}.
 *
 * Every entry is a real, dispatched event that resolves to an OpenRegister
 * object (so the flow's schema selects which flows run) — there are no
 * "declared but never fired" triggers. The canonical object-lifecycle triggers
 * keep a `legacy` alias (`created`/`updated`/`deleted`) so flows authored before
 * the catalog existed continue to fire unchanged.
 *
 * Other apps add their own triggers through {@see RegisterFlowTriggersEvent},
 * collected once per request on the first read and listed after the entries
 * above. A contribution with an empty field, or an id the catalog already has,
 * is refused and logged.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/visual-flow-builder/specs/flow-builder/spec.md
 * @spec openspec/changes/clients-vault-event-flow-triggers/specs/flow-triggers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Event\RegisterFlowTriggersEvent;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Declares the event catalog that backs flow triggers.
 *
 * @spec openspec/changes/clients-vault-event-flow-triggers/specs/flow-triggers/spec.md
 */
class EventCatalogService implements EventCatalogServiceInterface {
	/**
	 * The canonical trigger catalog. Order is display order.
	 *
	 * Each entry:
	 *  - id:     stable catalog trigger id (what a flow stores as its `trigger`).
	 *  - label:  human label for the builder.
	 *  - group:  palette grouping.
	 *  - legacy: optional pre-catalog alias that MUST keep firing the same flows.
	 *
	 * @var array<int, array{id: string, label: string, group: string, legacy?: string}>
	 */
	private const CATALOG = [
		['id' => 'object.created', 'label' => 'An object is created', 'group' => 'Object', 'legacy' => 'created'],
		['id' => 'object.updated', 'label' => 'An object is updated', 'group' => 'Object', 'legacy' => 'updated'],
		['id' => 'object.deleted', 'label' => 'An object is deleted', 'group' => 'Object', 'legacy' => 'deleted'],
		['id' => 'object.locked', 'label' => 'An object is locked', 'group' => 'Object'],
		['id' => 'object.unlocked', 'label' => 'An object is unlocked', 'group' => 'Object'],
		['id' => 'object.reverted', 'label' => 'An object is reverted', 'group' => 'Object'],
		['id' => 'object.transitioned', 'label' => 'An object changes state', 'group' => 'Object'],
		['id' => 'file.created', 'label' => 'A file is created', 'group' => 'File'],
		['id' => 'file.updated', 'label' => 'A file is written', 'group' => 'File'],
		['id' => 'file.deleted', 'label' => 'A file is deleted', 'group' => 'File'],
		['id' => 'user.created', 'label' => 'A user is created', 'group' => 'User'],
		['id' => 'user.deleted', 'label' => 'A user is deleted', 'group' => 'User'],
		['id' => 'share.created', 'label' => 'A share is created', 'group' => 'Share'],
		['id' => 'share.deleted', 'label' => 'A share is deleted', 'group' => 'Share'],
		['id' => 'tag.assigned', 'label' => 'A tag is assigned', 'group' => 'Tag'],
		['id' => 'tag.unassigned', 'label' => 'A tag is removed', 'group' => 'Tag'],
		// Plan-item lifecycle (flow-cmmn-case-semantics). Dispatched by the case
		// layer as CaseItemTransitionedEvent against the anchoring object, so one
		// plan item's outcome can satisfy another item's sentry or start a flow.
		// Additive: aliasesFor() is unchanged and no row enters the trigger index.
		['id' => 'case.item.completed', 'label' => 'A plan item is completed', 'group' => 'Case'],
		['id' => 'case.item.terminated', 'label' => 'A plan item is terminated', 'group' => 'Case'],
		['id' => 'case.item.disabled', 'label' => 'A plan item is disabled', 'group' => 'Case'],
	];

	/**
	 * The triggers other apps contributed, collected on the first read.
	 *
	 * @var list<array{id: string, label: string, group: string}>|null
	 */
	private ?array $contributed = null;

	/**
	 * Constructor.
	 *
	 * Both collaborators are optional so the catalog still answers its own
	 * entries where nothing is wired, as in a bare unit test.
	 *
	 * @param IEventDispatcher|null $dispatcher Dispatches RegisterFlowTriggersEvent.
	 * @param LoggerInterface|null  $logger     Records a refused or failed contribution.
	 *
	 * @spec openspec/changes/clients-vault-event-flow-triggers/specs/flow-triggers/spec.md
	 */
	public function __construct(
		private readonly ?IEventDispatcher $dispatcher=null,
		private readonly ?LoggerInterface $logger=null,
	) {
	}//end __construct()

	/**
	 * Return the catalog for the API/builder.
	 *
	 * @return array<int, array{id: string, label: string, group: string, legacy?: string}>
	 *
	 * @spec openspec/changes/clients-vault-event-flow-triggers/specs/flow-triggers/spec.md
	 */
	public function getCatalog(): array {
		return array_merge(self::CATALOG, $this->contributed());
	}//end getCatalog()

	/**
	 * Every trigger id a flow may legally store (catalog ids + legacy aliases).
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/clients-vault-event-flow-triggers/specs/flow-triggers/spec.md
	 */
	public function knownTriggerIds(): array {
		$ids = [];
		foreach ($this->getCatalog() as $entry) {
			$ids[] = $entry['id'];
			if (isset($entry['legacy']) === true) {
				$ids[] = $entry['legacy'];
			}
		}

		return $ids;
	}//end knownTriggerIds()

	/**
	 * The set of trigger strings equivalent to a dispatched trigger.
	 *
	 * A flow matches the dispatched trigger when its stored `trigger` is any
	 * member of this set, so `object.created` and legacy `created` are
	 * interchangeable and existing flows are never orphaned.
	 *
	 * @param string $dispatched The trigger the listener dispatched (catalog id or legacy alias).
	 *
	 * @return array<int, string> The equivalent trigger strings (always includes $dispatched).
	 */
	public function aliasesFor(string $dispatched): array {
		foreach (self::CATALOG as $entry) {
			if ($entry['id'] === $dispatched || (($entry['legacy'] ?? null) === $dispatched)) {
				$set = [$entry['id']];
				if (isset($entry['legacy']) === true) {
					$set[] = $entry['legacy'];
				}

				return $set;
			}
		}

		// Unknown/custom trigger: match only itself (round-trips unknown ids).
		return [$dispatched];
	}//end aliasesFor()

	/**
	 * The contributed triggers, collected once per request.
	 *
	 * @return list<array{id: string, label: string, group: string}>
	 *
	 * @spec openspec/changes/clients-vault-event-flow-triggers/specs/flow-triggers/spec.md
	 */
	private function contributed(): array {
		if ($this->contributed !== null) {
			return $this->contributed;
		}

		// Set BEFORE dispatching: a listener that reads the catalog would
		// otherwise re-enter and dispatch again.
		$this->contributed = [];
		if ($this->dispatcher === null) {
			return [];
		}

		$this->contributed = $this->accepted(offered: $this->collect(dispatcher: $this->dispatcher));
		return $this->contributed;
	}//end contributed()

	/**
	 * Ask the listening apps for their triggers; a throwing listener keeps what came before it.
	 *
	 * @param IEventDispatcher $dispatcher The dispatcher.
	 *
	 * @return list<array{id: string, label: string, group: string}>
	 *
	 * @spec openspec/changes/clients-vault-event-flow-triggers/specs/flow-triggers/spec.md
	 */
	private function collect(IEventDispatcher $dispatcher): array {
		$event = new RegisterFlowTriggersEvent();
		try {
			$dispatcher->dispatchTyped($event);
		} catch (Throwable $exception) {
			$this->logger?->warning(
				'[EventCatalogService] A flow trigger contribution failed: {msg}',
				['msg' => $exception->getMessage(), 'exception' => $exception]
			);
		}

		return $event->getTriggers();
	}//end collect()

	/**
	 * The offered triggers that are complete and new; the first offer of an id wins.
	 *
	 * @param list<array{id: string, label: string, group: string}> $offered The offered triggers.
	 *
	 * @return list<array{id: string, label: string, group: string}>
	 *
	 * @spec openspec/changes/clients-vault-event-flow-triggers/specs/flow-triggers/spec.md
	 */
	private function accepted(array $offered): array {
		$taken    = array_fill_keys(self::ownIds(), true);
		$accepted = [];
		foreach ($offered as $entry) {
			$entry = array_map('trim', $entry);
			if (in_array('', $entry, true) === true || isset($taken[$entry['id']]) === true) {
				$this->logger?->warning('[EventCatalogService] Refused flow trigger "{id}": empty field or id already in the catalog', ['id' => $entry['id']]);
				continue;
			}

			$taken[$entry['id']] = true;
			$accepted[]          = $entry;
		}

		return $accepted;
	}//end accepted()

	/**
	 * The catalog's own ids and legacy aliases.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/clients-vault-event-flow-triggers/specs/flow-triggers/spec.md
	 */
	private static function ownIds(): array {
		$ids = [];
		foreach (self::CATALOG as $entry) {
			$ids[] = $entry['id'];
			$ids[] = $entry['legacy'] ?? $entry['id'];
		}

		return $ids;
	}//end ownIds()
}//end class
