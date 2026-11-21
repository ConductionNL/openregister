<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Service\CalendarEvent;

use DateTime;
use Exception;
use OCA\OpenRegister\Service\CalendarEvent\VeventCodec;
use PHPUnit\Framework\TestCase;
use Sabre\VObject\Reader;
use OCA\OpenRegister\Service\CalendarEvent\VeventDraft;
use OCA\OpenRegister\Service\CalendarEvent\CalendarObjectAddress;

/**
 * @covers \OCA\OpenRegister\Service\CalendarEvent\VeventCodec
 * @uses \OCA\OpenRegister\Service\CalendarEvent\CalendarObjectAddress
 * @uses \OCA\OpenRegister\Service\CalendarEvent\VeventDraft
 */
class VeventCodecTest extends TestCase {

	public function testWrittenEventReadsBackWithItsLink(): void {
		$codec = new VeventCodec();
		$ics = $codec->calendarDataOf(new VeventDraft('UID1', new DateTime('2026-03-01T09:00:00+00:00'), ['summary' => 'A; b', 'location' => 'Zaal, 2', 'attendees' => ['a@example.org']], ['registerId' => 3, 'schemaId' => 4, 'objectUuid' => 'obj-1', 'objectTitle' => 'Zaak']));

		$this->assertStringContainsString("SUMMARY:A\; b\r\n", $ics);
		$this->assertStringContainsString('LINK;LINKREL="related";LABEL="Zaak";VALUE=URI:/apps/openregister/api/objects/3/4/obj-1', $ics);

		$event = $codec->eventArrayAt(Reader::read($ics)->VEVENT, new CalendarObjectAddress('7', 'UID1.ics'));
		$this->assertSame('UID1', $event['uid']);
		$this->assertSame('A; b', $event['summary']);
		$this->assertSame('2026-03-01T09:00:00+00:00', $event['dtstart']);
		$this->assertSame('Zaal, 2', $event['location']);
		$this->assertSame(['a@example.org'], $event['attendees']);
		$this->assertSame(['obj-1', 3, 4], [$event['objectUuid'], $event['registerId'], $event['schemaId']]);
	}

	public function testAReminderWritesADisplayAlarmThatReadsBack(): void {
		$codec = new VeventCodec();
		$ics = $codec->calendarDataOf(new VeventDraft('UID2', new DateTime('2026-03-01T09:00:00+00:00'), ['summary' => 'Follow up', 'reminderMinutesBefore' => 30], ['registerId' => 3, 'schemaId' => 4, 'objectUuid' => 'obj-1', 'objectTitle' => 'Zaak']));

		$this->assertStringContainsString("BEGIN:VALARM\r\n", $ics);
		$this->assertStringContainsString("ACTION:DISPLAY\r\n", $ics);
		$this->assertStringContainsString("TRIGGER:-PT30M\r\n", $ics);
		$this->assertStringContainsString("DESCRIPTION:Follow up\r\n", $ics);

		$event = $codec->eventArrayAt(Reader::read($ics)->VEVENT, new CalendarObjectAddress('7', 'UID2.ics'));
		$this->assertSame(30, $event['reminderMinutesBefore']);
	}

	public function testNoReminderWritesNoAlarmAndReadsBackNull(): void {
		$codec = new VeventCodec();
		foreach ([[], ['reminderMinutesBefore' => 0], ['reminderMinutesBefore' => -5], ['reminderMinutesBefore' => '30']] as $extra) {
			$ics = $codec->calendarDataOf(new VeventDraft('UID3', new DateTime('2026-03-01T09:00:00+00:00'), (['summary' => 'Plain'] + $extra), ['registerId' => 3, 'schemaId' => 4, 'objectUuid' => 'obj-1', 'objectTitle' => 'Zaak']));

			$this->assertStringNotContainsString('BEGIN:VALARM', $ics, json_encode($extra));
			$event = $codec->eventArrayAt(Reader::read($ics)->VEVENT, new CalendarObjectAddress('7', 'UID3.ics'));
			$this->assertNull($event['reminderMinutesBefore'], json_encode($extra));
		}
	}

	public function testRequiresAStart(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Event start time (dtstart) is required');
		(new VeventCodec())->requiredStart(['summary' => 'x']);
	}
}
