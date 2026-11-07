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
 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Exception\TaskAccessDeniedException;
use OCA\OpenRegister\Kernel\Contracts\TaskInterface;
use OCA\OpenRegister\Service\Task\Authorization\FailClosedGroups;
use OCA\OpenRegister\Service\Task\Authorization\TaskVerbAttempt;
use OCA\OpenRegister\Service\Flow\Principal\PrincipalReference;
use OCA\OpenRegister\Service\Flow\Principal\PrincipalResolverRegistry;
use OCP\IGroupManager;

/**
 * Decides who may run which lifecycle verb on which task.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) One small rule method per
 * relationship the spec names, plus the external rule set (flow-portal-task),
 * which lives HERE deliberately: a second authorization service would be two
 * places fail-closed has to hold instead of one.
 *
 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
 */
class TaskAuthorizationService {

	/**
	 * Which relationship each verb requires of the caller.
	 *
	 * The spec's minimums, as data: claim needs pool membership; unclaim,
	 * delegate, complete, resolve and checklist need the assignee; edit needs
	 * the assignee or pool membership; assign, reassign and cancel need the
	 * requester (an administrator passes all).
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
		// Editing the title, description, due date or priority belongs to
		// whoever works the task: the assignee or a member of its pool.
		'edit' => 'assertAssigneeOrPoolMember',
	];

	/**
	 * Verbs no caller may run on an external task (flow-portal-task).
	 *
	 * @var array<int, string>
	 */
	private const REFUSED_FOR_EXTERNAL = ['claim', 'unclaim', 'delegate', 'offer', 'assign', 'reassign', 'edit'];

	/**
	 * Verbs that ANSWER a task, admitted on an external task to the matched
	 * party alone.
	 *
	 * @var array<int, string>
	 */
	private const ANSWERING_VERBS = ['complete', 'resolve', 'checklist'];


	/**
	 * The group backend, read fail-closed.
	 *
	 * @var FailClosedGroups
	 */
	private readonly FailClosedGroups $groups;

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
	 * @param PrincipalResolverRegistry|null $principals Resolves a TYPED candidate
	 *                             reference to whoever it means right now, so this
	 *                             guard and the run guard cannot disagree. Absent,
	 *                             a typed candidate is not affirmed.
	 *
	 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md
	 */
	public function __construct(
		?IGroupManager $groupManager = null,
		private readonly ?PrincipalResolverRegistry $principals = null,
	) {
		$this->groups = new FailClosedGroups(groupManager: $groupManager);

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
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	public function assertMay(string $verb, Task $task, ?string $uid): void {
		$attempt = new TaskVerbAttempt(verb: $verb, task: $task, uid: $this->identityOf(verb: $verb, uid: $uid));
		$this->assertKnownPerformer(attempt: $attempt);

		// An EXTERNAL task is decided by its own rule set, BEFORE the
		// administrator bypass: the matched party is the only identity that
		// may answer, and "an administrator acting through the seam" is one
		// of the callers the spec names as denied.
		if ((string)$task->getPerformerType() === Task::PERFORMER_EXTERNAL) {
			$this->assertExternal(attempt: $attempt);
			return;
		}

		// `create` is the one verb carrying no per-task privilege beyond an
		// identity: there is no task yet to have a relationship with.
		if ($this->groups->isAdmin(uid: $attempt->uid) === true || $verb === 'create') {
			return;
		}

		$this->assertRule(attempt: $attempt);
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
	 * @param TaskInterface $task The task read.
	 * @param string|null $uid The reading identity.
	 *
	 * @return boolean True when visible; false otherwise, including every
	 *                 undeterminable case (fail closed).
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-the-inbox-answers-what-is-waiting-for-me-in-one-query
	 */
	public function mayRead(TaskInterface $task, ?string $uid): bool {
		if ($uid === null || trim($uid) === '') {
			return false;
		}

		return $this->readsAs(task: $task, uid: $uid);
	}//end mayRead()

	/**
	 * Whether an identified reader may read: administrator, assignee,
	 * requester, watcher, or candidate-pool member, asked in that order.
	 *
	 * @param TaskInterface $task The task read.
	 * @param string        $uid  The non-empty reading identity.
	 *
	 * @return boolean True when one relationship admits the reader.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-the-inbox-answers-what-is-waiting-for-me-in-one-query
	 */
	private function readsAs(TaskInterface $task, string $uid): bool {
		if ($this->groups->isAdmin(uid: $uid) === true || $this->isRelated(task: $task, uid: $uid) === true) {
			return true;
		}

		return $this->isPoolMember(attempt: new TaskVerbAttempt(verb: 'read', task: $task, uid: $uid));
	}//end readsAs()

	/**
	 * Whether a uid is an administrator, for callers that must branch on it.
	 *
	 * Fail-closed like everything here: no backend, no admin.
	 *
	 * @param string|null $uid The acting identity.
	 *
	 * @return boolean True only when the group backend affirms it.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	public function isAdministrator(?string $uid): bool {
		return $uid !== null && trim($uid) !== '' && $this->groups->isAdmin(uid: $uid) === true;
	}//end isAdministrator()

	/**
	 * Whether the reader is the assignee, the requester or a watcher.
	 *
	 * @param TaskInterface $task The task read.
	 * @param string        $uid  The non-empty reading identity.
	 *
	 * @return boolean True when one of the three relationships holds.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-the-inbox-answers-what-is-waiting-for-me-in-one-query
	 */
	private function isRelated(TaskInterface $task, string $uid): bool {
		return trim((string)$task->getAssignee()) === $uid
			|| trim((string)$task->getRequester()) === $uid
			|| in_array($uid, ($task->getWatchers() ?? []), true) === true;
	}//end isRelated()

	/**
	 * Whether the attempt's caller is in the candidate pool; any denial,
	 * including an unresolvable role, answers false.
	 *
	 * @param TaskVerbAttempt $attempt The attempt.
	 *
	 * @return boolean True when the pool admits the caller.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-the-inbox-answers-what-is-waiting-for-me-in-one-query
	 */
	private function isPoolMember(TaskVerbAttempt $attempt): bool {
		try {
			$this->assertPoolMember(attempt: $attempt);

			return true;
		} catch (TaskAccessDeniedException) {
			return false;
		}
	}//end isPoolMember()

	/**
	 * The first check every verb makes: an acting identity exists. No verb
	 * is anonymous, and no verb is reachable by uuid alone.
	 *
	 * @param string      $verb The verb being attempted.
	 * @param string|null $uid  The acting identity, or null when there is none.
	 *
	 * @return string The non-empty acting identity (as given, not trimmed).
	 *
	 * @throws TaskAccessDeniedException When anonymous.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	private function identityOf(string $verb, ?string $uid): string {
		if ($uid === null || trim($uid) === '') {
			throw new TaskAccessDeniedException(
				message: sprintf("Verb '%s' denied: no acting identity.", $verb)
			);
		}

		return $uid;
	}//end identityOf()

	/**
	 * The second check every verb makes: the performer type is in the
	 * vocabulary. Anything else is UNDETERMINABLE, which is a denial —
	 * adding a type to PERFORMER_TYPES is what admits it everywhere at once.
	 *
	 * @param TaskVerbAttempt $attempt The attempt.
	 *
	 * @return void
	 *
	 * @throws TaskAccessDeniedException When the performer type is unknown.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	private function assertKnownPerformer(TaskVerbAttempt $attempt): void {
		$performerType = (string)$attempt->task->getPerformerType();
		if (in_array($performerType, Task::PERFORMER_TYPES, true) === false) {
			throw new TaskAccessDeniedException(
				message: sprintf(
					"Verb '%s' denied: performer type '%s' is unknown, so authorization cannot be determined.",
					$attempt->verb,
					$performerType
				)
			);
		}
	}//end assertKnownPerformer()

	/**
	 * Apply the verb's rule from RULES; an unknown verb has no rule, so it
	 * has no permission.
	 *
	 * @param TaskVerbAttempt $attempt The attempt.
	 *
	 * @return void
	 *
	 * @throws TaskAccessDeniedException When denied.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	private function assertRule(TaskVerbAttempt $attempt): void {
		if (array_key_exists($attempt->verb, self::RULES) === false) {
			throw new TaskAccessDeniedException(message: sprintf("Verb '%s' denied: no authorization rule exists for it.", $attempt->verb));
		}

		$rule = self::RULES[$attempt->verb];
		if ($rule === 'assertPoolMember') {
			$this->assertPoolMember(attempt: $attempt);
			return;
		}

		if ($rule === 'assertAssignee') {
			$this->assertAssignee(attempt: $attempt);
			return;
		}

		$this->assertRequesterRule(attempt: $attempt, rule: $rule);
	}//end assertRule()

	/**
	 * The two remaining rules: assignee-or-pool (edit) and requester.
	 *
	 * @param TaskVerbAttempt $attempt The attempt.
	 * @param string          $rule    The rule RULES names for the verb.
	 *
	 * @return void
	 *
	 * @throws TaskAccessDeniedException When denied.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	private function assertRequesterRule(TaskVerbAttempt $attempt, string $rule): void {
		if ($rule !== 'assertAssigneeOrPoolMember') {
			$this->assertRequester(attempt: $attempt);
			return;
		}

		if (trim((string)$attempt->task->getAssignee()) === $attempt->uid) {
			return;
		}

		$this->assertPoolMember(attempt: $attempt);
	}//end assertRequesterRule()

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
	 * @param TaskVerbAttempt $attempt The attempt on an external task.
	 *
	 * @return void
	 *
	 * @throws TaskAccessDeniedException When denied, or undeterminable.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-tasks/spec.md#requirement-the-external-performer-type-is-portal-scoped-and-never-pooled
	 */
	private function assertExternal(TaskVerbAttempt $attempt): void {
		if ($attempt->verb === 'create') {
			return;
		}

		$this->refuseExternalPooling(attempt: $attempt);

		if (in_array($attempt->verb, self::ANSWERING_VERBS, true) === true) {
			$this->assertMatchedParty(attempt: $attempt);
			return;
		}

		$this->assertExternalCancel(attempt: $attempt);
	}//end assertExternal()

	/**
	 * Refuse the pooling, mandate and assignment verbs on an external task.
	 *
	 * @param TaskVerbAttempt $attempt The attempt on an external task.
	 *
	 * @return void
	 *
	 * @throws TaskAccessDeniedException When the verb is one of REFUSED_FOR_EXTERNAL.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-tasks/spec.md#requirement-the-external-performer-type-is-portal-scoped-and-never-pooled
	 */
	private function refuseExternalPooling(TaskVerbAttempt $attempt): void {
		if (in_array($attempt->verb, self::REFUSED_FOR_EXTERNAL, true) === true) {
			throw new TaskAccessDeniedException(
				message: sprintf(
					"Verb '%s' refused: performer type '%s' has no candidate pool and no mandate model; cancel or re-ask instead.",
					$attempt->verb,
					Task::PERFORMER_EXTERNAL
				)
			);
		}
	}//end refuseExternalPooling()

	/**
	 * `cancel` on an external task: administrator or requester. Any other
	 * verb has no rule on this performer type.
	 *
	 * @param TaskVerbAttempt $attempt The attempt on an external task.
	 *
	 * @return void
	 *
	 * @throws TaskAccessDeniedException When denied.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-tasks/spec.md#requirement-the-external-performer-type-is-portal-scoped-and-never-pooled
	 */
	private function assertExternalCancel(TaskVerbAttempt $attempt): void {
		if ($attempt->verb !== 'cancel') {
			throw new TaskAccessDeniedException(
				message: sprintf(
					"Verb '%s' denied: no authorization rule exists for it on performer type '%s'.",
					$attempt->verb,
					Task::PERFORMER_EXTERNAL
				)
			);
		}

		if ($this->groups->isAdmin(uid: $attempt->uid) === true) {
			return;
		}

		$this->assertRequester(attempt: $attempt);
	}//end assertExternalCancel()

	/**
	 * The caller must BE the stored party reference, as a whole.
	 *
	 * Fail closed on every undeterminable shape: no stored reference, a
	 * reference that is not a party reference, or a caller that is not one.
	 * Only a whole-string match of two party references admits; there is no
	 * administrator bypass and no on-behalf path.
	 *
	 * @param TaskVerbAttempt $attempt The attempt on an external task.
	 *
	 * @return void
	 *
	 * @throws TaskAccessDeniedException When the caller is not the matched party.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-only-the-matched-party-completes-fail-closed
	 */
	private function assertMatchedParty(TaskVerbAttempt $attempt): void {
		$party = trim((string)$attempt->task->getAssignee());
		if ($party === ''
			|| str_starts_with($party, Task::EXTERNAL_PARTY_PREFIX) === false
			|| str_starts_with($attempt->uid, Task::EXTERNAL_PARTY_PREFIX) === false
			|| hash_equals($party, $attempt->uid) === false
		) {
			throw new TaskAccessDeniedException(
				message: sprintf("Verb '%s' denied: only the matched portal subject may answer an external task.", $attempt->verb)
			);
		}
	}//end assertMatchedParty()

	/**
	 * The caller must be the task's current assignee.
	 *
	 * `on_behalf_of` does not loosen this: delegation REASSIGNS the task to
	 * the delegate (recording both identities), so the delegate then IS the
	 * assignee. There is no path where a non-assignee completes.
	 *
	 * @param TaskVerbAttempt $attempt The attempt.
	 *
	 * @return void
	 *
	 * @throws TaskAccessDeniedException When the caller is not the assignee.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	private function assertAssignee(TaskVerbAttempt $attempt): void {
		$assignee = trim((string)$attempt->task->getAssignee());
		if ($assignee === '' || $assignee !== $attempt->uid) {
			throw new TaskAccessDeniedException(
				message: sprintf("Verb '%s' denied: only the current assignee may perform it.", $attempt->verb)
			);
		}
	}//end assertAssignee()

	/**
	 * The caller must be the task's requester.
	 *
	 * @param TaskVerbAttempt $attempt The attempt.
	 *
	 * @return void
	 *
	 * @throws TaskAccessDeniedException When the caller is not the requester.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	private function assertRequester(TaskVerbAttempt $attempt): void {
		$requester = trim((string)$attempt->task->getRequester());
		if ($requester === '' || $requester !== $attempt->uid) {
			throw new TaskAccessDeniedException(
				message: sprintf("Verb '%s' denied: only the requester or an administrator may perform it.", $attempt->verb)
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
	 * 🔑 THE SAME PATH THE RUN GUARD TAKES runs last: a candidate entry that
	 * names its own type — a position, a function, a case role — is resolved
	 * by whichever app owns that concept, exactly as `FlowRunAssignee` does
	 * it. Two guards over one question is how they come to disagree. It runs
	 * AFTER the three legacy branches so nothing they already admit can be
	 * narrowed by it.
	 *
	 * @param TaskVerbAttempt $attempt The attempt.
	 *
	 * @return void
	 *
	 * @throws TaskAccessDeniedException When not a member, or undeterminable.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	private function assertPoolMember(TaskVerbAttempt $attempt): void {
		if ($this->isNamedOrInGroup(attempt: $attempt) === true
			|| $this->isRoleMember(attempt: $attempt) === true
			|| $this->matchesTypedCandidate(attempt: $attempt) === true
		) {
			return;
		}

		throw new TaskAccessDeniedException(
			message: sprintf("Verb '%s' denied: the caller is not in the task's candidate pool.", $attempt->verb)
		);
	}//end assertPoolMember()

	/**
	 * Whether the caller is named in `candidate_users` or in one of `candidate_groups`.
	 *
	 * @param TaskVerbAttempt $attempt The attempt.
	 *
	 * @return boolean True when named or a member.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	private function isNamedOrInGroup(TaskVerbAttempt $attempt): bool {
		if (in_array($attempt->uid, ($attempt->task->getCandidateUsers() ?? []), true) === true) {
			return true;
		}

		foreach (($attempt->task->getCandidateGroups() ?? []) as $groupId) {
			// A typed `{type, id}` entry is the typed branch's to resolve, never a bare group name.
			if (is_array($groupId) === true) {
				continue;
			}

			if ($this->groups->isInGroup(uid: $attempt->uid, groupId: (string)$groupId) === true) {
				return true;
			}
		}

		return false;
	}//end isNamedOrInGroup()

	/**
	 * Whether the caller is in the group named by `candidate_role`; a role
	 * that does not RESOLVE to a group denies naming the role.
	 *
	 * @param TaskVerbAttempt $attempt The attempt.
	 *
	 * @return boolean True when a role is set, resolves and has the caller.
	 *
	 * @throws TaskAccessDeniedException When the role cannot be resolved.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	private function isRoleMember(TaskVerbAttempt $attempt): bool {
		$role = trim((string)$attempt->task->getCandidateRole());
		if ($role === '') {
			return false;
		}

		$problem = $this->groups->roleProblem(role: $role);
		if ($problem !== null) {
			throw new TaskAccessDeniedException(message: sprintf("Verb '%s' denied: %s", $attempt->verb, $problem));
		}

		return $this->groups->isInGroup(uid: $attempt->uid, groupId: $role);
	}//end isRoleMember()

	/**
	 * Whether a TYPED candidate reference resolves to this caller.
	 *
	 * Without a resolver a typed candidate cannot be affirmed, so it is not
	 * affirmed (fail closed, like every other branch here).
	 *
	 * @param TaskVerbAttempt $attempt The attempt.
	 *
	 * @return boolean True when a typed candidate resolves to the caller.
	 *
	 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md
	 */
	private function matchesTypedCandidate(TaskVerbAttempt $attempt): bool {
		if ($this->principals === null) {
			return false;
		}

		$typed = $this->typedCandidates(task: $attempt->task);
		if ($typed === []) {
			return false;
		}

		return in_array($attempt->uid, $this->principals->resolveAll(references: $typed), true);
	}//end matchesTypedCandidate()

	/**
	 * The candidate entries that carry an explicit type.
	 *
	 * A bare string has already been read by the legacy branches, under the
	 * wider meaning every stored task was written against; taking it again
	 * here as the default type would widen the pool rather than extend it.
	 *
	 * @param TaskInterface $task The task acted on.
	 *
	 * @return array<int, PrincipalReference> The typed references.
	 *
	 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) `PrincipalReference::listFrom()` is a
	 * NAMED CONSTRUCTOR on a value object, which is the canonical PHP idiom for
	 * one and is indistinguishable to this rule from a static call into a
	 * service. The type has no dependencies, no state and nothing to stub.
	 */
	private function typedCandidates(TaskInterface $task): array {
		$typed = [];
		$fields = [($task->getCandidateUsers() ?? []), ($task->getCandidateGroups() ?? []), [$task->getCandidateRole()]];

		foreach ($fields as $field) {
			foreach (PrincipalReference::listFrom(value: array_values(array_filter($field))) as $reference) {
				if ($reference->type !== PrincipalReference::DEFAULT_TYPE) {
					$typed[] = $reference;
				}
			}
		}

		return $typed;
	}//end typedCandidates()
}//end class
