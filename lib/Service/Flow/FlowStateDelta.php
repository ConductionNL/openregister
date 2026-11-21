<?php

/**
 * FlowStateDelta: what a hop added to or changed in the items it passed on.
 *
 * A step row's counts say how many items went in and came out; they do not say
 * what the node did to them. The delta does: per output item, the data keys
 * that are new or hold a different value than in the input item it came from.
 * Next to the full sample in the run log, it answers "what did this step
 * produce" without diffing two samples by hand (flow-engine-unification 3.4e,
 * carried over from the graph executor's `produced` trace).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-engine-unification/specs/flow-execution-history/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * Computes the added and changed data keys of a hop's output items.
 *
 * @spec openspec/changes/flow-engine-unification/specs/flow-execution-history/spec.md
 */
final class FlowStateDelta {

	/**
	 * The added and changed data keys of each output item, against the input
	 * item it came from: the one its `pairedItem` names, else the one at the
	 * same position. An output item with no input counterpart produced all of
	 * its data. At most FlowEngine::LOG_ITEM_SAMPLE items are described, the
	 * same items the run log samples.
	 *
	 * @param array<int|string, mixed> $itemsIn  The items the hop received.
	 * @param array<int|string, mixed> $itemsOut The items the hop passed on.
	 *
	 * @return list<array<string, mixed>> One delta per sampled output item, in order.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-execution-history/spec.md
	 */
	public static function produced(array $itemsIn, array $itemsOut): array {
		$inputs = array_values($itemsIn);
		$deltas = [];
		foreach (array_slice(array_values($itemsOut), 0, FlowEngine::LOG_ITEM_SAMPLE) as $position => $item) {
			$source   = ($inputs[self::sourceIndex(item: $item, position: $position)] ?? null);
			$deltas[] = self::delta(before: self::dataOf(item: $source), after: self::dataOf(item: $item));
		}

		return $deltas;
	}//end produced()

	/**
	 * The index of the input item an output item came from.
	 *
	 * @param mixed   $item     The output item.
	 * @param integer $position Its position among the output items.
	 *
	 * @return integer The input index.
	 */
	private static function sourceIndex(mixed $item, int $position): int {
		if (is_array($item) === true) {
			$paired = ($item[FlowItemShape::PAIRED_ITEM]['item'] ?? null);
			if (is_int($paired) === true) {
				return $paired;
			}
		}

		return $position;
	}//end sourceIndex()

	/**
	 * An item's data: its `json` member, or nothing.
	 *
	 * @param mixed $item The item.
	 *
	 * @return array<int|string, mixed>
	 */
	private static function dataOf(mixed $item): array {
		if (is_array($item) === false || is_array($item[FlowItemShape::JSON] ?? null) === false) {
			return [];
		}

		return $item[FlowItemShape::JSON];
	}//end dataOf()

	/**
	 * The keys of `after` that `before` lacks or holds a different value for.
	 *
	 * @param array<int|string, mixed> $before The data before the hop.
	 * @param array<int|string, mixed> $after  The data after it.
	 *
	 * @return array<string, mixed>
	 */
	private static function delta(array $before, array $after): array {
		$delta = [];
		foreach ($after as $key => $value) {
			if (array_key_exists($key, $before) === false || $before[$key] !== $value) {
				$delta[(string) $key] = $value;
			}
		}

		return $delta;
	}//end delta()
}//end class
