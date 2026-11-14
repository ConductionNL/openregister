<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Notifications\BackgroundJob;

use DateTimeImmutable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Notifications\BackgroundJob\ScheduledFire;
use OCA\OpenRegister\Notifications\BackgroundJob\ScheduledNotificationDelivery;
use OCA\OpenRegister\Notifications\BackgroundJob\ScheduledObjectSources;
use OCA\OpenRegister\Notifications\BackgroundJob\ScheduledReminders;
use OCA\OpenRegister\Notifications\Db\NotificationDedupeState;
use OCA\OpenRegister\Notifications\Db\NotificationDedupeStateMapper;
use OCA\OpenRegister\Notifications\Db\ReminderProgress;
use OCA\OpenRegister\Notifications\Service\Notification\AnnotationNotificationDispatcher;
use OCA\OpenRegister\Notifications\Service\Notification\ScheduledFilterEvaluator;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The reminder step of the scheduled sweep: send, repeat, stop, and keep the state.
 *
 * @spec openspec/changes/service-hours-and-repeating-reminders/specs/notificatie-engine/spec.md
 */
class ScheduledRemindersTest extends TestCase {

	private const REMINDER = [
		'anchor' => 'requestedOn',
		'offset' => ['value' => 7, 'unit' => 'calendarDays'],
		'repeat' => ['interval' => ['value' => 7, 'unit' => 'calendarDays'], 'max' => 3],
		'stopWhen' => ['status' => 'closed'],
	];

	private AnnotationNotificationDispatcher&MockObject $dispatcher;

	private NotificationDedupeStateMapper&MockObject $dedupe;

	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->dispatcher = $this->createMock(AnnotationNotificationDispatcher::class);
		$this->dedupe     = $this->createMock(NotificationDedupeStateMapper::class);
		$this->logger     = $this->createMock(LoggerInterface::class);
	}//end setUp()

	public function testADueReminderIsSentWithItsSequenceAndCounted(): void {
		$this->dedupe->method('findByKey')->willReturn($this->state(sent: 1));
		$this->dispatcher->expects($this->once())->method('dispatch')
			->with($this->anything(), 'scheduled', ['notificationName' => 'chase', 'reminderSequence' => 2]);
		$this->dedupe->expects($this->once())->method('recordReminder')
			->with(
				$this->anything(),
				$this->callback(static fn (ReminderProgress $progress): bool => $progress->sent === 2 && $progress->stopped === null),
				$this->anything(),
				true
			);

		$fire = $this->sweep(status: 'open', now: '2027-03-16T06:00:00Z');

		$this->assertSame(1, $fire->dispatched);
	}//end testADueReminderIsSentWithItsSequenceAndCounted()

	public function testAMetStopConditionSendsNothingAndRecordsWhy(): void {
		$this->dedupe->method('findByKey')->willReturn($this->state(sent: 1));
		$this->dispatcher->expects($this->never())->method('dispatch');
		$this->dedupe->expects($this->once())->method('recordReminder')
			->with(
				$this->anything(),
				$this->callback(static fn (ReminderProgress $progress): bool => $progress->sent === 1 && $progress->stopped === 'stopWhen {"status":"closed"}'),
				$this->anything(),
				false
			);

		$this->sweep(status: 'closed', now: '2027-03-16T06:00:00Z');
	}//end testAMetStopConditionSendsNothingAndRecordsWhy()

	public function testAnExhaustedReminderSendsNoFourthButStaysRemembered(): void {
		$this->dedupe->method('findByKey')->willReturn($this->state(sent: 3));
		$this->dispatcher->expects($this->never())->method('dispatch');
		$this->dedupe->expects($this->once())->method('recordReminder')
			->with($this->anything(), $this->callback(static fn (ReminderProgress $progress): bool => $progress->sent === 3), $this->anything(), false);

		$this->sweep(status: 'open', now: '2027-05-01T06:00:00Z');
	}//end testAnExhaustedReminderSendsNoFourthButStaysRemembered()

	public function testAReminderNotYetDueStoresNothingNew(): void {
		$this->dedupe->method('findByKey')->willReturn(null);
		$this->dispatcher->expects($this->never())->method('dispatch');
		$this->dedupe->expects($this->never())->method('recordReminder');

		$this->sweep(status: 'open', now: '2027-03-02T06:00:00Z');
	}//end testAReminderNotYetDueStoresNothingNew()

	public function testBusinessDaysWithoutACalendarServiceAreLoggedAndSkipped(): void {
		$this->dedupe->method('findByKey')->willReturn(null);
		$this->dispatcher->expects($this->never())->method('dispatch');
		$this->logger->expects($this->once())->method('warning')
			->with($this->stringContains('reminder "chase" on object u-1 not evaluated'));

		$this->sweep(status: 'open', now: '2027-03-16T06:00:00Z', reminder: ['offset' => ['value' => 3, 'unit' => 'businessDays']] + self::REMINDER);
	}//end testBusinessDaysWithoutACalendarServiceAreLoggedAndSkipped()

	public function testOnlyTheNamedRuleFiresFromAReminderDispatch(): void {
		$allows = new \ReflectionMethod(AnnotationNotificationDispatcher::class, 'reminderAllows');
		$plain  = ['trigger' => ['type' => 'scheduled']];
		$chase  = $plain + ['reminder' => self::REMINDER];
		$send   = ['notificationName' => 'chase', 'reminderSequence' => 1];

		$this->assertTrue($allows->invoke(null, $chase, 'chase', $send));
		$this->assertFalse($allows->invoke(null, $plain, 'other', $send));
		$this->assertFalse($allows->invoke(null, $chase, 'chase', ['notificationName' => 'other']));
		$this->assertTrue($allows->invoke(null, $plain, 'other', ['notificationName' => 'other']));
	}//end testOnlyTheNamedRuleFiresFromAReminderDispatch()

	private function state(int $sent): NotificationDedupeState {
		$state = new NotificationDedupeState();
		$state->setFingerprint(sha1('2027-03-01'));
		$state->setReminderSent($sent);

		return $state;
	}//end state()

	private function sweep(string $status, string $now, array $reminder = self::REMINDER): ScheduledFire {
		$schema = new Schema();
		$schema->setId(7);
		$fire = new ScheduledFire(schema: $schema, name: 'chase', trigger: ['type' => 'scheduled', 'intervalSec' => 60], nowDt: new DateTimeImmutable($now));
		$fire->reminder = $reminder;
		$object = new ObjectEntity();
		$object->setUuid('u-1');
		$object->setObject(['requestedOn' => '2027-03-01', 'status' => $status]);

		$reminders = new ScheduledReminders(
			sources: new ScheduledObjectSources($this->createMock(SchemaCatalogInterface::class), new ScheduledFilterEvaluator(logger: $this->logger)),
			delivery: new ScheduledNotificationDelivery($this->dispatcher, $this->dedupe, $this->createMock(IAppConfig::class), $this->logger)
		);
		$reminders->onMatch(fire: $fire, object: $object);

		return $fire;
	}//end sweep()
}//end class
