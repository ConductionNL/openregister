<?php

/**
 * Unit tests for CalendarLinkService.
 *
 * Covers:
 *  - linkEvent: writes ONLY a link-table row and populates summary/dtstart from CalDAV
 *  - createAndLinkEvent: delegates to CalendarEventService::createEvent + writes link with taggedWithXor=true
 *  - unlinkEvent: removes the link row + strips X-OR-* properties when row was tagged
 *  - unlinkEvent: legacy X-OR-only events are unlinked via fallback scan
 *  - getLinkedEvents: UNION between link-table and X-OR-* scan, dedupe by (calendarUri, eventUid)
 *    with `source` annotation (link-table / xor-only / both)
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTime;
use OCA\DAV\CalDAV\CalDavBackend;
use OCA\OpenRegister\Db\CalendarLink;
use OCA\OpenRegister\Db\CalendarLinkMapper;
use OCA\OpenRegister\Service\CalendarEventService;
use OCA\OpenRegister\Service\CalendarLinkService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class CalendarLinkServiceTest extends TestCase
{
    private CalendarLinkMapper&MockObject $linkMapper;
    private CalendarEventService&MockObject $calendarEventService;
    private CalDavBackend&MockObject $calDavBackend;
    private IUserSession&MockObject $userSession;
    private LoggerInterface&MockObject $logger;
    private CalendarLinkService $service;

    protected function setUp(): void
    {
        $this->linkMapper           = $this->createMock(CalendarLinkMapper::class);
        $this->calendarEventService = $this->createMock(CalendarEventService::class);
        $this->calDavBackend        = $this->createMock(CalDavBackend::class);
        $this->userSession          = $this->createMock(IUserSession::class);
        $this->logger               = $this->createMock(LoggerInterface::class);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('admin');
        $this->userSession->method('getUser')->willReturn($user);

        $this->service = new CalendarLinkService(
            $this->linkMapper,
            $this->calendarEventService,
            $this->calDavBackend,
            $this->userSession,
            $this->logger,
        );
    }

    private function buildVevent(string $uid, string $summary = 'Test'): string
    {
        return implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Test//EN',
            'BEGIN:VEVENT',
            'UID:'.$uid,
            'DTSTAMP:20260101T000000Z',
            'SUMMARY:'.$summary,
            'DTSTART:20260601T100000Z',
            'DTEND:20260601T110000Z',
            'LOCATION:HQ',
            'END:VEVENT',
            'END:VCALENDAR',
            '',
        ]);
    }

    public function testLinkEventWritesLinkTableRowOnly(): void
    {
        $this->calDavBackend->method('getCalendarsForUser')->willReturn([
            ['id' => 7, 'uri' => 'personal'],
        ]);
        $this->calDavBackend->method('getCalendarObjects')->willReturn([
            ['uri' => 'event-1.ics'],
        ]);
        $this->calDavBackend->method('getCalendarObject')->willReturn([
            'calendardata' => $this->buildVevent('ev-uid-1', 'Kickoff'),
        ]);

        $this->linkMapper->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (CalendarLink $link) {
                $this->assertSame('obj-1', $link->getObjectUuid());
                $this->assertSame('personal', $link->getCalendarUri());
                $this->assertSame(7, $link->getCalendarId());
                $this->assertSame('ev-uid-1', $link->getEventUid());
                $this->assertSame('event-1.ics', $link->getEventUri());
                $this->assertSame('Kickoff', $link->getSummary());
                $this->assertSame('HQ', $link->getLocation());
                $this->assertFalse($link->getTaggedWithXor());
                return true;
            }))
            ->willReturnArgument(0);

        // Critically: CalendarEventService::createEvent / linkEvent must NOT be called.
        $this->calendarEventService->expects($this->never())->method('createEvent');
        $this->calendarEventService->expects($this->never())->method('linkEvent');

        $result = $this->service->linkEvent('obj-1', 1, 2, 'personal', 'ev-uid-1');
        $this->assertInstanceOf(CalendarLink::class, $result);
    }

    public function testCreateAndLinkEventDelegatesAndMirrors(): void
    {
        $this->calendarEventService->expects($this->once())
            ->method('createEvent')
            ->willReturn([
                'id'         => 'new-event.ics',
                'uid'        => 'new-uid',
                'calendarId' => '7',
                'summary'    => 'New meeting',
                'dtstart'    => '2026-06-01T10:00:00+00:00',
                'dtend'      => '2026-06-01T11:00:00+00:00',
                'location'   => 'HQ',
            ]);

        $this->calDavBackend->method('getCalendarsForUser')->willReturn([
            ['id' => 7, 'uri' => 'personal'],
        ]);

        $this->linkMapper->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (CalendarLink $link) {
                $this->assertSame('obj-1', $link->getObjectUuid());
                $this->assertSame('new-uid', $link->getEventUid());
                $this->assertSame('new-event.ics', $link->getEventUri());
                $this->assertSame('personal', $link->getCalendarUri());
                $this->assertTrue($link->getTaggedWithXor());
                return true;
            }))
            ->willReturnArgument(0);

        $result = $this->service->createAndLinkEvent('obj-1', 1, 2, [
            'summary' => 'New meeting',
            'objectTitle' => 'My object',
        ]);
        $this->assertInstanceOf(CalendarLink::class, $result);
    }

    public function testUnlinkEventStripsXorWhenTagged(): void
    {
        $link = new CalendarLink();
        $link->setObjectUuid('obj-1');
        $link->setEventUid('ev-1');
        $link->setEventUri('event.ics');
        $link->setCalendarId(7);
        $link->setTaggedWithXor(true);

        $this->linkMapper->method('findByObjectAndEvent')->willReturn($link);
        $this->linkMapper->expects($this->once())
            ->method('deleteByObjectAndEvent')
            ->with('obj-1', 'ev-1')
            ->willReturn(1);

        $this->calendarEventService->expects($this->once())
            ->method('unlinkEvent')
            ->with('7', 'event.ics');

        $this->service->unlinkEvent('obj-1', 'ev-1');
    }

    public function testUnlinkEventNoRowFallsBackToXorScan(): void
    {
        $this->linkMapper->method('findByObjectAndEvent')
            ->willThrowException(new DoesNotExistException('nope'));
        $this->linkMapper->expects($this->once())
            ->method('deleteByObjectAndEvent')
            ->willReturn(0);

        $this->calendarEventService->method('getEventsForObject')->willReturn([
            ['id' => 'legacy.ics', 'uid' => 'ev-legacy', 'calendarId' => '5'],
        ]);
        $this->calendarEventService->expects($this->once())
            ->method('unlinkEvent')
            ->with('5', 'legacy.ics');

        $this->service->unlinkEvent('obj-1', 'ev-legacy');
    }

    public function testGetLinkedEventsUnionAndDedupe(): void
    {
        // Link-table row for ev-A on calendar 'personal'.
        $link = new CalendarLink();
        $link->setObjectUuid('obj-1');
        $link->setEventUid('ev-A');
        $link->setEventUri('a.ics');
        $link->setCalendarUri('personal');
        $link->setCalendarId(7);
        $link->setSummary('Cached summary');
        $link->setDtstart(new DateTime('2026-06-01T10:00:00Z'));
        $link->setLinkedBy('admin');
        $link->setLinkedAt(new DateTime('2026-05-01T00:00:00Z'));
        $link->setTaggedWithXor(false);

        $this->linkMapper->method('findByObjectUuid')->willReturn([$link]);

        // Calendar map for the dedupe step.
        $this->calDavBackend->method('getCalendarsForUser')->willReturn([
            ['id' => 7, 'uri' => 'personal'],
            ['id' => 9, 'uri' => 'work'],
        ]);

        // X-OR-* scan returns two events:
        //   - ev-A on calendar 7 (duplicates the link-table row → both)
        //   - ev-B on calendar 9 (only via X-OR → xor-only)
        $this->calendarEventService->method('getEventsForObject')->willReturn([
            [
                'id'         => 'a.ics',
                'uid'        => 'ev-A',
                'calendarId' => '7',
                'summary'    => 'Live summary',
                'dtstart'    => '2026-06-01T10:00:00+00:00',
                'objectUuid' => 'obj-1',
                'registerId' => 1,
                'schemaId'   => 2,
            ],
            [
                'id'         => 'b.ics',
                'uid'        => 'ev-B',
                'calendarId' => '9',
                'summary'    => 'Only in XOR',
                'dtstart'    => '2026-06-02T10:00:00+00:00',
                'objectUuid' => 'obj-1',
                'registerId' => 1,
                'schemaId'   => 2,
            ],
        ]);

        $result = $this->service->getLinkedEvents('obj-1');

        $this->assertCount(2, $result);

        $byUid = [];
        foreach ($result as $row) {
            $byUid[$row['uid']] = $row;
        }

        $this->assertSame('both', $byUid['ev-A']['source']);
        // Live X-OR-* value should win over the link-table cache.
        $this->assertSame('Live summary', $byUid['ev-A']['summary']);

        $this->assertSame('xor-only', $byUid['ev-B']['source']);
        $this->assertSame('work', $byUid['ev-B']['calendarUri']);
    }

    public function testGetAvailableCalendarsFiltersOnVevent(): void
    {
        $this->calDavBackend->method('getCalendarsForUser')->willReturn([
            [
                'id' => 7,
                'uri' => 'personal',
                '{DAV:}displayname' => 'Personal',
                '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set' => 'VEVENT,VTODO',
                '{http://apple.com/ns/ical/}calendar-color' => '#ff0000',
            ],
            [
                'id' => 8,
                'uri' => 'tasks-only',
                '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set' => 'VTODO',
            ],
        ]);

        $result = $this->service->getAvailableCalendars();
        $this->assertCount(1, $result);
        $this->assertSame('personal', $result[0]['uri']);
        $this->assertSame('Personal', $result[0]['displayName']);
        $this->assertSame('#ff0000', $result[0]['color']);
    }
}
