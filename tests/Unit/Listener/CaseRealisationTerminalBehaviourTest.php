<?php

/**
 * Terminal tasks and runs drive the case plan by their trimmed uuid.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Event\FlowRunTerminalEvent;
use OCA\OpenRegister\Event\TaskTerminalEvent;
use OCA\OpenRegister\Listener\CaseRunTerminalListener;
use OCA\OpenRegister\Listener\CaseTaskTerminalListener;
use OCA\OpenRegister\Service\Case\CasePlanServiceInterface;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * Which terminal events reach the case plan, and with which uuid.
 */
class CaseRealisationTerminalBehaviourTest extends TestCase {
	private function task(?string $uuid): TaskTerminalEvent {
		$task = new Task();
		$task->setUuid($uuid);
		return new TaskTerminalEvent($task);
	}

	public function testATerminalTaskReachesThePlanWithItsTrimmedUuid(): void {
		$plans = $this->createMock(CasePlanServiceInterface::class);
		$plans->expects($this->once())->method('onRealisationTerminal')->with('task-7');

		(new CaseTaskTerminalListener($plans))->handle($this->task('  task-7 '));
	}

	public function testATaskWithABlankUuidIsIgnored(): void {
		$plans = $this->createMock(CasePlanServiceInterface::class);
		$plans->expects($this->never())->method('onRealisationTerminal');
		$listener = new CaseTaskTerminalListener($plans);

		$listener->handle($this->task('   '));
		$listener->handle($this->task(null));
	}

	public function testTheTaskListenerIgnoresARunEvent(): void {
		$plans = $this->createMock(CasePlanServiceInterface::class);
		$plans->expects($this->never())->method('onRealisationTerminal');

		(new CaseTaskTerminalListener($plans))->handle(new FlowRunTerminalEvent('run-1', 'completed'));
	}

	public function testATerminalRunReachesThePlanWithItsRunUuid(): void {
		$plans = $this->createMock(CasePlanServiceInterface::class);
		$plans->expects($this->once())->method('onRealisationTerminal')->with('run-42');

		(new CaseRunTerminalListener($plans))->handle(new FlowRunTerminalEvent('run-42', 'failed'));
	}

	public function testTheRunListenerIgnoresATaskEvent(): void {
		$plans = $this->createMock(CasePlanServiceInterface::class);
		$plans->expects($this->never())->method('onRealisationTerminal');
		$listener = new CaseRunTerminalListener($plans);

		$listener->handle($this->task('task-1'));
		$listener->handle(new Event());
	}
}
