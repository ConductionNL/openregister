<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Notifications\Service\Notification;

use DateTimeImmutable;
use OCA\OpenRegister\Notifications\Db\ReminderProgress;
use OCA\OpenRegister\Notifications\Service\Notification\ReminderDecision;
use OCA\OpenRegister\Notifications\Service\Notification\ReminderPlan;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar;
use PHPUnit\Framework\TestCase;

/**
 * A reminder's schedule, driven by an explicit clock.
 *
 * @spec openspec/changes/service-hours-and-repeating-reminders/specs/notificatie-engine/spec.md
 */
class ReminderPlanTest extends TestCase {

	private const CHASE = [
		'anchor' => 'requestedOn',
		'offset' => ['value' => 7, 'unit' => 'calendarDays'],
		'repeat' => ['interval' => ['value' => 7, 'unit' => 'calendarDays'], 'max' => 3],
		'stopWhen' => ['attachment' => ['operator' => 'exists']],
	];

	private static function at(string $when): DateTimeImmutable {
		return new DateTimeImmutable($when);
	}//end at()

	private static function calendar(): WorkingCalendar {
		return WorkingCalendar::fromArray(
			definition: ['slug' => 'werkdagen', 'hoursPerWorkingDay' => 8, 'workingWeekdays' => [1, 2, 3, 4, 5], 'dayStartsAt' => '09:00', 'timezone' => 'UTC']
		);
	}//end calendar()

	public function testAReminderCountsBusinessDaysBeforeTheHearing(): void {
		$plan = new ReminderPlan(reminder: ['anchor' => 'hearingDate', 'offset' => ['value' => -3, 'unit' => 'businessDays']]);

		// Hearing on Monday 15 March 2027: three business days before is Wednesday 10 March.
		$decision = $plan->decide(anchorValue: '2027-03-15', stored: null, now: self::at('2027-03-10T12:00:00Z'), calendar: self::calendar());

		$this->assertTrue($plan->needsCalendar());
		$this->assertSame(ReminderDecision::DUE, $decision->kind);
		$this->assertSame('2027-03-10', $decision->dueAt?->format('Y-m-d'));
		$this->assertSame(1, $decision->sequence());
	}//end testAReminderCountsBusinessDaysBeforeTheHearing()

	public function testNotYetDueWaits(): void {
		$plan     = new ReminderPlan(reminder: self::CHASE);
		$decision = $plan->decide(anchorValue: '2027-03-01', stored: null, now: self::at('2027-03-07T23:59:00Z'), calendar: null);

		$this->assertSame(ReminderDecision::WAIT, $decision->kind);
		$this->assertSame('2027-03-08T00:00:00+00:00', $decision->dueAt?->format(DATE_ATOM));
	}//end testNotYetDueWaits()

	public function testAChaseRunsThreeTimesSevenDaysApartAndStops(): void {
		$plan   = new ReminderPlan(reminder: self::CHASE);
		$stored = null;
		$sent   = [];
		// A daily sweep for a month while the condition stays unmet.
		for ($day = 0; $day < 31; $day++) {
			$now      = self::at('2027-03-01T06:00:00Z')->modify(sprintf('+%d days', $day));
			$decision = $plan->decide(anchorValue: '2027-03-01', stored: $stored, now: $now, calendar: null);
			$stored   = $decision->progress;
			if ($decision->kind === ReminderDecision::DUE) {
				$sent[] = $now->format('Y-m-d') . '#' . $decision->sequence();
				$stored = new ReminderProgress(fingerprint: $stored->fingerprint, sent: $decision->sequence());
			}
		}

		$this->assertSame(['2027-03-08#1', '2027-03-15#2', '2027-03-22#3'], $sent);
		$this->assertSame(ReminderDecision::DONE, $plan->decide(anchorValue: '2027-03-01', stored: $stored, now: self::at('2027-04-30T00:00:00Z'), calendar: null)->kind);
	}//end testAChaseRunsThreeTimesSevenDaysApartAndStops()

	public function testWithoutARepeatOneReminderIsSent(): void {
		$plan   = new ReminderPlan(reminder: ['anchor' => 'd', 'offset' => ['value' => 1, 'unit' => 'calendarDays']]);
		$sent   = new ReminderProgress(fingerprint: sha1('2027-03-01'), sent: 1);

		$this->assertSame(1, $plan->total());
		$this->assertSame(ReminderDecision::DONE, $plan->decide(anchorValue: '2027-03-01', stored: $sent, now: self::at('2027-06-01T00:00:00Z'), calendar: null)->kind);
	}//end testWithoutARepeatOneReminderIsSent()

	public function testAMovedAnchorReArmsFromTheNewDate(): void {
		$plan   = new ReminderPlan(reminder: self::CHASE);
		$stored = new ReminderProgress(fingerprint: sha1('2027-03-01'), sent: 3);

		// The hearing moved two weeks later: the count starts over from the new date.
		$decision = $plan->decide(anchorValue: '2027-03-15', stored: $stored, now: self::at('2027-03-16T00:00:00Z'), calendar: null);

		$this->assertSame(ReminderDecision::WAIT, $decision->kind);
		$this->assertTrue($decision->rearmed);
		$this->assertSame(0, $decision->progress?->sent);
		$this->assertSame(sha1('2027-03-15'), $decision->progress?->fingerprint);
		$this->assertSame('2027-03-22', $decision->dueAt?->format('Y-m-d'));
	}//end testAMovedAnchorReArmsFromTheNewDate()

	public function testAStoppedReminderDoesNotReArm(): void {
		$plan    = new ReminderPlan(reminder: self::CHASE);
		$stopped = new ReminderProgress(fingerprint: sha1('2027-03-01'), sent: 1, stopped: $plan->stopReason());

		foreach (['2027-03-01', '2027-04-01'] as $anchor) {
			$decision = $plan->decide(anchorValue: $anchor, stored: $stopped, now: self::at('2027-05-01T00:00:00Z'), calendar: null);
			$this->assertSame(ReminderDecision::STOPPED, $decision->kind);
			$this->assertSame($stopped, $decision->progress);
		}
	}//end testAStoppedReminderDoesNotReArm()

	public function testAClearedAnchorArmsNothing(): void {
		$plan = new ReminderPlan(reminder: self::CHASE);

		foreach ([null, '', 'someday', '2027-02-30', 12] as $value) {
			$this->assertSame(ReminderDecision::NO_ANCHOR, $plan->decide(anchorValue: $value, stored: null, now: self::at('2027-05-01T00:00:00Z'), calendar: null)->kind);
		}
	}//end testAClearedAnchorArmsNothing()

	public function testTheStopReasonNamesTheCondition(): void {
		$plan = new ReminderPlan(reminder: self::CHASE);

		$this->assertSame('stopWhen {"attachment":{"operator":"exists"}}', $plan->stopReason());
		$this->assertSame(['attachment' => ['operator' => 'exists']], $plan->stopWhen());
		$this->assertFalse($plan->needsCalendar());
	}//end testTheStopReasonNamesTheCondition()

	public function testBusinessDaysWithoutACalendarAreRefused(): void {
		$plan = new ReminderPlan(reminder: ['anchor' => 'd', 'offset' => ['value' => -3, 'unit' => 'businessDays']]);

		$this->expectException(\Throwable::class);
		$plan->decide(anchorValue: '2027-03-15', stored: null, now: self::at('2027-03-15T00:00:00Z'), calendar: null);
	}//end testBusinessDaysWithoutACalendarAreRefused()
}//end class
