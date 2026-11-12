<?php

/**
 * Characterisation tests for the per-timer invariant inspection: each
 * defect bucket on its own, a timer in several buckets at once, and which
 * timers trigger a subject lookup.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Repair;

use DateTime;
use OCA\OpenRegister\Db\FlowTimer;
use OCA\OpenRegister\Db\FlowTimerMapper;
use OCA\OpenRegister\Db\IdCursor;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Repair\CheckFlowTimerInvariants;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Repair\CheckFlowTimerInvariants
 */
class CheckFlowTimerInvariantsInspectTest extends TestCase {

	/**
	 * The subject uuids the check looked up, in order.
	 *
	 * @var array<int, string>
	 */
	private array $lookedUp = [];

	/**
	 * Measure one armed and one suspended page.
	 *
	 * @param array<int, FlowTimer> $armed     The armed timers.
	 * @param array<int, FlowTimer> $suspended The suspended timers.
	 *
	 * @return array<string, int>
	 */
	private function measure(array $armed, array $suspended): array {
		$timers = $this->createMock(FlowTimerMapper::class);
		$timers->method('findByStatePaged')->willReturnCallback(
			static function (string $state, IdCursor $cursor) use ($armed, $suspended): array {
				if ($cursor->afterId !== 0) {
					return [];
				}

				return $state === FlowTimer::STATE_ARMED ? $armed : $suspended;
			}
		);

		$tasks = $this->createMock(TaskMapper::class);
		$tasks->method('findByUuid')->willReturnCallback(
			function (string $uuid): Task {
				$this->lookedUp[] = $uuid;
				if ($uuid === 'gone') {
					throw new DoesNotExistException('gone');
				}

				$task = new Task();
				$task->setUuid($uuid);
				$task->setState($uuid === 'done' ? Task::STATE_COMPLETED : Task::STATE_ACTIVE);

				return $task;
			}
		);

		return (new CheckFlowTimerInvariants(timers: $timers, tasks: $tasks, logger: new NullLogger()))->measure();
	}

	private function timer(int $id, string $state, string $subjectType, string $subject): FlowTimer {
		$timer = new FlowTimer();
		$timer->setId($id);
		$timer->setState($state);
		$timer->setSubjectType($subjectType);
		$timer->setSubjectUuid($subject);

		return $timer;
	}

	public function testASuspendedTimerWithAFireAtCountsAsCarryingAClock(): void {
		$suspended = $this->timer(1, FlowTimer::STATE_SUSPENDED, 'task', 'open');
		$suspended->setFireAt(new DateTime('2026-10-01'));

		self::assertSame(['armedWithoutFireAt' => 0, 'suspendedWithClock' => 1, 'orphaned' => 0], $this->measure([], [$suspended]));
	}

	public function testASuspendedTimerWithoutAClockIsHealthy(): void {
		$suspended = $this->timer(1, FlowTimer::STATE_SUSPENDED, 'task', 'open');

		self::assertSame(['armedWithoutFireAt' => 0, 'suspendedWithClock' => 0, 'orphaned' => 0], $this->measure([], [$suspended]));
	}

	public function testOneTimerCanFallInSeveralBuckets(): void {
		$armed = $this->timer(1, FlowTimer::STATE_ARMED, 'task', 'gone');
		$suspended = $this->timer(2, FlowTimer::STATE_SUSPENDED, 'task', 'done');
		$suspended->setFireAt(new DateTime('2026-10-01'));
		$suspended->setRunningSince(new DateTime('2026-09-01'));

		self::assertSame(['armedWithoutFireAt' => 1, 'suspendedWithClock' => 1, 'orphaned' => 2], $this->measure([$armed], [$suspended]));
		self::assertSame(['gone', 'done'], $this->lookedUp);
	}

	public function testOnlyTaskSubjectsAreLookedUp(): void {
		$armed = $this->timer(1, FlowTimer::STATE_ARMED, 'object', 'gone');
		$armed->setFireAt(new DateTime('2026-10-01'));
		$open = $this->timer(2, FlowTimer::STATE_ARMED, 'task', 'open');
		$open->setFireAt(new DateTime('2026-10-01'));

		self::assertSame(['armedWithoutFireAt' => 0, 'suspendedWithClock' => 0, 'orphaned' => 0], $this->measure([$armed, $open], []));
		self::assertSame(['open'], $this->lookedUp);
	}
}
