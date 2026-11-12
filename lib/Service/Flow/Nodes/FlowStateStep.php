<?php

/**
 * One flow-state step's handle and configuration, carried together.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use OCA\OpenRegister\Service\Flow\FlowStateHandle;

/**
 * What every `FlowStateNode` operation reads besides the item itself.
 *
 * The state handle and the step's configuration never vary within one
 * `execute()` call; only the item does. Carrying the pair as one value keeps
 * each operation at the item plus this.
 *
 * @spec exclude The flow-state slot contract has no canonical spec; see
 *  FlowStateSlots::leaseExpired() for the gap.
 */
class FlowStateStep {

	/**
	 * Constructor.
	 *
	 * @param FlowStateHandle $state  The flow's state handle.
	 * @param array           $config The step's configuration.
	 */
	public function __construct(
		public readonly FlowStateHandle $state,
		public readonly array $config,
	) {

	}//end __construct()

	/**
	 * The state key the slot table is stored under.
	 *
	 * @return string The key.
	 *
	 * @spec exclude The flow-state slot contract has no canonical spec.
	 */
	public function slotsKey(): string {
		return (string)$this->config['slots'];

	}//end slotsKey()

	/**
	 * The holder an item names, through the configured holder field.
	 *
	 * @param array $json The item's json.
	 *
	 * @return string The holder, or an empty string when the item names none.
	 *
	 * @spec exclude The flow-state slot contract has no canonical spec.
	 */
	public function holderOf(array $json): string {
		return (string)($json[(string)($this->config['holder'] ?? 'holder')] ?? '');

	}//end holderOf()
}//end class
