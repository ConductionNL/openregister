<?php

/**
 * Golden test of SftpTransport over a phpseclib4 stub.
 *
 * Every test runs in a separate process, so the stub replaces the real phpseclib4
 * there and no test opens a network connection.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Edepot
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Edepot;

use OCA\OpenRegister\Service\Edepot\Transport\SftpTransport;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use phpseclib4\Net\SFTP;
use Psr\Log\AbstractLogger;
use Stringable;

/**
 * @covers \OCA\OpenRegister\Service\Edepot\Transport\SftpTransport
 * @uses   \OCA\OpenRegister\Service\Edepot\Transport\SipTransportSteps
 * @uses   \OCA\OpenRegister\Service\Edepot\Transport\TransportResult
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class SftpTransportGoldenTest extends TestCase {

	/**
	 * Log lines, as `level message context`.
	 *
	 * @var array<int, string>
	 */
	private array $logs = [];

	/**
	 * A local SIP file of 5 bytes.
	 *
	 * @var string
	 */
	private string $sip = '';

	/**
	 * A local key file.
	 *
	 * @var string
	 */
	private string $key = '';

	/**
	 * Create the local files.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->sip = tempnam(sys_get_temp_dir(), 'sip');
		file_put_contents($this->sip, 'hello');
		$this->key = tempnam(sys_get_temp_dir(), 'key');
		file_put_contents($this->key, 'KEY');
	}//end setUp()

	/**
	 * Remove the local files.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unlink($this->sip);
		unlink($this->key);
	}//end tearDown()

	/**
	 * Build the transport over the phpseclib4 stub.
	 *
	 * @return SftpTransport The transport.
	 */
	private function transport(): SftpTransport {
		require_once __DIR__ . '/fixtures/phpseclib-sftp-stub.php';
		SFTP::$size = 5;

		$logs = &$this->logs;
		$logger = new class($logs) extends AbstractLogger {
			/**
			 * Build the logger.
			 *
			 * @param array<int, string> $sink Where lines go.
			 */
			public function __construct(private array &$sink) {
			}

			/**
			 * Record a line.
			 *
			 * @param mixed $level The level.
			 * @param string|Stringable $message The message.
			 * @param array<string, mixed> $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|Stringable $message, array $context = []): void {
				$this->sink[] = $level . ' ' . $message . ' ' . json_encode($context);
			}//end log()
		};

		return new SftpTransport($logger);
	}//end transport()

	/**
	 * A password config.
	 *
	 * @return array<string, mixed> The config.
	 */
	private function config(): array {
		return ['host' => 'depot.example', 'username' => 'u', 'password' => 'p', 'remotePath' => '/in/', 'hostKeyFingerprint' => self::pin()];
	}//end config()

	/**
	 * The fingerprint of the stub server's host key.
	 *
	 * @return string The fingerprint.
	 */
	private static function pin(): string {
		return 'SHA256:' . rtrim(base64_encode(hash('sha256', 'server key', true)), '=');
	}//end pin()

	/**
	 * A transfer connects, logs in, uploads and checks the size.
	 *
	 * @return void
	 */
	public function testSuccessfulTransfer(): void {
		$result = $this->transport()->send($this->sip, $this->config());

		$remote = '/in/' . basename($this->sip);
		$this->assertTrue($result->isSuccess());
		$this->assertSame($remote, $result->getTransferReference());
		$this->assertSame(
			[['connect', 'depot.example', 22], ['hostKey'], ['login', 'u', 'p'], ['put', $remote, $this->sip, 1], ['filesize', $remote]],
			SFTP::$calls
		);
		$this->assertSame(
			[
				'info [SftpTransport] Starting SFTP transfer {"host":"depot.example"}',
				'info [SftpTransport] SFTP transfer successful ' . json_encode(['remotePath' => $remote, 'size' => 5]),
			],
			$this->logs
		);
	}//end testSuccessfulTransfer()

	/**
	 * A key file logs in with the loaded key, on the configured port, and a missing
	 * remote path uploads to the root.
	 *
	 * @return void
	 */
	public function testKeyLoginAndPort(): void {
		$config = ['host' => 'h', 'username' => 'u', 'keyPath' => $this->key, 'password' => 'ignored', 'port' => '2222', 'hostKeyFingerprint' => self::pin()];

		$result = $this->transport()->send($this->sip, $config);

		$this->assertTrue($result->isSuccess());
		$this->assertSame('/' . basename($this->sip), $result->getTransferReference());
		$this->assertSame(['connect', 'h', 2222], SFTP::$calls[0]);
		$this->assertSame(['login', 'u', 'loaded:KEY'], SFTP::$calls[2]);
	}//end testKeyLoginAndPort()

	/**
	 * A key alone is enough; neither key nor password is refused before connecting.
	 *
	 * @return void
	 */
	public function testCredentialsRequired(): void {
		$transport = $this->transport();

		$this->assertTrue($transport->send($this->sip, ['host' => 'h', 'username' => 'u', 'keyPath' => $this->key, 'hostKeyFingerprint' => self::pin()])->isSuccess());
		$this->assertSame(['login', 'u', 'loaded:KEY'], SFTP::$calls[2]);

		SFTP::$calls = [];
		$this->assertSame(
			'SFTP requires either password or keyPath for authentication',
			$transport->send($this->sip, ['host' => 'h', 'username' => 'u'])->getErrorMessage()
		);
		$this->assertSame([], SFTP::$calls);
	}//end testCredentialsRequired()

	/**
	 * A pinned key that matches is checked before the login and logs no warning.
	 *
	 * @return void
	 */
	public function testPinnedHostKeyMatches(): void {
		$result = $this->transport()->send($this->sip, $this->config());

		$this->assertTrue($result->isSuccess());
		$this->assertSame(['hostKey'], SFTP::$calls[1] ?? null);
		$this->assertSame('login', SFTP::$calls[2][0] ?? null);
		$this->assertCount(2, $this->logs);
	}//end testPinnedHostKeyMatches()

	/**
	 * A server with another key gets no credentials and no file.
	 *
	 * @return void
	 */
	public function testPinnedHostKeyMismatchSendsNothing(): void {
		$result = $this->transport()->send($this->sip, ['hostKeyFingerprint' => 'SHA256:other'] + $this->config());

		$this->assertSame('SFTP host key does not match hostKeyFingerprint', $result->getErrorMessage());
		$this->assertSame([['connect', 'depot.example', 22], ['hostKey']], SFTP::$calls);
		$this->assertFalse($this->transport()->testConnection(['hostKeyFingerprint' => 'SHA256:other'] + $this->config()));
	}//end testPinnedHostKeyMismatchSendsNothing()

	/**
	 * Without a pinned key the server gets no credentials and no file, and the
	 * refusal names the key it presented.
	 *
	 * @return void
	 */
	public function testUnpinnedHostKeySendsNothing(): void {
		$config = ['hostKeyFingerprint' => ''] + $this->config();

		$result = $this->transport()->send($this->sip, $config);

		$this->assertFalse($result->isSuccess());
		$this->assertStringContainsString('hostKeyFingerprint is not configured', (string)$result->getErrorMessage());
		$this->assertStringContainsString(self::pin(), (string)$result->getErrorMessage());
		$this->assertSame([['connect', 'depot.example', 22], ['hostKey']], SFTP::$calls);
		$this->assertFalse($this->transport()->testConnection($config));
	}//end testUnpinnedHostKeySendsNothing()

	/**
	 * A refused login stops before the upload.
	 *
	 * @return void
	 */
	public function testAuthenticationFailure(): void {
		$transport = $this->transport();
		SFTP::$loginResult = false;

		$result = $transport->send($this->sip, $this->config());

		$this->assertFalse($result->isSuccess());
		$this->assertSame('SFTP authentication failed', $result->getErrorMessage());
		$this->assertCount(3, SFTP::$calls);
		$this->assertSame('error [SftpTransport] SFTP transfer failed {"error":"SFTP authentication failed"}', $this->logs[1]);
	}//end testAuthenticationFailure()

	/**
	 * A failed upload carries phpseclib's error and skips the size check.
	 *
	 * @return void
	 */
	public function testUploadFailure(): void {
		$transport = $this->transport();
		SFTP::$putResult = false;

		$result = $transport->send($this->sip, $this->config());

		$this->assertSame('SFTP upload failed: disk full', $result->getErrorMessage());
		$this->assertCount(4, SFTP::$calls);
	}//end testUploadFailure()

	/**
	 * A remote size that differs fails the transfer.
	 *
	 * @return void
	 */
	public function testSizeMismatch(): void {
		$transport = $this->transport();
		SFTP::$size = 4;

		$result = $transport->send($this->sip, $this->config());

		$this->assertFalse($result->isSuccess());
		$this->assertSame('Remote file size mismatch: expected 5, got 4', $result->getErrorMessage());
	}//end testSizeMismatch()

	/**
	 * A size phpseclib cannot report fails the transfer too.
	 *
	 * @return void
	 */
	public function testUnknownRemoteSize(): void {
		$transport = $this->transport();
		SFTP::$size = false;

		$this->assertFalse($transport->send($this->sip, $this->config())->isSuccess());
	}//end testUnknownRemoteSize()

	/**
	 * An incomplete config is refused before connecting.
	 *
	 * @return void
	 */
	public function testIncompleteConfig(): void {
		$transport = $this->transport();

		$this->assertSame('Missing required SFTP config: host', $transport->send($this->sip, [])->getErrorMessage());
		$this->assertSame('info [SftpTransport] Starting SFTP transfer {"host":"unknown"}', $this->logs[0]);
		$this->assertSame([], SFTP::$calls);
	}//end testIncompleteConfig()

	/**
	 * A missing SIP file connects to nothing.
	 *
	 * @return void
	 */
	public function testMissingSipFile(): void {
		$result = $this->transport()->send('/nonexistent/sip.zip', $this->config());

		$this->assertSame('SIP file not found: /nonexistent/sip.zip', $result->getErrorMessage());
		$this->assertSame([], SFTP::$calls);
	}//end testMissingSipFile()

	/**
	 * A connection test logs in and asks the working directory.
	 *
	 * @return void
	 */
	public function testConnection(): void {
		$this->assertTrue($this->transport()->testConnection($this->config()));
		$this->assertSame(
			[['connect', 'depot.example', 22], ['hostKey'], ['login', 'u', 'p'], ['pwd']],
			SFTP::$calls
		);
	}//end testConnection()

	/**
	 * A refused login fails the connection test.
	 *
	 * @return void
	 */
	public function testConnectionRefused(): void {
		$transport = $this->transport();
		SFTP::$loginResult = false;

		$this->assertFalse($transport->testConnection($this->config()));
	}//end testConnectionRefused()
}//end class
