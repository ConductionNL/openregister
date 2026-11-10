<?php

/**
 * The working-calendar delete guard, branch by branch.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Listener\WorkingCalendarDeleteGuardListener;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarWriteGuardInterface;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A calendar open timers still name refuses its delete; anything else passes.
 *
 * @covers \OCA\OpenRegister\Listener\WorkingCalendarDeleteGuardListener
 * @uses \OCA\OpenRegister\Event\AbstractObjectEntityEvent
 * @uses \OCA\OpenRegister\Event\AbstractPreWriteObjectEvent
 * @uses \OCA\OpenRegister\Event\ObjectDeletingEvent
 * @uses \OCA\OpenRegister\Event\WriteVetoState
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 */
final class WorkingCalendarDeleteGuardListenerBehaviourTest extends TestCase {

	private WorkingCalendarWriteGuardInterface&MockObject $guard;

	private LoggerInterface&MockObject $logger;

	/**
	 * Fresh collaborators per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->guard = $this->createMock(WorkingCalendarWriteGuardInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->guard->method('blockedMessage')->willReturn('blocked');
	}//end setUp()

	/**
	 * Handle a delete of an object with the given data.
	 *
	 * @param array|null $data The object data.
	 *
	 * @return ObjectDeletingEvent The handled event.
	 */
	private function handled(?array $data): ObjectDeletingEvent {
		$object = new ObjectEntity();
		if ($data !== null) {
			$object->setObject($data);
		}

		$event = new ObjectDeletingEvent(object: $object);
		(new WorkingCalendarDeleteGuardListener(guard: $this->guard, logger: $this->logger))->handle($event);
		return $event;
	}//end handled()

	/**
	 * Open timers on the calendar refuse the delete with a 409 naming them.
	 *
	 * @return void
	 */
	public function testACalendarWithOpenTimersRefusesItsDelete(): void {
		$this->guard->method('isWorkingCalendar')->willReturn(true);
		$this->guard->expects($this->once())->method('deletionBlockers')->with('nl-gov')
			->willReturn(['count' => 2, 'timers' => ['t1', 't2']]);

		$event = $this->handled(data: ['slug' => ' nl-gov ']);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(
			[
				'code' => WorkingCalendarDeleteGuardListener::ERROR_CODE,
				'message' => 'blocked',
				'status' => 409,
				'calendar' => 'nl-gov',
				'openTimers' => 2,
				'timers' => ['t1', 't2'],
			],
			$event->getErrors()
		);
	}//end testACalendarWithOpenTimersRefusesItsDelete()

	/**
	 * A calendar no timer names is deletable.
	 *
	 * @return void
	 */
	public function testACalendarWithoutTimersIsDeletable(): void {
		$this->guard->method('isWorkingCalendar')->willReturn(true);
		$this->guard->method('deletionBlockers')->willReturn(['count' => 0, 'timers' => []]);

		$event = $this->handled(data: ['slug' => 'nl-gov']);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame([], $event->getErrors());
	}//end testACalendarWithoutTimersIsDeletable()

	/**
	 * A calendar without slug is never checked.
	 *
	 * @return void
	 */
	public function testACalendarWithoutSlugIsNotChecked(): void {
		$this->guard->method('isWorkingCalendar')->willReturn(true);
		$this->guard->expects($this->never())->method('deletionBlockers');

		$this->assertFalse($this->handled(data: ['slug' => '  '])->isPropagationStopped());
		$this->assertFalse($this->handled(data: [])->isPropagationStopped());
	}//end testACalendarWithoutSlugIsNotChecked()

	/**
	 * An object that is no working calendar is never checked.
	 *
	 * @return void
	 */
	public function testAnObjectThatIsNoCalendarIsNotChecked(): void {
		$this->guard->method('isWorkingCalendar')->willReturn(false);
		$this->guard->expects($this->never())->method('deletionBlockers');

		$this->assertFalse($this->handled(data: ['slug' => 'nl-gov'])->isPropagationStopped());
	}//end testAnObjectThatIsNoCalendarIsNotChecked()

	/**
	 * A guard that throws lets the delete through and logs it.
	 *
	 * @return void
	 */
	public function testAFailingGuardFailsOpenAndLogs(): void {
		$this->guard->method('isWorkingCalendar')->willReturn(true);
		$this->guard->method('deletionBlockers')->willThrowException(new RuntimeException('no table'));
		$this->logger->expects($this->once())->method('warning')
			->with($this->stringContains('allowing the delete: no table'), $this->callback(static fn (array $c): bool => $c['exception'] === RuntimeException::class));

		$this->assertFalse($this->handled(data: ['slug' => 'nl-gov'])->isPropagationStopped());
	}//end testAFailingGuardFailsOpenAndLogs()

	/**
	 * Other events are ignored.
	 *
	 * @return void
	 */
	public function testOtherEventsAreIgnored(): void {
		$this->guard->expects($this->never())->method('isWorkingCalendar');
		$event = new Event();

		(new WorkingCalendarDeleteGuardListener(guard: $this->guard, logger: $this->logger))->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testOtherEventsAreIgnored()
}//end class
