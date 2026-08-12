<?php

declare(strict_types=1);

namespace Unit\Service\Notification;

use DateTimeImmutable;
use OCA\OpenRegister\Service\Notification\DigestScheduleEvaluator;
use OCA\OpenRegister\Service\Notification\NotificationDeliveryWindowService;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * Covers the fixed-time `digest` schedule evaluator: daily/weekly
 * `lastOccurrence()` computation, `isDue()` semantics (a row queued AFTER
 * the last occurrence waits for the NEXT one), and live re-evaluation
 * across a DST transition.
 */
class DigestScheduleEvaluatorTest extends TestCase {
	private DigestScheduleEvaluator $evaluator;

	protected function setUp(): void {
		parent::setUp();
		$config = $this->createMock(IConfig::class);
		$windowService = new NotificationDeliveryWindowService($config, null);
		$this->evaluator = new DigestScheduleEvaluator($windowService);
	}

	public function testIsValidDigestSpecAcceptsWellFormedDaily(): void {
		$this->assertTrue(
			$this->evaluator->isValidDigestSpec(['schedule' => 'daily', 'at' => '07:00'])
		);
	}

	public function testIsValidDigestSpecRejectsWeeklyWithoutWeekday(): void {
		$this->assertFalse(
			$this->evaluator->isValidDigestSpec(['schedule' => 'weekly', 'at' => '08:00'])
		);
	}

	public function testIsValidDigestSpecAcceptsWellFormedWeekly(): void {
		$this->assertTrue(
			$this->evaluator->isValidDigestSpec(['schedule' => 'weekly', 'at' => '08:00', 'weekday' => 1])
		);
	}

	public function testIsValidDigestSpecRejectsBadTimeFormat(): void {
		$this->assertFalse(
			$this->evaluator->isValidDigestSpec(['schedule' => 'daily', 'at' => '7am'])
		);
	}

	/**
	 * Scenario: "Rule declares a daily fixed-time digest schedule" — events
	 * queued the previous afternoon/evening are due once the flush job
	 * ticks after 07:00 the following morning, and NOT before.
	 */
	public function testDailyIsDueOnlyAfterScheduledTimePasses(): void {
		$digest = ['schedule' => 'daily', 'at' => '07:00', 'timezone' => 'Europe/Amsterdam'];

		// Queued at 14:00 CEST the previous day.
		$enqueuedAt = new DateTimeImmutable('2026-07-11T12:00:00+00:00');

		// Still the previous evening — not due.
		$stillEvening = new DateTimeImmutable('2026-07-11T19:00:00+00:00');
		$this->assertFalse($this->evaluator->isDue($digest, $enqueuedAt, $stillEvening));

		// 06:59 CEST the next morning — not yet due.
		$justBefore = new DateTimeImmutable('2026-07-12T04:59:00+00:00');
		$this->assertFalse($this->evaluator->isDue($digest, $enqueuedAt, $justBefore));

		// 07:00 CEST the next morning — due.
		$atSchedule = new DateTimeImmutable('2026-07-12T05:00:00+00:00');
		$this->assertTrue($this->evaluator->isDue($digest, $enqueuedAt, $atSchedule));
	}

	/**
	 * A row created AFTER the most recent scheduled occurrence must wait
	 * for the NEXT occurrence, not flush immediately alongside an older
	 * batch.
	 */
	public function testRowQueuedAfterOccurrenceWaitsForNextOne(): void {
		$digest = ['schedule' => 'daily', 'at' => '07:00', 'timezone' => 'Europe/Amsterdam'];

		// Queued at 07:30 CEST — just AFTER today's 07:00 occurrence.
		$enqueuedAt = new DateTimeImmutable('2026-07-12T05:30:00+00:00');

		// Later the same day: not due (today's occurrence already passed
		// before this row existed).
		$sameDayLater = new DateTimeImmutable('2026-07-12T12:00:00+00:00');
		$this->assertFalse($this->evaluator->isDue($digest, $enqueuedAt, $sameDayLater));

		// Next morning at 07:00 CEST: due.
		$nextMorning = new DateTimeImmutable('2026-07-13T05:00:00+00:00');
		$this->assertTrue($this->evaluator->isDue($digest, $enqueuedAt, $nextMorning));
	}

	public function testWeeklyOnlyDueOnDeclaredWeekday(): void {
		// 2026-07-13 is a Monday (weekday=1).
		$digest = ['schedule' => 'weekly', 'at' => '08:00', 'weekday' => 1, 'timezone' => 'Europe/Amsterdam'];

		$enqueuedAt = new DateTimeImmutable('2026-07-06T10:00:00+00:00');

		// Tuesday 2026-07-07 08:30 CEST — Monday's occurrence already fired
		// before enqueue would make this odd; use an enqueue BEFORE this
		// week's Monday occurrence and check it's not due until Monday.
		$beforeMonday = new DateTimeImmutable('2026-07-10T08:00:00+00:00');
		$this->assertFalse($this->evaluator->isDue($digest, $enqueuedAt, $beforeMonday));

		$onMonday = new DateTimeImmutable('2026-07-13T06:30:00+00:00');
		$this->assertTrue($this->evaluator->isDue($digest, $enqueuedAt, $onMonday));
	}

	/**
	 * Malformed digest specs fail OPEN (treated as due) so a bad
	 * annotation cannot indefinitely trap events in the queue.
	 */
	public function testMalformedDigestSpecFailsOpen(): void {
		$enqueuedAt = new DateTimeImmutable('2026-07-12T10:00:00+00:00');
		$now = new DateTimeImmutable('2026-07-12T10:00:01+00:00');

		$this->assertTrue($this->evaluator->isDue(['schedule' => 'bogus'], $enqueuedAt, $now));
	}

	/**
	 * Live re-evaluation across a DST transition: `lastOccurrence()` must
	 * resolve the schedule's local time against the CURRENT offset, not an
	 * offset cached at enqueue time.
	 */
	public function testLastOccurrenceIsCorrectAcrossDstTransition(): void {
		$digest = ['schedule' => 'daily', 'at' => '07:00', 'timezone' => 'Europe/Amsterdam'];

		// Evaluated just after the 2026-03-29 spring-forward (CEST, UTC+2).
		// 07:00 CEST local == 05:00 UTC.
		$now = new DateTimeImmutable('2026-03-29T08:00:00+00:00');
		$last = $this->evaluator->lastOccurrence($digest, $now);

		$this->assertSame(
			'2026-03-29T05:00:00+00:00',
			$last->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:sP')
		);
	}
}
