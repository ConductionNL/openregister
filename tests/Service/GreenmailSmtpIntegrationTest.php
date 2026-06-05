<?php

/**
 * Greenmail SMTP integration test for the email-linking pipeline.
 *
 * Exercises the SMTP plumbing the `nextcloud-entity-relations` change
 * relies on: a test fixture sends mail to Greenmail's SMTP listener,
 * the test then queries Greenmail's HTTP API to verify receipt. This
 * proves the integration test stack — docker-compose `mail` profile
 * up, ports exposed, accounts auto-created — is wired correctly.
 *
 * The Mail-app linking surface (`EmailService::linkEmail()`) takes a
 * pre-synced Mail-app message ID, which is one layer above SMTP/IMAP
 * receipt and is covered by `tests/Unit/Service/EmailServiceTest.php`.
 * That layer is intentionally NOT in scope here — this test is the
 * SMTP-receipt smoke that the env can support email linking when the
 * Mail app is enabled and configured against Greenmail.
 *
 * Skips with a clear message when Greenmail is not running so the
 * suite still passes in environments without the `mail` profile.
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
class GreenmailSmtpIntegrationTest extends TestCase
{

    private const SMTP_HOST = 'openregister-greenmail';

    private const SMTP_PORT = 3025;

    private const API_BASE = 'http://openregister-greenmail:8080';

    protected function setUp(): void
    {
        parent::setUp();
        if ($this->isGreenmailUp() === false) {
            $this->markTestSkipped(
                'Greenmail is not running. Start with: '.'`docker-compose --profile mail up -d greenmail`'
            );
        }
    }//end setUp()

    public function testSmtpReceivesMailAndApiReportsIt(): void
    {
        $messageId = 'phpunit-'.bin2hex(random_bytes(8)).'@test.local';
        $subject   = 'phpunit-greenmail-'.uniqid();
        $body      = 'Body for '.$subject;

        $this->sendMail(
            from: 'sender@phpunit.test',
            to: 'recipient@phpunit.test',
            subject: $subject,
            body: $body,
            messageId: $messageId
        );

        // Greenmail's HTTP API exposes received messages under
        // /api/service/readiness for liveness, but for inbox content
        // we use the /api/user/{addr}/messages endpoint which lists
        // delivered messages for an account. Auto-account creation is
        // enabled via `-Dgreenmail.setup.test.all` so the recipient's
        // mailbox is available immediately.
        $messages = $this->fetchMessagesFor(address: 'recipient@phpunit.test');
        $this->assertNotEmpty($messages, 'Greenmail MUST report at least one delivered message');

        $found = false;
        foreach ($messages as $msg) {
            $msgSubject = ($msg['subject'] ?? null);
            if ($msgSubject === $subject) {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, 'Greenmail MUST contain the subject we sent');
    }//end testSmtpReceivesMailAndApiReportsIt()

    public function testGreenmailHealthEndpointReturnsRunning(): void
    {
        $payload = $this->httpGet(self::API_BASE.'/api/service/readiness');
        $this->assertNotNull($payload, 'Greenmail readiness MUST respond');
        $decoded = json_decode($payload, true);
        $this->assertIsArray($decoded);
        $this->assertSame(
            'Service running',
            ($decoded['message'] ?? null),
            'Greenmail HTTP API MUST report Service running'
        );
    }//end testGreenmailHealthEndpointReturnsRunning()

    private function isGreenmailUp(): bool
    {
        $body = $this->httpGet(self::API_BASE.'/api/service/readiness');
        return $body !== null;
    }//end isGreenmailUp()

    private function sendMail(string $from, string $to, string $subject, string $body, string $messageId): void
    {
        $socket = @fsockopen(self::SMTP_HOST, self::SMTP_PORT, $errno, $errstr, 5);
        $this->assertNotFalse($socket, 'SMTP connect MUST succeed: '.$errstr);

        $this->expectSmtp($socket, '220');
        fwrite($socket, "HELO phpunit.test\r\n");
        // HELO is single-line so a plain expect drains the response
        // cleanly. EHLO would return multi-line 250 banners that we'd
        // have to drain one-by-one, which adds noise to the test.
        $this->expectSmtp($socket, '250');
        fwrite($socket, "MAIL FROM:<$from>\r\n");
        $this->expectSmtp($socket, '250');
        fwrite($socket, "RCPT TO:<$to>\r\n");
        $this->expectSmtp($socket, '250');
        fwrite($socket, "DATA\r\n");
        $this->expectSmtp($socket, '354');

        $message  = "From: $from\r\n";
        $message .= "To: $to\r\n";
        $message .= "Subject: $subject\r\n";
        $message .= "Message-ID: <$messageId>\r\n";
        $message .= "MIME-Version: 1.0\r\n";
        $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $message .= "\r\n";
        $message .= $body;
        $message .= "\r\n.\r\n";
        fwrite($socket, $message);
        $this->expectSmtp($socket, '250');

        fwrite($socket, "QUIT\r\n");
        fclose($socket);
    }//end sendMail()

    /**
     * Drain a (possibly multi-line) SMTP response and assert the
     * status code on the final line. SMTP convention: continuation
     * lines look like `250-...` (hyphen), the final line uses a
     * space `250 ...`. Greenmail's HELO returns 2 lines so a single
     * `fgets` would leave the second line in the buffer and confuse
     * subsequent expectations.
     *
     * @param resource $socket SMTP socket.
     */
    private function expectSmtp($socket, string $code): void
    {
        $line = '';
        do {
            $line = fgets($socket, 1024);
            $this->assertNotFalse($line, 'SMTP server MUST respond with a status line');
            $this->assertStringStartsWith(
                $code,
                $line,
                sprintf('SMTP MUST return %s but got %s', $code, trim((string) $line))
            );
        } while (strlen($line) >= 4 && $line[3] === '-');
    }//end expectSmtp()

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchMessagesFor(string $address): array
    {
        $body = $this->httpGet(self::API_BASE.'/api/user/'.urlencode($address).'/messages');
        if ($body === null) {
            return [];
        }

        $decoded = json_decode($body, true);
        if (is_array($decoded) === false) {
            return [];
        }

        // Greenmail returns either {messages: [...]} or a bare list
        // depending on version; normalise to a flat list.
        if (isset($decoded['messages']) === true && is_array($decoded['messages']) === true) {
            return $decoded['messages'];
        }

        return $decoded;
    }//end fetchMessagesFor()

    private function httpGet(string $url): ?string
    {
        $ctx  = stream_context_create(
            [
                'http' => [
                    'method'        => 'GET',
                    'timeout'       => 3,
                    'ignore_errors' => true,
                ],
            ]
        );
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            return null;
        }

        return $body;
    }//end httpGet()
}//end class
