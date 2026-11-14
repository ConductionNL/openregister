<?php

/**
 * Which lifecycle verbs a task row offers its caller.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Task
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Task;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Exception\TaskAccessDeniedException;
use OCA\OpenRegister\Kernel\Contracts\TaskInterface;
use OCA\OpenRegister\Service\Task\TaskAct;
use OCA\OpenRegister\Service\Task\TaskAuthorizationService;
use OCA\OpenRegister\Service\Task\TaskVerbList;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Task\TaskVerbList
 * @uses \OCA\OpenRegister\Db\Task
 * @uses \OCA\OpenRegister\Service\Task\TaskAct
 */
final class TaskVerbListTest extends TestCase {

	/**
	 * The verbs each act asked about, in order.
	 *
	 * @var array<int, string>
	 */
	private array $asked = [];

	/**
	 * A verb list whose authorization allows every verb except the denied ones.
	 *
	 * @param array<int, string> $denied Verbs the authorization refuses.
	 *
	 * @return TaskVerbList
	 */
	private function verbs(array $denied = []): TaskVerbList {
		$authorization = $this->createMock(TaskAuthorizationService::class);
		$authorization->method('assertMay')->willReturnCallback(
			function (TaskAct $act, Task $task) use ($denied): void {
				$this->asked[] = $act->verb . ':' . $act->actor;
				if (in_array($act->verb, $denied, true) === true) {
					throw new TaskAccessDeniedException('no');
				}
			}
		);

		return new TaskVerbList($authorization);
	}//end verbs()

	/**
	 * An open task, optionally assigned.
	 *
	 * @param string|null $assignee The assignee.
	 * @param string      $state    The state.
	 *
	 * @return Task
	 */
	private static function task(?string $assignee = null, string $state = 'ready'): Task {
		$task = new Task();
		$task->setAssignee($assignee);
		$task->setState($state);
		return $task;
	}//end task()

	public function testAnUnassignedTaskOffersClaimAndOfferButNotUnclaimOrDelegate(): void {
		$this->assertSame(
			['claim', 'assign', 'reassign', 'offer', 'resolve', 'complete', 'cancel'],
			$this->verbs()->for(self::task(), 'alice')
		);
	}//end testAnUnassignedTaskOffersClaimAndOfferButNotUnclaimOrDelegate()

	public function testAnAssignedTaskOffersUnclaimAndDelegateButNotClaimOrOffer(): void {
		$this->assertSame(
			['unclaim', 'assign', 'reassign', 'delegate', 'resolve', 'complete', 'cancel'],
			$this->verbs()->for(self::task('bob'), 'alice')
		);
	}//end testAnAssignedTaskOffersUnclaimAndDelegateButNotClaimOrOffer()

	public function testABlankAssigneeCountsAsUnassigned(): void {
		$this->assertContains('claim', $this->verbs()->for(self::task('  '), 'alice'));
	}//end testABlankAssigneeCountsAsUnassigned()

	public function testADeniedVerbIsLeftOutAndTheCallerIsTheActor(): void {
		$verbs = $this->verbs(['cancel', 'assign'])->for(self::task(), 'alice');

		$this->assertSame(['claim', 'reassign', 'offer', 'resolve', 'complete'], $verbs);
		$this->assertSame(
			['claim:alice', 'assign:alice', 'reassign:alice', 'offer:alice', 'resolve:alice', 'complete:alice', 'cancel:alice'],
			$this->asked
		);
	}//end testADeniedVerbIsLeftOutAndTheCallerIsTheActor()

	public function testNoCallerGetsNothingWithoutAsking(): void {
		$this->assertSame([], $this->verbs()->for(self::task(), null));
		$this->assertSame([], $this->verbs()->for(self::task(), '   '));
		$this->assertSame([], $this->asked);
	}//end testNoCallerGetsNothingWithoutAsking()

	public function testATerminalTaskOffersNothing(): void {
		foreach (Task::TERMINAL_STATES as $state) {
			$this->assertSame([], $this->verbs()->for(self::task(null, $state), 'alice'), $state);
		}

		$this->assertSame([], $this->asked);
	}//end testATerminalTaskOffersNothing()

	public function testATaskThatIsNotAStoredRowOffersNothing(): void {
		$this->assertSame([], $this->verbs()->for($this->createMock(TaskInterface::class), 'alice'));
	}//end testATaskThatIsNotAStoredRowOffersNothing()
}//end class
