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

/**
 * @covers \OCA\OpenRegister\Service\CalendarEvent\VeventCodec
 */
class VeventCodecTest extends TestCase {

	public function testWrittenEventReadsBackWithItsLink(): void {
		$codec = new VeventCodec();
		$ics = $codec->calendarData(
			'UID1',
			new DateTime('2026-03-01T09:00:00+00:00'),
			['summary' => 'A; b', 'location' => 'Zaal, 2', 'attendees' => ['a@example.org']],
			['registerId' => 3, 'schemaId' => 4, 'objectUuid' => 'obj-1', 'objectTitle' => 'Zaak']
		);

		$this->assertStringContainsString("SUMMARY:A\; b\r\n", $ics);
		$this->assertStringContainsString('LINK;LINKREL="related";LABEL="Zaak";VALUE=URI:/apps/openregister/api/objects/3/4/obj-1', $ics);

		$event = $codec->eventArray(Reader::read($ics)->VEVENT, '7', 'UID1.ics');
		$this->assertSame('UID1', $event['uid']);
		$this->assertSame('A; b', $event['summary']);
		$this->assertSame('2026-03-01T09:00:00+00:00', $event['dtstart']);
		$this->assertSame('Zaal, 2', $event['location']);
		$this->assertSame(['a@example.org'], $event['attendees']);
		$this->assertSame(['obj-1', 3, 4], [$event['objectUuid'], $event['registerId'], $event['schemaId']]);
	}

	public function testRequiresAStart(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Event start time (dtstart) is required');
		(new VeventCodec())->requiredStart(['summary' => 'x']);
	}
}
