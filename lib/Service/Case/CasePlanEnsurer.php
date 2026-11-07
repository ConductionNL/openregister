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
use OCA\OpenRegister\Db\CaseItem;
use OCA\OpenRegister\Db\CaseItemAudit;
use OCA\OpenRegister\Exception\CaseValidationException;
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
	 * @param string $objectUuid The anchoring object.
	 * @param int|null $registerId Its register.
	 * @param int|null $schemaId Its schema.
	 * @param array<string, mixed> $definition `settings` + nested `items`; a node MAY carry `state`.
	 * @param array<int, mixed> $history Entries `{item, from?, to, at, actor?, reason?}`.
	 * @param string $actor The importing identity (`system:<app>`).
	 *
	 * @return array{created: array<int, string>, existing: array<int, string>, items: array<int, array<string, mixed>>}
	 *
	 * @throws CaseValidationException On the first refused value, before anything is written.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-items-can-be-ensured-convergently-with-their-recorded-states
	 */
	public function ensure(string $objectUuid, ?int $registerId, ?int $schemaId, array $definition, array $history, string $actor): array {
		$normalised = $this->definitions->validate(definition: $definition);
		$types = [];
		$this->checkStates(nodes: $normalised['items'], types: $types);
		$context = ['object' => $objectUuid, 'register' => $registerId, 'schema' => $schemaId];
		$context['settings'] = $normalised['settings'];
		$context['actor'] = $actor;
		$context['history'] = $this->checkHistory(history: $history, types: $types);
		$context['existing'] = $this->existingRows(objectUuid: $objectUuid, registerId: $registerId, schemaId: $schemaId, types: $types);

		$outcome = $this->write(nodes: $normalised['items'], context: $context);

		return $this->settle(objectUuid: $objectUuid, outcome: $outcome, actor: $actor);
	}//end ensure()

	/**
	 * The rows the object already has, by key, each checked against the call's
	 * anchor and against the type the definition declares for its key.
	 *
	 * @param string $objectUuid The anchoring object.
	 * @param int|null $registerId The call's register.
	 * @param int|null $schemaId The call's schema.
	 * @param array<string, string> $types The definition's key => type.
	 *
	 * @return array<string, CaseItem> The existing rows by key.
	 *
	 * @throws CaseValidationException On another anchor, or on a key that exists as another type.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-items-can-be-ensured-convergently-with-their-recorded-states
	 */
	private function existingRows(string $objectUuid, ?int $registerId, ?int $schemaId, array $types): array {
		$existing = [];
		foreach ($this->store->items->findByObject(objectUuid: $objectUuid) as $row) {
			$this->checkAnchor(row: $row, registerId: $registerId, schemaId: $schemaId);
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
	 * @param array<string, mixed> $context object, register, schema, settings, actor, history, existing.
	 *
	 * @return array{created: array<int, string>, kept: array<int, string>} The keys created and the keys that existed.
	 *
	 * @throws Throwable Whatever failed, after rolling back.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-items-can-be-ensured-convergently-with-their-recorded-states
	 */
	private function write(array $nodes, array $context): array {
		$outcome = ['created' => [], 'kept' => []];
		$this->store->db->beginTransaction();
		try {
			$this->ensureLevel(nodes: $nodes, parentId: null, context: $context, outcome: $outcome);
			$this->store->db->commit();
		} catch (Throwable $failure) {
			$this->store->db->rollBack();
			throw $failure;
		}

		return $outcome;
	}//end write()

	/**
	 * After the commit: arm the timers and report what the object now holds.
	 *
	 * @param string $objectUuid The anchoring object.
	 * @param array{created: array<int, string>, kept: array<int, string>} $outcome What the write did.
	 * @param string $actor The ensuring identity.
	 *
	 * @return array{created: array<int, string>, existing: array<int, string>, items: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-items-can-be-ensured-convergently-with-their-recorded-states
	 */
	private function settle(string $objectUuid, array $outcome, string $actor): array {
		$rows = $this->store->items->findByObject(objectUuid: $objectUuid);
		$this->armTimers(rows: $rows, created: $outcome['created'], actor: $actor);

		return [
			'created' => $outcome['created'],
			'existing' => $outcome['kept'],
			'items' => array_map(static fn (CaseItem $row): array => $row->jsonSerialize(), $rows),
		];
	}//end settle()

	/**
	 * Arm the deadlines of the items this call created as active, and mark the rest.
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
	 * @param array<int, CaseItem> $rows Every row of the object, after the commit.
	 * @param array<int, string> $created The keys this call created.
	 * @param string $actor The ensuring identity.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-deadlines-run-on-the-shared-clock
	 */
	private function armTimers(array $rows, array $created, string $actor): void {
		if ($this->timers === null) {
			return;
		}

		$active = array_filter($rows, static fn (CaseItem $row): bool => $row->getState() === CaseItem::STATE_ACTIVE);
		$isNew = static fn (CaseItem $row): bool => in_array((string)$row->getItemKey(), $created, true);
		foreach (array_filter($active, $isNew) as $row) {
			$this->timers->onEntered(item: $row, actor: $actor);
		}

		foreach (array_filter($active, static fn (CaseItem $row): bool => $isNew($row) === false) as $row) {
			$this->timers->markIfStatutory(item: $row);
		}
	}//end armTimers()

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
			$key = (string)$node['key'];
			$type = (string)$node['type'];
			$this->checkState(key: $key, type: $type, state: (string)($node['state'] ?? CaseItem::STATE_AVAILABLE));
			$types[$key] = $type;
			$this->checkStates(nodes: ($node['children'] ?? []), types: $types);
		}
	}//end checkStates()

	/**
	 * One node's recorded state must be one its type can reach.
	 *
	 * @param string $key The item key.
	 * @param string $type Its plan-item type.
	 * @param string $state The state it carries.
	 *
	 * @return void
	 *
	 * @throws CaseValidationException Naming the item, its type and the state.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-items-can-be-ensured-convergently-with-their-recorded-states
	 */
	private function checkState(string $key, string $type, string $state): void {
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
	 * Validate history and group it by item key.
	 *
	 * @param array<int, mixed> $history The entries.
	 * @param array<string, string> $types The definition's key => type.
	 *
	 * @return array<string, array<int, array{from: string, to: string, at: DateTime, actor: string|null, reason: string|null}>>
	 *
	 * @throws CaseValidationException On an entry that is not usable.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-items-can-be-ensured-convergently-with-their-recorded-states
	 */
	private function checkHistory(array $history, array $types): array {
		$byKey = [];
		foreach ($history as $index => $entry) {
			$label = sprintf('history[%d]', (int)$index);
			$key = $this->historyKey(label: $label, entry: $entry, types: $types);
			$byKey[$key][] = $this->historyRecord(label: $label, entry: $entry);
		}

		return $byKey;
	}//end checkHistory()

	/**
	 * The item a history entry names, which the definition must declare.
	 *
	 * @param string $label The entry, for messages.
	 * @param mixed $entry The entry as submitted.
	 * @param array<string, string> $types The definition's key => type.
	 *
	 * @return string The trimmed item key.
	 *
	 * @throws CaseValidationException When the entry is not an object or names an undeclared item.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-items-can-be-ensured-convergently-with-their-recorded-states
	 */
	private function historyKey(string $label, mixed $entry, array $types): string {
		if (is_array($entry) === false) {
			throw new CaseValidationException(message: sprintf('%s must be an object.', $label));
		}

		$key = trim((string)($entry['item'] ?? ''));
		if (isset($types[$key]) === false) {
			throw new CaseValidationException(message: sprintf("%s names item '%s', which the definition does not declare.", $label, $key));
		}

		return $key;
	}//end historyKey()

	/**
	 * One history entry as it is imported: both states real, the moment readable.
	 *
	 * @param string $label The entry, for messages.
	 * @param array<string, mixed> $entry The entry as submitted.
	 *
	 * @return array{from: string, to: string, at: DateTime, actor: string|null, reason: string|null} The entry.
	 *
	 * @throws CaseValidationException On a state that is not one, or an unreadable moment.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-items-can-be-ensured-convergently-with-their-recorded-states
	 */
	private function historyRecord(string $label, array $entry): array {
		$from = trim((string)($entry['from'] ?? ''));
		$to = trim((string)($entry['to'] ?? ''));
		self::checkHistoryStates(label: $label, from: $from, to: $to);

		return [
			'from' => $from,
			'to' => $to,
			'at' => $this->historyMoment(label: $label, value: ($entry['at'] ?? null)),
			'actor' => $this->stringOrNull(value: ($entry['actor'] ?? null)),
			'reason' => $this->stringOrNull(value: ($entry['reason'] ?? null)),
		];
	}//end historyRecord()

	/**
	 * A history entry's to-state, and its from-state when given, must be plan-item states.
	 *
	 * @param string $label The entry, for messages.
	 * @param string $from The trimmed from-state, or ''.
	 * @param string $to The trimmed to-state.
	 *
	 * @return void
	 *
	 * @throws CaseValidationException When either is not a state.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-items-can-be-ensured-convergently-with-their-recorded-states
	 */
	private static function checkHistoryStates(string $label, string $from, string $to): void {
		if (in_array($to, CaseItem::STATES, true) === false || ($from !== '' && in_array($from, CaseItem::STATES, true) === false)) {
			throw new CaseValidationException(
				message: sprintf("%s has from-state '%s' and to-state '%s'; both must be plan-item states.", $label, $from, $to)
			);
		}
	}//end checkHistoryStates()

	/**
	 * The moment of a history entry.
	 *
	 * @param string $label The entry, for messages.
	 * @param mixed $value Its `at`.
	 *
	 * @return DateTime The moment.
	 *
	 * @throws CaseValidationException When it is not an ISO 8601 date-time.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-items-can-be-ensured-convergently-with-their-recorded-states
	 */
	private function historyMoment(string $label, mixed $value): DateTime {
		$moment = $this->moment(value: $value);
		if ($moment === null) {
			throw new CaseValidationException(message: sprintf('%s has an unreadable moment; `at` must be an ISO 8601 date-time.', $label));
		}

		return $moment;
	}//end historyMoment()

	/**
	 * A plan is anchored to ONE object: an existing row that names another
	 * register or schema refuses the call.
	 *
	 * @param CaseItem $row An existing row.
	 * @param int|null $registerId The call's register.
	 * @param int|null $schemaId The call's schema.
	 *
	 * @return void
	 *
	 * @throws CaseValidationException On a mismatch.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-items-can-be-ensured-convergently-with-their-recorded-states
	 */
	private function checkAnchor(CaseItem $row, ?int $registerId, ?int $schemaId): void {
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
	 * Ensure one level and recurse into stages.
	 *
	 * @param array<int, array<string, mixed>> $nodes The level.
	 * @param int|null $parentId The containing stage's row id.
	 * @param array<string, mixed> $context object, register, schema, settings, actor, history, existing.
	 * @param array{created: array<int, string>, kept: array<int, string>} $outcome Collects created and existing keys.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-items-can-be-ensured-convergently-with-their-recorded-states
	 */
	private function ensureLevel(array $nodes, ?int $parentId, array $context, array &$outcome): void {
		foreach ($nodes as $position => $node) {
			$key = (string)$node['key'];
			$row = ($context['existing'][$key] ?? null);
			$bucket = 'kept';
			if ($row instanceof CaseItem === false) {
				$row = $this->insert(node: $node, parentId: $parentId, position: (int)$position, context: $context);
				$bucket = 'created';
			}

			$outcome[$bucket][] = $key;
			$this->ensureLevel(nodes: ($node['children'] ?? []), parentId: (int)$row->getId(), context: $context, outcome: $outcome);
		}
	}//end ensureLevel()

	/**
	 * Insert one missing item with its recorded state, its creation audit and
	 * its imported history.
	 *
	 * @param array<string, mixed> $node The validated node.
	 * @param int|null $parentId The containing stage's row id.
	 * @param int $position Its position among its siblings.
	 * @param array<string, mixed> $context See {@see ensureLevel()}.
	 *
	 * @return CaseItem The inserted row.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-items-can-be-ensured-convergently-with-their-recorded-states
	 */
	private function insert(array $node, ?int $parentId, int $position, array $context): CaseItem {
		$actor = (string)$context['actor'];
		$row = $this->rowFor(node: $node, parentId: $parentId, position: $position, context: $context);
		$state = self::carryState(row: $row, node: $node);
		$persisted = $this->store->items->insert($row);
		$this->auditCreation(item: $persisted, state: $state, actor: $actor);
		$this->importHistory(item: $persisted, entries: ($context['history'][(string)$node['key']] ?? []), actor: $actor);

		return $persisted;
	}//end insert()

	/**
	 * The unsaved row of one missing item, compiled by the one definition compiler.
	 *
	 * @param array<string, mixed> $node The validated node.
	 * @param int|null $parentId The containing stage's row id.
	 * @param int $position Its position among its siblings.
	 * @param array<string, mixed> $context See {@see ensureLevel()}.
	 *
	 * @return CaseItem The unsaved row, in `available`.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-items-can-be-ensured-convergently-with-their-recorded-states
	 */
	private function rowFor(array $node, ?int $parentId, int $position, array $context): CaseItem {
		return $this->definitions->rowFrom(
			node: $node,
			objectUuid: (string)$context['object'],
			registerId: $context['register'],
			schemaId: $context['schema'],
			parentId: $parentId,
			position: $position,
			settings: $context['settings'],
			origin: $this->definitions->originOf(node: $node),
			actor: (string)$context['actor']
		);
	}//end rowFor()

	/**
	 * Put the state a node carries on its unsaved row; a row that is past
	 * `available` is stamped as entered now.
	 *
	 * @param CaseItem $row The unsaved row.
	 * @param array<string, mixed> $node The validated node.
	 *
	 * @return string The state.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-items-can-be-ensured-convergently-with-their-recorded-states
	 */
	private static function carryState(CaseItem $row, array $node): string {
		$state = (string)($node['state'] ?? CaseItem::STATE_AVAILABLE);
		$row->setState($state);
		$row->setIsTerminal(in_array($state, CaseItem::TERMINAL_STATES, true));
		if ($state !== CaseItem::STATE_AVAILABLE) {
			$row->setEnteredAt(new DateTime());
		}

		return $state;
	}//end carryState()

	/**
	 * Audit an ensured row's creation, by the importer.
	 *
	 * @param CaseItem $item The inserted row.
	 * @param string $state The state it was inserted in.
	 * @param string $actor The importing identity.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-items-can-be-ensured-convergently-with-their-recorded-states
	 */
	private function auditCreation(CaseItem $item, string $state, string $actor): void {
		$why = new CaseTransitionCause(cause: CaseItemAudit::CAUSE_IMPORT, causeRef: 'ensure:' . $actor, actor: $actor, reason: null);
		$entry = $why->auditFor(item: $item, fromState: '', toState: $state);
		$entry->setImported(false);
		$this->store->audits->insert($entry);
	}//end auditCreation()

	/**
	 * Append an ensured row's brought-over history, flagged as imported, with
	 * the original moments and actors.
	 *
	 * @param CaseItem $item The inserted row.
	 * @param array<int, array{from: string, to: string, at: DateTime, actor: string|null, reason: string|null}> $entries Its history.
	 * @param string $actor The importer, recorded as the cause reference and as the actor of an entry that names none.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-items-can-be-ensured-convergently-with-their-recorded-states
	 */
	private function importHistory(CaseItem $item, array $entries, string $actor): void {
		foreach ($entries as $entry) {
			$why = new CaseTransitionCause(
				cause: CaseItemAudit::CAUSE_IMPORT,
				causeRef: 'ensure:' . $actor,
				actor: ($entry['actor'] ?? $actor),
				reason: $entry['reason']
			);
			$audit = $why->auditFor(item: $item, fromState: $entry['from'], toState: $entry['to']);
			$audit->setImported(true);
			$audit->setCreated($entry['at']);
			$this->store->audits->insert($audit);
		}
	}//end importHistory()

	/**
	 * Parse an ISO 8601 moment.
	 *
	 * @param mixed $value The value.
	 *
	 * @return DateTime|null The moment, or null when unreadable.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-items-can-be-ensured-convergently-with-their-recorded-states
	 */
	private function moment(mixed $value): ?DateTime {
		if (is_string($value) === false) {
			return null;
		}

		// Only a full ISO 8601 date-time with an offset: relative phrases that
		// DateTime would happily accept ("yesterday") are not a moment. An
		// empty value does not match either.
		$pattern = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:?\d{2})$/';
		if (preg_match($pattern, trim($value)) !== 1) {
			return null;
		}

		try {
			return new DateTime(trim($value));
		} catch (Throwable) {
			return null;
		}
	}//end moment()

	/**
	 * A trimmed non-empty string, or null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null The string.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-items-can-be-ensured-convergently-with-their-recorded-states
	 */
	private function stringOrNull(mixed $value): ?string {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		return trim($value);
	}//end stringOrNull()
}//end class
