<?php

/**
 * The routing strategies — and the rule above all of them.
 *
 * The rule: a strategy that resolves to NOBODY, with no fallback, leaves
 * the task POOLED. Not assigned to the requester, not to the first member,
 * not to a system identity — each of those turns a routing
 * misconfiguration into a silently answerable task.
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
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Service\Task\TaskPerformerResolver;
use OCP\IGroupManager;
use OCP\IGroup;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/**
 * Strategy behaviour over expanded pools.
 *
 * @covers \OCA\OpenRegister\Service\Task\TaskPerformerResolver
 * @covers \OCA\OpenRegister\Db\Task
 */
class TaskPerformerResolverTest extends TestCase {

	/**
	 * A group backend serving one group with the given member uids.
	 *
	 * @param string $groupId The group id served.
	 * @param array<int, string> $memberUids Its members.
	 *
	 * @return IGroupManager The mock.
	 */
	private function backendWithGroup(string $groupId, array $memberUids): IGroupManager {
		$users = [];
		foreach ($memberUids as $uid) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$users[] = $user;
		}

		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn($users);

		$manager = $this->createMock(IGroupManager::class);
		$manager->method('get')->willReturnCallback(
			static fn (string $gid) => ($gid === $groupId) ? $group : null
		);

		return $manager;
	}//end backendWithGroup()

	/**
	 * THE SPEC'S SCENARIO: least-loaded over a pool whose members are all
	 * filtered out, no fallback — the task stays unassigned, and no implicit
	 * assignment to requester or a system identity occurs.
	 *
	 * @return void
	 */
	public function testAStrategyThatFindsNobodyAssignsNobody(): void {
		$mapper = $this->createMock(TaskMapper::class);
		$mapper->method('countOpenAssigned')->willReturn([]);
		$resolver = new TaskPerformerResolver(
			tasks: $mapper,
			groupManager: $this->backendWithGroup('empty-team', [])
		);

		$task = new Task();
		$task->setRequester('rita');
		$task->setCandidateGroups(['empty-team']);
		$task->setRoutingStrategy('least-loaded');
		$task->setRoutingFallback(null);

		$this->assertNull($resolver->resolveAssignee(task: $task));
	}//end testAStrategyThatFindsNobodyAssignsNobody()

	/**
	 * The ONLY fallback is the configured one.
	 *
	 * @return void
	 */
	public function testTheConfiguredFallbackIsUsed(): void {
		$mapper = $this->createMock(TaskMapper::class);
		$resolver = new TaskPerformerResolver(tasks: $mapper, groupManager: null);

		$task = new Task();
		$task->setRoutingStrategy('least-loaded');
		$task->setRoutingFallback('fallback-franz');

		$this->assertSame('fallback-franz', $resolver->resolveAssignee(task: $task));
	}//end testTheConfiguredFallbackIsUsed()

	/**
	 * least-loaded picks the member with the fewest open tasks.
	 *
	 * @return void
	 */
	public function testLeastLoadedPicksTheEmptiestPlate(): void {
		$mapper = $this->createMock(TaskMapper::class);
		$mapper->method('countOpenAssigned')->willReturn(
			[
				'anna' => 4,
				'bert' => 1,
			]
		);
		$resolver = new TaskPerformerResolver(tasks: $mapper, groupManager: null);

		$task = new Task();
		$task->setCandidateUsers(['anna', 'bert', 'carla']);
		$task->setRoutingStrategy('least-loaded');

		// carla holds zero open tasks and wins.
		$this->assertSame('carla', $resolver->resolveAssignee(task: $task));
	}//end testLeastLoadedPicksTheEmptiestPlate()

	/**
	 * round-robin picks the least recently assigned; never-assigned first.
	 *
	 * @return void
	 */
	public function testRoundRobinPicksTheLeastRecentlyAssigned(): void {
		$mapper = $this->createMock(TaskMapper::class);
		$mapper->method('latestAssignedAt')->willReturn(
			[
				'anna' => '2026-08-30 10:00:00',
				'bert' => '2026-08-29 10:00:00',
			]
		);
		$resolver = new TaskPerformerResolver(tasks: $mapper, groupManager: null);

		$withVirgin = new Task();
		$withVirgin->setCandidateUsers(['anna', 'bert', 'nils']);
		$withVirgin->setRoutingStrategy('round-robin');
		$this->assertSame('nils', $resolver->resolveAssignee(task: $withVirgin));

		$mapper2 = $this->createMock(TaskMapper::class);
		$mapper2->method('latestAssignedAt')->willReturn(
			[
				'anna' => '2026-08-30 10:00:00',
				'bert' => '2026-08-29 10:00:00',
			]
		);
		$resolver2 = new TaskPerformerResolver(tasks: $mapper2, groupManager: null);
		$allSeasoned = new Task();
		$allSeasoned->setCandidateUsers(['anna', 'bert']);
		$allSeasoned->setRoutingStrategy('round-robin');
		$this->assertSame('bert', $resolver2->resolveAssignee(task: $allSeasoned));
	}//end testRoundRobinPicksTheLeastRecentlyAssigned()

	/**
	 * single-role assigns only when the role resolves to exactly one person.
	 *
	 * @return void
	 */
	public function testSingleRoleNeedsExactlyOne(): void {
		$mapper = $this->createMock(TaskMapper::class);

		$one = new Task();
		$one->setCandidateRole('controller');
		$one->setRoutingStrategy('single-role');
		$resolverOne = new TaskPerformerResolver(
			tasks: $mapper,
			groupManager: $this->backendWithGroup('controller', ['carl'])
		);
		$this->assertSame('carl', $resolverOne->resolveAssignee(task: $one));

		$many = new Task();
		$many->setCandidateRole('reviewers');
		$many->setRoutingStrategy('single-role');
		$resolverMany = new TaskPerformerResolver(
			tasks: $mapper,
			groupManager: $this->backendWithGroup('reviewers', ['rey', 'ria'])
		);
		$this->assertNull($resolverMany->resolveAssignee(task: $many));
	}//end testSingleRoleNeedsExactlyOne()

	/**
	 * or-set never picks: everyone in the set may claim.
	 *
	 * @return void
	 */
	public function testOrSetLeavesTheWholeSetClaimable(): void {
		$mapper = $this->createMock(TaskMapper::class);
		$resolver = new TaskPerformerResolver(tasks: $mapper, groupManager: null);

		$task = new Task();
		$task->setCandidateUsers(['anna', 'bert']);
		$task->setRoutingStrategy('or-set');

		$this->assertNull($resolver->resolveAssignee(task: $task));
	}//end testOrSetLeavesTheWholeSetClaimable()

	/**
	 * hierarchical takes the first tier in declared order.
	 *
	 * @return void
	 */
	public function testHierarchicalTakesTheFirstTier(): void {
		$mapper = $this->createMock(TaskMapper::class);
		$resolver = new TaskPerformerResolver(tasks: $mapper, groupManager: null);

		$task = new Task();
		$task->setCandidateUsers(['first-line', 'second-line']);
		$task->setRoutingStrategy('hierarchical');

		$this->assertSame('first-line', $resolver->resolveAssignee(task: $task));
	}//end testHierarchicalTakesTheFirstTier()
}//end class
