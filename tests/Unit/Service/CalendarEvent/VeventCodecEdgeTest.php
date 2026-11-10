<?php

/**
 * Behaviour tests for VeventCodec edges: unreadable start dates, end dates, attendees and status.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\CalendarEvent
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\CalendarEvent;

use DateTime;
use DateTimeZone;
use Exception;
use OCA\OpenRegister\Service\CalendarEvent\VeventCodec;
use PHPUnit\Framework\TestCase;
use Sabre\VObject\Reader;
use OCA\OpenRegister\Service\CalendarEvent\VeventDraft;
use OCA\OpenRegister\Service\CalendarEvent\CalendarObjectAddress;

/**
 * All dates are UTC, so the encoded lines do not depend on the machine's timezone.
 *
 * @covers \OCA\OpenRegister\Service\CalendarEvent\VeventCodec
 */
class VeventCodecEdgeTest extends TestCase
{
    public function testAnUnreadableStartIsRefusedWithTheParserReason(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Event start time (dtstart) could not be read as a date: ');
        (new VeventCodec())->requiredStart(['dtstart' => 'the day after tomorrow-ish']);
    }//end testAnUnreadableStartIsRefusedWithTheParserReason()

    public function testAnEndDateIsWrittenAndAttendeesThatAreNotAListAreIgnored(): void
    {
        $ics = (new VeventCodec())->calendarDataOf(new VeventDraft('uid-1', new DateTime('2027-03-01T09:00:00Z', new DateTimeZone('UTC')), ['summary' => 'Intake', 'dtend' => '2027-03-01T10:30:00Z', 'attendees' => 'alice@example.org'], ['registerId' => 1, 'schemaId' => 2, 'objectUuid' => 'obj-1', 'objectTitle' => 'Case 1']));

        $this->assertStringContainsString("DTEND:20270301T103000Z", $ics);
        $this->assertStringNotContainsString('ATTENDEE', $ics);
    }//end testAnEndDateIsWrittenAndAttendeesThatAreNotAListAreIgnored()

    public function testAnEventIsReadWithLowercaseStatusAndWithoutMissingIds(): void
    {
        $vevent = Reader::read(
            "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:uid-9\r\nSUMMARY:Hearing\r\n"
            ."DTSTART:20270301T090000Z\r\nSTATUS:CONFIRMED\r\nX-OPENREGISTER-OBJECT:obj-9\r\nX-OPENREGISTER-SCHEMA:12\r\n"
            ."ATTENDEE:mailto:bob@example.org\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n"
        )->VEVENT;

        $event = (new VeventCodec())->eventArrayAt($vevent, new CalendarObjectAddress('cal-1', 'uid-9.ics'));

        $this->assertSame('confirmed', $event['status']);
        $this->assertSame(['obj-9', null, 12], [$event['objectUuid'], $event['registerId'], $event['schemaId']]);
        $this->assertSame(['bob@example.org'], $event['attendees']);
        $this->assertNull($event['dtend']);
        $this->assertSame('', $event['description']);
    }//end testAnEventIsReadWithLowercaseStatusAndWithoutMissingIds()
}//end class
