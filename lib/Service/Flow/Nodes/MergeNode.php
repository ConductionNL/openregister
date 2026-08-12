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
 * @spec openspec/changes/or-flow-merge/specs/flow-merge/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;
use UnexpectedValueException;

/**
 * Merges branch item lists — append, merge-by-key, or unique.
 */
class MergeNode implements IFlowNode, IFlowNodeConfigKeys {

	/**
	 * The modes this node understands.
	 *
	 * @var array<int, string>
	 */
	private const MODES = ['append', 'mergeByKey', 'unique'];

	/**
	 * Constructor.
	 *
	 * @param IL10N $l10n Translations.
	 * @param IURLGenerator $urls For the palette icon.
	 */
	public function __construct(
		private readonly IL10N $l10n,
		private readonly IURLGenerator $urls,
	) {

	}//end __construct()

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
	 * Merging grants no privilege.
	 *
	 * @param int $scope The scope constant.
	 *
	 * @return boolean Whether it is available.
	 */
	public function isAvailableForScope(int $scope): bool {
		return in_array($scope, [IManager::SCOPE_ADMIN, IManager::SCOPE_USER], true);
	}//end isAvailableForScope()

	/**
	 * The config vocabulary of a merge step.
	 *
	 * @return array<int, string> The accepted config keys.
	 *
	 * @spec openspec/changes/or-flow-preflight/specs/flow-preflight/spec.md
	 */
	public function configKeys(): array {
		return ['mode', 'key'];
	}//end configKeys()

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
	 * @param array $items The input items (all branches, concatenated).
	 * @param array $config The step configuration.
	 * @param array $context Run-level metadata.
	 *
	 * @return array The combined items.
	 */
	public function execute(array $items, array $config, array $context): array {
		$mode = (string)($config['mode'] ?? 'append');
		$key = (string)($config['key'] ?? '');

		if ($mode === 'mergeByKey') {
			return $this->mergeByKey(items: $items, key: $key);
		}

		if ($mode === 'unique') {
			return $this->unique(items: $items, key: $key);
		}

		// Append: the engine already concatenated the branches; re-pair each
		// item to its own position so provenance stays intact.
		$out = [];
		foreach ($items as $index => $item) {
			$out[] = FlowItems::item(
				json: (array)($item[FlowItems::JSON] ?? []),
				binary: (array)($item[FlowItems::BINARY] ?? []),
				fromItemIndex: $index
			);
		}

		return $out;
	}//end execute()

	/**
	 * Group items by their `key` value and shallow-merge each group's records.
	 *
	 * @param array $items The input items.
	 * @param string $key The field to group on.
	 *
	 * @return array<int, array> One item per distinct key value.
	 *
	 * @spec openspec/changes/or-flow-merge/specs/flow-merge/spec.md
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
			$out[] = FlowItems::item(json: $groups[$value]['json'], binary: [], fromItemIndex: $groups[$value]['from']);
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
	 * @spec openspec/changes/or-flow-merge/specs/flow-merge/spec.md
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
				binary: (array)($item[FlowItems::BINARY] ?? []),
				fromItemIndex: $index
			);
		}

		return $out;
	}//end unique()
}//end class
