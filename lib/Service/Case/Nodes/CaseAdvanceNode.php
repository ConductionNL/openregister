<?php

/**
 * A flow step that advances a case: a BPMN process moving a CMMN plan item.
 *
 * Transitions the plan item with the configured key, on the object the step
 * receives, to the configured state, through
 * {@see CasePlanService::transition()} as the run's acting identity; the
 * plan's cascade then runs as for any other transition. A refusal (an
 * illegal edge, a denied caller, no plan, no such item) fails the step with
 * the engine's own message; it is never swallowed.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Case\Nodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-a-flow-step-opens-or-advances-a-case
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Case\Nodes;

use OCA\OpenRegister\Db\CaseItem;
use OCA\OpenRegister\Service\Case\CaseItemTransition;
use RuntimeException;
use UnexpectedValueException;

/**
 * The `openregister.case-advance` step.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-a-flow-step-opens-or-advances-a-case
 */
class CaseAdvanceNode extends CaseNodeBase implements CaseAdvanceNodeInterface {

	/**
	 * The step type this node answers to.
	 *
	 * @var string
	 */
	public const TYPE = 'openregister.case-advance';

	/**
	 * The step type.
	 *
	 * @return string The node id.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-flow-step-opens-or-advances-a-case
	 */
	public function getId(): string {
		return self::TYPE;
	}//end getId()

	/**
	 * The palette label.
	 *
	 * @return string The display name.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-flow-step-opens-or-advances-a-case
	 */
	public function getDisplayName(): string {
		return $this->l10n->t('Advance a case');
	}//end getDisplayName()

	/**
	 * The palette description.
	 *
	 * @return string The description.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-flow-step-opens-or-advances-a-case
	 */
	public function getDescription(): string {
		return $this->l10n->t(
			'Move one item of the case plan on the object this step receives, such as a milestone. A move the plan does not allow stops the step.'
		);
	}//end getDescription()

	/**
	 * Top-level configuration keys.
	 *
	 * @return array<int, string> The keys.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-flow-step-opens-or-advances-a-case
	 */
	public function configKeys(): array {
		return ['item', 'to', 'reason', 'uuid'];
	}//end configKeys()

	/**
	 * The editor form.
	 *
	 * @return array<int, array<string, mixed>> The fields.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-flow-step-opens-or-advances-a-case
	 */
	public function configForm(): array {
		return [$this->itemField(), $this->stateField(), $this->reasonField(), $this->objectField()];
	}//end configForm()

	/**
	 * The editor field naming the plan item to move.
	 *
	 * @return array<string, mixed> The field.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-flow-step-opens-or-advances-a-case
	 */
	private function itemField(): array {
		return [
			'key' => 'item',
			'label' => $this->l10n->t('Plan item'),
			'type' => 'text',
			'required' => true,
			'help' => $this->l10n->t('The key of the stage, task or milestone to move.'),
		];
	}//end itemField()

	/**
	 * The editor field choosing the new state.
	 *
	 * @return array<string, mixed> The field.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-flow-step-opens-or-advances-a-case
	 */
	private function stateField(): array {
		return [
			'key' => 'to',
			'label' => $this->l10n->t('New state'),
			'type' => 'select',
			'required' => true,
			'options' => CaseItem::STATES,
		];
	}//end stateField()

	/**
	 * The editor field for the free-text reason.
	 *
	 * @return array<string, mixed> The field.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-flow-step-opens-or-advances-a-case
	 */
	private function reasonField(): array {
		return [
			'key' => 'reason',
			'label' => $this->l10n->t('Reason'),
			'type' => 'text',
		];
	}//end reasonField()

	/**
	 * The move a validated configuration asks for.
	 *
	 * @param array $config The validated step configuration.
	 *
	 * @return array{key: string, to: string, reason: string|null} The plan-item key, the target state and the reason.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-flow-step-opens-or-advances-a-case
	 */
	private static function moveOf(array $config): array {
		return ['key' => trim((string)$config['item']), 'to' => (string)$config['to'], 'reason' => self::reasonOf(config: $config)];
	}//end moveOf()

	/**
	 * The configured reason, trimmed, or null when there is none.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return string|null The reason.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-flow-step-opens-or-advances-a-case
	 */
	private static function reasonOf(array $config): ?string {
		$reason = ($config['reason'] ?? null);
		if (is_string($reason) === false || trim($reason) === '') {
			return null;
		}

		return trim($reason);
	}//end reasonOf()

	/**
	 * An advance step names an item and one of the six states.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When it does not.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-flow-step-opens-or-advances-a-case
	 */
	public function validateConfig(array $config): void {
		if (trim((string)($config['item'] ?? '')) === '') {
			throw new UnexpectedValueException($this->l10n->t('An advance-a-case step needs the key of the plan item to move.'));
		}

		if (in_array(($config['to'] ?? null), CaseItem::STATES, true) === false) {
			throw new UnexpectedValueException(
				$this->l10n->t('An advance-a-case step needs a new state: one of %s.', [implode(', ', CaseItem::STATES)])
			);
		}
	}//end validateConfig()

	/**
	 * Move the item on each distinct object.
	 *
	 * @param array $items The incoming items.
	 * @param array $config The step configuration.
	 * @param array $context The run context.
	 *
	 * @return array The items, each carrying `case` = {item, state}.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-flow-step-opens-or-advances-a-case
	 */
	public function execute(array $items, array $config, array $context): array {
		$work = fn (CaseStepCall $call): array => $this->advance(call: $call, move: self::moveOf(config: $config));

		return $this->perTarget(run: new CaseStepRun(items: $items, config: $config, context: $context), work: $work);
	}//end execute()

	/**
	 * Find the item by key and transition it.
	 *
	 * @param CaseStepCall $call The object and the acting identity.
	 * @param array{key: string, to: string, reason: string|null} $move The plan-item key, the target state and the free-text reason.
	 *
	 * @return array{item: string, state: string} The item and its state afterwards.
	 *
	 * @throws RuntimeException When the plan has no such item.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-flow-step-opens-or-advances-a-case
	 */
	private function advance(CaseStepCall $call, array $move): array {
		$objectUuid = $call->target->objectUuid;
		$uid = $call->uid;
		$plan = $this->steps->plans->getPlan(objectUuid: $objectUuid, uid: $uid);
		$row = $this->liveRealisation(items: (array)($plan['items'] ?? []), key: $move['key']);
		if ($row !== null) {
			$transition = new CaseItemTransition(itemUuid: (string)$row['uuid'], to: $move['to'], actor: $uid, reason: $move['reason']);
			$moved = $this->steps->plans->transition(transition: $transition);

			return ['item' => $move['key'], 'state' => (string)$moved->getState()];
		}

		throw new RuntimeException(
			$this->l10n->t('The case plan on object %1$s has no item "%2$s".', [$objectUuid, $move['key']])
		);
	}//end advance()

	/**
	 * The row of a key that can still move: a repeating item has one row per
	 * realisation (same key, `realisationCount` + 1), and the first row in plan
	 * order is the oldest, which is already completed or terminated. A row
	 * that is not terminal wins (the newest of them, should several be open);
	 * when every realisation is terminal the newest is returned, so the step
	 * fails on the realisation that is actually current.
	 *
	 * @param array<int, array<string, mixed>> $items The plan's rows.
	 * @param string $key The plan-item key.
	 *
	 * @return array<string, mixed>|null The row, or null when the plan has no such key.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-flow-step-opens-or-advances-a-case
	 */
	private function liveRealisation(array $items, string $key): ?array {
		$chosen = null;
		foreach (array_filter($items, static fn (array $row): bool => ($row['key'] ?? null) === $key) as $row) {
			if ($chosen === null || $this->outranks(candidate: $row, current: $chosen) === true) {
				$chosen = $row;
			}
		}

		return $chosen;
	}//end liveRealisation()

	/**
	 * Whether a row of the same key is a better one to advance than the one held.
	 *
	 * @param array<string, mixed> $candidate The row under consideration.
	 * @param array<string, mixed> $current The row held so far.
	 *
	 * @return bool True when the candidate is open and the held row is not, or both are alike and the candidate is a later realisation.
	 */
	private function outranks(array $candidate, array $current): bool {
		$candidateOpen = (($candidate['isTerminal'] ?? false) === false);
		$currentOpen = (($current['isTerminal'] ?? false) === false);
		if ($candidateOpen !== $currentOpen) {
			return $candidateOpen;
		}

		return (int)($candidate['realisationCount'] ?? 1) > (int)($current['realisationCount'] ?? 1);
	}//end outranks()
}//end class
