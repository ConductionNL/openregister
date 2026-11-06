<?php

/**
 * Golden test of SftpTransport over a phpseclib3 stub.
 *
 * Every test runs in a separate process, because the stub makes phpseclib3 loadable
 * and other tests need it absent.
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
use phpseclib3\Net\SFTP;
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
	 * Build the transport; with $stub phpseclib3 becomes loadable.
	 *
	 * @param bool $stub Whether to load the phpseclib3 stub.
	 *
	 * @return SftpTransport The transport.
	 */
	private function transport(bool $stub = true): SftpTransport {
		if ($stub === true) {
			require_once __DIR__ . '/fixtures/phpseclib-sftp-stub.php';
			SFTP::$size = 5;
		}

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
		return ['host' => 'depot.example', 'username' => 'u', 'password' => 'p', 'remotePath' => '/in/'];
	}//end config()

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
			[['connect', 'depot.example', 22], ['login', 'u', 'p'], ['put', $remote, $this->sip, 1], ['size', $remote]],
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
		$config = ['host' => 'h', 'username' => 'u', 'keyPath' => $this->key, 'password' => 'ignored', 'port' => '2222'];

		$result = $this->transport()->send($this->sip, $config);

		$this->assertTrue($result->isSuccess());
		$this->assertSame('/' . basename($this->sip), $result->getTransferReference());
		$this->assertSame(['connect', 'h', 2222], SFTP::$calls[0]);
		$this->assertSame(['login', 'u', 'loaded:KEY'], SFTP::$calls[1]);
	}//end testKeyLoginAndPort()

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
		$this->assertCount(2, SFTP::$calls);
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
		$this->assertCount(3, SFTP::$calls);
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
	 * Without phpseclib3 the transfer fails with the install hint, after the config check.
	 *
	 * @return void
	 */
	public function testWithoutPhpseclib(): void {
		$transport = $this->transport(stub: false);

		$this->assertSame(
			'phpseclib3 is not installed. Install phpseclib/phpseclib to enable SFTP transport.',
			$transport->send($this->sip, $this->config())->getErrorMessage()
		);
		$this->assertSame('Missing required SFTP config: host', $transport->send($this->sip, [])->getErrorMessage());
		$this->assertSame('info [SftpTransport] Starting SFTP transfer {"host":"unknown"}', $this->logs[2]);
	}//end testWithoutPhpseclib()

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
			[['connect', 'depot.example', 22], ['login', 'u', 'p'], ['pwd']],
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
