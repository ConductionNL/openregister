<?php

/**
 * The three case listeners: a terminal task or run evaluates the plans it
 * realised, an object update or transition is forwarded with the object's
 * data, and every other event is ignored.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Event\FlowRunTerminalEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\TaskTerminalEvent;
use OCA\OpenRegister\Listener\CaseObjectEventListener;
use OCA\OpenRegister\Listener\CaseRunTerminalListener;
use OCA\OpenRegister\Listener\CaseTaskTerminalListener;
use OCA\OpenRegister\Service\Case\CasePlanService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * Listener coverage.
 *
 * @covers \OCA\OpenRegister\Listener\CaseTaskTerminalListener
 * @covers \OCA\OpenRegister\Listener\CaseRunTerminalListener
 * @covers \OCA\OpenRegister\Listener\CaseObjectEventListener
 * @covers \OCA\OpenRegister\Event\TaskTerminalEvent
 */
class CaseListenersTest extends TestCase {

	/**
	 * A terminal task evaluates by its uuid; an empty uuid and other events are ignored.
	 *
	 * @return void
	 */
	public function testATerminalTaskDrivesItsPlan(): void {
		$plans = $this->createMock(CasePlanService::class);
		$plans->expects($this->once())->method('onRealisationTerminal')->with('task-1');
		$listener = new CaseTaskTerminalListener(plans: $plans);

		$task = new Task();
		$task->setUuid('task-1');
		$event = new TaskTerminalEvent(task: $task);
		$this->assertSame($task, $event->getTask());
		$listener->handle($event);
		$listener->handle(new TaskTerminalEvent(task: new Task()));
		$listener->handle(new Event());
	}//end testATerminalTaskDrivesItsPlan()

	/**
	 * A terminal run evaluates by its uuid; other events are ignored.
	 *
	 * @return void
	 */
	public function testATerminalRunDrivesItsStage(): void {
		$plans = $this->createMock(CasePlanService::class);
		$plans->expects($this->once())->method('onRealisationTerminal')->with('run-1');
		$listener = new CaseRunTerminalListener(plans: $plans);
		$listener->handle(new FlowRunTerminalEvent(runUuid: 'run-1', status: 'completed'));
		$listener->handle(new Event());
	}//end testATerminalRunDrivesItsStage()

	/**
	 * Object updates and transitions are forwarded with the catalog id and
	 * the object's data; other events are not.
	 *
	 * @return void
	 */
	public function testObjectEventsAreForwarded(): void {
		$object = new ObjectEntity();
		$object->setUuid('obj-1');
		$object->setObject(['status' => 'ingetrokken']);
		$plans = $this->createMock(CasePlanService::class);
		$plans->expects($this->exactly(2))->method('onObjectEvent')->willReturnCallback(
			function (string $uuid, string $event, array $payload): void {
				$this->assertSame('obj-1', $uuid);
				$this->assertContains($event, ['object.updated', 'object.transitioned']);
				$this->assertSame('ingetrokken', $payload['status']);
			}
		);
		$listener = new CaseObjectEventListener(plans: $plans);

		$listener->handle(new ObjectUpdatedEvent(newObject: $object, oldObject: new ObjectEntity()));
		$listener->handle(new ObjectTransitionedEvent(object: $object, action: 'x', from: 'a', to: 'b', userId: null, register: '1', schema: '1'));
		$listener->handle(new ObjectUpdatedEvent(newObject: new ObjectEntity(), oldObject: new ObjectEntity()));
		$listener->handle(new Event());
	}//end testObjectEventsAreForwarded()
}//end class
