<?php

/**
 * OpenRegister - consecutive edits merge only under an administered window.
 *
 * Pins the default (nothing merges) and the promise a merged entry makes (it
 * says how many edits it covers). The clock is passed in rather than read, so
 * the five-minute case takes no time to test.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Audit
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/enhanced-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Audit;

use DateTimeImmutable;
use OCA\OpenRegister\Service\Audit\AuditAggregationService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Audit\AuditAggregationService
 */
final class AuditAggregationServiceTest extends TestCase {

	/**
	 * The service, over an app config answering one stored window.
	 *
	 * @param int $stored The window the instance has stored.
	 *
	 * @return AuditAggregationService The service.
	 */
	private function service(int $stored = 0): AuditAggregationService {
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueInt')->willReturn($stored);

		return new AuditAggregationService($config);
	}//end service()

	/**
	 * Order is not merged away by default.
	 *
	 * An instance nobody administered records every edit separately, so two
	 * edits a minute apart are two entries in order.
	 *
	 * @return void
	 */
	public function testNothingMergesByDefault(): void {
		$service = $this->service();

		$this->assertSame(0, $service->windowSeconds());
		$this->assertFalse(
			$service->isWithinWindow(
				previous: new DateTimeImmutable('2026-09-15 10:00:00'),
				now: new DateTimeImmutable('2026-09-15 10:01:00')
			)
		);
	}//end testNothingMergesByDefault()

	/**
	 * A set window merges what falls inside it and nothing outside it.
	 *
	 * @return void
	 */
	public function testASetWindowMergesOnlyWhatFallsInside(): void {
		$service = $this->service(stored: 300);
		$start = new DateTimeImmutable('2026-09-15 10:00:00');

		$this->assertTrue(
			$service->isWithinWindow(previous: $start, now: $start->modify('+4 minutes'))
		);
		$this->assertFalse(
			$service->isWithinWindow(previous: $start, now: $start->modify('+6 minutes'))
		);
	}//end testASetWindowMergesOnlyWhatFallsInside()

	/**
	 * A merged entry says what it merged.
	 *
	 * Three edits inside the window fold into one entry naming three.
	 *
	 * @return void
	 */
	public function testAMergedEntryNamesHowManyEditsItCovers(): void {
		$service = $this->service(stored: 300);

		$first = ['status' => ['old' => 'ontvangen', 'new' => 'in behandeling']];
		$second = $service->fold(
			previous: $first,
			incoming: ['status' => ['old' => 'in behandeling', 'new' => 'afgehandeld']]
		);
		$third = $service->fold(
			previous: $second,
			incoming: ['toelichting' => ['old' => null, 'new' => 'Afgerond']]
		);

		$this->assertSame(3, $third['aggregation']['edits']);

		// The span, not the last hop: what the value was when the merged entry
		// began and what it is now.
		$this->assertSame('ontvangen', $third['status']['old']);
		$this->assertSame('afgehandeld', $third['status']['new']);
		$this->assertSame('Afgerond', $third['toelichting']['new']);
	}//end testAMergedEntryNamesHowManyEditsItCovers()

	/**
	 * Every shape a fold meets keeps its place: a malformed edit count restarts at one, a property
	 * without an old value or not yet seen joins as it arrived, a change without a new value leaves the
	 * span alone, and the incoming aggregation marker never overrides the count.
	 *
	 * @return void
	 */
	public function testAFoldOfEveryShapeIsPinned(): void {
		$service = $this->service(stored: 300);

		$this->assertSame(
			[
				'kept' => ['old' => 'a', 'new' => 'b'],
				'span' => ['old' => 'first', 'new' => 'last'],
				'scalar' => ['old' => 'x', 'new' => 'y'],
				'noOld' => ['new' => 'z'],
				'fresh' => ['old' => null, 'new' => 'n'],
				'aggregation' => ['edits' => 5],
			],
			$service->fold(
				previous: [
					'kept' => ['old' => 'a', 'new' => 'b'],
					'span' => ['old' => 'first', 'new' => 'middle'],
					'scalar' => 'not-a-change',
					'noOld' => ['new' => 'q'],
					'aggregation' => ['edits' => '4'],
				],
				incoming: [
					'kept' => 'not-a-change',
					'span' => ['old' => 'middle', 'new' => 'last'],
					'scalar' => ['old' => 'x', 'new' => 'y'],
					'noOld' => ['new' => 'z'],
					'fresh' => ['old' => null, 'new' => 'n'],
					'aggregation' => ['edits' => 99],
				]
			)
		);

		$this->assertSame(
			['a' => ['old' => 1, 'new' => 2], 'aggregation' => ['edits' => 2]],
			$service->fold(previous: ['aggregation' => ['edits' => 'many']], incoming: ['a' => ['old' => 1, 'new' => 2]])
		);
		$this->assertSame(
			['a' => ['old' => 1, 'new' => 3], 'aggregation' => ['edits' => 2]],
			$service->fold(previous: ['a' => ['old' => 1, 'new' => 2], 'aggregation' => 'broken'], incoming: ['a' => ['old' => 2, 'new' => 3]])
		);
	}//end testAFoldOfEveryShapeIsPinned()

	/**
	 * A window above the seal interval is clamped, not stored as asked.
	 *
	 * Beyond the seal interval the entry is in the hash chain and cannot be
	 * amended, so a longer window would merge only the edits that happened to
	 * arrive before the sweep.
	 *
	 * @return void
	 */
	public function testAWindowAboveTheSealIntervalIsClamped(): void {
		$service = $this->service();

		$this->assertSame(300, $service->clamp(seconds: 3600));
		$this->assertSame(0, $service->clamp(seconds: -5));
		$this->assertSame(60, $service->clamp(seconds: 60));
	}//end testAWindowAboveTheSealIntervalIsClamped()

	/**
	 * A stored window above the ceiling still reads as the ceiling.
	 *
	 * The clamp is on the way out as well as on the way in: a value written
	 * around this service, by a migration or by hand, cannot make the trail
	 * behave in a way the class says is impossible.
	 *
	 * @return void
	 */
	public function testAStoredWindowAboveTheCeilingReadsAsTheCeiling(): void {
		$this->assertSame(300, $this->service(stored: 86400)->windowSeconds());
	}//end testAStoredWindowAboveTheCeilingReadsAsTheCeiling()

	/**
	 * An edit that arrives before the entry it would merge into never merges.
	 *
	 * @return void
	 */
	public function testAnEditBeforeTheEntryNeverMerges(): void {
		$service = $this->service(stored: 300);
		$start = new DateTimeImmutable('2026-09-15 10:05:00');

		$this->assertFalse(
			$service->isWithinWindow(previous: $start, now: $start->modify('-1 minute'))
		);
	}//end testAnEditBeforeTheEntryNeverMerges()
}//end class
