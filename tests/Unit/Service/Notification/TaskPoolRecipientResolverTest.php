<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Offering to a pool notifies the pool: every member of the candidate
 * groups plus the candidate users, nobody outside it, and nobody at all
 * when a group cannot be resolved (fail closed, never "everyone").
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-task-lifecycle-delivery-is-automatic-and-declarative
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Notification;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Service\Notification\TaskObjectAdapter;
use OCA\OpenRegister\Service\Notification\TaskPoolRecipientResolver;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TaskPoolRecipientResolverTest extends TestCase {
	private IGroupManager&MockObject $groups;

	private IUserManager&MockObject $users;

	protected function setUp(): void {
		parent::setUp();
		$this->groups = $this->createMock(IGroupManager::class);
		$this->users = $this->createMock(IUserManager::class);
	}

	private function group(array $uids): IGroup {
		$members = [];
		foreach ($uids as $uid) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$members[] = $user;
		}

		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn($members);

		return $group;
	}

	private function pooled(array $groups, array $users = []): TaskObjectAdapter {
		$task = new Task();
		$task->setUuid('t-pool');
		$task->setState(Task::STATE_ENABLED);
		$task->setPerformerType(Task::PERFORMER_GROUP);
		$task->setCandidateGroups($groups);
		$task->setCandidateUsers($users);

		return new TaskObjectAdapter($task);
	}

	public function testAllFourMembersAndNobodyElse(): void {
		$this->groups->method('get')->with('permits')->willReturn($this->group(['a', 'b', 'c', 'd']));
		$resolver = new TaskPoolRecipientResolver($this->groups, $this->users, $this->createMock(LoggerInterface::class));

		$this->assertSame(['a', 'b', 'c', 'd'], $resolver->resolve($this->pooled(['permits']), ['action' => 'offer']));
	}

	public function testANonMemberIsNotNotified(): void {
		$this->groups->method('get')->willReturn($this->group(['member']));
		$resolver = new TaskPoolRecipientResolver($this->groups, $this->users, $this->createMock(LoggerInterface::class));

		$this->assertNotContains('outsider', $resolver->resolve($this->pooled(['permits']), []));
	}

	public function testAnUnknownGroupAddsNobodyAndIsLogged(): void {
		$this->groups->method('get')->willReturn(null);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains('does not exist'));
		$resolver = new TaskPoolRecipientResolver($this->groups, $this->users, $logger);

		$this->assertSame([], $resolver->resolve($this->pooled(['ghosts']), []));
	}

	public function testCandidateUsersAreIncludedOnlyWhenTheyExist(): void {
		$this->users->method('userExists')->willReturnCallback(static fn (string $uid): bool => $uid === 'real');
		$resolver = new TaskPoolRecipientResolver($this->groups, $this->users, $this->createMock(LoggerInterface::class));

		$this->assertSame(['real'], $resolver->resolve($this->pooled([], ['real', 'phantom']), []));
	}

	public function testMembersAreDistinctAcrossGroups(): void {
		$this->groups->method('get')->willReturnCallback(
			fn (string $gid): IGroup => $gid === 'a' ? $this->group(['x', 'y']) : $this->group(['y', 'z'])
		);
		$resolver = new TaskPoolRecipientResolver($this->groups, $this->users, $this->createMock(LoggerInterface::class));

		$this->assertSame(['x', 'y', 'z'], $resolver->resolve($this->pooled(['a', 'b']), []));
	}
}
