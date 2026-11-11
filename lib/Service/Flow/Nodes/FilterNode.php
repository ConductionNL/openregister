<?php

/**
 * Keeps only the items whose condition holds.
 *
 * The simplest node that proves an item list is a list: it returns fewer items
 * than it received, and returning none is a legitimate outcome that ends the
 * branch's data rather than an error.
 *
 * The condition is evaluated PER ITEM, so one authored expression filters a
 * whole collection without the author drawing a loop.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/or-flow-expressions/specs/flow-expressions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use OCA\OpenRegister\Service\Flow\FlowExpression;
use OCA\OpenRegister\Service\Flow\FlowExpressionDataOptions;
use OCA\OpenRegister\Service\Flow\FlowItemOptions;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
use OCA\OpenRegister\Service\Flow\IFlowNodeTaxonomy;
use OCA\OpenRegister\Service\Flow\IFlowSideEffectFree;
use UnexpectedValueException;

/**
 * Drops items that do not match.
 */
class FilterNode extends LogicNodeBase implements IFlowNode, FilterNodeInterface, IFlowNodeConfigKeys, IFlowNodeTaxonomy, IFlowSideEffectFree {
	/**
	 * The step type.
	 *
	 * @return string The id.
	 */
	public function getId(): string {
		return 'openregister.filter';
	}//end getId()

	/**
	 * Palette name.
	 *
	 * @return string The display name.
	 */
	public function getDisplayName(): string {
		return $this->l10n->t('Filter');
	}//end getDisplayName()

	/**
	 * Palette description.
	 *
	 * @return string The description.
	 */
	public function getDescription(): string {
		return $this->l10n->t('Keep only the items matching a condition.');
	}//end getDescription()

	/**
	 * Palette icon.
	 *
	 * @return string The icon URL.
	 */
	public function getIcon(): string {
		return $this->urls->imagePath('core', 'actions/filter.svg');
	}//end getIcon()

	/**
	 * The config vocabulary of a filter step.
	 *
	 * @return array<int, string> The accepted config keys.
	 *
	 * @spec openspec/changes/or-flow-preflight/specs/flow-preflight/spec.md
	 */
	public function configKeys(): array {
		return ['condition'];
	}//end configKeys()

	/**
	 * Reject a filter with no condition, or one that cannot be evaluated.
	 *
	 * A filter with no condition keeps everything, which is a step that does
	 * nothing while looking like it does something.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the condition is missing or malformed.
	 *
	 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
	 */
	public function validateConfig(array $config): void {
		$condition = ($config['condition'] ?? null);
		if ($condition === null || $condition === []) {
			throw new UnexpectedValueException($this->l10n->t('A filter needs a condition.'));
		}

		// A CONSTANT is not a filter. `FlowExpression::isValid()` accepts any
		// scalar because a bare literal is legal JSONLogic — true where a value
		// is wanted, useless where a predicate is. Evaluated per item a constant
		// gives the same answer every time, so the step keeps everything or
		// drops everything while reading like a rule.
		//
		// Measured: a condition of `'{{ status == "synced" }}'` passed this
		// guard and then kept all eleven items. The run was green end to end and
		// the sweep flagged every object instead of the nine it meant to.
		if (is_array($condition) === false) {
			$this->refuseConstant(condition: $condition);
		}

		if (FlowExpression::isValid(logic: $condition) === false) {
			throw new UnexpectedValueException($this->l10n->t('That condition is not a valid expression.'));
		}

	}//end validateConfig()

	/**
	 * Refuse a condition that is not an expression: a template string gets
	 * the template hint, anything else is named as a fixed value.
	 *
	 * @param mixed $condition The configured condition, not an array.
	 *
	 * @return never
	 *
	 * @throws UnexpectedValueException Always.
	 */
	private function refuseConstant(mixed $condition): never {
		if (is_string($condition) === true && str_contains($condition, '{{') === true) {
			throw new UnexpectedValueException(
				$this->l10n->t(
					'A filter condition is an expression, not a template. Write {example} rather than "{given}".',
					[
						'example' => '{"==": [{"var": "json.status"}, "synced"]}',
						'given' => $condition,
					]
				)
			);
		}

		throw new UnexpectedValueException(
			$this->l10n->t(
				'A filter condition must be an expression, not a fixed value: "{given}" answers the same for every item.',
				['given' => var_export($condition, true)]
			)
		);
	}//end refuseConstant()

	/**
	 * Keep the matching items.
	 *
	 * Output items are re-paired to their ORIGINAL input index, not to their
	 * new position, so provenance survives the drop: item 3 of the output can
	 * still be traced to item 7 of the input.
	 *
	 * @param FlowNodeCall $call The input items, the step configuration and the run context.
	 *
	 * @return array The items that matched.
	 */
	public function execute(FlowNodeCall $call): array {
		$items = $call->items;
		$config = $call->config;
		$context = $call->context;
		$documents = $this->documentsFor(items: $items, context: $context);
		$verdicts = $this->verdicts(condition: ($config['condition'] ?? null), documents: $documents);

		return $this->kept(items: $items, verdicts: $verdicts);
	}//end execute()

	/**
	 * The expression document of each item, keyed by its input index.
	 *
	 * @param array $items   The input items.
	 * @param array $context Run-level metadata.
	 *
	 * @return array<int|string, array> One data document per item.
	 */
	private function documentsFor(array $items, array $context): array {
		$count = count($items);
		$documents = [];
		foreach ($items as $index => $item) {
			$documents[$index] = FlowExpression::documentFor(
				item: $item,
				options: new FlowExpressionDataOptions(
					itemIndex: $index,
					itemCount: $count,
					context: $context
				)
			);
		}

		return $documents;
	}//end documentsFor()

	/**
	 * Whether the condition holds for each document, keyed as the documents are.
	 *
	 * @param mixed $condition The JSONLogic condition.
	 * @param array $documents The data documents, keyed by input index.
	 *
	 * @return array<int|string, bool> The verdict per input index.
	 */
	private function verdicts(mixed $condition, array $documents): array {
		$verdicts = [];
		foreach ($documents as $index => $data) {
			$verdicts[$index] = FlowExpression::isTrue(logic: $condition, data: $data);
		}

		return $verdicts;
	}//end verdicts()

	/**
	 * The items whose verdict holds, re-paired to their original input index.
	 *
	 * @param array $items    The input items.
	 * @param array $verdicts The verdict per input index.
	 *
	 * @return array The items that matched.
	 */
	private function kept(array $items, array $verdicts): array {
		$kept = [];
		foreach ($items as $index => $item) {
			if ($verdicts[$index] === false) {
				continue;
			}

			$kept[] = FlowItems::item(
				json: (array)($item[FlowItems::JSON] ?? []),
				options: new FlowItemOptions(binary: (array)($item[FlowItems::BINARY] ?? []), fromItemIndex: $index)
			);
		}

		return $kept;
	}//end kept()

	/**
	 * What kind of step this is. Reshapes the item stream by dropping items.
	 *
	 * @return string The BPMN kind.
	 *
	 * @spec openspec/changes/flow-node-taxonomy/specs/flow-node-taxonomy/spec.md#requirement-a-node-declares-a-semantic-kind-drawn-from-bpmn
	 */
	public function getKind(): string {
		return IFlowNodeTaxonomy::KIND_SCRIPT_TASK;

	}//end getKind()
}//end class
