<?php

/**
 * Unit tests for VeventSearchShape: generated VEVENT lines read as ICalendar::search() results.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Calendar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Calendar;

use DateTimeZone;
use OCA\OpenRegister\Calendar\VeventSearchShape;
use PHPUnit\Framework\TestCase;

class VeventSearchShapeTest extends TestCase {

	private VeventSearchShape $shape;

	protected function setUp(): void {
		parent::setUp();
		$this->shape = new VeventSearchShape();
	}

	public function testAnAllDayDeadlineWithItsAlarm(): void {
		$result = $this->shape->toSearchResult(
			[
				'BEGIN:VEVENT',
				'UID:openregister-9-a-termijn@openregister.app',
				'DTSTAMP:20260914T080000Z',
				'SUMMARY:Bezwaar\\, spoed\\; zie\\nnotitie',
				'DTSTART;VALUE=DATE:20261020',
				'DTEND;VALUE=DATE:20261021',
				'CATEGORIES:OpenRegister,DEADLINE',
				'URL:/apps/openregister/objects/7/9/a',
				'BEGIN:VALARM',
				'ACTION:DISPLAY',
				'TRIGGER;RELATED=START:-P7D',
				'DESCRIPTION:termijn',
				'END:VALARM',
				'END:VEVENT',
			],
			'openregister-schema-9'
		);

		$this->assertNotNull($result);
		$this->assertSame('openregister-9-a-termijn@openregister.app', $result['id']);
		$this->assertSame('VEVENT', $result['type']);
		$this->assertSame('openregister-schema-9', $result['calendar-key']);
		$this->assertSame('openregister-schema-9', $result['calendar-uri']);

		$vevent = $result['objects'][0];
		$this->assertSame(["Bezwaar, spoed; zie\nnotitie", []], $vevent['SUMMARY']);
		$this->assertSame(['20261020', ['VALUE' => 'DATE']], $vevent['DTSTART']);
		$this->assertSame(['20261021', ['VALUE' => 'DATE']], $vevent['DTEND']);
		$this->assertSame([['OpenRegister', 'DEADLINE'], []], $vevent['CATEGORIES']);
		$this->assertSame(['/apps/openregister/objects/7/9/a', []], $vevent['URL']);
		$this->assertSame(
			[[
				'ACTION' => ['DISPLAY', []],
				'TRIGGER' => ['-P7D', ['RELATED' => 'START']],
				'DESCRIPTION' => ['termijn', []],
			]],
			$vevent['VALARM']
		);
		$this->assertArrayNotHasKey('DESCRIPTION', $vevent);
	}

	public function testATimedAppointmentWithAttendees(): void {
		$result = $this->shape->toSearchResult(
			[
				'BEGIN:VEVENT',
				'UID:u-1',
				'DTSTART;TZID=Europe/Amsterdam:20261020T093000',
				'DTEND;TZID=Europe/Amsterdam:20261020T103000',
				'ATTENDEE;PARTSTAT=ACCEPTED:mailto:a@example.org',
				'ATTENDEE;PARTSTAT=NEEDS-ACTION;CN="Doe: Jane":urn:openregister:attendee:jane',
				'END:VEVENT',
			],
			'k'
		);

		$this->assertNotNull($result);
		$vevent = $result['objects'][0];
		$this->assertSame(['20261020T093000', ['TZID' => 'Europe/Amsterdam']], $vevent['DTSTART']);
		$this->assertSame(
			[
				['mailto:a@example.org', ['PARTSTAT' => 'ACCEPTED']],
				['urn:openregister:attendee:jane', ['PARTSTAT' => 'NEEDS-ACTION', 'CN' => 'Doe: Jane']],
			],
			$vevent['ATTENDEE']
		);

		$span = $this->shape->span($vevent, new DateTimeZone('UTC'));
		$this->assertNotNull($span);
		$this->assertSame('2026-10-20T07:30:00+00:00', $span['start']->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM));
		$this->assertSame('2026-10-20T08:30:00+00:00', $span['end']->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM));
	}

	public function testLinesWithoutAVeventOrAUidAnswerNull(): void {
		$this->assertNull($this->shape->toSearchResult(['BEGIN:VTODO', 'UID:x', 'END:VTODO'], 'k'));
		$this->assertNull($this->shape->toSearchResult(['BEGIN:VEVENT', 'SUMMARY:no uid', 'END:VEVENT'], 'k'));
		$this->assertNull($this->shape->toSearchResult(['BEGIN:VEVENT', 'UID:unterminated'], 'k'));
	}

	public function testTheSpanOfADateReadsTheTenantZoneAndAUtcValueReadsUtc(): void {
		$amsterdam = new DateTimeZone('Europe/Amsterdam');

		$dates = $this->shape->span(
			['DTSTART' => ['20261020', ['VALUE' => 'DATE']], 'DTEND' => ['20261021', ['VALUE' => 'DATE']]],
			$amsterdam
		);
		$this->assertNotNull($dates);
		$this->assertSame('2026-10-20T00:00:00+02:00', $dates['start']->format(DATE_ATOM));
		$this->assertSame('2026-10-21T00:00:00+02:00', $dates['end']->format(DATE_ATOM));

		$utc = $this->shape->span(['DTSTART' => ['20261020T120000Z', []]], $amsterdam);
		$this->assertNotNull($utc);
		$this->assertSame('2026-10-20T12:00:00+00:00', $utc['start']->format(DATE_ATOM));
		// Without a DTEND the event ends where it starts.
		$this->assertEquals($utc['start'], $utc['end']);
	}

	public function testASpanWithoutAReadableStartIsNull(): void {
		$zone = new DateTimeZone('UTC');

		$this->assertNull($this->shape->span([], $zone));
		$this->assertNull($this->shape->span(['DTSTART' => ['not-a-date', []]], $zone));
	}
}
