<?php

namespace Unit\Service;

use Exception;
use OCA\DAV\CalDAV\CalDavBackend;
use OCA\OpenRegister\Service\CalendarEventService;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CalendarEventServiceTest extends TestCase
{
    private CalDavBackend&MockObject $calDavBackend;
    private IUserSession&MockObject $userSession;
    private IConfig&MockObject $config;
    private LoggerInterface&MockObject $logger;
    private IURLGenerator&MockObject $urlGenerator;
    private CalendarEventService $service;

    /**
     * In-memory backing store for IConfig::getUserValue / setUserValue
     * so the pin scenarios are testable without an actual DB.
     *
     * @var array<string,string>
     */
    private array $configStore = [];

    protected function setUp(): void
    {
        $this->calDavBackend = $this->createMock(CalDavBackend::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->config = $this->createMock(IConfig::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        // Calendar app index base; deep-link appends `edit/{token}` itself.
        $this->urlGenerator->method('linkToRoute')->willReturn('/index.php/apps/calendar/');
        $this->urlGenerator->method('getWebroot')->willReturn('');

        $this->configStore = [];
        $this->config->method('getUserValue')->willReturnCallback(
            function (string $userId, string $appName, string $key, $default = '') {
                $compositeKey = $userId.'/'.$appName.'/'.$key;
                return $this->configStore[$compositeKey] ?? $default;
            }
        );
        $this->config->method('setUserValue')->willReturnCallback(
            function (string $userId, string $appName, string $key, $value): void {
                $compositeKey = $userId.'/'.$appName.'/'.$key;
                $this->configStore[$compositeKey] = (string) $value;
            }
        );

        $this->service = new CalendarEventService(
            $this->calDavBackend,
            $this->userSession,
            $this->config,
            $this->logger,
            $this->urlGenerator
        );
    }

    private function setupUser(string $uid = 'admin'): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $this->userSession->method('getUser')->willReturn($user);
    }

    private function setupCalendar(int $id = 1): void
    {
        $this->calDavBackend->method('getCalendarsForUser')
            ->willReturn([
                [
                    'id' => $id,
                    'uri' => 'personal',
                    '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set' => 'VEVENT,VTODO',
                ],
            ]);
    }

    private function buildVevent(string $objectUuid, string $summary = 'Test Event'): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:TEST-UID\r\nSUMMARY:{$summary}\r\nDTSTART:20260325T130000Z\r\nDTEND:20260325T150000Z\r\nX-OPENREGISTER-REGISTER:5\r\nX-OPENREGISTER-SCHEMA:12\r\nX-OPENREGISTER-OBJECT:{$objectUuid}\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
    }

    public function testGetEventsForObjectReturnsMatchingEvents(): void
    {
        $this->setupUser();
        $this->setupCalendar();

        $veventData = $this->buildVevent('abc-123');

        $this->calDavBackend->method('getCalendarObjects')->willReturn([
            ['uri' => 'event1.ics'],
        ]);
        $this->calDavBackend->method('getCalendarObject')->willReturn([
            'calendardata' => $veventData,
        ]);

        $events = $this->service->getEventsForObject('abc-123');

        $this->assertCount(1, $events);
        $this->assertSame('abc-123', $events[0]['objectUuid']);
        $this->assertSame('Test Event', $events[0]['summary']);
        $this->assertSame(5, $events[0]['registerId']);
    }

    public function testGetEventsForObjectSkipsNonMatching(): void
    {
        $this->setupUser();
        $this->setupCalendar();

        $veventData = $this->buildVevent('other-uuid');

        $this->calDavBackend->method('getCalendarObjects')->willReturn([
            ['uri' => 'event1.ics'],
        ]);
        $this->calDavBackend->method('getCalendarObject')->willReturn([
            'calendardata' => $veventData,
        ]);

        $events = $this->service->getEventsForObject('abc-123');

        $this->assertCount(0, $events);
    }

    public function testGetEventsForObjectThrowsWhenNoUser(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('No user logged in');

        $this->service->getEventsForObject('abc-123');
    }

    public function testCreateEventBuildsVeventWithProperties(): void
    {
        $this->setupUser();
        $this->setupCalendar();

        $this->calDavBackend->expects($this->once())
            ->method('createCalendarObject')
            ->with(
                1,
                $this->matchesRegularExpression('/\.ics$/'),
                $this->callback(function (string $data): bool {
                    return str_contains($data, 'VEVENT')
                        && str_contains($data, 'X-OPENREGISTER-OBJECT:abc-123')
                        && str_contains($data, 'X-OPENREGISTER-REGISTER:5')
                        && str_contains($data, 'SUMMARY:Test Meeting')
                        && str_contains($data, 'LINK;LINKREL="related"');
                })
            );

        $result = $this->service->createEvent(5, 12, 'abc-123', 'Object Title', [
            'summary' => 'Test Meeting',
            'dtstart' => '2026-03-25T13:00:00Z',
            'dtend' => '2026-03-25T15:00:00Z',
            'location' => 'Room 1',
            'attendees' => ['user@test.local'],
        ]);

        $this->assertNotNull($result);
        $this->assertSame('abc-123', $result['objectUuid']);
        $this->assertSame('Test Meeting', $result['summary']);
    }

    public function testUnlinkEventRemovesProperties(): void
    {
        $veventData = $this->buildVevent('abc-123');

        $this->calDavBackend->method('getCalendarObject')->willReturn([
            'calendardata' => $veventData,
        ]);

        $this->calDavBackend->expects($this->once())
            ->method('updateCalendarObject')
            ->with(
                1,
                'event1.ics',
                $this->callback(function (string $data): bool {
                    return !str_contains($data, 'X-OPENREGISTER-OBJECT')
                        && !str_contains($data, 'X-OPENREGISTER-REGISTER');
                })
            );

        $this->service->unlinkEvent('1', 'event1.ics');
    }

    public function testUnlinkEventThrowsWhenNotFound(): void
    {
        $this->calDavBackend->method('getCalendarObject')->willReturn(null);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Calendar event not found');

        $this->service->unlinkEvent('1', 'nonexistent.ics');
    }

    /**
     * Regression: createEvent() and getEventsForObject() must target
     * the same calendar even when CalDavBackend::getCalendarsForUser()
     * returns rows in a different order across calls.
     *
     * Scenario:
     *   - First call (createEvent) sees [contact_birthdays(id=2), personal(id=1)]
     *   - Second call (getEventsForObject) sees [personal(id=1), contact_birthdays(id=2)]
     *
     * Before the pin, the service picked the first VEVENT-supporting
     * calendar in each call → write to id=2, read from id=1, events
     * invisible to the registry tab. With the pin, both calls target
     * the `personal` calendar (since `personal` wins over arbitrary
     * other VEVENT calendars in the selection ordering).
     */
    public function testFindUserCalendarPinsAcrossNonDeterministicOrder(): void
    {
        $this->setupUser('admin');

        $birthdays = [
            'id'  => 2,
            'uri' => 'contact_birthdays',
            '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set' => 'VEVENT',
        ];
        $personal = [
            'id'  => 1,
            'uri' => 'personal',
            '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set' => 'VEVENT,VTODO',
        ];

        // Return a different row order on each call to simulate the
        // non-deterministic ordering observed in production.
        $this->calDavBackend->method('getCalendarsForUser')
            ->willReturnOnConsecutiveCalls(
                [$birthdays, $personal],
                [$personal, $birthdays],
                [$birthdays, $personal]
            );

        $this->calDavBackend->expects($this->once())
            ->method('createCalendarObject')
            ->with(
                1, // personal calendar id
                $this->matchesRegularExpression('/\.ics$/'),
                $this->callback(function (string $data): bool {
                    return str_contains($data, 'X-OPENREGISTER-OBJECT:abc-123');
                })
            );

        $this->service->createEvent(5, 12, 'abc-123', 'Object Title', [
            'summary' => 'Pinned test',
        ]);

        // Verify the pin was persisted to the user config.
        $this->assertSame('personal', $this->configStore['admin/openregister/events_calendar_uri']);

        // Now exercise getEventsForObject — must read from the same id=1
        // calendar, NOT id=2, even though the row order has flipped.
        $this->calDavBackend->expects($this->once())
            ->method('getCalendarObjects')
            ->with(1)
            ->willReturn([]);

        $this->service->getEventsForObject('abc-123');
    }

    /**
     * findUserCalendar() must repair a stale pin (calendar deleted /
     * no longer supports VEVENT) by reselecting and re-persisting.
     */
    public function testFindUserCalendarRepairsStalePin(): void
    {
        $this->setupUser('admin');

        // Stale pin points at a calendar URI that no longer exists.
        $this->configStore['admin/openregister/events_calendar_uri'] = 'deleted_calendar';

        $personal = [
            'id'  => 1,
            'uri' => 'personal',
            '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set' => 'VEVENT',
        ];
        $this->calDavBackend->method('getCalendarsForUser')->willReturn([$personal]);
        $this->calDavBackend->method('getCalendarObjects')->willReturn([]);

        $this->service->getEventsForObject('abc-123');

        // Pin was rewritten to the surviving calendar.
        $this->assertSame('personal', $this->configStore['admin/openregister/events_calendar_uri']);
    }
}
