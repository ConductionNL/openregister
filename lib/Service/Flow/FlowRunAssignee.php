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
use OCA\OpenRegister\Service\Flow\Principal\PrincipalReference;
use OCA\OpenRegister\Service\Flow\Principal\PrincipalResolverRegistry;
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
	 * @param PrincipalResolverRegistry|null $principals Resolves a TYPED reference to the
	 *                                         users it currently means. Nullable for the
	 *                                         same reason and with the same direction:
	 *                                         absent, a typed assignment refuses.
	 *
	 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md
	 */
	public function __construct(
		private readonly ?IGroupManager $groupManager = null,
		private readonly ?PrincipalResolverRegistry $principals = null,
	) {
	}//end __construct()

	/**
	 * The assignee recorded by whichever step is currently awaiting an answer.
	 *
	 * Reads the per-node resume slots the node wrote. A run carries slots for
	 * several nodes across its life, so the one that matters is a slot that
	 * ASKED (`askedAt`) and has not been answered.
	 *
	 * WITH A NODE ID, the addressed node's own slot decides. A run can await
	 * several nodes at once, each with its own assignee, and the run-level scan
	 * answers with the FIRST asked slot's — which refuses the second node's own
	 * audience. A `nodeId` whose slot is NOT held falls through to the scan:
	 * naming a node that asked nothing must not become a way around the guard
	 * on the node that did.
	 *
	 * @param FlowRun $run The suspended run.
	 * @param string|null $nodeId The node the answer addresses, when the caller knows it.
	 *
	 * @return string The assignee uid or group id; '' when the step is unassigned.
	 *
	 * @spec openspec/changes/flow-engine-consumer-seams/specs/flow-engine-consumer-seams/spec.md#requirement-a-server-side-signal-passes-the-same-guard-as-the-http-resume
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) `PrincipalReference::from()` and
	 * `listFrom()` are NAMED CONSTRUCTORS on a value object, which is the
	 * canonical PHP idiom for one and is indistinguishable to this rule from a
	 * static call into a service. Injecting a reader for a value object would
	 * be worse design chosen by a linter: the type has no dependencies, no
	 * state and nothing to stub.
	 */
	public function recordedFor(FlowRun $run, ?string $nodeId = null): string {
		$value = $this->recordedValueFor(run: $run, nodeId: $nodeId);
		if (is_string($value) === true) {
			return trim($value);
		}

		// A typed reference rendered for a caller that wants one string. The
		// GUARD never takes this path — it reads the raw value — so a lossy
		// rendering here cannot widen or narrow who may answer.
		$references = PrincipalReference::listFrom(value: $value);

		if ($references === []) {
			return '';
		}

		return $references[0]->id;
	}//end recordedFor()

	/**
	 * The recorded assignee EXACTLY as the node wrote it.
	 *
	 * 🔴 THE SHAPE IS THE INFORMATION. A bare string and `{type, id}` mean
	 * different things — the first is the older, wider "uid or group", the
	 * second is exact — so flattening both to a string before the guard sees
	 * them is precisely what loses the distinction the typed reference exists
	 * to draw.
	 *
	 * @param FlowRun     $run    The suspended run.
	 * @param string|null $nodeId The node the answer addresses, when known.
	 *
	 * @return mixed The recorded value: a string, a reference, a list, or '' when unassigned.
	 *
	 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md
	 */
	public function recordedValueFor(FlowRun $run, ?string $nodeId = null): mixed {
		$context = ($run->getContext() ?? []);
		$slots = ($context[FlowResumeState::CONTEXT_KEY] ?? []);
		if (is_array($slots) === false) {
			return '';
		}

		$nodeId = trim((string)$nodeId);
		if ($nodeId !== '') {
			$slot = ($slots[$nodeId] ?? null);
			if (is_array($slot) === true && isset($slot['askedAt']) === true) {
				// The addressed node is asking; ITS record decides, including
				// an empty one — an unassigned step is deliberately open.
				return ($slot['assignee'] ?? '');
			}
		}

		foreach ($slots as $slot) {
			if (is_array($slot) === false) {
				continue;
			}

			if (isset($slot['askedAt']) === false) {
				continue;
			}

			$assignee = ($slot['assignee'] ?? '');
			if ($this->isUnassigned(value: $assignee) === false) {
				return $assignee;
			}
		}

		return '';
	}//end recordedValueFor()

	/**
	 * Whether this user may answer the step the run is waiting on.
	 *
	 * @param FlowRun     $run The suspended run.
	 * @param string|null $uid The acting user, or null when there is no session.
	 * @param string|null $nodeId The node the answer addresses, when the caller
	 *                            knows it — see {@see recordedFor()} for how
	 *                            addressing narrows and never loosens.
	 *
	 * @return boolean True when the answer may be accepted.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-run-suspended-on-an-external-signal-must-be-reachable
	 */
	public function mayAnswer(FlowRun $run, ?string $uid, ?string $nodeId = null): bool {
		$recorded = $this->recordedValueFor(run: $run, nodeId: $nodeId);

		// Unassigned is deliberately open — see the class docblock. This is the
		// one branch that must NOT be tightened without changing the spec.
		if ($this->isUnassigned(value: $recorded) === true) {
			return true;
		}

		// Fail CLOSED: an assigned decision is never anonymous.
		if ($uid === null || trim($uid) === '') {
			return false;
		}

		$actor = trim($uid);

		// 🔴 A BARE STRING KEEPS ITS OLD, WIDER MEANING: uid OR group. Today's
		// guard tries uid equality and then group membership, so a stored
		// `"bezwaar"` authorises the user AND the group's members — the union.
		// Reading it as a typed `user` would NARROW that, and would silently
		// stop authorising every group the fleet's stored flows name by bare
		// string. Which of the two an old string meant is a question for a
		// person, not for this guard: the repair reports the ambiguous ones and
		// rewrites only what resolves one way.
		if (is_string($recorded) === true) {
			return $this->matchesLegacyString(actor: $actor, assignee: trim($recorded));
		}

		// A TYPED reference means exactly what it says, and is resolved fresh
		// on every answer: whoever holds the post today may answer, and whoever
		// held it in March may not.
		return $this->matchesTypedReference(actor: $actor, recorded: $recorded);
	}//end mayAnswer()

	/**
	 * Whether nothing was recorded, in either spelling.
	 *
	 * @param mixed $value The recorded value.
	 *
	 * @return boolean True when the step is unassigned.
	 *
	 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) `PrincipalReference::from()` and
	 * `listFrom()` are NAMED CONSTRUCTORS on a value object, which is the
	 * canonical PHP idiom for one and is indistinguishable to this rule from a
	 * static call into a service. Injecting a reader for a value object would
	 * be worse design chosen by a linter: the type has no dependencies, no
	 * state and nothing to stub.
	 */
	private function isUnassigned(mixed $value): bool {
		if (is_string($value) === true) {
			return trim($value) === '';
		}

		return PrincipalReference::listFrom(value: $value) === [];
	}//end isUnassigned()

	/**
	 * The pre-typed reading: uid equality, then group membership.
	 *
	 * Kept exactly as it was, deliberately. Every stored flow on every instance
	 * was authored against these semantics.
	 *
	 * @param string $actor    The acting uid.
	 * @param string $assignee The recorded string.
	 *
	 * @return boolean Whether the actor may answer.
	 *
	 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md
	 */
	private function matchesLegacyString(string $actor, string $assignee): bool {
		if ($actor === $assignee) {
			return true;
		}

		// Absent a group manager this refuses rather than admits, which is the
		// safe direction — and is why its absence must be visible in tests
		// rather than inferred from a passing suite.
		return ($this->groupManager !== null && $this->groupManager->isInGroup($actor, $assignee) === true);
	}//end matchesLegacyString()

	/**
	 * The typed reading: whoever the reference means, right now.
	 *
	 * @param string $actor    The acting uid.
	 * @param mixed  $recorded The recorded reference or list of them.
	 *
	 * @return boolean Whether the actor may answer.
	 *
	 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) `PrincipalReference::from()` and
	 * `listFrom()` are NAMED CONSTRUCTORS on a value object, which is the
	 * canonical PHP idiom for one and is indistinguishable to this rule from a
	 * static call into a service. Injecting a reader for a value object would
	 * be worse design chosen by a linter: the type has no dependencies, no
	 * state and nothing to stub.
	 */
	private function matchesTypedReference(string $actor, mixed $recorded): bool {
		if ($this->principals === null) {
			// Fail closed, like the missing group manager: a typed assignment
			// nothing can resolve refuses rather than admits.
			return false;
		}

		$references = PrincipalReference::listFrom(value: $recorded);

		return in_array($actor, $this->principals->resolveAll(references: $references), true);
	}//end matchesTypedReference()
}//end class
