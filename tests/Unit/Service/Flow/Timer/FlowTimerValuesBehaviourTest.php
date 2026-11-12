<?php

/**
 * The value conversions the timer classes share, and the immutable ledger
 * entry they write: the clock fallback, mutable copies that keep the zone,
 * date parsing and its refusals, the empty-string and empty-array nulls.
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

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Timer;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Exception\FlowTimerValidationException;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerLedgerEntry;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerValues;
use PHPUnit\Framework\TestCase;

/**
 * FlowTimerValues and FlowTimerLedgerEntry.
 */
class FlowTimerValuesBehaviourTest extends TestCase {

	private FlowTimerValues $values;

	protected function setUp(): void {
		$this->values = new FlowTimerValues();
	}//end setUp()

	public function testAnInjectedClockIsUsedAsAnImmutableCopy(): void {
		$now = new DateTime('2026-03-02T10:15:00.250000', new DateTimeZone('Europe/Amsterdam'));

		$instant = $this->values->instant($now);

		$this->assertSame('2026-03-02T10:15:00.250000+01:00', $instant->format('Y-m-d\TH:i:s.uP'));
		$now->modify('+1 day');
		$this->assertSame('2026-03-02', $instant->format('Y-m-d'));
	}//end testAnInjectedClockIsUsedAsAnImmutableCopy()

	public function testWithoutAClockTheRealTimeIsUsed(): void {
		$before = time();
		$instant = $this->values->instant(null);

		$this->assertGreaterThanOrEqual($before, $instant->getTimestamp());
		$this->assertLessThanOrEqual(time(), $instant->getTimestamp());
	}//end testWithoutAClockTheRealTimeIsUsed()

	public function testAMutableCopyKeepsTheInstantTheMicrosecondsAndTheZone(): void {
		$value = new DateTimeImmutable('2026-07-01T23:30:00.125000', new DateTimeZone('Europe/Amsterdam'));

		$copy = $this->values->mutable($value);

		$this->assertSame('2026-07-01T23:30:00.125000+02:00', $copy->format('Y-m-d\TH:i:s.uP'));
		$this->assertSame('Europe/Amsterdam', $copy->getTimezone()->getName());
	}//end testAMutableCopyKeepsTheInstantTheMicrosecondsAndTheZone()

	public function testMutableOrNullPassesNullAndCopiesAnInstant(): void {
		$this->assertNull($this->values->mutableOrNull(null));
		$copy = $this->values->mutableOrNull(new DateTimeImmutable('2026-01-05T08:00:00+00:00'));
		$this->assertSame('2026-01-05T08:00:00+00:00', $copy?->format(DATE_ATOM));
	}//end testMutableOrNullPassesNullAndCopiesAnInstant()

	public function testDateOrNullTreatsNullAndEmptyStringAsAbsent(): void {
		$this->assertNull($this->values->dateOrNull(null, 'anchor'));
		$this->assertNull($this->values->dateOrNull('', 'anchor'));
	}//end testDateOrNullTreatsNullAndEmptyStringAsAbsent()

	public function testDateOrNullAcceptsADateTimeAndAnIsoString(): void {
		$fromObject = $this->values->dateOrNull(new DateTime('2026-02-03T04:05:06+00:00'), 'anchor');
		$fromString = $this->values->dateOrNull('2026-02-03T04:05:06+00:00', 'anchor');

		$this->assertInstanceOf(DateTimeImmutable::class, $fromObject);
		$this->assertSame('2026-02-03T04:05:06+00:00', $fromObject->format(DATE_ATOM));
		$this->assertSame('2026-02-03T04:05:06+00:00', $fromString?->format(DATE_ATOM));
	}//end testDateOrNullAcceptsADateTimeAndAnIsoString()

	public function testDateOrNullRefusesANonStringNamingTheFieldAndType(): void {
		$this->expectException(FlowTimerValidationException::class);
		$this->expectExceptionMessage('anchor must be a date string or a DateTime; got int.');

		$this->values->dateOrNull(12, 'anchor');
	}//end testDateOrNullRefusesANonStringNamingTheFieldAndType()

	public function testDateOrNullRefusesAnUnparseableString(): void {
		$this->expectException(FlowTimerValidationException::class);
		$this->expectExceptionMessage("startAt 'not a date' is not a parseable date.");

		$this->values->dateOrNull('not a date', 'startAt');
	}//end testDateOrNullRefusesAnUnparseableString()

	public function testStringOrNullTrimsAndDropsBlanksAndNonStrings(): void {
		$this->assertSame('reason', $this->values->stringOrNull('  reason '));
		$this->assertNull($this->values->stringOrNull('   '));
		$this->assertNull($this->values->stringOrNull(5));
	}//end testStringOrNullTrimsAndDropsBlanksAndNonStrings()

	public function testArrayOrNullDropsEmptyArraysAndNonArrays(): void {
		$this->assertSame(['a' => 1], $this->values->arrayOrNull(['a' => 1]));
		$this->assertNull($this->values->arrayOrNull([]));
		$this->assertNull($this->values->arrayOrNull('a'));
	}//end testArrayOrNullDropsEmptyArraysAndNonArrays()

	public function testALedgerEntryStartsBareAndItsWithersLeaveTheOriginalUntouched(): void {
		$moment = new DateTimeImmutable('2026-04-01T09:00:00+00:00');
		$entry = new FlowTimerLedgerEntry('extended', 'alice', 'more time', $moment);
		$prior = new DateTime('2026-04-02T09:00:00+00:00');
		$next = new DateTime('2026-04-05T09:00:00+00:00');

		$moved = $entry->withFireMoments($prior, $next);
		$based = $moved->withBasis('businessDays', 2.5);

		$this->assertNull($entry->priorFireAt());
		$this->assertNull($entry->newFireAt());
		$this->assertSame('', $entry->basis());
		$this->assertNull($entry->impact());
		$this->assertSame($prior, $moved->priorFireAt());
		$this->assertSame($next, $moved->newFireAt());
		$this->assertSame('', $moved->basis());
		$this->assertSame($prior, $based->priorFireAt());
		$this->assertSame('businessDays', $based->basis());
		$this->assertSame(2.5, $based->impact());
		$this->assertSame(['extended', 'alice', 'more time'], [$based->type, $based->actor, $based->reason]);
		$this->assertSame($moment, $based->moment);
	}//end testALedgerEntryStartsBareAndItsWithersLeaveTheOriginalUntouched()

	public function testABasisWithoutImpactClearsAnEarlierImpact(): void {
		$entry = (new FlowTimerLedgerEntry('armed', null, '', new DateTimeImmutable('2026-01-01T00:00:00+00:00')))
			->withBasis('hours', 4.0)
			->withBasis('calendarDays');

		$this->assertSame('calendarDays', $entry->basis());
		$this->assertNull($entry->impact());
		$this->assertNull($entry->actor);
	}//end testABasisWithoutImpactClearsAnEarlierImpact()
}//end class
