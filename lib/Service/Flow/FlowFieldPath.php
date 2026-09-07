<?php

/**
 * Writing a value at a dotted field path.
 *
 * 🔑 ONE COPY, BECAUSE TWO NODES MEAN THE SAME THING BY IT. `SetFieldsNode` and
 * `DecisionTableNode` each carried a byte-identical `assign()`, and the second
 * one's docblock said so out loud: "same semantics as `openregister.set-fields`
 * gives a literal path". Two copies of a rule about how authored structure is
 * built is the shape that drifts, and the drift would be invisible: both nodes
 * would keep writing something, just not the same something.
 *
 * WHY WRITING THROUGH A DOT EXISTS AT ALL
 * --------------------------------------
 * `{{dotted.path}}` had always READ through nested structures; writing one did
 * not. `"entry.owner"` created a top-level key literally CALLED `entry.owner`
 * beside any real `entry`. Nothing failed: the flow ran, the item came out, and
 * the object the author meant to build was simply never there — indistinguishable
 * from success until something downstream reads the shape and finds it empty.
 *
 * It matters because composing a NESTED record is the reason a flow sets fields
 * at all. Hydra's run record is `cycles[].stages[]`, and without this a flow can
 * only ever produce a flat bag. `compute` is no help: JsonLogic evaluates
 * expressions and cannot construct an object.
 *
 * A segment whose current value is not an array is REPLACED by a container,
 * because merging into a scalar has no meaning and skipping silently would be
 * exactly the invisible no-op these nodes refuse everywhere else. The structure
 * is a property of the configuration: an author who wrote `a.b.c` asked for
 * containers.
 *
 * ⚠️ THE PATH ARRIVES ALREADY RENDERED. `SetFieldsNode::renderPath()` refuses a
 * rendered segment containing a `.`, so splitting here cannot reinterpret a
 * value the author templated in: the nesting is exactly what the configuration
 * spelled out.
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
 * @spec openspec/changes/flow-decision-tables/specs/flow-decision-tables/spec.md#requirement-a-decision-table-step-evaluates-its-table-against-every-item
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * Writes a value into a record at a dotted path.
 */
final class FlowFieldPath {

	/**
	 * Write a value at a dotted path, creating the containers it needs.
	 *
	 * @param array  $json  The item's record, modified in place.
	 * @param string $path  The field path, optionally dotted.
	 * @param mixed  $value The value to write.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-decision-tables/specs/flow-decision-tables/spec.md#requirement-a-decision-table-step-evaluates-its-table-against-every-item
	 */
	public static function assign(array &$json, string $path, mixed $value): void {
		if (str_contains($path, '.') === false) {
			$json[$path] = $value;

			return;
		}

		$segments = explode('.', $path);
		$last = array_pop($segments);
		$cursor = &$json;

		foreach ($segments as $segment) {
			if (isset($cursor[$segment]) === false || is_array($cursor[$segment]) === false) {
				$cursor[$segment] = [];
			}

			$cursor = &$cursor[$segment];
		}

		$cursor[$last] = $value;
		unset($cursor);

	}//end assign()
}//end class
