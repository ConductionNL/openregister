<?php

/**
 * Routes each item to an output branch — per-item If / Switch.
 *
 * {@see SwitchNode} branches the whole batch: the engine picks one outgoing edge
 * by its condition and every item follows it. This node is the other kind of
 * branch, the one n8n's If and Switch actually are — it decides *per item*. Each
 * item is tested against the node's rules in order and tagged for the output of
 * the first rule it matches (or the fallback); items matching nothing with no
 * fallback are dropped. The engine then delivers each item only to the output
 * place it was tagged for ({@see FlowEngine::advanceItems()}).
 *
 * So a Router with rules -> "high" / "low" and an edge from each output splits
 * one stream of items into two, and both branches run with their own share.
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
 * @spec openspec/changes/or-flow-per-item-routing/specs/flow-per-item-routing/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use OCA\OpenRegister\Service\Flow\FlowExpression;
use OCA\OpenRegister\Service\Flow\FlowExpressionDataOptions;
use OCA\OpenRegister\Service\Flow\FlowItemOptions;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
use OCA\OpenRegister\Service\Flow\IFlowNodeTaxonomy;
use OCA\OpenRegister\Service\Flow\IFlowSideEffectFree;
use UnexpectedValueException;

/**
 * Tags each item with the output branch it should go to.
 */
class RouterNode extends LogicNodeBase implements IFlowNode, RouterNodeInterface, IFlowNodeConfigKeys, IFlowNodeTaxonomy, IFlowSideEffectFree {
	/**
	 * The step type.
	 *
	 * @return string The id.
	 */
	public function getId(): string {
		return 'openregister.route';
	}//end getId()

	/**
	 * Palette name.
	 *
	 * @return string The display name.
	 */
	public function getDisplayName(): string {
		return $this->l10n->t('Route items');
	}//end getDisplayName()

	/**
	 * Palette description.
	 *
	 * @return string The description.
	 */
	public function getDescription(): string {
		return $this->l10n->t('Send each item down a different branch, chosen per item by a rule.');
	}//end getDescription()

	/**
	 * Palette icon.
	 *
	 * @return string The icon URL.
	 */
	public function getIcon(): string {
		return $this->urls->imagePath('core', 'actions/arrow-right.svg');
	}//end getIcon()

	/**
	 * The config vocabulary of a route step.
	 *
	 * `routes` is NOT here, deliberately. It is the single most common way to
	 * author this node wrong (hydra-analyze-verdicts shipped it), and it is
	 * wrong all the way down — the entries are `when`/`to` where this node
	 * reads `condition`/`output`. Accepting the outer key would leave the
	 * inner mismatch just as silent.
	 *
	 * @return array<int, string> The accepted config keys.
	 *
	 * @spec openspec/changes/or-flow-preflight/specs/flow-preflight/spec.md
	 */
	public function configKeys(): array {
		return ['rules', 'default'];
	}//end configKeys()

	/**
	 * Reject a router with no rules — it would route nothing.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When no rules are configured.
	 */
	public function validateConfig(array $config): void {
		if ($this->rulesOf(config: $config) === []) {
			throw new UnexpectedValueException($this->l10n->t('A router needs at least one rule.'));
		}

	}//end validateConfig()

	/**
	 * Tag each item with the output it matches.
	 *
	 * Rules are tried in order; the first whose condition holds for the item wins
	 * and the item is tagged for that rule's output. An item matching no rule
	 * takes the fallback (`default`) when one is set, and is otherwise dropped —
	 * the same as a Switch case with no match and no default.
	 *
	 * @param array $items The input items.
	 * @param array $config The step configuration.
	 * @param array $context Run-level metadata.
	 *
	 * @return array The items, each tagged (or dropped when unmatched).
	 */
	public function execute(array $items, array $config, array $context): array {
		$documents = $this->documentsFor(items: $items, context: $context);

		return $this->tagged(items: $items, outputs: $this->outputsFor(config: $config, documents: $documents));
	}//end execute()

	/**
	 * The output each document selects, keyed as the documents are.
	 *
	 * @param array $config    The step configuration.
	 * @param array $documents The data documents, keyed by input index.
	 *
	 * @return array<int|string, string|null> The output per input index, null for none.
	 */
	private function outputsFor(array $config, array $documents): array {
		$outputs = [];
		foreach ($documents as $index => $data) {
			$outputs[$index] = $this->outputFor(config: $config, data: $data);
		}

		return $outputs;
	}//end outputsFor()

	/**
	 * The expression document of each item, keyed by its input index.
	 *
	 * Every item is evaluated at position 0 of the list, as the router always did.
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
				item: (array)$item,
				options: new FlowExpressionDataOptions(
					itemCount: $count,
					context: $context
				)
			);
		}

		return $documents;
	}//end documentsFor()

	/**
	 * Each item tagged for its output; an item with no output goes nowhere.
	 *
	 * @param array $items   The input items.
	 * @param array $outputs The output per input index, null for none.
	 *
	 * @return array The tagged items.
	 */
	private function tagged(array $items, array $outputs): array {
		$out = [];
		foreach ($items as $index => $item) {
			if ($outputs[$index] === null) {
				// No rule matched and no fallback: this item goes nowhere.
				continue;
			}

			$out[] = FlowItems::item(
				json: (array)($item[FlowItems::JSON] ?? []),
				options: new FlowItemOptions(binary: (array)($item[FlowItems::BINARY] ?? []), fromItemIndex: $index, output: $outputs[$index])
			);
		}

		return $out;
	}//end tagged()

	/**
	 * The output branch an item's data selects, or null when it selects none.
	 *
	 * @param array $config The step configuration.
	 * @param array $data   The item's expression data.
	 *
	 * @return string|null The chosen output, or null.
	 */
	private function outputFor(array $config, array $data): ?string {
		foreach ($this->rulesOf(config: $config) as $rule) {
			$output = trim((string)($rule['output'] ?? ''));
			if ($output === '') {
				continue;
			}

			if (FlowExpression::isTrue(logic: ($rule['condition'] ?? null), data: $data) === true) {
				return $output;
			}
		}

		$fallback = trim((string)($config['default'] ?? ''));
		if ($fallback !== '') {
			return $fallback;
		}

		return null;
	}//end outputFor()

	/**
	 * The configured rules, as a clean list.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return array<int, array> The rules.
	 */
	private function rulesOf(array $config): array {
		$rules = ($config['rules'] ?? []);
		if (is_array($rules) === false) {
			return [];
		}

		return array_values(array_filter($rules, static fn ($rule): bool => is_array($rule) === true));
	}//end rulesOf()

	/**
	 * What kind of step this is. Chooses which way out.
	 *
	 * @return string The BPMN kind.
	 *
	 * @spec openspec/changes/flow-node-taxonomy/specs/flow-node-taxonomy/spec.md#requirement-a-node-declares-a-semantic-kind-drawn-from-bpmn
	 */
	public function getKind(): string {
		return IFlowNodeTaxonomy::KIND_GATEWAY;

	}//end getKind()
}//end class
