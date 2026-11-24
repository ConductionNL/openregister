<?php

/**
 * How many times a step has failed in a row on this run, kept on the resume state so a retry survives the suspension.
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
 * @spec openspec/changes/flow-error-branch-and-step-retry/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * The failed-attempt counter of a retrying step.
 *
 * A retry suspends the run (design D-1), so the count cannot live in memory:
 * it is written to the run's {@see FlowResumeState}, which the run service
 * persists for every live run and drops when the run ends. The slot is the
 * step's id under a `retry:` prefix, never the node's own slot, so a node
 * that reads its resume state (a wait, a signal) does not mistake a retry
 * for its own resumption.
 *
 * @spec openspec/changes/flow-error-branch-and-step-retry/specs/flow-engine/spec.md
 */
final class FlowStepAttempts {
	/**
	 * The prefix of a retry slot in the resume state.
	 */
	public const SLOT_PREFIX = 'retry:';

	/**
	 * The key the count is kept under in its slot.
	 */
	private const KEY = 'failedAttempts';

	/**
	 * How many attempts of the step have failed so far on this run.
	 *
	 * @param FlowResumeState $state The run's resume state.
	 * @param string          $step  The step (transition) id.
	 *
	 * @return int The failed attempts; 0 when the step has not failed yet.
	 *
	 * @spec openspec/changes/flow-error-branch-and-step-retry/specs/flow-engine/spec.md
	 */
	public static function failed(FlowResumeState $state, string $step): int {
		return (int)$state->forNode(nodeId: self::SLOT_PREFIX.$step)->get(key: self::KEY, default: 0);
	}//end failed()

	/**
	 * Record that the step has now failed this many times.
	 *
	 * @param FlowResumeState $state  The run's resume state.
	 * @param string          $step   The step (transition) id.
	 * @param int             $failed The failed attempts so far.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-error-branch-and-step-retry/specs/flow-engine/spec.md
	 */
	public static function record(FlowResumeState $state, string $step, int $failed): void {
		$state->forNode(nodeId: self::SLOT_PREFIX.$step)->set(key: self::KEY, value: $failed);
	}//end record()

	/**
	 * Forget the count once the step has succeeded or given up, so a later pass over it starts afresh.
	 *
	 * @param FlowResumeState $state The run's resume state.
	 * @param string          $step  The step (transition) id.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-error-branch-and-step-retry/specs/flow-engine/spec.md
	 */
	public static function clear(FlowResumeState $state, string $step): void {
		$state->forget(nodeId: self::SLOT_PREFIX.$step);
	}//end clear()
}//end class
