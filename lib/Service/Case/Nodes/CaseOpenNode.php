<?php

/**
 * A flow step that opens a case: a BPMN process creating a CMMN case plan.
 *
 * Creates a case plan on the object the step receives, from the definition
 * the step carries, through {@see CasePlanService::createPlan()} as the
 * run's acting identity. An object that already has a plan is reported on
 * the item (`case.opened: false`, `case.reason: exists`), not raised, so a
 * re-run or a second trigger is safe. Any other refusal fails the step.
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

use OCA\OpenRegister\Exception\CasePlanExistsException;
use OCA\OpenRegister\Service\Flow\FlowItems;
use UnexpectedValueException;

/**
 * The `openregister.case-open` step.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-a-flow-step-opens-or-advances-a-case
 */
class CaseOpenNode extends CaseNodeBase {

	/**
	 * The step type this node answers to.
	 *
	 * @var string
	 */
	public const TYPE = 'openregister.case-open';

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
		return $this->l10n->t('Open a case');
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
			'Start a case plan on the object this step receives. If the object already has one, the step says so and carries on.'
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
		return ['definition', 'uuid'];
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
				'key' => 'definition',
				'label' => $this->l10n->t('Case plan'),
				'type' => 'json',
				'required' => true,
				'help' => $this->l10n->t('The plan to start: settings and items, the same shape the case plan API takes.'),
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
	 * A case-open step needs a plan with items.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When it has none.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-flow-step-opens-or-advances-a-case
	 */
	public function validateConfig(array $config): void {
		$definition = ($config['definition'] ?? null);
		if (is_array($definition) === false || is_array(($definition['items'] ?? null)) === false || $definition['items'] === []) {
			throw new UnexpectedValueException($this->l10n->t('An open-a-case step needs a case plan with at least one item.'));
		}
	}//end validateConfig()

	/**
	 * Open the case on each distinct object.
	 *
	 * @param array $items The incoming items.
	 * @param array $config The step configuration.
	 * @param array $context The run context.
	 *
	 * @return array The items, each carrying `case` = {opened, reason}.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-flow-step-opens-or-advances-a-case
	 */
	public function execute(array $items, array $config, array $context): array {
		if ($items === []) {
			return $items;
		}

		$this->validateConfig(config: $config);
		$uid = $this->actingUid(context: $context);
		$results = [];
		foreach ($this->targets(items: $items, config: $config) as $uuid => $target) {
			$results[$uuid] = $this->scope->call(
				context: $context,
				operation: fn (): array => $this->open(target: $target, definition: $config['definition'], uid: $uid)
			);
		}

		$out = [];
		foreach ($items as $index => $item) {
			$json = ($item[FlowItems::JSON] ?? []);
			$targets = $this->targets(items: [$item], config: $config);
			$json['case'] = $results[(string)array_key_first($targets)];
			$item[FlowItems::JSON] = $json;
			$out[$index] = $item;
		}

		return $out;
	}//end execute()

	/**
	 * Create the plan, or report that one exists.
	 *
	 * @param array{uuid: string, register: int|null, schema: int|null} $target The object.
	 * @param array $definition The plan.
	 * @param string $uid The acting identity.
	 *
	 * @return array{opened: bool, reason: string} What happened.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-flow-step-opens-or-advances-a-case
	 */
	private function open(array $target, array $definition, string $uid): array {
		try {
			$this->plans->createPlan(
				objectUuid: $target['uuid'],
				registerId: $target['register'],
				schemaId: $target['schema'],
				definition: $definition,
				uid: $uid
			);
		} catch (CasePlanExistsException) {
			return ['opened' => false, 'reason' => 'exists'];
		}

		return ['opened' => true, 'reason' => 'opened'];
	}//end open()
}//end class
