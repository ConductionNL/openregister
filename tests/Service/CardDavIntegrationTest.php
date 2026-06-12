<?php

/**
 * CardDAV integration test for the contact-linking pipeline.
 *
 * Exercises the underlying CardDAV protocol the
 * `nextcloud-entity-relations` change relies on: the test creates a
 * vCard in the user's default address book via PUT, fetches it via
 * GET, deletes it via DELETE. This proves the env can support
 * `ContactService::linkContact()` when the Contacts app is enabled.
 *
 * The link surface itself (`ContactService`) is covered by
 * `tests/Unit/Service/ContactServiceTest.php`. That layer is
 * intentionally NOT in scope here — this test is the CardDAV-protocol
 * smoke that the env can support contact linking when Contacts is up.
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
class CardDavIntegrationTest extends TestCase
{

    private const NC_BASE = 'http://localhost';

    private const NC_USER = 'admin';

    private const NC_PASS = 'admin';

    private string $contactUid;

    protected function setUp(): void
    {
        parent::setUp();
        if ($this->isNextcloudReachable() === false) {
            $this->markTestSkipped(
                'Nextcloud HTTP endpoint not reachable at '.self::NC_BASE.'. Start with: docker-compose up -d nextcloud'
            );
        }

        $this->contactUid = 'phpunit-contact-'.bin2hex(random_bytes(8));
    }//end setUp()

    protected function tearDown(): void
    {
        $this->httpRequest(
            method: 'DELETE',
            path: $this->contactPath(),
            headers: [],
            body: null
        );
        parent::tearDown();
    }//end tearDown()

    public function testCreateAndFetchContactViaCardDav(): void
    {
        $vcard = $this->buildVcard(uid: $this->contactUid, fullName: 'PHPUnit Contact');

        [$putStatus] = $this->httpRequest(
            method: 'PUT',
            path: $this->contactPath(),
            headers: ['Content-Type: text/vcard; charset=utf-8'],
            body: $vcard
        );

        $this->assertContains(
            $putStatus,
            [201, 204],
            'CardDAV PUT MUST return 201 (created) or 204 (no content)'
        );

        [$getStatus, $getBody] = $this->httpRequest(
            method: 'GET',
            path: $this->contactPath(),
            headers: [],
            body: null
        );

        $this->assertSame(200, $getStatus, 'CardDAV GET MUST return the freshly stored contact');
        $this->assertStringContainsString('UID:'.$this->contactUid, (string) $getBody);
        $this->assertStringContainsString('FN:PHPUnit Contact', (string) $getBody);
    }//end testCreateAndFetchContactViaCardDav()

    public function testDeleteContactRemovesIt(): void
    {
        $vcard = $this->buildVcard(uid: $this->contactUid, fullName: 'PHPUnit Delete Target');
        $this->httpRequest(
            method: 'PUT',
            path: $this->contactPath(),
            headers: ['Content-Type: text/vcard; charset=utf-8'],
            body: $vcard
        );

        [$delStatus] = $this->httpRequest(
            method: 'DELETE',
            path: $this->contactPath(),
            headers: [],
            body: null
        );
        $this->assertContains(
            $delStatus,
            [200, 204],
            'CardDAV DELETE MUST return 200 or 204'
        );

        [$getStatus] = $this->httpRequest(
            method: 'GET',
            path: $this->contactPath(),
            headers: [],
            body: null
        );
        $this->assertSame(404, $getStatus, 'contact MUST be gone after DELETE');
    }//end testDeleteContactRemovesIt()

    private function contactPath(): string
    {
        return '/remote.php/dav/addressbooks/users/'.self::NC_USER.'/contacts/'.$this->contactUid.'.vcf';
    }//end contactPath()

    private function buildVcard(string $uid, string $fullName): string
    {
        return "BEGIN:VCARD\r\n"."VERSION:3.0\r\n"."UID:$uid\r\n"."FN:$fullName\r\n"."N:".$fullName.";;;;\r\n"."EMAIL;TYPE=HOME:$uid@phpunit.test\r\n"."END:VCARD\r\n";
    }//end buildVcard()

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
