<?php

/**
 * FlowStepOutcome: what one engine log entry puts in a step row's error and output columns.
 *
 * The step history and the firing commit both write step rows from the same
 * log entry; this is the one place that says which keys land where.
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

use OCA\OpenRegister\Db\FlowRunStep;

/**
 * Stamps a step row's outcome columns from its log entry.
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
final class FlowStepOutcome {

	/**
	 * Set the error and output columns of a step row from its log entry.
	 *
	 * `error` and `reason` are distinct outcomes that both belong in the error
	 * column: a thrown step and a deliberately stopped one are each something
	 * a person needs to read back. The output is what the node produced, minus
	 * the items themselves — a step row is an index into the run, not a second
	 * copy of its data.
	 *
	 * @param FlowRunStep          $step  The row being written.
	 * @param array<string, mixed> $entry The engine's log entry.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public static function stamp(FlowRunStep $step, array $entry): void {
		$step->setError(($entry['error'] ?? ($entry['reason'] ?? null)));
		$step->setOutput(
			array_filter(
				[
					'itemsIn' => ($entry['itemsIn'] ?? null),
					'itemsOut' => ($entry['itemsOut'] ?? null),
					'checkId' => ($entry['checkId'] ?? null),
				],
				static fn ($v): bool => $v !== null
			)
		);
	}//end stamp()
}//end class
