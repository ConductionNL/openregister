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
use OCA\OpenRegister\Service\Flow\FlowItems;
use RuntimeException;
use UnexpectedValueException;

/**
 * The `openregister.case-advance` step.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-a-flow-step-opens-or-advances-a-case
 */
class CaseAdvanceNode extends CaseNodeBase {

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
		return [
			[
				'key' => 'item',
				'label' => $this->l10n->t('Plan item'),
				'type' => 'text',
				'required' => true,
				'help' => $this->l10n->t('The key of the stage, task or milestone to move.'),
			],
			[
				'key' => 'to',
				'label' => $this->l10n->t('New state'),
				'type' => 'select',
				'required' => true,
				'options' => CaseItem::STATES,
			],
			[
				'key' => 'reason',
				'label' => $this->l10n->t('Reason'),
				'type' => 'text',
			],
			[
				'key' => 'uuid',
				'label' => $this->l10n->t('Object'),
				'type' => 'text',
				'help' => $this->l10n->t('Which object the case is. Leave empty to use the object the step receives.'),
			],
		];
	}//end configForm()

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
		if ($items === []) {
			return $items;
		}

		$this->validateConfig(config: $config);
		$uid = $this->actingUid(context: $context);
		$key = trim((string)$config['item']);
		$reason = null;
		if (is_string(($config['reason'] ?? null)) === true && trim($config['reason']) !== '') {
			$reason = trim($config['reason']);
		}

		$results = [];
		foreach (array_keys($this->targets(items: $items, config: $config)) as $uuid) {
			$results[$uuid] = $this->scope->call(
				context: $context,
				operation: fn (): array => $this->advance(objectUuid: (string)$uuid, key: $key, to: (string)$config['to'], uid: $uid, reason: $reason)
			);
		}

		$out = [];
		foreach ($items as $index => $item) {
			$json = ($item[FlowItems::JSON] ?? []);
			$json['case'] = $results[(string)array_key_first($this->targets(items: [$item], config: $config))];
			$item[FlowItems::JSON] = $json;
			$out[$index] = $item;
		}

		return $out;
	}//end execute()

	/**
	 * Find the item by key and transition it.
	 *
	 * @param string $objectUuid The object.
	 * @param string $key The plan-item key.
	 * @param string $to The target state.
	 * @param string $uid The acting identity.
	 * @param string|null $reason Free text.
	 *
	 * @return array{item: string, state: string} The item and its state afterwards.
	 *
	 * @throws RuntimeException When the plan has no such item.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-flow-step-opens-or-advances-a-case
	 */
	private function advance(string $objectUuid, string $key, string $to, string $uid, ?string $reason): array {
		$plan = $this->plans->getPlan(objectUuid: $objectUuid, uid: $uid);
		foreach (($plan['items'] ?? []) as $row) {
			if (($row['key'] ?? null) !== $key) {
				continue;
			}

			$moved = $this->plans->transition(itemUuid: (string)$row['uuid'], to: $to, uid: $uid, reason: $reason);

			return ['item' => $key, 'state' => (string)$moved->getState()];
		}

		throw new RuntimeException(
			$this->l10n->t('The case plan on object %1$s has no item "%2$s".', [$objectUuid, $key])
		);
	}//end advance()
}//end class
