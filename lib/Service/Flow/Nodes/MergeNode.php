<?php

/**
 * Combines the item lists arriving from several branches into one.
 *
 * A Merge sits at a join: an edge whose `from` names several nodes. The Petri
 * net already holds the transition until every one of those branches has
 * arrived — so "wait for all" is the default, done by the engine, and this node
 * decides only HOW to combine what arrived. By the time `execute()` runs, the
 * engine has concatenated every incoming branch's items into `$items`, in the
 * branches' declared order.
 *
 * Modes:
 *  - `append` (default) — keep them all, in order. The concatenation the engine
 *    already produced; this is a pass-through.
 *  - `mergeByKey` — group items that share a value under `key`, and shallow-merge
 *    each group's records into one. The later branch wins a field collision,
 *    which matches "enrich the record as it flows through".
 *  - `unique` — drop later items whose `key` value was already seen, keeping the
 *    first. Deduplication across branches.
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
 * @spec openspec/specs/flow-merge/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use OCA\OpenRegister\Service\Flow\FlowItemOptions;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigForm;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
use OCA\OpenRegister\Service\Flow\IFlowNodeTaxonomy;
use OCA\OpenRegister\Service\Flow\IFlowSideEffectFree;
use UnexpectedValueException;

/**
 * Merges branch item lists — append, merge-by-key, or unique.
 */
class MergeNode extends LogicNodeBase implements IFlowNode, MergeNodeInterface, IFlowNodeConfigKeys,
	IFlowNodeConfigForm, IFlowNodeTaxonomy, IFlowSideEffectFree
{

	/**
	 * The modes this node understands.
	 *
	 * @var array<int, string>
	 */
	private const MODES = ['append', 'mergeByKey', 'unique'];

	/**
	 * The step type.
	 *
	 * @return string The id.
	 */
	public function getId(): string {
		return 'openregister.merge';
	}//end getId()

	/**
	 * Palette name.
	 *
	 * @return string The display name.
	 */
	public function getDisplayName(): string {
		return $this->l10n->t('Merge');
	}//end getDisplayName()

	/**
	 * Palette description.
	 *
	 * @return string The description.
	 */
	public function getDescription(): string {
		return $this->l10n->t('Combine the items arriving from several branches into one list.');
	}//end getDescription()

	/**
	 * Palette icon.
	 *
	 * @return string The icon URL.
	 */
	public function getIcon(): string {
		return $this->urls->imagePath('core', 'actions/menu.svg');
	}//end getIcon()

	/**
	 * The config vocabulary of a merge step.
	 *
	 * @return array<int, string> The accepted config keys.
	 *
	 * @spec openspec/specs/flow-preflight/spec.md
	 */
	public function configKeys(): array {
		return ['mode', 'key'];
	}//end configKeys()

	/**
	 * How the arriving branches are combined, and the field they are matched on.
	 *
	 * @return array<int, array<string, mixed>> The field descriptions.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-node-type-declares-its-own-form-and-its-own-run-log-actions
	 */
	public function configForm(): array {
		return [
			[
				'key' => 'mode',
				'label' => $this->l10n->t('How to combine'),
				'type' => 'text',
				'help' => $this->l10n->t(
					'"append" keeps every item in branch order (the default), "mergeByKey" joins '
						. 'the items that share a key into one, "unique" keeps only the first item per key.'
				),
			],
			[
				'key' => 'key',
				'label' => $this->l10n->t('Key field'),
				'type' => 'text',
				'help' => $this->l10n->t('The field items are matched on. Needed for '
					. '"mergeByKey" and "unique"; when two branches set the same field, the later branch wins.'),
			],
		];
	}//end configForm()

	/**
	 * Reject an unknown mode, or a keyed mode with no key.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the mode is unknown or a key is missing.
	 */
	public function validateConfig(array $config): void {
		$mode = (string)($config['mode'] ?? 'append');
		if (in_array($mode, self::MODES, true) === false) {
			throw new UnexpectedValueException(
				$this->l10n->t('Unknown merge mode. Use append, mergeByKey or unique.')
			);
		}

		if (($mode === 'mergeByKey' || $mode === 'unique') && trim((string)($config['key'] ?? '')) === '') {
			throw new UnexpectedValueException($this->l10n->t('This merge mode needs a key field.'));
		}

	}//end validateConfig()

	/**
	 * Combine the already-concatenated branch items.
	 *
	 * @param FlowNodeCall $call The input items, the step configuration and the run context.
	 *
	 * @return array The combined items.
	 */
	public function execute(FlowNodeCall $call): array {
		$items = $call->items;
		$config = $call->config;
		return $this->merged(items: $items, config: $config);
	}//end execute()

	/**
	 * The items combined the way the configured mode says.
	 *
	 * @param array $items  The input items.
	 * @param array $config The step configuration.
	 *
	 * @return array The combined items.
	 */
	private function merged(array $items, array $config): array {
		$key = (string)($config['key'] ?? '');

		return match ((string)($config['mode'] ?? 'append')) {
			'mergeByKey' => $this->mergeByKey(items: $items, key: $key),
			'unique' => $this->unique(items: $items, key: $key),
			default => $this->append(items: $items),
		};
	}//end merged()

	/**
	 * Append: the engine already concatenated the branches; re-pair each
	 * item to its own position so provenance stays intact.
	 *
	 * @param array $items The input items.
	 *
	 * @return array The items, re-paired.
	 */
	private function append(array $items): array {
		$out = [];
		foreach ($items as $index => $item) {
			$out[] = FlowItems::item(
				json: (array)($item[FlowItems::JSON] ?? []),
				options: new FlowItemOptions(binary: (array)($item[FlowItems::BINARY] ?? []), fromItemIndex: $index)
			);
		}

		return $out;
	}//end append()

	/**
	 * Group items by their `key` value and shallow-merge each group's records.
	 *
	 * @param array $items The input items.
	 * @param string $key The field to group on.
	 *
	 * @return array<int, array> One item per distinct key value.
	 *
	 * @spec openspec/specs/flow-merge/spec.md
	 */
	private function mergeByKey(array $items, string $key): array {
		$groups = [];
		$order = [];
		foreach ($items as $index => $item) {
			$json = (array)($item[FlowItems::JSON] ?? []);
			$value = (string)($json[$key] ?? '');
			if (isset($groups[$value]) === false) {
				$groups[$value] = ['json' => [], 'from' => $index];
				$order[] = $value;
			}

			// A later branch's fields win the collision — enrichment as the
			// record flows through.
			$groups[$value]['json'] = array_merge($groups[$value]['json'], $json);
		}

		$out = [];
		foreach ($order as $value) {
			$out[] = FlowItems::item(json: $groups[$value]['json'], options: new FlowItemOptions(binary: [], fromItemIndex: $groups[$value]['from']));
		}

		return $out;
	}//end mergeByKey()

	/**
	 * Keep the first item for each distinct `key` value; drop later duplicates.
	 *
	 * @param array $items The input items.
	 * @param string $key The field to dedupe on.
	 *
	 * @return array<int, array> The deduplicated items.
	 *
	 * @spec openspec/specs/flow-merge/spec.md
	 */
	private function unique(array $items, string $key): array {
		$seen = [];
		$out = [];
		foreach ($items as $index => $item) {
			$json = (array)($item[FlowItems::JSON] ?? []);
			$value = (string)($json[$key] ?? '');
			if (isset($seen[$value]) === true) {
				continue;
			}

			$seen[$value] = true;
			$out[] = FlowItems::item(
				json: $json,
				options: new FlowItemOptions(binary: (array)($item[FlowItems::BINARY] ?? []), fromItemIndex: $index)
			);
		}

		return $out;
	}//end unique()

	/**
	 * What kind of step this is. A join: several ways in, one out.
	 *
	 * @return string The BPMN kind.
	 *
	 * @spec openspec/changes/flow-node-taxonomy/specs/flow-node-taxonomy/spec.md#requirement-a-node-declares-a-semantic-kind-drawn-from-bpmn
	 */
	public function getKind(): string {
		return IFlowNodeTaxonomy::KIND_GATEWAY;

	}//end getKind()
}//end class
