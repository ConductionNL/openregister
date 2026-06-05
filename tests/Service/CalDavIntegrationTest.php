<?php

/**
 * CalDAV integration test for the calendar-event-linking pipeline.
 *
 * Exercises the underlying CalDAV protocol the
 * `nextcloud-entity-relations` change relies on: the test creates an
 * event in the Nextcloud personal calendar via PUT, fetches it via
 * GET, deletes it via DELETE. This proves the env can support
 * `CalendarEventService::linkEvent()` when the Calendar app is
 * enabled.
 *
 * The link surface itself (`CalendarEventService`) takes a pre-existing
 * event ID and is covered by `tests/Unit/Service/CalendarEventServiceTest.php`.
 * That layer is intentionally NOT in scope here.
 *
 * Skips when the Nextcloud HTTP endpoint is unreachable so the suite
 * still passes in environments where the dev container isn't running.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/nextcloud-entity-relations/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Service;

use PHPUnit\Framework\TestCase;

/**
 * @group integration
 */
class CalDavIntegrationTest extends TestCase
{

    private const NC_BASE = 'http://localhost';

    private const NC_USER = 'admin';

    private const NC_PASS = 'admin';

    private string $eventUid;

    protected function setUp(): void
    {
        parent::setUp();
        if ($this->isNextcloudReachable() === false) {
            $this->markTestSkipped(
                'Nextcloud HTTP endpoint not reachable at '.self::NC_BASE.'. Start with: docker-compose up -d nextcloud'
            );
        }

        $this->eventUid = 'phpunit-event-'.bin2hex(random_bytes(8));
    }//end setUp()

    protected function tearDown(): void
    {
        // Best-effort cleanup — CalDAV DELETE is idempotent.
        $this->httpRequest(
            method: 'DELETE',
            path: $this->eventPath(),
            headers: [],
            body: null
        );
        parent::tearDown();
    }//end tearDown()

    public function testCreateAndFetchEventViaCalDav(): void
    {
        $ical = $this->buildIcal(uid: $this->eventUid, summary: 'phpunit CalDAV smoke');

        [$putStatus] = $this->httpRequest(
            method: 'PUT',
            path: $this->eventPath(),
            headers: ['Content-Type: text/calendar; charset=utf-8'],
            body: $ical
        );

        $this->assertContains(
            $putStatus,
            [201, 204],
            'CalDAV PUT MUST return 201 (created) or 204 (no content)'
        );

        [$getStatus, $getBody] = $this->httpRequest(
            method: 'GET',
            path: $this->eventPath(),
            headers: [],
            body: null
        );

        $this->assertSame(200, $getStatus, 'CalDAV GET MUST return the freshly stored event');
        $this->assertStringContainsString('UID:'.$this->eventUid, (string) $getBody);
        $this->assertStringContainsString('phpunit CalDAV smoke', (string) $getBody);
    }//end testCreateAndFetchEventViaCalDav()

    public function testDeleteEventRemovesIt(): void
    {
        // Create an event we'll then delete.
        $ical = $this->buildIcal(uid: $this->eventUid, summary: 'phpunit CalDAV delete');
        $this->httpRequest(
            method: 'PUT',
            path: $this->eventPath(),
            headers: ['Content-Type: text/calendar; charset=utf-8'],
            body: $ical
        );

        [$delStatus] = $this->httpRequest(
            method: 'DELETE',
            path: $this->eventPath(),
            headers: [],
            body: null
        );
        $this->assertContains(
            $delStatus,
            [200, 204],
            'CalDAV DELETE MUST return 200 or 204'
        );

        [$getStatus] = $this->httpRequest(
            method: 'GET',
            path: $this->eventPath(),
            headers: [],
            body: null
        );
        $this->assertSame(404, $getStatus, 'event MUST be gone after DELETE');
    }//end testDeleteEventRemovesIt()

    private function eventPath(): string
    {
        return '/remote.php/dav/calendars/'.self::NC_USER.'/personal/'.$this->eventUid.'.ics';
    }//end eventPath()

    private function buildIcal(string $uid, string $summary): string
    {
        return "BEGIN:VCALENDAR\r\n"."VERSION:2.0\r\n"."PRODID:-//OpenRegister//phpunit//EN\r\n"."BEGIN:VEVENT\r\n"."UID:$uid\r\n"."DTSTART:20260601T100000Z\r\n"."DTEND:20260601T110000Z\r\n"."SUMMARY:$summary\r\n"."STATUS:CONFIRMED\r\n"."END:VEVENT\r\n"."END:VCALENDAR\r\n";
    }//end buildIcal()

    private function isNextcloudReachable(): bool
    {
        [$status] = $this->httpRequest(
            method: 'GET',
            path: '/status.php',
            headers: [],
            body: null,
            authenticated: false
        );
        return $status === 200;
    }//end isNextcloudReachable()

    /**
     * @param  string[] $headers
     * @return array{0: int, 1: string|null}
     */
    private function httpRequest(string $method, string $path, array $headers, ?string $body, bool $authenticated=true): array
    {
        $ch = curl_init(self::NC_BASE.$path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        if ($authenticated === true) {
            curl_setopt($ch, CURLOPT_USERPWD, self::NC_USER.':'.self::NC_PASS);
        }

        $response = curl_exec($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($response === false) {
            return [0, null];
        }

        return [$status, (string) $response];
    }//end httpRequest()
}//end class
