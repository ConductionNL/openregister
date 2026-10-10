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
 * @spec openspec/changes/one-engine-bpmn-and-cmmn/specs/flow-cases/spec.md#requirement-plan-items-can-be-ensured-convergently-with-their-recorded-states
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Case;

use DateTime;
use OCA\OpenRegister\Db\CaseItem;
use OCA\OpenRegister\Db\CaseItemAudit;
use OCA\OpenRegister\Db\CaseItemAuditMapper;
use OCA\OpenRegister\Db\CaseItemMapper;
use OCA\OpenRegister\Exception\CaseValidationException;
use OCP\IDBConnection;
use Throwable;

/**
 * Ensures plan items with recorded states, idempotently.
 *
 * @spec openspec/changes/one-engine-bpmn-and-cmmn/specs/flow-cases/spec.md#requirement-plan-items-can-be-ensured-convergently-with-their-recorded-states
 */
class CasePlanEnsurer {

	/**
	 * Constructor.
	 *
	 * @param CaseItemMapper $items The plan-item table.
	 * @param CaseItemAuditMapper $audits The append-only audit.
	 * @param CasePlanDefinition $definitions The one definition validator.
	 * @param CasePlanTransitions $transitions The one lifecycle table.
	 * @param IDBConnection $db Holds the transaction.
	 */
	public function __construct(
		private readonly CaseItemMapper $items,
		private readonly CaseItemAuditMapper $audits,
		private readonly CasePlanDefinition $definitions,
		private readonly CasePlanTransitions $transitions,
		private readonly IDBConnection $db,
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
	 * @spec openspec/changes/one-engine-bpmn-and-cmmn/specs/flow-cases/spec.md#requirement-plan-items-can-be-ensured-convergently-with-their-recorded-states
	 */
	public function ensure(
		string $objectUuid,
		?int $registerId,
		?int $schemaId,
		array $definition,
		array $history,
		string $actor,
	): array {
		$normalised = $this->definitions->validate(definition: $definition);
		$types = [];
		$this->checkStates(nodes: $normalised['items'], types: $types);
		$historyByKey = $this->checkHistory(history: $history, types: $types);

		$existing = [];
		foreach ($this->items->findByObject(objectUuid: $objectUuid) as $row) {
			$this->checkAnchor(row: $row, registerId: $registerId, schemaId: $schemaId);
			$existing[(string)$row->getItemKey()] = $row;
		}

		foreach ($types as $key => $type) {
			if (isset($existing[$key]) === true && $existing[$key]->getPlanItemType() !== $type) {
				throw new CaseValidationException(
					message: sprintf("'%s' exists as a %s on this object; it cannot be ensured as a %s.", $key, (string)$existing[$key]->getPlanItemType(), $type)
				);
			}
		}

		$created = [];
		$kept = [];
		$this->db->beginTransaction();
		try {
			$this->ensureLevel(
				nodes: $normalised['items'],
				parentId: null,
				context: [
					'object' => $objectUuid,
					'register' => $registerId,
					'schema' => $schemaId,
					'settings' => $normalised['settings'],
					'actor' => $actor,
					'history' => $historyByKey,
					'existing' => $existing,
				],
				created: $created,
				kept: $kept
			);
			$this->db->commit();
		} catch (Throwable $failure) {
			$this->db->rollBack();
			throw $failure;
		}

		return [
			'created' => $created,
			'existing' => $kept,
			'items' => array_map(static fn (CaseItem $row): array => $row->jsonSerialize(), $this->items->findByObject(objectUuid: $objectUuid)),
		];
	}//end ensure()

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
	 * @spec openspec/changes/one-engine-bpmn-and-cmmn/specs/flow-cases/spec.md#requirement-plan-items-can-be-ensured-convergently-with-their-recorded-states
	 */
	private function checkStates(array $nodes, array &$types): void {
		foreach ($nodes as $node) {
			$key = (string)$node['key'];
			$type = (string)$node['type'];
			$state = (string)($node['state'] ?? CaseItem::STATE_AVAILABLE);
			if (in_array($state, $this->reachable(type: $type), true) === false) {
				throw new CaseValidationException(
					message: sprintf(
						"'%s' (%s) cannot carry state '%s'; its lifecycle reaches %s.",
						$key,
						$type,
						$state,
						implode(', ', $this->reachable(type: $type))
					)
				);
			}

			$types[$key] = $type;
			$this->checkStates(nodes: ($node['children'] ?? []), types: $types);
		}
	}//end checkStates()

	/**
	 * The states a type can be in: `available` and everything the table
	 * reaches from it.
	 *
	 * @param string $type The plan-item type.
	 *
	 * @return array<int, string> The reachable states.
	 *
	 * @spec openspec/changes/one-engine-bpmn-and-cmmn/specs/flow-cases/spec.md#requirement-plan-items-can-be-ensured-convergently-with-their-recorded-states
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
	 * @spec openspec/changes/one-engine-bpmn-and-cmmn/specs/flow-cases/spec.md#requirement-plan-items-can-be-ensured-convergently-with-their-recorded-states
	 */
	private function checkHistory(array $history, array $types): array {
		$byKey = [];
		foreach ($history as $index => $entry) {
			$label = sprintf('history[%d]', (int)$index);
			if (is_array($entry) === false) {
				throw new CaseValidationException(message: sprintf('%s must be an object.', $label));
			}

			$key = trim((string)($entry['item'] ?? ''));
			if (isset($types[$key]) === false) {
				throw new CaseValidationException(message: sprintf("%s names item '%s', which the definition does not declare.", $label, $key));
			}

			$from = trim((string)($entry['from'] ?? ''));
			$to = trim((string)($entry['to'] ?? ''));
			if (in_array($to, CaseItem::STATES, true) === false || ($from !== '' && in_array($from, CaseItem::STATES, true) === false)) {
				throw new CaseValidationException(
					message: sprintf("%s has from-state '%s' and to-state '%s'; both must be plan-item states.", $label, $from, $to)
				);
			}

			$moment = $this->moment(value: ($entry['at'] ?? null));
			if ($moment === null) {
				throw new CaseValidationException(message: sprintf('%s has an unreadable moment; `at` must be an ISO 8601 date-time.', $label));
			}

			$byKey[$key][] = [
				'from' => $from,
				'to' => $to,
				'at' => $moment,
				'actor' => $this->stringOrNull(value: ($entry['actor'] ?? null)),
				'reason' => $this->stringOrNull(value: ($entry['reason'] ?? null)),
			];
		}//end foreach

		return $byKey;
	}//end checkHistory()

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
	 * @spec openspec/changes/one-engine-bpmn-and-cmmn/specs/flow-cases/spec.md#requirement-plan-items-can-be-ensured-convergently-with-their-recorded-states
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
	 * @param array<int, string> $created Collects created keys.
	 * @param array<int, string> $kept Collects existing keys.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-engine-bpmn-and-cmmn/specs/flow-cases/spec.md#requirement-plan-items-can-be-ensured-convergently-with-their-recorded-states
	 */
	private function ensureLevel(array $nodes, ?int $parentId, array $context, array &$created, array &$kept): void {
		foreach ($nodes as $position => $node) {
			$key = (string)$node['key'];
			$row = ($context['existing'][$key] ?? null);
			if ($row instanceof CaseItem) {
				$kept[] = $key;
			}

			if ($row instanceof CaseItem === false) {
				$row = $this->insert(node: $node, parentId: $parentId, position: (int)$position, context: $context);
				$created[] = $key;
			}

			$this->ensureLevel(nodes: ($node['children'] ?? []), parentId: (int)$row->getId(), context: $context, created: $created, kept: $kept);
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
	 * @spec openspec/changes/one-engine-bpmn-and-cmmn/specs/flow-cases/spec.md#requirement-plan-items-can-be-ensured-convergently-with-their-recorded-states
	 */
	private function insert(array $node, ?int $parentId, int $position, array $context): CaseItem {
		$origin = CaseItem::ORIGIN_DEFINED;
		if (($node['discretionary'] ?? false) === true) {
			$origin = CaseItem::ORIGIN_DISCRETIONARY;
		}

		$actor = (string)$context['actor'];
		$row = $this->definitions->rowFrom(
			node: $node,
			objectUuid: (string)$context['object'],
			registerId: $context['register'],
			schemaId: $context['schema'],
			parentId: $parentId,
			position: $position,
			settings: $context['settings'],
			origin: $origin,
			actor: $actor
		);
		$state = (string)($node['state'] ?? CaseItem::STATE_AVAILABLE);
		$row->setState($state);
		$row->setIsTerminal(in_array($state, CaseItem::TERMINAL_STATES, true));
		if ($state !== CaseItem::STATE_AVAILABLE) {
			$row->setEnteredAt(new DateTime());
		}

		$persisted = $this->items->insert($row);
		$this->appendAudit(item: $persisted, from: '', to: $state, actor: $actor, reason: null, imported: false, moment: null, actorOfRecord: $actor);

		foreach (($context['history'][(string)$node['key']] ?? []) as $entry) {
			$this->appendAudit(
				item: $persisted,
				from: $entry['from'],
				to: $entry['to'],
				actor: ($entry['actor'] ?? $actor),
				reason: $entry['reason'],
				imported: true,
				moment: $entry['at'],
				actorOfRecord: $actor
			);
		}

		return $persisted;
	}//end insert()

	/**
	 * Append one audit entry.
	 *
	 * @param CaseItem $item The row.
	 * @param string $from The from-state ('' on creation).
	 * @param string $to The to-state.
	 * @param string $actor The identity the entry names.
	 * @param string|null $reason Free text.
	 * @param bool $imported Whether it is brought-over history.
	 * @param DateTime|null $moment Its original moment, for imported history.
	 * @param string $actorOfRecord The importer, recorded as the cause reference.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The flag IS the stored column.
	 *
	 * @spec openspec/changes/one-engine-bpmn-and-cmmn/specs/flow-cases/spec.md#requirement-plan-items-can-be-ensured-convergently-with-their-recorded-states
	 */
	private function appendAudit(
		CaseItem $item,
		string $from,
		string $to,
		string $actor,
		?string $reason,
		bool $imported,
		?DateTime $moment,
		string $actorOfRecord,
	): void {
		$entry = new CaseItemAudit();
		$entry->setCaseItemId((int)$item->getId());
		$entry->setFromState($from);
		$entry->setToState($to);
		$entry->setCause(CaseItemAudit::CAUSE_IMPORT);
		$entry->setCauseRef('ensure:' . $actorOfRecord);
		$entry->setActor($actor);
		$entry->setReason($reason);
		$entry->setAuthorized(true);
		$entry->setImported($imported);
		if ($moment !== null) {
			$entry->setCreated($moment);
		}

		$this->audits->insert($entry);
	}//end appendAudit()

	/**
	 * Parse an ISO 8601 moment.
	 *
	 * @param mixed $value The value.
	 *
	 * @return DateTime|null The moment, or null when unreadable.
	 *
	 * @spec openspec/changes/one-engine-bpmn-and-cmmn/specs/flow-cases/spec.md#requirement-plan-items-can-be-ensured-convergently-with-their-recorded-states
	 */
	private function moment(mixed $value): ?DateTime {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		// Only a full ISO 8601 date-time with an offset: relative phrases that
		// DateTime would happily accept ("yesterday") are not a moment.
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
	 * @spec openspec/changes/one-engine-bpmn-and-cmmn/specs/flow-cases/spec.md#requirement-plan-items-can-be-ensured-convergently-with-their-recorded-states
	 */
	private function stringOrNull(mixed $value): ?string {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		return trim($value);
	}//end stringOrNull()
}//end class
