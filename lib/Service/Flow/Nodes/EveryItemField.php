<?php

/**
 * One value written onto the record of every item.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use OCA\OpenRegister\Service\Flow\FlowItems;

/**
 * A key and the value the waiting nodes place under it on each item's `json`.
 *
 * The value goes on the item, not on the envelope: a key at the envelope level
 * is invisible to a Switch and dropped by the next rebuild. Entries that are
 * not arrays pass through untouched.
 *
 * @spec openspec/specs/flow-user-task-node/spec.md#requirement-the-outcome-is-written-onto-every-item-not-only-onto-the-run
 */
final class EveryItemField {
	/**
	 * Constructor.
	 *
	 * @param string $key   The item field the value lands under.
	 * @param mixed  $value The value.
	 */
	public function __construct(
		private readonly string $key,
		private readonly mixed $value,
	) {
	}//end __construct()

	/**
	 * The items, each carrying the value under the key.
	 *
	 * @param array $items The items.
	 *
	 * @return array The items with the value placed.
	 *
	 * @spec openspec/specs/flow-user-task-node/spec.md#requirement-the-outcome-is-written-onto-every-item-not-only-onto-the-run
	 */
	public function placedOn(array $items): array {
		foreach ($items as $index => $item) {
			if (is_array($item) === false) {
				continue;
			}

			$json = (array)($item[FlowItems::JSON] ?? []);
			$json[$this->key] = $this->value;
			$item[FlowItems::JSON] = $json;
			$items[$index] = $item;
		}

		return $items;
	}//end placedOn()
}//end class
