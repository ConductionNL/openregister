<?php

/**
 * What one set-fields step does to each item, read once from its config.
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
 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

/**
 * The reshaping a `SetFieldsNode` step applies, and where in the batch it is.
 *
 * The four mappings and `keepOnlySet` are the step's config, read the same
 * way for every item. The run context and batch size feed `compute`, whose
 * expressions may read `itemIndex`, `itemCount` and `context`; the item
 * index is set per item with {@see forItem()}.
 *
 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
 */
class SetFieldsPlan {

	/**
	 * The `set` mapping: path to value template.
	 *
	 * @var array
	 */
	public readonly array $set;

	/**
	 * The `compute` mapping: path to expression.
	 *
	 * @var array
	 */
	public readonly array $compute;

	/**
	 * The `rename` mapping: old key to new key.
	 *
	 * @var array
	 */
	public readonly array $rename;

	/**
	 * The keys to `remove`.
	 *
	 * @var array
	 */
	public readonly array $remove;

	/**
	 * Whether everything the step did not set is dropped.
	 *
	 * @var boolean
	 */
	public readonly bool $keepOnlySet;

	/**
	 * This item's position in the batch.
	 *
	 * @var integer
	 */
	private int $itemIndex = 0;

	/**
	 * Constructor.
	 *
	 * @param array $config    The step configuration.
	 * @param array $context   The run context, for `compute`.
	 * @param int   $itemCount The batch size, for `compute`.
	 */
	public function __construct(
		array $config,
		public readonly array $context,
		public readonly int $itemCount,
	) {
		$this->set = (array)($config['set'] ?? []);
		$this->compute = (array)($config['compute'] ?? []);
		$this->rename = (array)($config['rename'] ?? []);
		$this->remove = (array)($config['remove'] ?? []);
		$this->keepOnlySet = (($config['keepOnlySet'] ?? false) === true);

	}//end __construct()

	/**
	 * The same plan, positioned at one item of the batch.
	 *
	 * @param int $itemIndex The item's position.
	 *
	 * @return self The positioned plan.
	 *
	 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
	 */
	public function forItem(int $itemIndex): self {
		$plan = clone $this;
		$plan->itemIndex = $itemIndex;

		return $plan;

	}//end forItem()

	/**
	 * This item's position in the batch.
	 *
	 * @return int The position, 0 until {@see forItem()} sets it.
	 *
	 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
	 */
	public function itemIndex(): int {
		return $this->itemIndex;

	}//end itemIndex()
}//end class
