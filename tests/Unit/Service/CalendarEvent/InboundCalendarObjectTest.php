<?php

/**
 * Unit tests for InboundCalendarObject: payload reading, tag check and raw UID read.
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
 *
 * @spec openspec/changes/calendar-leaf-inbound/specs/integration-calendar/spec.md#requirement-inbound-calendar-changes-refresh-the-link-table-req-cal-inb-001
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\CalendarEvent;

use OCA\OpenRegister\Service\CalendarEvent\InboundCalendarObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(InboundCalendarObject::class)]
class InboundCalendarObjectTest extends TestCase {

	public function testReadsTheCalendarAndObjectRows(): void {
		$object = InboundCalendarObject::fromEventPayload(
			7,
			['uri' => 'personal', 'displayname' => 'Personal'],
			['uri' => 'e1.ics', 'calendardata' => "BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n"]
		);

		$this->assertSame(7, $object->calendarId);
		$this->assertSame('personal', $object->calendarUri);
		$this->assertSame('e1.ics', $object->eventUri);
		$this->assertSame("BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n", $object->calendarText);
	}

	public function testAbsentKeysBecomeEmptyStrings(): void {
		$object = InboundCalendarObject::fromEventPayload(3, [], ['calendardata' => 42]);

		$this->assertSame('', $object->calendarUri);
		$this->assertSame('', $object->eventUri);
		$this->assertSame('', $object->calendarText);
		$this->assertNull($object->uid());
		$this->assertFalse($object->isTagged());
	}

	public function testAStreamedBodyIsRead(): void {
		$stream = fopen('php://memory', 'r+');
		fwrite($stream, "UID:streamed\r\n");
		rewind($stream);

		$object = InboundCalendarObject::fromEventPayload(1, [], ['calendardata' => $stream]);

		$this->assertSame('streamed', $object->uid());
	}

	public function testTheTagCheckIsCaseInsensitiveAndNeedsTheObjectTag(): void {
		$this->assertTrue((new InboundCalendarObject(1, '', '', "x-openregister-object:o1\r\n"))->isTagged());
		$this->assertFalse((new InboundCalendarObject(1, '', '', "X-OPENREGISTER-REGISTER:4\r\n"))->isTagged());
	}

	public function testTheUidIsReadFromItsOwnLineWithOrWithoutParameters(): void {
		$text = "BEGIN:VCALENDAR\r\nPRODID:-//X//UID:not-this//EN\r\nBEGIN:VEVENT\r\nUID;X-PARAM=1:  abc-123  \r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
		$this->assertSame('abc-123', (new InboundCalendarObject(1, '', '', $text))->uid());
		$this->assertSame('plain', (new InboundCalendarObject(1, '', '', "uid:plain\n"))->uid());
	}

	public function testAnEmptyUidIsNoUid(): void {
		$this->assertNull((new InboundCalendarObject(1, '', '', "UID:   \r\n"))->uid());
	}
}
