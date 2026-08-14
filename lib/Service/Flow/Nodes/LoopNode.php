<?php

/**
 * Splits an item list into fixed-size batches.
 *
 * A node already acts once per item, so "loop over items" is not needed to
 * process a collection — that is the item model's whole point. What a batch
 * node IS for: a downstream step that must be handed a bounded slice at a time
 * (an API with a page limit, a bulk write with a max payload). This node takes
 * the item list and emits one item per batch, each carrying its slice under
 * `items`, so the next step sees N batch-items instead of one long list.
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
 * Batches an item list into slices of a configured size.
 */
class LoopNode implements IFlowNode, IFlowNodeConfigKeys {
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
		return 'openregister.batch';
	}//end getId()

	/**
	 * Palette name.
	 *
	 * @return string The display name.
	 */
	public function getDisplayName(): string {
		return $this->l10n->t('Batch items');
	}//end getDisplayName()

	/**
	 * Palette description.
	 *
	 * @return string The description.
	 */
	public function getDescription(): string {
		return $this->l10n->t('Split the items into fixed-size batches for a step that needs them a slice at a time.');
	}//end getDescription()

	/**
	 * Palette icon.
	 *
	 * @return string The icon URL.
	 */
	public function getIcon(): string {
		return $this->urls->imagePath('core', 'actions/play-next.svg');
	}//end getIcon()

	/**
	 * Batching grants no privilege.
	 *
	 * @param int $scope The scope constant.
	 *
	 * @return boolean Whether it is available.
	 */
	public function isAvailableForScope(int $scope): bool {
		return in_array($scope, [IManager::SCOPE_ADMIN, IManager::SCOPE_USER], true);
	}//end isAvailableForScope()

	/**
	 * The config vocabulary of a loop step.
	 *
	 * @return array<int, string> The accepted config keys.
	 *
	 * @spec openspec/changes/or-flow-preflight/specs/flow-preflight/spec.md
	 */
	public function configKeys(): array {
		return ['batchSize'];
	}//end configKeys()

	/**
	 * Reject a non-positive batch size.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the batch size is not a positive integer.
	 */
	public function validateConfig(array $config): void {
		$size = (int)($config['batchSize'] ?? 0);
		if ($size < 1) {
			throw new UnexpectedValueException($this->l10n->t('A loop needs a batch size of one or more.'));
		}

	}//end validateConfig()

	/**
	 * Emit one item per batch.
	 *
	 * Each output item carries `batchIndex`, `batchCount` and the slice under
	 * `items`, so a downstream step can act on the slice and know where it is in
	 * the sequence. An empty input yields no batches, which ends the branch.
	 *
	 * @param array $items The input items.
	 * @param array $config The step configuration.
	 * @param array $context Run-level metadata.
	 *
	 * @return array One item per batch.
	 */
	public function execute(array $items, array $config, array $context): array {
		$size = max(1, (int)($config['batchSize'] ?? 1));
		if ($items === []) {
			return [];
		}

		$batches = array_chunk($items, $size);
		$count = count($batches);

		$out = [];
		foreach ($batches as $batchIndex => $batch) {
			$out[] = FlowItems::item(
				json: [
					'batchIndex' => $batchIndex,
					'batchCount' => $count,
					'items' => array_map(
						static function (array $item): array {
							return (array)($item[FlowItems::JSON] ?? []);
						},
						$batch
					),
				],
				binary: [],
				fromItemIndex: ($batchIndex * $size)
			);
		}

		return $out;
	}//end execute()
}//end class
