<?php

/**
 * Golden (characterisation) matrix for TaskAuthorizationService.
 *
 * Pins every ALLOWED and every REFUSED outcome of the task lifecycle
 * authorization, branch by branch, including the exact denial messages,
 * so the class can be restructured without moving a single decision.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Task
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Task;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

use OCA\OpenRegister\Service\Task\TaskAct;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Exception\TaskAccessDeniedException;
use OCA\OpenRegister\Service\Flow\Principal\PrincipalResolverRegistry;
use OCA\OpenRegister\Service\Task\TaskAuthorizationService;
use OCP\IGroupManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Every branch of assertMay(), mayRead() and isAdministrator(), pinned.
 *
 * @covers \OCA\OpenRegister\Service\Task\TaskAuthorizationService
 * @uses \OCA\OpenRegister\Db\Task
 * @uses \OCA\OpenRegister\Exception\TaskAccessDeniedException
 * @uses \OCA\OpenRegister\Service\Flow\Principal\PrincipalReference
 * @uses \OCA\OpenRegister\Service\Task\Authorization\FailClosedGroups
 * @uses \OCA\OpenRegister\Service\Task\Authorization\TaskVerbAttempt
 * @uses \OCA\OpenRegister\Service\Task\TaskAct
 */
final class TaskAuthorizationServiceGoldenTest extends TestCase {

	private const ALLOWED = 'allowed';

	/**
	 * Build the service under a described backend.
	 *
	 * Backend keys: `none` (no group manager), `throws` (every call throws),
	 * `admins`, `members` (group => uids), `groups` (existing group ids),
	 * `typed` (uids the principal registry resolves; absent = no registry).
	 *
	 * @param array<string, mixed> $backend The backend description.
	 *
	 * @return TaskAuthorizationService The service.
	 */
	private function service(array $backend): TaskAuthorizationService {
		$registry = null;
		if (array_key_exists('typed', $backend) === true) {
			$registry = $this->createMock(PrincipalResolverRegistry::class);
			$registry->method('resolveAll')->willReturn($backend['typed']);
		}

		if (($backend['none'] ?? false) === true) {
			return new TaskAuthorizationService(groupManager: null, principals: $registry);
		}

		$groups = $this->createMock(IGroupManager::class);
		if (($backend['throws'] ?? false) === true) {
			$groups->method('isAdmin')->willThrowException(new RuntimeException('down'));
			$groups->method('isInGroup')->willThrowException(new RuntimeException('down'));
			$groups->method('groupExists')->willThrowException(new RuntimeException('down'));

			return new TaskAuthorizationService(groupManager: $groups, principals: $registry);
		}

		$admins  = ($backend['admins'] ?? []);
		$members = ($backend['members'] ?? []);
		$exists  = ($backend['groups'] ?? []);
		$groups->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => in_array($uid, $admins, true));
		$groups->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $gid): bool => in_array($uid, ($members[$gid] ?? []), true)
		);
		$groups->method('groupExists')->willReturnCallback(static fn (string $gid): bool => in_array($gid, $exists, true));

		return new TaskAuthorizationService(groupManager: $groups, principals: $registry);
	}//end service()

	/**
	 * Build a task from a description; defaults to a user task assigned to
	 * alice and requested by rita.
	 *
	 * @param array<string, mixed> $fields Overrides.
	 *
	 * @return Task The task.
	 */
	private function task(array $fields): Task {
		$fields = array_merge(
			['performerType' => Task::PERFORMER_USER, 'assignee' => 'alice', 'requester' => 'rita'],
			$fields
		);

		$task = new Task();
		$task->setUuid('t-golden');
		$task->setState(Task::STATE_ACTIVE);
		$task->setPerformerType($fields['performerType']);
		$task->setAssignee($fields['assignee']);
		$task->setRequester($fields['requester']);
		$task->setCandidateUsers(($fields['candidateUsers'] ?? null));
		$task->setCandidateGroups(($fields['candidateGroups'] ?? null));
		$task->setCandidateRole(($fields['candidateRole'] ?? null));
		$task->setWatchers(($fields['watchers'] ?? null));

		return $task;
	}//end task()

	/**
	 * Every assertMay() branch.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: string, 3: string|null, 4: string}>
	 */
	public static function assertMayCases(): array {
		$pool   = "Verb '%s' denied: the caller is not in the task's candidate pool.";
		$asg    = "Verb '%s' denied: only the current assignee may perform it.";
		$req    = "Verb '%s' denied: only the requester or an administrator may perform it.";
		$ext    = [
			'performerType' => Task::PERFORMER_EXTERNAL,
			'assignee' => 'party:p-1',
			'requester' => 'rita',
		];
		$noExt  = "Verb '%s' refused: performer type 'external' has no candidate pool and no mandate model; cancel or re-ask instead.";
		$match  = "Verb '%s' denied: only the matched portal subject may answer an external task.";
		$admins = ['admins' => ['root']];

		$cases = [
			// Determinability.
			'no identity' => [[], $admins, 'complete', null, "Verb 'complete' denied: no acting identity."],
			'blank identity' => [[], $admins, 'complete', '  ', "Verb 'complete' denied: no acting identity."],
			'no identity before unknown type' => [['performerType' => 'robot'], $admins, 'claim', null, "Verb 'claim' denied: no acting identity."],
			'unknown performer type, even for admin' => [
				['performerType' => 'robot'],
				$admins,
				'complete',
				'root',
				"Verb 'complete' denied: performer type 'robot' is unknown, so authorization cannot be determined.",
			],
			'empty performer type' => [
				['performerType' => null],
				$admins,
				'create',
				'root',
				"Verb 'create' denied: performer type '' is unknown, so authorization cannot be determined.",
			],
			// Administrator bypass and create.
			'admin passes a requester verb' => [[], $admins, 'cancel', 'root', self::ALLOWED],
			'admin passes an unknown verb' => [[], $admins, 'frobnicate', 'root', self::ALLOWED],
			'admin backend throws: no bypass' => [[], ['throws' => true], 'cancel', 'root', sprintf($req, 'cancel')],
			'no backend: no bypass' => [[], ['none' => true], 'cancel', 'root', sprintf($req, 'cancel')],
			'create by anyone identified' => [[], [], 'create', 'mallory', self::ALLOWED],
			'unknown verb' => [[], [], 'frobnicate', 'alice', "Verb 'frobnicate' denied: no authorization rule exists for it."],
			// Assignee verbs.
			'assignee completes' => [[], [], 'complete', 'alice', self::ALLOWED],
			'assignee trimmed' => [['assignee' => ' alice '], [], 'resolve', 'alice', self::ALLOWED],
			'uid is not trimmed' => [[], [], 'complete', ' alice', sprintf($asg, 'complete')],
			'stranger completes' => [[], [], 'complete', 'mallory', sprintf($asg, 'complete')],
			'no assignee' => [['assignee' => null], [], 'unclaim', 'alice', sprintf($asg, 'unclaim')],
			'requester delegates' => [[], [], 'delegate', 'rita', sprintf($asg, 'delegate')],
			'assignee checklist' => [[], [], 'checklist', 'alice', self::ALLOWED],
			// Requester verbs.
			'requester assigns' => [[], [], 'assign', 'rita', self::ALLOWED],
			'requester reassigns' => [['requester' => ' rita'], [], 'reassign', 'rita', self::ALLOWED],
			'assignee offers' => [[], [], 'offer', 'alice', sprintf($req, 'offer')],
			'no requester' => [['requester' => null], [], 'cancel', 'rita', sprintf($req, 'cancel')],
			// Pool verbs.
			'candidate user claims' => [['candidateUsers' => ['bob']], [], 'claim', 'bob', self::ALLOWED],
			'candidate group member claims' => [['candidateGroups' => ['g1']], ['members' => ['g1' => ['bob']]], 'claim', 'bob', self::ALLOWED],
			'non-member claims' => [['candidateGroups' => ['g1']], ['members' => ['g1' => ['carol']]], 'claim', 'bob', sprintf($pool, 'claim')],
			'group check throws' => [['candidateGroups' => ['g1']], ['throws' => true], 'claim', 'bob', sprintf($pool, 'claim')],
			'empty group id' => [['candidateGroups' => ['']], ['members' => ['' => ['bob']]], 'claim', 'bob', sprintf($pool, 'claim')],
			'role member claims' => [['candidateRole' => 'r1'], ['groups' => ['r1'], 'members' => ['r1' => ['bob']]], 'claim', 'bob', self::ALLOWED],
			'role exists, not member' => [['candidateRole' => ' r1 '], ['groups' => ['r1']], 'claim', 'bob', sprintf($pool, 'claim')],
			'role does not resolve' => [
				['candidateRole' => 'ghost'],
				[],
				'claim',
				'bob',
				"Verb 'claim' denied: role 'ghost' does not resolve to any group.",
			],
			'role exists check throws' => [
				['candidateRole' => 'r1'],
				['throws' => true],
				'claim',
				'bob',
				"Verb 'claim' denied: role 'r1' does not resolve to any group.",
			],
			'role without backend' => [
				['candidateRole' => 'r1'],
				['none' => true],
				'claim',
				'bob',
				"Verb 'claim' denied: role 'r1' cannot be resolved because no group backend is available.",
			],
			'candidate user wins before a bad role' => [['candidateUsers' => ['bob'], 'candidateRole' => 'ghost'], [], 'claim', 'bob', self::ALLOWED],
			'bad role refuses before typed' => [
				['candidateRole' => 'ghost', 'candidateUsers' => [['type' => 'position', 'id' => 'p']]],
				['typed' => ['bob']],
				'claim',
				'bob',
				"Verb 'claim' denied: role 'ghost' does not resolve to any group.",
			],
			'typed candidate resolves' => [
				['candidateUsers' => [['type' => 'position', 'id' => 'p']]],
				['typed' => ['bob']],
				'claim',
				'bob',
				self::ALLOWED,
			],
			'typed candidate resolves elsewhere' => [
				['candidateUsers' => [['type' => 'position', 'id' => 'p']]],
				['typed' => ['carol']],
				'claim',
				'bob',
				sprintf($pool, 'claim'),
			],
			'typed candidate without registry' => [
				['candidateUsers' => [['type' => 'position', 'id' => 'p']]],
				[],
				'claim',
				'bob',
				sprintf($pool, 'claim'),
			],
			'bare candidates are not typed' => [['candidateUsers' => ['carol']], ['typed' => ['bob']], 'claim', 'bob', sprintf($pool, 'claim')],
			'empty pool' => [[], ['typed' => ['bob']], 'claim', 'bob', sprintf($pool, 'claim')],
			// Edit: assignee or pool.
			'assignee edits' => [[], [], 'edit', 'alice', self::ALLOWED],
			'pool member edits' => [['candidateUsers' => ['bob']], [], 'edit', 'bob', self::ALLOWED],
			'requester edits' => [[], [], 'edit', 'rita', sprintf($pool, 'edit')],
			'no assignee, empty uid match impossible' => [['assignee' => null], [], 'edit', 'alice', sprintf($pool, 'edit')],
			// External tasks.
			'external create' => [$ext, [], 'create', 'mallory', self::ALLOWED],
			'external claim refused for admin' => [$ext, $admins, 'claim', 'root', sprintf($noExt, 'claim')],
			'external edit refused for party' => [$ext, [], 'edit', 'party:p-1', sprintf($noExt, 'edit')],
			'external offer refused' => [$ext, [], 'offer', 'rita', sprintf($noExt, 'offer')],
			'external reassign refused' => [$ext, [], 'reassign', 'rita', sprintf($noExt, 'reassign')],
			'external party completes' => [$ext, [], 'complete', 'party:p-1', self::ALLOWED],
			'external party trimmed' => [array_merge($ext, ['assignee' => ' party:p-1 ']), [], 'resolve', 'party:p-1', self::ALLOWED],
			'external admin may not complete' => [$ext, $admins, 'complete', 'root', sprintf($match, 'complete')],
			'external other party' => [$ext, [], 'checklist', 'party:p-2', sprintf($match, 'checklist')],
			'external assignee not a party' => [array_merge($ext, ['assignee' => 'alice']), [], 'complete', 'alice', sprintf($match, 'complete')],
			'external no assignee' => [array_merge($ext, ['assignee' => null]), [], 'complete', 'party:', sprintf($match, 'complete')],
			'external admin cancels' => [$ext, $admins, 'cancel', 'root', self::ALLOWED],
			'external requester cancels' => [$ext, [], 'cancel', 'rita', self::ALLOWED],
			'external party cancels' => [$ext, [], 'cancel', 'party:p-1', sprintf($req, 'cancel')],
			'external unknown verb' => [
				$ext,
				$admins,
				'frobnicate',
				'root',
				"Verb 'frobnicate' denied: no authorization rule exists for it on performer type 'external'.",
			],
		];

		return $cases;
	}//end assertMayCases()

	/**
	 * Every assertMay() outcome is pinned, allowed or refused with its message.
	 *
	 * @param array<string, mixed> $task     Task fields.
	 * @param array<string, mixed> $backend  Backend description.
	 * @param string               $verb     The verb.
	 * @param string|null          $uid      The caller.
	 * @param string               $expected `allowed`, or the exact denial message.
	 *
	 * @return void
	 */
	#[DataProvider('assertMayCases')]
	public function testAssertMayOutcome(array $task, array $backend, string $verb, ?string $uid, string $expected): void {
		$service = $this->service(backend: $backend);

		$outcome = self::ALLOWED;
		try {
			$service->assertMay(act: new TaskAct(verb: $verb, actor: $uid), task: $this->task(fields: $task));
		} catch (TaskAccessDeniedException $denied) {
			$outcome = $denied->getMessage();
		}

		$this->assertSame($expected, $outcome);
	}//end testAssertMayOutcome()

	/**
	 * Every mayRead() branch.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: string|null, 3: bool}>
	 */
	public static function mayReadCases(): array {
		return [
			'no identity' => [[], ['admins' => ['root']], null, false],
			'blank identity' => [[], ['admins' => ['root']], ' ', false],
			'admin' => [[], ['admins' => ['root']], 'root', true],
			'admin backend throws' => [[], ['throws' => true], 'root', false],
			'assignee' => [[], [], 'alice', true],
			'requester' => [[], [], 'rita', true],
			'trimmed requester' => [['requester' => ' rita '], [], 'rita', true],
			'watcher' => [['watchers' => ['wendy']], [], 'wendy', true],
			'candidate user' => [['candidateUsers' => ['bob']], [], 'bob', true],
			'group member' => [['candidateGroups' => ['g1']], ['members' => ['g1' => ['bob']]], 'bob', true],
			'unresolvable role' => [['candidateRole' => 'ghost'], [], 'bob', false],
			'role without backend' => [['candidateRole' => 'r1'], ['none' => true], 'bob', false],
			'typed candidate' => [['candidateUsers' => [['type' => 'position', 'id' => 'p']]], ['typed' => ['bob']], 'bob', true],
			'stranger' => [['watchers' => ['wendy']], [], 'mallory', false],
			'external party reads as assignee' => [['performerType' => Task::PERFORMER_EXTERNAL, 'assignee' => 'party:p-1'], [], 'party:p-1', true],
			'unknown performer type still reads' => [['performerType' => 'robot'], [], 'alice', true],
		];
	}//end mayReadCases()

	/**
	 * Every mayRead() outcome is pinned.
	 *
	 * @param array<string, mixed> $task     Task fields.
	 * @param array<string, mixed> $backend  Backend description.
	 * @param string|null          $uid      The reader.
	 * @param boolean              $expected Visible or not.
	 *
	 * @return void
	 */
	#[DataProvider('mayReadCases')]
	public function testMayReadOutcome(array $task, array $backend, ?string $uid, bool $expected): void {
		$service = $this->service(backend: $backend);

		$this->assertSame($expected, $service->mayRead(task: $this->task(fields: $task), uid: $uid));
	}//end testMayReadOutcome()

	/**
	 * Every isAdministrator() branch.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string|null, 2: bool}>
	 */
	public static function isAdministratorCases(): array {
		return [
			'no identity' => [['admins' => ['root']], null, false],
			'blank identity' => [['admins' => ['root']], '', false],
			'admin' => [['admins' => ['root']], 'root', true],
			'non-admin' => [['admins' => ['root']], 'alice', false],
			'no backend' => [['none' => true], 'root', false],
			'backend throws' => [['throws' => true], 'root', false],
		];
	}//end isAdministratorCases()

	/**
	 * Every isAdministrator() outcome is pinned.
	 *
	 * @param array<string, mixed> $backend  Backend description.
	 * @param string|null          $uid      The caller.
	 * @param boolean              $expected Admin or not.
	 *
	 * @return void
	 */
	#[DataProvider('isAdministratorCases')]
	public function testIsAdministratorOutcome(array $backend, ?string $uid, bool $expected): void {
		$this->assertSame($expected, $this->service(backend: $backend)->isAdministrator(uid: $uid));
	}//end testIsAdministratorOutcome()
}//end class
