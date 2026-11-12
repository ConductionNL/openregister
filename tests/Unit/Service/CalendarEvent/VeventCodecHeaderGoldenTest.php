<?php

/**
 * Golden for the opening lines of a new event written by VeventCodec.
 *
 * Written against the codec before its header helper was split: the calendar
 * and event preamble, the UID, a DTSTAMP, the escaped summary (or the default
 * one) and the DTSTART, in that order. DTSTAMP is matched by shape only, so the
 * golden does not depend on the clock.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\CalendarEvent
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/calendar-integration/spec.md
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\CalendarEvent;

use DateTime;
use DateTimeZone;
use OCA\OpenRegister\Service\CalendarEvent\VeventCodec;
use PHPUnit\Framework\TestCase;
use OCA\OpenRegister\Service\CalendarEvent\VeventDraft;

/**
 * @covers \OCA\OpenRegister\Service\CalendarEvent\VeventCodec
 */
class VeventCodecHeaderGoldenTest extends TestCase {

	/**
	 * The lines of a new event, DTSTAMP replaced by a marker once its shape is checked.
	 *
	 * @param array<string, mixed> $data The event data.
	 *
	 * @return list<string>
	 */
	private function lines(array $data): array {
		$ics = (new VeventCodec())->calendarDataOf(new VeventDraft('uid-1', new DateTime('2027-03-01T09:00:00Z', new DateTimeZone('UTC')), $data, ['registerId' => 1, 'schemaId' => 2, 'objectUuid' => 'obj-1', 'objectTitle' => 'Case 1']));
		$this->assertStringEndsWith("\r\n", $ics);
		$lines = explode("\r\n", substr($ics, 0, -2));
		$this->assertMatchesRegularExpression('/^DTSTAMP:\d{8}T\d{6}Z$/', $lines[5]);
		$lines[5] = 'DTSTAMP:*';

		return $lines;
	}

	public function testTheWholeEventInOrder(): void {
		$this->assertSame(
			[
				'BEGIN:VCALENDAR',
				'VERSION:2.0',
				'PRODID:-//OpenRegister//Events//EN',
				'BEGIN:VEVENT',
				'UID:uid-1',
				'DTSTAMP:*',
				'SUMMARY:Intake\, room\; 2',
				'DTSTART:20270301T090000Z',
				'LOCATION:Hall',
				'X-OPENREGISTER-REGISTER:1',
				'X-OPENREGISTER-SCHEMA:2',
				'X-OPENREGISTER-OBJECT:obj-1',
				'LINK;LINKREL="related";LABEL="Case 1";VALUE=URI:/apps/openregister/api/objects/1/2/obj-1',
				'END:VEVENT',
				'END:VCALENDAR',
			],
			$this->lines(['summary' => 'Intake, room; 2', 'location' => 'Hall'])
		);
	}

	public function testAnEventWithoutASummaryIsUntitled(): void {
		$this->assertSame('SUMMARY:Untitled event', $this->lines([])[6]);
	}
}
