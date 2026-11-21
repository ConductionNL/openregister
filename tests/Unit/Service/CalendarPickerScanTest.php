<?php

/**
 * Tests for {@see \OCA\OpenRegister\Service\CalendarPickerScan}: which VEVENTs
 * become picker rows, the cutoff, and the row shape with optional fields.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Service\CalendarPickerScan;
use PHPUnit\Framework\TestCase;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;

/**
 * CalendarPickerScanTest.
 */
class CalendarPickerScanTest extends TestCase {
	/**
	 * @param array<string, mixed> $props VEVENT properties.
	 */
	private function vevent(array $props): VEvent {
		$calendar = new VCalendar();
		/** @var VEvent $event */
		$event = $calendar->add('VEVENT', $props);
		// VCalendar::add() always generates UID and DTSTAMP; drop UID when the test leaves it out.
		if (array_key_exists('UID', $props) === false) {
			$event->remove('UID');
		}
		return $event;
	}

	private function utc(string $moment): DateTimeImmutable {
		return new DateTimeImmutable($moment, new DateTimeZone('UTC'));
	}

	public function testAFullEventBecomesARowWithEveryField(): void {
		$scan = new CalendarPickerScan(calendarId: 7, calendarUri: 'personal');
		$event = $this->vevent([
			'UID' => 'ev-1',
			'SUMMARY' => 'Hearing',
			'DTSTART' => $this->utc('2026-03-04 10:00:00'),
			'DTEND' => $this->utc('2026-03-04 11:30:00'),
			'LOCATION' => 'Room 2',
		]);

		$this->assertSame(
			[
				'uid' => 'ev-1',
				'uri' => 'ev-1.ics',
				'summary' => 'Hearing',
				'dtstart' => '2026-03-04T10:00:00+00:00',
				'dtend' => '2026-03-04T11:30:00+00:00',
				'location' => 'Room 2',
				'calendarId' => 7,
				'calendarUri' => 'personal',
			],
			$scan->pickerRow($event, 'ev-1.ics')
		);
	}

	public function testOptionalFieldsAreNullOrEmptyWhenAbsent(): void {
		$row = (new CalendarPickerScan(1, 'c'))->pickerRow($this->vevent(['UID' => 'ev-2']), 'ev-2.ics');

		$this->assertSame('', $row['summary']);
		$this->assertNull($row['dtstart']);
		$this->assertNull($row['dtend']);
		$this->assertNull($row['location']);
	}

	public function testNoEventOrAnEventWithoutUidGivesNoRow(): void {
		$scan = new CalendarPickerScan(1, 'c');

		$this->assertNull($scan->pickerRow(null, 'x.ics'));
		$this->assertNull($scan->pickerRow($this->vevent(['SUMMARY' => 'No uid']), 'x.ics'));
	}

	public function testAnEventStartingBeforeTheCutoffIsSkippedAndOneAtTheCutoffIsKept(): void {
		$scan = new CalendarPickerScan(1, 'c');
		$scan->cutoff = $this->utc('2026-03-04 10:00:00')->getTimestamp();

		$this->assertNull($scan->pickerRow($this->vevent(['UID' => 'a', 'DTSTART' => $this->utc('2026-03-04 09:59:59')]), 'a.ics'));
		$this->assertSame('b', $scan->pickerRow($this->vevent(['UID' => 'b', 'DTSTART' => $this->utc('2026-03-04 10:00:00')]), 'b.ics')['uid']);
	}

	public function testAnEventWithoutStartIsKeptEvenWithACutoff(): void {
		$scan = new CalendarPickerScan(1, 'c');
		$scan->cutoff = $this->utc('2026-03-04 10:00:00')->getTimestamp();

		$this->assertSame('a', $scan->pickerRow($this->vevent(['UID' => 'a']), 'a.ics')['uid']);
	}
}
