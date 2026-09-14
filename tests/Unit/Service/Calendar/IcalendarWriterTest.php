<?php

/**
 * Unit tests for IcalendarWriter.
 *
 * The two defects a calendar client never reports are an unfolded line and a
 * naive local time, so both are asserted here rather than left to a reader's
 * eye: folding counts octets and never splits a multi-byte character, and a
 * calendar carrying timed events carries the matching VTIMEZONE.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Calendar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Calendar;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTimeZone;
use OCA\OpenRegister\Service\Calendar\IcalendarWriter;
use PHPUnit\Framework\TestCase;

class IcalendarWriterTest extends TestCase {

	private IcalendarWriter $writer;

	protected function setUp(): void {
		parent::setUp();
		$this->writer = new IcalendarWriter();
	}

	public function testAShortLineIsNotFolded(): void {
		$this->assertSame('SUMMARY:Hoorzitting', $this->writer->fold('SUMMARY:Hoorzitting'));
	}

	public function testALongLineFoldsAtSeventyFiveOctets(): void {
		$line = 'SUMMARY:' . str_repeat('a', 200);

		$folded = $this->writer->fold($line);
		$segments = explode("\r\n", $folded);

		$this->assertGreaterThan(1, count($segments));
		$this->assertSame(75, strlen($segments[0]));

		foreach (array_slice($segments, 1) as $segment) {
			$this->assertStringStartsWith(' ', $segment);
			$this->assertLessThanOrEqual(75, strlen($segment));
		}

		// Unfolding must return the original line exactly.
		$this->assertSame($line, str_replace("\r\n ", '', $folded));
	}

	public function testFoldingNeverSplitsAMultiByteCharacter(): void {
		// Every character is three octets, so a naive 75-octet cut lands
		// inside one of them.
		$line = 'SUMMARY:' . str_repeat('★', 60);

		$folded = $this->writer->fold($line);

		$this->assertSame($line, str_replace("\r\n ", '', $folded));
		foreach (explode("\r\n", $folded) as $segment) {
			$this->assertTrue(
				mb_check_encoding($segment, 'UTF-8'),
				'A fold split a multi-byte character: ' . bin2hex($segment)
			);
		}
	}

	public function testTextIsEscapedInTheRightOrder(): void {
		$this->assertSame(
			'a\\\\b\;c\\,d\\ne',
			$this->writer->escapeText("a\\b;c,d\ne")
		);
	}

	public function testOffsetsAreFormattedAsHoursAndMinutes(): void {
		$this->assertSame('+0100', $this->writer->formatOffset(3600));
		$this->assertSame('+0200', $this->writer->formatOffset(7200));
		$this->assertSame('-0330', $this->writer->formatOffset(-12600));
		$this->assertSame('+0000', $this->writer->formatOffset(0));
	}

	public function testAZoneWithTransitionsGetsBothHalvesOfTheYear(): void {
		$lines = $this->writer->timeZoneComponent(
			timeZone: new DateTimeZone('Europe/Amsterdam'),
			fromYear: 2026,
			toYear: 2026
		);

		$body = implode("\n", $lines);

		$this->assertStringContainsString('BEGIN:VTIMEZONE', $body);
		$this->assertStringContainsString('TZID:Europe/Amsterdam', $body);
		$this->assertStringContainsString('BEGIN:DAYLIGHT', $body);
		$this->assertStringContainsString('BEGIN:STANDARD', $body);
		$this->assertStringContainsString('TZOFFSETTO:+0200', $body);
		$this->assertStringContainsString('TZOFFSETTO:+0100', $body);
		$this->assertStringContainsString('END:VTIMEZONE', $body);
	}

	public function testAZoneWithNoTransitionsStillCarriesItsOffset(): void {
		$lines = $this->writer->timeZoneComponent(
			timeZone: new DateTimeZone('UTC'),
			fromYear: 2026,
			toYear: 2026
		);

		$body = implode("\n", $lines);

		$this->assertStringContainsString('TZID:UTC', $body);
		$this->assertStringContainsString('BEGIN:STANDARD', $body);
		$this->assertStringContainsString('TZOFFSETTO:+0000', $body);
	}

	public function testTheCalendarIsWellFormedAndCrlfTerminated(): void {
		$body = $this->writer->calendar(
			calendarName: 'Zaken',
			timeZone: new DateTimeZone('Europe/Amsterdam'),
			componentLines: ['BEGIN:VEVENT', 'UID:one', 'END:VEVENT'],
			fromYear: 2026,
			toYear: 2026
		);

		$this->assertStringStartsWith("BEGIN:VCALENDAR\r\n", $body);
		$this->assertStringEndsWith("END:VCALENDAR\r\n", $body);
		$this->assertStringContainsString('VERSION:2.0', $body);
		$this->assertStringContainsString('PRODID:' . IcalendarWriter::PRODID, $body);
		$this->assertStringContainsString('X-WR-CALNAME:Zaken', $body);
		$this->assertStringContainsString('X-WR-TIMEZONE:Europe/Amsterdam', $body);
		$this->assertStringContainsString('BEGIN:VTIMEZONE', $body);

		// Every line terminator is CRLF: a bare LF is what a strict parser
		// stops on.
		$this->assertSame(0, preg_match('/(?<!\r)\n/', $body));
	}
}
