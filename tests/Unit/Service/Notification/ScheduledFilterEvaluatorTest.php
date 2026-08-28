<?php

/**
 * Unit tests for ScheduledFilterEvaluator.
 *
 * Covers Phase 1 of notification-engine-scheduled-conditions:
 *   - scalar back-compat;
 *   - each operator happy + negative paths (equals, notEquals, withinNext, olderThan);
 *   - window boundaries (exactly now, exactly now+duration);
 *   - date-only vs date-time values;
 *   - missing fields;
 *   - unparsable values fail closed;
 *   - AND combinations.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace Unit\Service\Notification;

use DateTimeImmutable;
use OCA\OpenRegister\Service\Notification\ScheduledFilterEvaluator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ScheduledFilterEvaluatorTest extends TestCase {

	private ScheduledFilterEvaluator $evaluator;

	private DateTimeImmutable $now;

	protected function setUp(): void {
		$this->evaluator = new ScheduledFilterEvaluator(logger: new NullLogger());
		$this->now = new DateTimeImmutable('2026-06-11T12:00:00+00:00');
	}

	public function testEmptyFilterMatches(): void {
		$this->assertTrue($this->evaluator->matches(objectData: ['status' => 'open'], filter: [], now: $this->now));
	}

	public function testScalarEqualityMatchesByteForByte(): void {
		$this->assertTrue(
			$this->evaluator->matches(
				objectData: ['status' => 'open'],
				filter: ['status' => 'open'],
				now: $this->now
			)
		);

		// Strict equality — different types do NOT match (preserves v1 behaviour).
		$this->assertFalse(
			$this->evaluator->matches(
				objectData: ['count' => 1],
				filter: ['count' => '1'],
				now: $this->now
			)
		);
	}

	public function testScalarEqualityFailsOnMismatch(): void {
		$this->assertFalse(
			$this->evaluator->matches(
				objectData: ['status' => 'done'],
				filter: ['status' => 'open'],
				now: $this->now
			)
		);
	}

	public function testScalarEqualityFailsOnMissingField(): void {
		$this->assertFalse(
			$this->evaluator->matches(
				objectData: [],
				filter: ['status' => 'open'],
				now: $this->now
			)
		);
	}

	public function testEqualsOperatorHappy(): void {
		$this->assertTrue(
			$this->evaluator->matches(
				objectData: ['status' => 'open'],
				filter: ['status' => ['operator' => 'equals', 'value' => 'open']],
				now: $this->now
			)
		);
	}

	public function testEqualsOperatorNegative(): void {
		$this->assertFalse(
			$this->evaluator->matches(
				objectData: ['status' => 'done'],
				filter: ['status' => ['operator' => 'equals', 'value' => 'open']],
				now: $this->now
			)
		);
	}

	public function testNotEqualsOperatorHappy(): void {
		$this->assertTrue(
			$this->evaluator->matches(
				objectData: ['status' => 'open'],
				filter: ['status' => ['operator' => 'notEquals', 'value' => 'done']],
				now: $this->now
			)
		);
	}

	public function testNotEqualsOperatorNegative(): void {
		$this->assertFalse(
			$this->evaluator->matches(
				objectData: ['status' => 'done'],
				filter: ['status' => ['operator' => 'notEquals', 'value' => 'done']],
				now: $this->now
			)
		);
	}

	public function testNotEqualsTreatsMissingFieldAsNotEqualToNonNull(): void {
		$this->assertTrue(
			$this->evaluator->matches(
				objectData: [],
				filter: ['status' => ['operator' => 'notEquals', 'value' => 'done']],
				now: $this->now
			)
		);
	}

	public function testNotEqualsTreatsExplicitNullVsNullAsEqual(): void {
		$this->assertFalse(
			$this->evaluator->matches(
				objectData: ['status' => null],
				filter: ['status' => ['operator' => 'notEquals', 'value' => null]],
				now: $this->now
			)
		);
	}

	public function testWithinNextHappy(): void {
		// dueDate 12 hours from now, window is next 24h.
		$this->assertTrue(
			$this->evaluator->matches(
				objectData: ['dueDate' => '2026-06-12T00:00:00+00:00'],
				filter: ['dueDate' => ['operator' => 'withinNext', 'value' => 'PT24H']],
				now: $this->now
			)
		);
	}

	public function testWithinNextRejectsPast(): void {
		$this->assertFalse(
			$this->evaluator->matches(
				objectData: ['dueDate' => '2026-06-10T00:00:00+00:00'],
				filter: ['dueDate' => ['operator' => 'withinNext', 'value' => 'PT24H']],
				now: $this->now
			)
		);
	}

	public function testWithinNextRejectsBeyondWindow(): void {
		$this->assertFalse(
			$this->evaluator->matches(
				objectData: ['dueDate' => '2026-06-13T00:00:00+00:00'],
				filter: ['dueDate' => ['operator' => 'withinNext', 'value' => 'PT24H']],
				now: $this->now
			)
		);
	}

	public function testWithinNextLowerBoundExactlyNowIsExcluded(): void {
		// Field date == now → strictly greater-than fails (half-open lower bound).
		$this->assertFalse(
			$this->evaluator->matches(
				objectData: ['dueDate' => '2026-06-11T12:00:00+00:00'],
				filter: ['dueDate' => ['operator' => 'withinNext', 'value' => 'PT24H']],
				now: $this->now
			)
		);
	}

	public function testWithinNextUpperBoundExactlyAtIsIncluded(): void {
		// Field date == now + 24h → inclusive upper bound matches.
		$this->assertTrue(
			$this->evaluator->matches(
				objectData: ['dueDate' => '2026-06-12T12:00:00+00:00'],
				filter: ['dueDate' => ['operator' => 'withinNext', 'value' => 'PT24H']],
				now: $this->now
			)
		);
	}

	public function testWithinNextAcceptsDateOnlyValue(): void {
		// Date-only at midnight tomorrow is 12 hours from "now" (12:00 today).
		$this->assertTrue(
			$this->evaluator->matches(
				objectData: ['dueDate' => '2026-06-12'],
				filter: ['dueDate' => ['operator' => 'withinNext', 'value' => 'PT24H']],
				now: $this->now
			)
		);
	}

	public function testOlderThanHappy(): void {
		$this->assertTrue(
			$this->evaluator->matches(
				objectData: ['createdAt' => '2026-05-01T00:00:00+00:00'],
				filter: ['createdAt' => ['operator' => 'olderThan', 'value' => 'P30D']],
				now: $this->now
			)
		);
	}

	public function testOlderThanRejectsRecent(): void {
		$this->assertFalse(
			$this->evaluator->matches(
				objectData: ['createdAt' => '2026-06-10T00:00:00+00:00'],
				filter: ['createdAt' => ['operator' => 'olderThan', 'value' => 'P30D']],
				now: $this->now
			)
		);
	}

	public function testOlderThanRejectsExactlyAtThreshold(): void {
		// 30 days before now = 2026-05-12T12:00 — strict less-than means equal is OUT.
		$this->assertFalse(
			$this->evaluator->matches(
				objectData: ['createdAt' => '2026-05-12T12:00:00+00:00'],
				filter: ['createdAt' => ['operator' => 'olderThan', 'value' => 'P30D']],
				now: $this->now
			)
		);
	}

	public function testWithinNextFailsClosedOnMissingField(): void {
		$this->assertFalse(
			$this->evaluator->matches(
				objectData: [],
				filter: ['dueDate' => ['operator' => 'withinNext', 'value' => 'PT24H']],
				now: $this->now
			)
		);
	}

	public function testWithinNextFailsClosedOnUnparsableDate(): void {
		$this->assertFalse(
			$this->evaluator->matches(
				objectData: ['dueDate' => 'not-a-date'],
				filter: ['dueDate' => ['operator' => 'withinNext', 'value' => 'PT24H']],
				now: $this->now
			)
		);
	}

	public function testWithinNextFailsClosedOnUnparsableDuration(): void {
		$this->assertFalse(
			$this->evaluator->matches(
				objectData: ['dueDate' => '2026-06-12T00:00:00+00:00'],
				filter: ['dueDate' => ['operator' => 'withinNext', 'value' => '24h']],
				now: $this->now
			)
		);
	}

	public function testUnknownOperatorFailsClosed(): void {
		$this->assertFalse(
			$this->evaluator->matches(
				objectData: ['status' => 'open'],
				filter: ['status' => ['operator' => 'maybe', 'value' => 'open']],
				now: $this->now
			)
		);
	}

	public function testAndCombination(): void {
		// Both entries pass.
		$this->assertTrue(
			$this->evaluator->matches(
				objectData: [
					'status' => 'open',
					'dueDate' => '2026-06-12T00:00:00+00:00',
				],
				filter: [
					'status' => ['operator' => 'notEquals', 'value' => 'done'],
					'dueDate' => ['operator' => 'withinNext', 'value' => 'PT24H'],
				],
				now: $this->now
			)
		);

		// Second entry fails → whole filter fails.
		$this->assertFalse(
			$this->evaluator->matches(
				objectData: [
					'status' => 'open',
					'dueDate' => '2026-06-15T00:00:00+00:00',
				],
				filter: [
					'status' => ['operator' => 'notEquals', 'value' => 'done'],
					'dueDate' => ['operator' => 'withinNext', 'value' => 'PT24H'],
				],
				now: $this->now
			)
		);
	}

	public function testMixedScalarAndOperatorEntries(): void {
		$this->assertTrue(
			$this->evaluator->matches(
				objectData: [
					'priority' => 'high',
					'dueDate' => '2026-06-12T00:00:00+00:00',
				],
				filter: [
					'priority' => 'high',
					'dueDate' => ['operator' => 'withinNext', 'value' => 'PT24H'],
				],
				now: $this->now
			)
		);
	}

	// ---- notification-scheduled-filter-grammar: membership, instants, combinators ----

	public function testBareListMeansMembership(): void {
		// decidesk's 18 rappel rules are written this way.
		$filter = ['lifecycle' => ['open', 'in-uitvoering']];

		self::assertTrue($this->evaluator->matches(objectData: ['lifecycle' => 'open'], filter: $filter, now: $this->now));
		self::assertTrue($this->evaluator->matches(objectData: ['lifecycle' => 'in-uitvoering'], filter: $filter, now: $this->now));
		self::assertFalse($this->evaluator->matches(objectData: ['lifecycle' => 'afgedaan'], filter: $filter, now: $this->now));
		self::assertFalse($this->evaluator->matches(objectData: [], filter: $filter, now: $this->now));
	}

	public function testNotInIsSatisfiedByAMissingField(): void {
		$filter = ['state' => ['operator' => 'notIn', 'values' => ['paid']]];

		self::assertTrue($this->evaluator->matches(objectData: [], filter: $filter, now: $this->now));
		self::assertTrue($this->evaluator->matches(objectData: ['state' => 'open'], filter: $filter, now: $this->now));
		self::assertFalse($this->evaluator->matches(objectData: ['state' => 'paid'], filter: $filter, now: $this->now));
	}

	public function testMembershipOverAnArrayFieldIsIntersection(): void {
		$filter = ['tags' => ['b', 'c']];

		self::assertTrue($this->evaluator->matches(objectData: ['tags' => ['a', 'b']], filter: $filter, now: $this->now));
		self::assertFalse($this->evaluator->matches(objectData: ['tags' => ['a']], filter: $filter, now: $this->now));
	}

	public function testBeforeAndAfterResolveTheThreeInstantSpellings(): void {
		$past = ['d' => '2026-06-01T00:00:00+00:00'];

		self::assertTrue($this->evaluator->matches(objectData: $past, filter: ['d' => ['operator' => 'before', 'value' => 'now']], now: $this->now));
		self::assertTrue($this->evaluator->matches(objectData: $past, filter: ['d' => ['operator' => 'before', 'value' => '2026-07-01']], now: $this->now));
		// Signed duration: "-P7D" is a week ago, so a June date is before it.
		self::assertTrue($this->evaluator->matches(objectData: $past, filter: ['d' => ['operator' => 'before', 'value' => '-P7D']], now: $this->now));
		self::assertFalse($this->evaluator->matches(objectData: $past, filter: ['d' => ['operator' => 'after', 'value' => 'now']], now: $this->now));
	}

	public function testShillinqCombinatorEvaluates(): void {
		// APTransaction.overdue, verbatim.
		$filter = [
			'all' => [
				['field' => 'state', 'operator' => 'notIn', 'values' => ['paid', 'written-off', 'voided']],
				['field' => 'dueDate', 'operator' => 'before', 'value' => 'now'],
			],
		];

		self::assertTrue($this->evaluator->matches(objectData: ['state' => 'open', 'dueDate' => '2026-06-01T00:00:00+00:00'], filter: $filter, now: $this->now));
		self::assertFalse($this->evaluator->matches(objectData: ['state' => 'paid', 'dueDate' => '2026-06-01T00:00:00+00:00'], filter: $filter, now: $this->now));
		self::assertFalse($this->evaluator->matches(objectData: ['state' => 'open', 'dueDate' => '2027-01-01T00:00:00+00:00'], filter: $filter, now: $this->now));
	}

	public function testEmptyAllMatchesButEmptyAnyDoesNot(): void {
		// "any of nothing" is false; treating it as true would silently widen a
		// rule to the whole table.
		self::assertTrue($this->evaluator->matches(objectData: ['x' => 1], filter: ['all' => []], now: $this->now));
		self::assertFalse($this->evaluator->matches(objectData: ['x' => 1], filter: ['any' => []], now: $this->now));
	}

	public function testAnyIsADisjunction(): void {
		$filter = [
			'any' => [
				['field' => 'a', 'operator' => 'equals', 'value' => 1],
				['field' => 'b', 'operator' => 'equals', 'value' => 2],
			],
		];

		self::assertTrue($this->evaluator->matches(objectData: ['a' => 1, 'b' => 9], filter: $filter, now: $this->now));
		self::assertTrue($this->evaluator->matches(objectData: ['a' => 9, 'b' => 2], filter: $filter, now: $this->now));
		self::assertFalse($this->evaluator->matches(objectData: ['a' => 9, 'b' => 9], filter: $filter, now: $this->now));
	}

	public function testAnUnexecutableFilterMatchesNothing(): void {
		// openconnector's `op` spelling. Previously accepted and silently
		// non-matching; now explicitly non-matching, and loud about it.
		$filter = ['isEnabled' => ['op' => 'equals', 'value' => true]];

		self::assertFalse($this->evaluator->matches(objectData: ['isEnabled' => true], filter: $filter, now: $this->now));
	}

}//end class
