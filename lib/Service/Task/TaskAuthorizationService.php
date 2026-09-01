<?php

/**
 * Per-verb, fail-closed authorization for the task lifecycle.
 *
 * Every decision runs BEFORE any mutation, and every path that cannot
 * DETERMINE the answer DENIES: an unresolvable role, an absent group
 * backend, an unknown performer type — each refuses by throwing, never by
 * returning a nullable "service unavailable" a caller could read as "check
 * skipped" (the decidesk fail-open shape, and the hole measured at
 * `lib/Controller/FlowRunController.php:423-436` where knowing a run uuid
 * was the whole check).
 *
 * The fence from design D-1 applies here too: every branch in this class is
 * about lifecycle, identity or membership. No branch may encode what any
 * specific app's task MEANS.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Task
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Exception\TaskAccessDeniedException;
use OCP\IGroupManager;
use Throwable;

/**
 * Decides who may run which lifecycle verb on which task.
 *
 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
 */
class TaskAuthorizationService {

	/**
	 * Which relationship each verb requires of the caller.
	 *
	 * The spec's minimums, as data: claim needs pool membership; unclaim,
	 * delegate, complete, resolve and checklist need the assignee; assign,
	 * reassign and cancel need the requester (an administrator passes all).
	 *
	 * @var array<string, string> verb => private rule method.
	 */
	private const RULES = [
		'claim' => 'assertPoolMember',
		'unclaim' => 'assertAssignee',
		'delegate' => 'assertAssignee',
		'complete' => 'assertAssignee',
		'resolve' => 'assertAssignee',
		'checklist' => 'assertAssignee',
		'assign' => 'assertRequester',
		'reassign' => 'assertRequester',
		'cancel' => 'assertRequester',
		// Offer rewrites the pool AND the routing fallback, which decides who
		// ends up assigned: that is the requester's call, nobody else's.
		'offer' => 'assertRequester',
	];

	/**
	 * Verbs no caller may run on an external task (flow-portal-task).
	 *
	 * @var array<int, string>
	 */
	private const REFUSED_FOR_EXTERNAL = ['claim', 'unclaim', 'delegate', 'offer', 'assign', 'reassign'];

	/**
	 * Verbs that ANSWER a task, admitted on an external task to the matched
	 * party alone.
	 *
	 * @var array<int, string>
	 */
	private const ANSWERING_VERBS = ['complete', 'resolve', 'checklist'];

	/**
	 * Constructor.
	 *
	 * @param IGroupManager|null $groupManager Resolves group membership, role
	 *                                         groups and administrators.
	 *                                         Nullable so the service stays
	 *                                         constructible without a
	 *                                         container; ABSENT, every
	 *                                         membership-dependent decision
	 *                                         DENIES — the fail-closed
	 *                                         direction, same as
	 *                                         {@see \OCA\OpenRegister\Service\Flow\FlowRunAssignee}.
	 */
	public function __construct(
		private readonly ?IGroupManager $groupManager = null,
	) {

	}//end __construct()

	/**
	 * Assert that a caller may run a verb on a task; throw when not.
	 *
	 * The service deliberately has no boolean twin of this method: a caller
	 * that could ask without consequence could also forget to act on the
	 * answer. Denial is an exception, and the message carries the reason the
	 * audit records.
	 *
	 * @param string $verb One of the lifecycle verbs.
	 * @param Task $task The task acted on.
	 * @param string|null $uid The acting identity, or null when there is none.
	 *
	 * @return void
	 *
	 * @throws TaskAccessDeniedException When denied, or undeterminable.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	public function assertMay(string $verb, Task $task, ?string $uid): void {
		// No verb is anonymous, and no verb is reachable by uuid alone.
		if ($uid === null || trim($uid) === '') {
			throw new TaskAccessDeniedException(
				message: sprintf("Verb '%s' denied: no acting identity.", $verb)
			);
		}

		// An unknown performer type is UNDETERMINABLE, which is a denial.
		// PERFORMER_TYPES is the extensible vocabulary: adding `external`
		// there admits it everywhere at once.
		$performerType = (string)$task->getPerformerType();
		if (in_array($performerType, Task::PERFORMER_TYPES, true) === false) {
			throw new TaskAccessDeniedException(
				message: sprintf("Verb '%s' denied: performer type '%s' is unknown, so authorization cannot be determined.", $verb, $performerType)
			);
		}

		// An EXTERNAL task is decided by its own rule set, BEFORE the
		// administrator bypass: the matched party is the only identity that
		// may answer, and "an administrator acting through the seam" is one
		// of the callers the spec names as denied.
		if ($performerType === Task::PERFORMER_EXTERNAL) {
			$this->assertExternal(verb: $verb, task: $task, uid: $uid);
			return;
		}

		if ($this->isAdmin(uid: $uid) === true) {
			return;
		}

		// The one verb carrying no per-task privilege beyond an identity:
		// there is no task yet to have a relationship with.
		if ($verb === 'create') {
			return;
		}

		// An unknown verb has no rule, so it has no permission.
		if (array_key_exists($verb, self::RULES) === false) {
			throw new TaskAccessDeniedException(
				message: sprintf("Verb '%s' denied: no authorization rule exists for it.", $verb)
			);
		}

		if (self::RULES[$verb] === 'assertPoolMember') {
			$this->assertPoolMember(verb: $verb, task: $task, uid: $uid);
			return;
		}

		if (self::RULES[$verb] === 'assertAssignee') {
			$this->assertAssignee(verb: $verb, task: $task, uid: $uid);
			return;
		}

		$this->assertRequester(verb: $verb, task: $task, uid: $uid);
	}//end assertMay()

	/**
	 * Whether a caller may READ a task.
	 *
	 * The same five relationships the inbox WHERE clause admits: assignee,
	 * candidate-pool member, requester, watcher, administrator. Watchers get
	 * exactly this — read visibility — and no lifecycle right whatsoever.
	 * Boolean rather than asserting because reads legitimately branch
	 * (404 vs body), while VERBS must not be able to ignore a denial.
	 *
	 * @param Task $task The task read.
	 * @param string|null $uid The reading identity.
	 *
	 * @return boolean True when visible; false otherwise, including every
	 *                 undeterminable case (fail closed).
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-the-inbox-answers-what-is-waiting-for-me-in-one-query
	 */
	public function mayRead(Task $task, ?string $uid): bool {
		if ($uid === null || trim($uid) === '') {
			return false;
		}

		if ($this->isAdmin(uid: $uid) === true) {
			return true;
		}

		if (trim((string)$task->getAssignee()) === $uid || trim((string)$task->getRequester()) === $uid) {
			return true;
		}

		if (in_array($uid, ($task->getWatchers() ?? []), true) === true) {
			return true;
		}

		try {
			$this->assertPoolMember(verb: 'read', task: $task, uid: $uid);

			return true;
		} catch (TaskAccessDeniedException) {
			return false;
		}
	}//end mayRead()

	/**
	 * Whether a uid is an administrator, for callers that must branch on it.
	 *
	 * Fail-closed like everything here: no backend, no admin.
	 *
	 * @param string|null $uid The acting identity.
	 *
	 * @return boolean True only when the group backend affirms it.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	public function isAdministrator(?string $uid): bool {
		if ($uid === null || trim($uid) === '') {
			return false;
		}

		return $this->isAdmin(uid: $uid);
	}//end isAdministrator()

	/**
	 * Whether a uid is an administrator. Fail-closed: no backend, no admin.
	 *
	 * @param string $uid The acting identity.
	 *
	 * @return boolean True only when the group backend affirms it.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	private function isAdmin(string $uid): bool {
		if ($this->groupManager === null) {
			return false;
		}

		try {
			return $this->groupManager->isAdmin($uid);
		} catch (Throwable) {
			// An unavailable backend cannot GRANT anything.
			return false;
		}
	}//end isAdmin()

	/**
	 * The rule set of an external (portal party) task.
	 *
	 * Three groups of verbs, decided in this order. The pooling and mandate
	 * verbs (`claim`, `unclaim`, `delegate`, and the assignment verbs
	 * `offer`, `assign`, `reassign` that would move the frozen match) are
	 * REFUSED for everyone, naming the performer type: there is no candidate
	 * pool to claim from and no mandate model for a party outside the
	 * instance, and the frozen match is corrected by cancel or re-ask, never
	 * by moving the reference (design D-3). The answering verbs (`complete`,
	 * `resolve`, `checklist`) admit exactly ONE identity: the stored party
	 * reference, compared as a whole, with no administrator bypass and no
	 * on-behalf path. The requester's verb (`cancel`) keeps its ordinary
	 * rule, administrator included, because withdrawing an ask is the
	 * caseworker's act, not an answer.
	 *
	 * @param string $verb The verb being attempted.
	 * @param Task $task The external task.
	 * @param string $uid The acting identity (a party reference, or a uid).
	 *
	 * @return void
	 *
	 * @throws TaskAccessDeniedException When denied, or undeterminable.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-tasks/spec.md#requirement-the-external-performer-type-is-portal-scoped-and-never-pooled
	 */
	private function assertExternal(string $verb, Task $task, string $uid): void {
		if ($verb === 'create') {
			return;
		}

		if (in_array($verb, self::REFUSED_FOR_EXTERNAL, true) === true) {
			throw new TaskAccessDeniedException(
				message: sprintf(
					"Verb '%s' refused: performer type '%s' has no candidate pool and no mandate model; cancel or re-ask instead.",
					$verb,
					Task::PERFORMER_EXTERNAL
				)
			);
		}

		if (in_array($verb, self::ANSWERING_VERBS, true) === true) {
			$party = trim((string)$task->getAssignee());
			// Fail closed on every undeterminable shape: no stored reference,
			// a reference that is not a party reference, or a caller that is
			// not one. Only a whole-string match of two party references admits.
			if ($party === ''
				|| str_starts_with($party, Task::EXTERNAL_PARTY_PREFIX) === false
				|| str_starts_with($uid, Task::EXTERNAL_PARTY_PREFIX) === false
				|| hash_equals($party, $uid) === false
			) {
				throw new TaskAccessDeniedException(
					message: sprintf("Verb '%s' denied: only the matched portal subject may answer an external task.", $verb)
				);
			}

			return;
		}

		if ($verb === 'cancel') {
			if ($this->isAdmin(uid: $uid) === true) {
				return;
			}

			$this->assertRequester(verb: $verb, task: $task, uid: $uid);
			return;
		}

		throw new TaskAccessDeniedException(
			message: sprintf("Verb '%s' denied: no authorization rule exists for it on performer type '%s'.", $verb, Task::PERFORMER_EXTERNAL)
		);
	}//end assertExternal()

	/**
	 * The caller must be the task's current assignee.
	 *
	 * `on_behalf_of` does not loosen this: delegation REASSIGNS the task to
	 * the delegate (recording both identities), so the delegate then IS the
	 * assignee. There is no path where a non-assignee completes.
	 *
	 * @param string $verb The verb, for the denial message.
	 * @param Task $task The task acted on.
	 * @param string $uid The acting identity.
	 *
	 * @return void
	 *
	 * @throws TaskAccessDeniedException When the caller is not the assignee.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	private function assertAssignee(string $verb, Task $task, string $uid): void {
		$assignee = trim((string)$task->getAssignee());
		if ($assignee === '' || $assignee !== $uid) {
			throw new TaskAccessDeniedException(
				message: sprintf("Verb '%s' denied: only the current assignee may perform it.", $verb)
			);
		}
	}//end assertAssignee()

	/**
	 * The caller must be the task's requester.
	 *
	 * @param string $verb The verb, for the denial message.
	 * @param Task $task The task acted on.
	 * @param string $uid The acting identity.
	 *
	 * @return void
	 *
	 * @throws TaskAccessDeniedException When the caller is not the requester.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	private function assertRequester(string $verb, Task $task, string $uid): void {
		$requester = trim((string)$task->getRequester());
		if ($requester === '' || $requester !== $uid) {
			throw new TaskAccessDeniedException(
				message: sprintf("Verb '%s' denied: only the requester or an administrator may perform it.", $verb)
			);
		}
	}//end assertRequester()

	/**
	 * The caller must be in the task's candidate pool.
	 *
	 * Membership means: named in `candidate_users`; in one of
	 * `candidate_groups`; or in the group named by `candidate_role`.
	 * Every branch that needs the group backend DENIES without it, and a
	 * role naming a group that does not exist denies NAMING THE ROLE —
	 * never "no check applicable".
	 *
	 * @param string $verb The verb, for the denial message.
	 * @param Task $task The task acted on.
	 * @param string $uid The acting identity.
	 *
	 * @return void
	 *
	 * @throws TaskAccessDeniedException When not a member, or undeterminable.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	private function assertPoolMember(string $verb, Task $task, string $uid): void {
		$users = ($task->getCandidateUsers() ?? []);
		if (in_array($uid, $users, true) === true) {
			return;
		}

		$groups = ($task->getCandidateGroups() ?? []);
		foreach ($groups as $groupId) {
			if ($this->isInGroup(uid: $uid, groupId: (string)$groupId) === true) {
				return;
			}
		}

		$role = trim((string)$task->getCandidateRole());
		if ($role !== '') {
			$this->assertRoleResolvable(verb: $verb, role: $role);
			if ($this->isInGroup(uid: $uid, groupId: $role) === true) {
				return;
			}
		}

		throw new TaskAccessDeniedException(
			message: sprintf("Verb '%s' denied: the caller is not in the task's candidate pool.", $verb)
		);
	}//end assertPoolMember()

	/**
	 * Membership through the group backend, denying when it is absent.
	 *
	 * @param string $uid The acting identity.
	 * @param string $groupId The group to test.
	 *
	 * @return boolean True only when the backend affirms membership.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	private function isInGroup(string $uid, string $groupId): bool {
		if ($this->groupManager === null || $groupId === '') {
			return false;
		}

		try {
			return $this->groupManager->isInGroup($uid, $groupId);
		} catch (Throwable) {
			return false;
		}
	}//end isInGroup()

	/**
	 * A role must RESOLVE — to the group of the same name — or the decision
	 * is undeterminable and denies naming the role.
	 *
	 * @param string $verb The verb, for the denial message.
	 * @param string $role The role name.
	 *
	 * @return void
	 *
	 * @throws TaskAccessDeniedException When the role cannot be resolved.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	private function assertRoleResolvable(string $verb, string $role): void {
		if ($this->groupManager === null) {
			throw new TaskAccessDeniedException(
				message: sprintf("Verb '%s' denied: role '%s' cannot be resolved because no group backend is available.", $verb, $role)
			);
		}

		$exists = false;
		try {
			$exists = $this->groupManager->groupExists($role);
		} catch (Throwable) {
			$exists = false;
		}

		if ($exists === false) {
			throw new TaskAccessDeniedException(
				message: sprintf("Verb '%s' denied: role '%s' does not resolve to any group.", $verb, $role)
			);
		}
	}//end assertRoleResolvable()
}//end class
