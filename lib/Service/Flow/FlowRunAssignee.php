<?php

/**
 * Whose decision a suspended run is waiting on, and whether a given user is it.
 *
 * WHY THIS IS A SERVICE AND NOT A CONTROLLER METHOD. It began as one, guarding
 * the HTTP resume endpoint, and that was enough while HTTP was the only way to
 * answer a step. It is not: a leaf app whose own object completes a task resumes
 * the run IN-PROCESS, through `FlowRunService::signal()`, which never passes the
 * controller. Left where it was, every such caller would have to re-implement
 * the rule.
 *
 * 🔴 AND RE-IMPLEMENTING IT IS THE FAILURE MODE. Two copies of one access rule
 * do not stay identical, and the copy that drifts is the one nobody looks at. A
 * divergence here does not throw — it lets the wrong person answer somebody
 * else's question, correctly formatted, HTTP 200. Notably, the GROUP branch is
 * the half a hand-written copy tends to forget, and forgetting it refuses the
 * step's own intended audience while still reading as "the guard works",
 * because refusing is what a guard does.
 *
 * SCOPE, STATED HONESTLY. This answers the WHO of an already-recorded
 * assignment. It is not ADR-098's task entity, inbox or definition versioning.
 * A step that records NO assignee is deliberately answerable by anyone —
 * tightening that would break webhook and child-run signals, which are not
 * human decisions at all — and callers must treat "unassigned" as permitted
 * rather than as a missing check.
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
 * @spec openspec/specs/flow-engine/spec.md#requirement-a-run-suspended-on-an-external-signal-must-be-reachable
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Db\FlowRun;
use OCP\IGroupManager;

/**
 * Reads a suspended run's recorded assignee and decides who may answer it.
 *
 * @spec openspec/specs/flow-engine/spec.md#requirement-a-run-suspended-on-an-external-signal-must-be-reachable
 */
class FlowRunAssignee {
	/**
	 * Constructor.
	 *
	 * @param IGroupManager|null $groupManager Resolves group membership. Nullable so the
	 *                                         service stays constructible without a
	 *                                         container; absent, a group assignment
	 *                                         REFUSES rather than admits — the
	 *                                         fail-closed direction.
	 */
	public function __construct(
		private readonly ?IGroupManager $groupManager = null,
	) {
	}//end __construct()

	/**
	 * The assignee recorded by whichever step is currently awaiting an answer.
	 *
	 * Reads the per-node resume slots the node wrote. A run carries slots for
	 * several nodes across its life, so the one that matters is a slot that
	 * ASKED (`askedAt`) and has not been answered.
	 *
	 * @param FlowRun $run The suspended run.
	 *
	 * @return string The assignee uid or group id; '' when the step is unassigned.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-run-suspended-on-an-external-signal-must-be-reachable
	 */
	public function recordedFor(FlowRun $run): string {
		$context = ($run->getContext() ?? []);
		$slots = ($context[FlowResumeState::CONTEXT_KEY] ?? []);
		if (is_array($slots) === false) {
			return '';
		}

		foreach ($slots as $slot) {
			if (is_array($slot) === false) {
				continue;
			}

			if (isset($slot['askedAt']) === false) {
				continue;
			}

			$assignee = trim((string)($slot['assignee'] ?? ''));
			if ($assignee !== '') {
				return $assignee;
			}
		}

		return '';
	}//end recordedFor()

	/**
	 * Whether this user may answer the step the run is waiting on.
	 *
	 * @param FlowRun     $run The suspended run.
	 * @param string|null $uid The acting user, or null when there is no session.
	 *
	 * @return boolean True when the answer may be accepted.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-run-suspended-on-an-external-signal-must-be-reachable
	 */
	public function mayAnswer(FlowRun $run, ?string $uid): bool {
		$assignee = $this->recordedFor(run: $run);

		// Unassigned is deliberately open — see the class docblock. This is the
		// one branch that must NOT be tightened without changing the spec.
		if ($assignee === '') {
			return true;
		}

		// Fail CLOSED: an assigned decision is never anonymous.
		if ($uid === null || trim($uid) === '') {
			return false;
		}

		if ($uid === $assignee) {
			return true;
		}

		// The group branch. Absent a group manager this refuses rather than
		// admits, which is the safe direction — and is why its absence must be
		// visible in tests rather than inferred from a passing suite.
		if ($this->groupManager !== null && $this->groupManager->isInGroup($uid, $assignee) === true) {
			return true;
		}

		return false;
	}//end mayAnswer()
}//end class
