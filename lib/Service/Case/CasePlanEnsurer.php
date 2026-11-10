<?php

/**
 * Convergent bring-over of plan items from another engine.
 *
 * Keyed on (object uuid, item key): creates only the items the object does
 * not hold yet, each with the state it carries, and leaves existing rows
 * untouched. Audit entries are appended only for rows created in this call,
 * and supplied history for those rows is imported flagged `imported` with
 * its original moment, so a re-run appends nothing and a crash mid-case
 * resumes by creating what is missing. Everything is validated before the
 * first write. No realisation is created (the history it came from had
 * none) and no cascade runs (evaluating a half-written plan would act on
 * it); the next event or an explicit evaluate resumes the plan.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Case
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-items-can-be-ensured-convergently-with-their-recorded-states
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Case;

use DateTime;
use DateTimeImmutable;
use OCA\OpenRegister\Db\CaseItem;
use OCA\OpenRegister\Exception\CaseValidationException;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerAct;
use Throwable;

/**
 * Ensures plan items with recorded states, idempotently.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) One verb whose steps are each a small check (states, history, anchor,
 * insert, post-commit arming); the total sums them, no single method is complex.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-items-can-be-ensured-convergently-with-their-recorded-states
 */
class CasePlanEnsurer {

	/**
	 * Constructor.
	 *
	 * @param CasePlanStore $store The plan-item table, the append-only audit and the transaction.
	 * @param CasePlanDefinition $definitions The one definition validator.
	 * @param CasePlanTransitions $transitions The one lifecycle table.
	 * @param CasePlanItemTimers|null $timers Arms the due and expiry timers of an item ensured as active: no transition
	 *                                        event is dispatched for it, so the listener that arms them never hears of it.
	 */
	public function __construct(
		private readonly CasePlanStore $store,
		private readonly CasePlanDefinition $definitions,
		private readonly CasePlanTransitions $transitions,
		private readonly ?CasePlanItemTimers $timers = null,
	) {

	}//end __construct()

	/**
	 * Ensure the definition's items exist on the object with their states.
	 *
	 * @param CaseItemsEnsureRequest $request The anchor, the definition (a node MAY carry `state`), the history and the importing identity.
	 *
	 * @return array{created: array<int, string>, existing: array<int, string>, items: array<int, array<string, mixed>>}
	 *
	 * @throws CaseValidationException On the first refused value, before anything is written.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-items-can-be-ensured-convergently-with-their-recorded-states
	 */
	public function ensure(CaseItemsEnsureRequest $request): array {
		$normalised = $this->definitions->validate(definition: $request->definition);
		$types      = [];
		$this->checkStates(nodes: $normalised['items'], types: $types);
		$insertion = new CasePlanInsertion(anchor: $request->anchor, settings: $normalised['settings'], actor: $request->actor);
		$history = new CaseEnsuredHistory(history: $request->history, types: $types);
		$run = new CaseEnsureRun(insertion: $insertion, history: $history, existing: $this->existingRows(anchor: $request->anchor, types: $types));

		$this->write(nodes: $normalised['items'], run: $run);

		return $this->settle(run: $run);
	}//end ensure()

	/**
	 * The rows the object already has, by key, each checked against the call's
	 * anchor and against the type the definition declares for its key.
	 *
	 * @param CaseAnchor $anchor The anchoring object and the call's register and schema.
	 * @param array<string, string> $types The definition's key => type.
	 *
	 * @return array<string, CaseItem> The existing rows by key.
	 *
	 * @throws CaseValidationException On another anchor, or on a key that exists as another type.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-items-can-be-ensured-convergently-with-their-recorded-states
	 */
	private function existingRows(CaseAnchor $anchor, array $types): array {
		$existing = [];
		foreach ($this->store->items->findByObject(objectUuid: $anchor->objectUuid) as $row) {
			$this->checkAnchor(row: $row, anchor: $anchor);
			$existing[(string)$row->getItemKey()] = $row;
		}

		$this->checkTypes(types: $types, existing: $existing);

		return $existing;
	}//end existingRows()

	/**
	 * An item that exists keeps its type: ensuring it as another is refused.
	 *
	 * @param array<string, string> $types The definition's key => type.
	 * @param array<string, CaseItem> $existing The existing rows by key.
	 *
	 * @return void
	 *
	 * @throws CaseValidationException Naming the key and both types.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-items-can-be-ensured-convergently-with-their-recorded-states
	 */
	private function checkTypes(array $types, array $existing): void {
		foreach ($types as $key => $type) {
			if (isset($existing[$key]) === true && $existing[$key]->getPlanItemType() !== $type) {
				throw new CaseValidationException(
					message: sprintf("'%s' exists as a %s on this object; it cannot be ensured as a %s.", $key, (string)$existing[$key]->getPlanItemType(), $type)
				);
			}
		}
	}//end checkTypes()

	/**
	 * Insert the missing items in one transaction.
	 *
	 * @param array<int, array<string, mixed>> $nodes The validated top level.
	 * @param CaseEnsureRun                    $run   The insertion, history and existing rows; collects created and kept keys.
	 *
	 * @return void
	 *
	 * @throws Throwable Whatever failed, after rolling back.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-items-can-be-ensured-convergently-with-their-recorded-states
	 */
	private function write(array $nodes, CaseEnsureRun $run): void {
		$this->store->db->beginTransaction();
		try {
			foreach ($nodes as $position => $node) {
				$this->ensureNode(placed: new CasePlanPlacement(node: $node, parentId: null, position: (int)$position), run: $run);
			}

			$this->store->db->commit();
		} catch (Throwable $failure) {
			$this->store->db->rollBack();
			throw $failure;
		}
	}//end write()

	/**
	 * After the commit: arm the timers and report what the object now holds.
	 *
	 * An item inserted in a state it carries goes through no state machine, so
	 * no `CaseItemTransitionedEvent` reaches the timer listener, and an
	 * item that is active with a `dueAt` or `expiresAt` (a statutory one
	 * above all) would never get its timer. Done after the commit, like the
	 * listener: a refused arm is logged and, for a statutory expiry, marked
	 * for the re-arm pass, and never undoes the ensure. An item that already
	 * existed and is active was armed when it became active, unless that arm
	 * failed earlier and a resumed ensure is the only thing that looks at it
	 * again: its statutory expiry is marked, and the re-arm pass arms it or
	 * drops the marker when a timer is already running.
	 *
	 * @param CaseEnsureRun $run The anchoring object, the ensuring identity and what the write did.
	 *
	 * @return array{created: array<int, string>, existing: array<int, string>, items: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-deadlines-run-on-the-shared-clock
	 */
	private function settle(CaseEnsureRun $run): array {
		$rows = $this->store->items->findByObject(objectUuid: $run->insertion->anchor->objectUuid);
		$created = $run->created;
		$active = array_filter($rows, static fn (CaseItem $row): bool => $row->getState() === CaseItem::STATE_ACTIVE);
		$isNew = static fn (CaseItem $row): bool => in_array((string)$row->getItemKey(), $created, true);
		$this->armEntered(rows: array_filter($active, $isNew), actor: (string)$run->insertion->actor);
		$this->markStatutory(rows: array_filter($active, static fn (CaseItem $row): bool => $isNew($row) === false));

		return [
			'created' => $created,
			'existing' => $run->kept,
			'items' => array_map(static fn (CaseItem $row): array => $row->jsonSerialize(), $rows),
		];
	}//end settle()

	/**
	 * Arm the deadlines of the active items this call created.
	 *
	 * @param array<int, CaseItem> $rows The active rows this call created.
	 * @param string $actor The ensuring identity.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-deadlines-run-on-the-shared-clock
	 */
	private function armEntered(array $rows, string $actor): void {
		foreach ($rows as $row) {
			$this->timers?->onEntered(item: $row, act: new FlowTimerAct(actor: $actor, now: new DateTimeImmutable()));
		}
	}//end armEntered()

	/**
	 * Mark the statutory expiry of the active items that already existed, for the re-arm pass.
	 *
	 * @param array<int, CaseItem> $rows The active rows that already existed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-deadlines-run-on-the-shared-clock
	 */
	private function markStatutory(array $rows): void {
		foreach ($rows as $row) {
			$this->timers?->markIfStatutory(item: $row);
		}
	}//end markStatutory()

	/**
	 * Every node's state must be one its type can reach from `available`.
	 *
	 * @param array<int, array<string, mixed>> $nodes A level of validated nodes.
	 * @param array<string, string> $types Collects key => type.
	 *
	 * @return void
	 *
	 * @throws CaseValidationException Naming the item, its type and the state.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-items-can-be-ensured-convergently-with-their-recorded-states
	 */
	private function checkStates(array $nodes, array &$types): void {
		foreach ($nodes as $node) {
			$this->checkState(node: $node);
			$types[(string)$node['key']] = (string)$node['type'];
			$this->checkStates(nodes: ($node['children'] ?? []), types: $types);
		}
	}//end checkStates()

	/**
	 * One node's recorded state must be one its type can reach.
	 *
	 * @param array<string, mixed> $node A validated node: its key, its plan-item type and the state it carries.
	 *
	 * @return void
	 *
	 * @throws CaseValidationException Naming the item, its type and the state.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-items-can-be-ensured-convergently-with-their-recorded-states
	 */
	private function checkState(array $node): void {
		$key = (string)$node['key'];
		$type = (string)$node['type'];
		$state = (string)($node['state'] ?? CaseItem::STATE_AVAILABLE);
		$reachable = $this->reachable(type: $type);
		if (in_array($state, $reachable, true) === false) {
			throw new CaseValidationException(
				message: sprintf("'%s' (%s) cannot carry state '%s'; its lifecycle reaches %s.", $key, $type, $state, implode(', ', $reachable))
			);
		}
	}//end checkState()

	/**
	 * The states a type can be in: `available` and everything the table
	 * reaches from it.
	 *
	 * @param string $type The plan-item type.
	 *
	 * @return array<int, string> The reachable states.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-items-can-be-ensured-convergently-with-their-recorded-states
	 */
	private function reachable(string $type): array {
		$seen = [CaseItem::STATE_AVAILABLE];
		$queue = [CaseItem::STATE_AVAILABLE];
		while ($queue !== []) {
			$from = array_shift($queue);
			foreach ($this->transitions->targetsFor(type: $type, from: $from) as $target) {
				if (in_array($target, $seen, true) === false) {
					$seen[] = $target;
					$queue[] = $target;
				}
			}
		}

		return $seen;
	}//end reachable()

	/**
	 * A plan is anchored to ONE object: an existing row that names another
	 * register or schema refuses the call.
	 *
	 * @param CaseItem $row An existing row.
	 * @param CaseAnchor $anchor The call's register and schema.
	 *
	 * @return void
	 *
	 * @throws CaseValidationException On a mismatch.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-items-can-be-ensured-convergently-with-their-recorded-states
	 */
	private function checkAnchor(CaseItem $row, CaseAnchor $anchor): void {
		$registerId = $anchor->registerId;
		$schemaId = $anchor->schemaId;
		$registerDiffers = $registerId !== null && $row->getRegisterId() !== null && $row->getRegisterId() !== $registerId;
		$schemaDiffers = $schemaId !== null && $row->getSchemaId() !== null && $row->getSchemaId() !== $schemaId;
		if ($registerDiffers === true || $schemaDiffers === true) {
			throw new CaseValidationException(
				message: sprintf(
					'The plan on this object is anchored to register %d schema %d, not register %d schema %d.',
					(int)$row->getRegisterId(),
					(int)$row->getSchemaId(),
					(int)$registerId,
					(int)$schemaId
				)
			);
		}
	}//end checkAnchor()

	/**
	 * Ensure one placed node and recurse into a stage's children.
	 *
	 * @param CasePlanPlacement $placed The validated node, its containing stage's row id and its position.
	 * @param CaseEnsureRun     $run    The insertion, history and existing rows; collects created and kept keys.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-items-can-be-ensured-convergently-with-their-recorded-states
	 */
	private function ensureNode(CasePlanPlacement $placed, CaseEnsureRun $run): void {
		$key = (string)$placed->node['key'];
		$row = ($run->existing[$key] ?? null);
		if ($row instanceof CaseItem === false) {
			$row = $this->insert(placed: $placed, run: $run);
			$run->created[] = $key;
		} else {
			$run->kept[] = $key;
		}

		foreach (($placed->node['children'] ?? []) as $position => $child) {
			$this->ensureNode(placed: new CasePlanPlacement(node: $child, parentId: (int)$row->getId(), position: (int)$position), run: $run);
		}
	}//end ensureNode()

	/**
	 * Insert one missing item with its recorded state, its creation audit and
	 * its imported history.
	 *
	 * @param CasePlanPlacement $placed The validated node, its containing stage's row id and its position.
	 * @param CaseEnsureRun     $run    The insertion every row is built from and the history.
	 *
	 * @return CaseItem The inserted row.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-items-can-be-ensured-convergently-with-their-recorded-states
	 */
	private function insert(CasePlanPlacement $placed, CaseEnsureRun $run): CaseItem {
		$row = $this->definitions->rowFrom(placed: $placed, insertion: $run->insertion);
		self::carryState(row: $row, node: $placed->node);
		$persisted = $this->store->items->insert($row);
		foreach ($run->history->auditsFor(item: $persisted, actor: (string)$run->insertion->actor) as $audit) {
			$this->store->audits->insert($audit);
		}

		return $persisted;
	}//end insert()
	/**
	 * Put the state a node carries on its unsaved row; a row that is past
	 * `available` is stamped as entered now.
	 *
	 * @param CaseItem $row The unsaved row.
	 * @param array<string, mixed> $node The validated node.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-items-can-be-ensured-convergently-with-their-recorded-states
	 */
	private static function carryState(CaseItem $row, array $node): void {
		$state = (string)($node['state'] ?? CaseItem::STATE_AVAILABLE);
		$row->setState($state);
		$row->setIsTerminal(in_array($state, CaseItem::TERMINAL_STATES, true));
		if ($state !== CaseItem::STATE_AVAILABLE) {
			$row->setEnteredAt(new DateTime());
		}
	}//end carryState()

}//end class
