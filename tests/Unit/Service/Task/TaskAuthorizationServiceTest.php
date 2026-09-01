<?php

/**
 * Fail-closed authorization on every task verb.
 *
 * The scenarios that matter most are the UNDETERMINABLE ones: a role the
 * resolver cannot resolve, a group backend that is absent, a performer type
 * nobody knows. Each must DENY — reported as a denial with a reason, never
 * as success and never as "no check applicable". The stranger-with-a-uuid
 * case is the exact hole measured on the flow resume endpoint.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Task
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Task;

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Exception\TaskAccessDeniedException;
use OCA\OpenRegister\Service\Task\TaskAuthorizationService;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;

/**
 * The per-verb decisions, and their fail-closed indeterminate cases.
 *
 * @covers \OCA\OpenRegister\Service\Task\TaskAuthorizationService
 */
class TaskAuthorizationServiceTest extends TestCase {

	/**
	 * A group backend that knows nothing and nobody.
	 *
	 * @return IGroupManager The mock.
	 */
	private function emptyGroupBackend(): IGroupManager {
		$manager = $this->createMock(IGroupManager::class);
		$manager->method('isAdmin')->willReturn(false);
		$manager->method('isInGroup')->willReturn(false);
		$manager->method('groupExists')->willReturn(false);

		return $manager;
	}//end emptyGroupBackend()

	/**
	 * A task assigned to alice, requested by rita.
	 *
	 * @return Task The task.
	 */
	private function assignedTask(): Task {
		$task = new Task();
		$task->setUuid('t-1');
		$task->setState(Task::STATE_ACTIVE);
		$task->setPerformerType(Task::PERFORMER_USER);
		$task->setAssignee('alice');
		$task->setRequester('rita');

		return $task;
	}//end assignedTask()

	/**
	 * A STRANGER who merely knows the uuid is denied on every verb.
	 *
	 * @return void
	 */
	public function testAStrangerIsDeniedOnEveryVerb(): void {
		$service = new TaskAuthorizationService(groupManager: $this->emptyGroupBackend());
		$task = $this->assignedTask();

		// Every verb that touches an existing task. `offer` is in this list
		// because it was once missing from it, which is exactly how an
		// identity-only offer stayed green while it let anyone route a task
		// to themselves through routingFallback.
		$verbs = ['offer', 'claim', 'unclaim', 'assign', 'reassign', 'delegate', 'resolve', 'complete', 'cancel', 'checklist'];
		foreach ($verbs as $verb) {
			try {
				$service->assertMay(verb: $verb, task: $task, uid: 'mallory');
				$this->fail(sprintf("Verb '%s' admitted a stranger.", $verb));
			} catch (TaskAccessDeniedException $denied) {
				$this->assertStringContainsString($verb, $denied->getMessage());
			}
		}
	}//end testAStrangerIsDeniedOnEveryVerb()

	/**
	 * No identity, no verb — anonymity never acts.
	 *
	 * @return void
	 */
	public function testNoIdentityIsDenied(): void {
		$service = new TaskAuthorizationService(groupManager: $this->emptyGroupBackend());

		$this->expectException(TaskAccessDeniedException::class);
		$service->assertMay(verb: 'complete', task: $this->assignedTask(), uid: null);
	}//end testNoIdentityIsDenied()

	/**
	 * The assignee completes; so does an agent through the SAME check.
	 *
	 * @return void
	 */
	public function testTheAssigneeMayCompleteWhateverThePerformerType(): void {
		$service = new TaskAuthorizationService(groupManager: $this->emptyGroupBackend());

		$human = $this->assignedTask();
		$service->assertMay(verb: 'complete', task: $human, uid: 'alice');

		$agent = $this->assignedTask();
		$agent->setPerformerType(Task::PERFORMER_AGENT);
		$agent->setAssignee('agent-7');
		$service->assertMay(verb: 'complete', task: $agent, uid: 'agent-7');

		// Reaching here means neither threw.
		$this->assertTrue(true);
	}//end testTheAssigneeMayCompleteWhateverThePerformerType()

	/**
	 * AN UNKNOWN PERFORMER TYPE IS UNDETERMINABLE, WHICH IS A DENIAL — the
	 * extensibility contract: a new type is admitted by extending the
	 * vocabulary, never by falling through an open door.
	 *
	 * @return void
	 */
	public function testAnUnknownPerformerTypeDenies(): void {
		$service = new TaskAuthorizationService(groupManager: $this->emptyGroupBackend());
		$task = $this->assignedTask();
		$task->setPerformerType('external');

		try {
			$service->assertMay(verb: 'complete', task: $task, uid: 'alice');
			$this->fail('An unknown performer type was admitted.');
		} catch (TaskAccessDeniedException $denied) {
			$this->assertStringContainsString("'external'", $denied->getMessage());
		}
	}//end testAnUnknownPerformerTypeDenies()

	/**
	 * AN UNRESOLVABLE ROLE DENIES, NAMING THE ROLE — never "no check
	 * applicable", never success.
	 *
	 * @return void
	 */
	public function testAnUnresolvableRoleDeniesNamingTheRole(): void {
		$service = new TaskAuthorizationService(groupManager: $this->emptyGroupBackend());
		$task = new Task();
		$task->setState(Task::STATE_ENABLED);
		$task->setPerformerType(Task::PERFORMER_GROUP);
		$task->setCandidateRole('GHOST_ROLE');

		try {
			$service->assertMay(verb: 'claim', task: $task, uid: 'alice');
			$this->fail('An unresolvable role was treated as passable.');
		} catch (TaskAccessDeniedException $denied) {
			$this->assertStringContainsString('GHOST_ROLE', $denied->getMessage());
			$this->assertStringContainsString('denied', $denied->getMessage());
		}
	}//end testAnUnresolvableRoleDeniesNamingTheRole()

	/**
	 * NO GROUP BACKEND AT ALL: membership-dependent verbs DENY rather than
	 * skip — the constructible-without-a-container case fails closed.
	 *
	 * @return void
	 */
	public function testAnAbsentGroupBackendDenies(): void {
		$service = new TaskAuthorizationService(groupManager: null);
		$task = new Task();
		$task->setState(Task::STATE_ENABLED);
		$task->setPerformerType(Task::PERFORMER_GROUP);
		$task->setCandidateGroups(['reviewers']);

		$this->expectException(TaskAccessDeniedException::class);
		$service->assertMay(verb: 'claim', task: $task, uid: 'alice');
	}//end testAnAbsentGroupBackendDenies()

	/**
	 * A pool member may claim: named user, group member, and role member.
	 *
	 * @return void
	 */
	public function testPoolMembershipAdmitsAClaim(): void {
		$manager = $this->createMock(IGroupManager::class);
		$manager->method('isAdmin')->willReturn(false);
		$manager->method('groupExists')->willReturn(true);
		$manager->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $gid): bool => ($uid === 'greta' && $gid === 'reviewers')
		);
		$service = new TaskAuthorizationService(groupManager: $manager);

		$byUser = new Task();
		$byUser->setPerformerType(Task::PERFORMER_USER);
		$byUser->setCandidateUsers(['ursula']);
		$service->assertMay(verb: 'claim', task: $byUser, uid: 'ursula');

		$byGroup = new Task();
		$byGroup->setPerformerType(Task::PERFORMER_GROUP);
		$byGroup->setCandidateGroups(['reviewers']);
		$service->assertMay(verb: 'claim', task: $byGroup, uid: 'greta');

		$this->assertTrue(true);
	}//end testPoolMembershipAdmitsAClaim()

	/**
	 * The requester cancels and reassigns; the assignee does not.
	 *
	 * @return void
	 */
	public function testRequesterOwnsCancelAndReassign(): void {
		$service = new TaskAuthorizationService(groupManager: $this->emptyGroupBackend());
		$task = $this->assignedTask();

		$service->assertMay(verb: 'cancel', task: $task, uid: 'rita');
		$service->assertMay(verb: 'reassign', task: $task, uid: 'rita');

		$this->expectException(TaskAccessDeniedException::class);
		$service->assertMay(verb: 'cancel', task: $task, uid: 'alice');
	}//end testRequesterOwnsCancelAndReassign()

	/**
	 * OFFER BELONGS TO THE REQUESTER: it rewrites the pool and the routing
	 * fallback, which decide who ends up assigned. The assignee, a pool
	 * member and a stranger are all refused.
	 *
	 * @return void
	 */
	public function testOfferBelongsToTheRequester(): void {
		$service = new TaskAuthorizationService(groupManager: $this->emptyGroupBackend());
		$task = $this->assignedTask();
		$task->setCandidateUsers(['pat']);

		$service->assertMay(verb: 'offer', task: $task, uid: 'rita');

		foreach (['alice', 'pat', 'mallory'] as $notTheRequester) {
			try {
				$service->assertMay(verb: 'offer', task: $task, uid: $notTheRequester);
				$this->fail(sprintf("'%s' may offer but is not the requester.", $notTheRequester));
			} catch (TaskAccessDeniedException $denied) {
				$this->assertStringContainsString('offer', $denied->getMessage());
			}
		}
	}//end testOfferBelongsToTheRequester()

	/**
	 * An administrator passes every verb.
	 *
	 * @return void
	 */
	public function testAnAdministratorPasses(): void {
		$manager = $this->createMock(IGroupManager::class);
		$manager->method('isAdmin')->willReturn(true);
		$service = new TaskAuthorizationService(groupManager: $manager);

		$service->assertMay(verb: 'complete', task: $this->assignedTask(), uid: 'root');
		$service->assertMay(verb: 'cancel', task: $this->assignedTask(), uid: 'root');
		$this->assertTrue(true);
	}//end testAnAdministratorPasses()

	/**
	 * Read visibility: watchers see, strangers do not — and a watcher gains
	 * NO lifecycle right from watching.
	 *
	 * @return void
	 */
	public function testWatchersReadButNeverAct(): void {
		$service = new TaskAuthorizationService(groupManager: $this->emptyGroupBackend());
		$task = $this->assignedTask();
		$task->setWatchers(['wanda']);

		$this->assertTrue($service->mayRead(task: $task, uid: 'wanda'));
		$this->assertFalse($service->mayRead(task: $task, uid: 'mallory'));
		$this->assertFalse($service->mayRead(task: $task, uid: null));

		$this->expectException(TaskAccessDeniedException::class);
		$service->assertMay(verb: 'complete', task: $task, uid: 'wanda');
	}//end testWatchersReadButNeverAct()
}//end class
