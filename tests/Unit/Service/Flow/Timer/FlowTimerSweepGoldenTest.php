<?php

/**
 * Golden test for FlowTimerSweep::run(): every collaborator call it makes, in
 * order, and the result it returns, over batch sizes, truncation in each
 * phase, failures in each phase, and with and without the task sweep.
 * Re-record with UPDATE_GOLDEN=1.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Timer
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Timer;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Db\FlowTimer;
use OCA\OpenRegister\Db\FlowTimerMapper;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerService;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerSweep;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerSweepTasks;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerSweepTimers;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarService;
use OCA\OpenRegister\Service\Task\TaskServiceInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Timer\FlowTimerSweep
 */
class FlowTimerSweepGoldenTest extends TestCase {

	/**
	 * @var string
	 */
	private const FIXTURE = __DIR__ . '/fixtures/flow-timer-sweep-golden.json';

	/**
	 * Every collaborator call, in order.
	 *
	 * @var array<int, array>
	 */
	private array $calls = [];

	private static function timer(string $uuid): FlowTimer {
		$timer = new FlowTimer();
		$timer->setUuid($uuid);

		return $timer;
	}//end timer()

	private static function task(string $uuid, string $onTimeout, ?string $expiresAt): Task {
		$task = new Task();
		$task->setUuid($uuid);
		$task->setOnTimeout($onTimeout);
		if ($expiresAt !== null) {
			$task->setExpiresAt(new DateTime($expiresAt));
		}

		return $task;
	}//end task()

	/**
	 * Build a sweep whose collaborators answer from the scenario and log every call.
	 *
	 * @param array $scenario The scenario.
	 *
	 * @return FlowTimerSweep The sweep.
	 */
	private function sweep(array $scenario): FlowTimerSweep {
		$log = function (string $call, array $args): void {
			$this->calls[] = [$call => $args];
		};
		$at = static fn (DateTimeInterface $now): string => $now->format('c');

		$timers = $this->createMock(FlowTimerMapper::class);
		$timers->method('findDueExpiries')->willReturnCallback(
			static function (DateTimeInterface $now, int $limit) use ($log, $at, $scenario): array {
				$log('findDueExpiries', [$at($now), $limit]);
				return array_map([self::class, 'timer'], $scenario['expiries'] ?? []);
			}
		);
		$timers->method('findDueRungs')->willReturnCallback(
			static function (DateTimeInterface $now, int $limit) use ($log, $at, $scenario): array {
				$log('findDueRungs', [$at($now), $limit]);
				return array_map([self::class, 'timer'], $scenario['rungs'] ?? []);
			}
		);

		$service = $this->createMock(FlowTimerService::class);
		$service->method('fireExpiry')->willReturnCallback(
			static function (FlowTimer $timer, DateTimeInterface $now) use ($log, $at, $scenario): bool {
				$log('fireExpiry', [$timer->getUuid(), $at($now)]);
				$answer = ($scenario['fireExpiry'][$timer->getUuid()] ?? true);
				if ($answer === 'throw') {
					throw new RuntimeException('expiry broke for ' . $timer->getUuid());
				}

				return $answer;
			}
		);
		$service->method('fireRungs')->willReturnCallback(
			static function (FlowTimer $timer, DateTimeInterface $now) use ($log, $at, $scenario): int {
				$log('fireRungs', [$timer->getUuid(), $at($now)]);
				$answer = ($scenario['fireRungs'][$timer->getUuid()] ?? 1);
				if ($answer === 'throw') {
					throw new RuntimeException('rungs broke for ' . $timer->getUuid());
				}

				return $answer;
			}
		);

		$calendars = $this->createMock(WorkingCalendarService::class);
		$calendars->method('reset')->willReturnCallback(static function () use ($log): void {
			$log('reset', []);
		});

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			static function (string $message, array $context = []) use ($log): void {
				$exception = ($context['exception'] ?? null);
				if ($exception instanceof Throwable) {
					$context['exception'] = get_class($exception) . ': ' . $exception->getMessage();
				}

				$log('error', [$message, $context]);
			}
		);

		$tasks = null;
		$taskService = null;
		if (array_key_exists('tasks', $scenario) === true) {
			$tasks = $this->createMock(TaskMapper::class);
			$tasks->method('findDueTimeouts')->willReturnCallback(
				static function (DateTimeInterface $now, int $limit) use ($log, $at, $scenario): array {
					$log('findDueTimeouts', [$at($now), $limit]);
					return array_map(static fn (array $row): Task => self::task(...$row), $scenario['tasks']);
				}
			);
		}

		if (($scenario['taskService'] ?? array_key_exists('tasks', $scenario)) === true) {
			$taskService = $this->createMock(TaskServiceInterface::class);
			$taskService->method('applyTimerOutcome')->willReturnCallback(
				static function (string $uuid, string $outcome, string $source, string $reason) use ($log, $scenario): Task {
					$log('applyTimerOutcome', [$uuid, $outcome, $source, $reason]);
					if (in_array($uuid, ($scenario['taskFails'] ?? []), true) === true) {
						throw new RuntimeException('task broke for ' . $uuid);
					}

					return self::task($uuid, $outcome, null);
				}
			);
		}

		return new FlowTimerSweep(new FlowTimerSweepTimers($timers, $service, $calendars), $logger, new FlowTimerSweepTasks($tasks, $taskService));
	}//end sweep()

	private static function scenarios(): array {
		return [
			'nothing due' => ['batch' => 10],
			'batch zero is one' => ['batch' => 0, 'expiries' => ['e1']],
			'batch negative is one' => ['batch' => -5, 'rungs' => ['r1']],
			'expiries counted when fired' => ['batch' => 10, 'expiries' => ['e1', 'e2', 'e3'], 'fireExpiry' => ['e2' => false]],
			'expiries truncate' => ['batch' => 2, 'expiries' => ['e1', 'e2']],
			'rungs summed' => ['batch' => 10, 'rungs' => ['r1', 'r2'], 'fireRungs' => ['r1' => 3, 'r2' => 0]],
			'rungs truncate' => ['batch' => 2, 'rungs' => ['r1', 'r2', 'r3']],
			'expiry failure continues' => ['batch' => 10, 'expiries' => ['e1', 'e2'], 'fireExpiry' => ['e1' => 'throw'], 'rungs' => ['r1']],
			'rung failure continues' => ['batch' => 10, 'rungs' => ['r1', 'r2'], 'fireRungs' => ['r1' => 'throw']],
			'task sweep without mapper' => ['batch' => 10, 'taskService' => true],
			'tasks applied' => [
				'batch' => 10,
				'tasks' => [['t1', 'complete', '2026-09-01T08:00:00+00:00'], ['t2', 'escalate', null]],
			],
			'task failure continues' => [
				'batch' => 10,
				'tasks' => [['t1', 'complete', '2026-09-01T08:00:00+00:00'], ['t2', 'cancel', '2026-09-01T09:00:00+00:00']],
				'taskFails' => ['t1'],
			],
			'tasks truncate' => ['batch' => 1, 'tasks' => [['t1', 'complete', null]]],
			'tasks without service' => ['batch' => 10, 'tasks' => [['t1', 'complete', null]], 'taskService' => false],
			'everything' => [
				'batch' => 2,
				'expiries' => ['e1'],
				'fireExpiry' => ['e1' => 'throw'],
				'rungs' => ['r1'],
				'fireRungs' => ['r1' => 'throw'],
				'tasks' => [['t1', 'complete', null], ['t2', 'complete', null]],
				'taskFails' => ['t2'],
			],
		];
	}//end scenarios()

	public function testFlowTimerSweepMatchesGolden(): void {
		$now = new DateTimeImmutable('2026-09-01T10:00:00+00:00');

		$actual = [];
		foreach (self::scenarios() as $name => $scenario) {
			$this->calls = [];
			$result = $this->sweep($scenario)->run($now, $scenario['batch']);
			$actual[$name] = ['result' => $result, 'calls' => $this->calls];
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			if (is_dir(dirname(self::FIXTURE)) === false) {
				mkdir(dirname(self::FIXTURE));
			}

			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string)file_get_contents(self::FIXTURE), true);
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, $actual[$name] ?? null, $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testFlowTimerSweepMatchesGolden()
}//end class
