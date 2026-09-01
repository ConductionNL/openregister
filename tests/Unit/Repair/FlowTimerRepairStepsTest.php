<?php

/**
 * The seed step imports the descriptor idempotently and the invariant check
 * counts defects without repairing them.
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
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Repair\CheckFlowTimerInvariants;
use OCA\OpenRegister\Repair\SeedFlowTimerRegister;
use OCA\OpenRegister\Service\ConfigurationService;
use OCA\OpenRegister\Tests\Unit\Service\Flow\Timer\InMemoryTimerStore;
use OCP\App\IAppManager;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Repair\SeedFlowTimerRegister
 * @covers \OCA\OpenRegister\Repair\CheckFlowTimerInvariants
 * @covers \OCA\OpenRegister\Db\FlowTimer
 * @covers \OCA\OpenRegister\Db\FlowTimerMapper
 * @covers \OCA\OpenRegister\Db\Task
 * @covers \OCA\OpenRegister\Db\TaskMapper
 */
class FlowTimerRepairStepsTest extends TestCase {

	public function testTheSeedImportsTheDecodedDescriptorWithoutForce(): void {
		$configuration = $this->createMock(ConfigurationService::class);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->with('openregister')->willReturn(realpath(__DIR__ . '/../../..'));
		$configuration->expects(self::once())->method('importFromApp')
			->with('openregister', self::callback(static fn (array $data): bool => count($data['components']['objects']) === 3 && isset($data['components']['schemas']['working-calendar'])), '1.0.0', false)
			->willReturn([]);
		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info')->with(self::stringContains('Flow-timers register imported'));

		$step = new SeedFlowTimerRegister(configurationService: $configuration, appManager: $appManager, logger: new NullLogger());
		self::assertStringContainsString('flow-timers register', $step->getName());
		$step->run($output);
	}//end testTheSeedImportsTheDecodedDescriptorWithoutForce()

	public function testTheSeedWarnsAndNeverThrows(): void {
		$configuration = $this->createMock(ConfigurationService::class);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->willReturn('/nowhere');
		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('warning')->with(self::stringContains('not found'));
		(new SeedFlowTimerRegister(configurationService: $configuration, appManager: $appManager, logger: new NullLogger()))->run($output);

		$failing = $this->createMock(ConfigurationService::class);
		$failing->method('importFromApp')->willThrowException(new RuntimeException('db down'));
		$real = $this->createMock(IAppManager::class);
		$real->method('getAppPath')->willReturn(realpath(__DIR__ . '/../../..'));
		$output2 = $this->createMock(IOutput::class);
		$output2->expects(self::once())->method('warning')->with(self::stringContains('db down'));
		(new SeedFlowTimerRegister(configurationService: $failing, appManager: $real, logger: new NullLogger()))->run($output2);
	}//end testTheSeedWarnsAndNeverThrows()

	public function testTheInvariantCheckCountsDefectsAndCancelsNothing(): void {
		$store = new InMemoryTimerStore(db: $this->createMock(IDBConnection::class));
		$timers = $store->timerMapper();

		$healthy = $this->timer('ok', FlowTimer::STATE_ARMED, 'task-open');
		$healthy->setFireAt(new DateTime('2026-10-01'));
		$timers->insert($healthy);

		$noFireAt = $this->timer('no-fire', FlowTimer::STATE_ARMED, 'task-open');
		$timers->insert($noFireAt);

		$suspendedWithClock = $this->timer('susp', FlowTimer::STATE_SUSPENDED, 'task-open');
		$suspendedWithClock->setRunningSince(new DateTime('2026-09-01'));
		$timers->insert($suspendedWithClock);

		$orphanTerminal = $this->timer('orphan-1', FlowTimer::STATE_ARMED, 'task-done');
		$orphanTerminal->setFireAt(new DateTime('2026-10-01'));
		$timers->insert($orphanTerminal);

		$orphanAbsent = $this->timer('orphan-2', FlowTimer::STATE_SUSPENDED, 'task-gone');
		$timers->insert($orphanAbsent);

		$fired = $this->timer('fired', FlowTimer::STATE_FIRED, 'task-gone');
		$timers->insert($fired);

		$open = new Task();
		$open->setUuid('task-open');
		$open->setState(Task::STATE_ACTIVE);
		$store->tasks['task-open'] = $open;
		$done = new Task();
		$done->setUuid('task-done');
		$done->setState(Task::STATE_COMPLETED);
		$store->tasks['task-done'] = $done;

		$check = new CheckFlowTimerInvariants(timers: $timers, tasks: $store->taskMapper(), logger: new NullLogger());
		self::assertSame(['armedWithoutFireAt' => 1, 'suspendedWithClock' => 1, 'orphaned' => 2], $check->measure());

		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('warning')->with(self::stringContains('1 armed without fire_at, 1 suspended with fire_at or running_since, 2 orphaned'));
		$check->run($output);

		self::assertSame(FlowTimer::STATE_ARMED, $store->timers['orphan-1']->getState(), 'reported, not cancelled');
		self::assertStringContainsString('report', $check->getName());
	}//end testTheInvariantCheckCountsDefectsAndCancelsNothing()

	public function testACleanStoreReportsInfo(): void {
		$store = new InMemoryTimerStore(db: $this->createMock(IDBConnection::class));
		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info')->with(self::stringContains('0 armed without fire_at, 0 suspended with fire_at or running_since, 0 orphaned'));
		(new CheckFlowTimerInvariants(timers: $store->timerMapper(), tasks: $store->taskMapper(), logger: new NullLogger()))->run($output);
	}//end testACleanStoreReportsInfo()

	private function timer(string $uuid, string $state, string $subject): FlowTimer {
		$timer = new FlowTimer();
		$timer->setUuid($uuid);
		$timer->setState($state);
		$timer->setSubjectType('task');
		$timer->setSubjectUuid($subject);

		return $timer;
	}//end timer()
}//end class
