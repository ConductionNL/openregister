<?php

/**
 * An external OpenAnonymiser gets HTTP Basic credentials when configured.
 *
 * anonymiq is moving its text routes (`/api/v1/analyze`, `/api/v1/anonymize`)
 * behind HTTP Basic. Open Register posted to `/api/v1/analyze` with only a
 * Content-Type and an Accept header, so requiring a login there would have
 * sent every redaction run to the regex fallback. These tests drive the real
 * curl request against a local PHP server that records what it received.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\TextExtraction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/text-extraction/spec.md#requirement-file-and-object-chunk-extraction-lifecycle
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\TextExtraction;

use OCA\OpenRegister\Db\ChunkMapper;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Db\GdprEntityMapper;
use OCA\OpenRegister\Service\Anonymisation\AnonymisationBackendService;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\TextExtraction\EntityRecognitionHandler;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * @covers \OCA\OpenRegister\Service\TextExtraction\EntityRecognitionHandler
 */
class OpenAnonymiserCredentialsTest extends TestCase {

	/**
	 * The directory holding the router script and what it recorded.
	 *
	 * @var string
	 */
	private string $dir = '';

	/**
	 * The local server process.
	 *
	 * @var resource|null
	 */
	private $server = null;

	/**
	 * The base URL of the local server.
	 *
	 * @var string
	 */
	private string $baseUrl = '';

	/**
	 * Every message logged.
	 *
	 * @var array<int, string>
	 */
	private array $logged = [];

	/**
	 * Start a local server that records the request headers it receives.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->dir = sys_get_temp_dir() . '/or-anon-cred-' . bin2hex(random_bytes(6));
		mkdir($this->dir);
		file_put_contents(
			$this->dir . '/router.php',
			'<?php' . "\n"
			. 'file_put_contents(__DIR__ . "/seen.json", json_encode(['
			. '"path" => parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH), '
			. '"headers" => array_change_key_case(getallheaders(), CASE_LOWER)]));' . "\n"
			. 'header("Content-Type: application/json");' . "\n"
			. 'echo json_encode(["pii_entities" => []]);' . "\n"
		);

		$probe = stream_socket_server('tcp://127.0.0.1:0');
		$this->assertNotFalse($probe, 'Could not reserve a local port.');
		$address = stream_socket_get_name($probe, false);
		fclose($probe);
		$this->baseUrl = 'http://' . $address;

		$this->server = proc_open(
			[PHP_BINARY, '-S', $address, $this->dir . '/router.php'],
			[0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
			$pipes
		);
		$this->assertIsResource($this->server, 'Could not start the local server.');

		for ($attempt = 0; $attempt < 100; $attempt++) {
			$socket = @fsockopen('127.0.0.1', (int) parse_url($this->baseUrl, PHP_URL_PORT));
			if ($socket !== false) {
				fclose($socket);
				return;
			}

			usleep(50000);
		}

		$this->fail('The local server did not start.');

	}//end setUp()

	/**
	 * Stop the server and remove its files.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if (is_resource($this->server) === true) {
			proc_terminate($this->server);
			proc_close($this->server);
		}

		foreach (['router.php', 'seen.json'] as $file) {
			if (is_file($this->dir . '/' . $file) === true) {
				unlink($this->dir . '/' . $file);
			}
		}

		if (is_dir($this->dir) === true) {
			rmdir($this->dir);
		}

		parent::tearDown();

	}//end tearDown()

	/**
	 * Run the OpenAnonymiser branch against the local server.
	 *
	 * @param string $username The configured user name.
	 * @param string $password The stored password.
	 *
	 * @return array{path: string, headers: array<string, string>} What the server received.
	 */
	private function runAgainstServer(string $username, string $password): array {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getFileSettingsOnly')->willReturn(
			[
				'openAnonymiserSource' => 'external',
				'openAnonymiserApiEndpoint' => $this->baseUrl . '/',
				'openAnonymiserUsername' => $username,
			]
		);
		// Guarded so the same test runs, and fails on behaviour, against the
		// code from before the password getter existed.
		if (method_exists(SettingsService::class, 'getOpenAnonymiserPassword') === true) {
			$settings->method('getOpenAnonymiserPassword')->willReturn($password);
		}

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error', 'info', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logged[] = $level . ': ' . $message . ' ' . json_encode($context);
				}
			);
		}

		$backend = $this->createMock(AnonymisationBackendService::class);
		$backend->expects($this->never())->method('requestOpenAnonymiser');

		$handler = new EntityRecognitionHandler(
			$this->createMock(ChunkMapper::class),
			$this->createMock(GdprEntityMapper::class),
			$this->createMock(EntityRelationMapper::class),
			$this->createMock(IDBConnection::class),
			$logger,
			$settings,
			$backend
		);

		$method = new ReflectionMethod(EntityRecognitionHandler::class, 'detectWithOpenAnonymiser');
		$method->invoke($handler, 'Jan de Vries woont in Utrecht', null, 0.5);

		$seen = $this->dir . '/seen.json';
		$this->assertFileExists($seen, 'The request never reached the server. Logged: ' . implode(' | ', $this->logged));

		return json_decode((string) file_get_contents($seen), true);

	}//end runAgainstServer()

	/**
	 * THE DEFECT: configured credentials are sent as HTTP Basic.
	 *
	 * @return void
	 */
	public function testConfiguredCredentialsAreSentAsHttpBasic(): void {
		$seen = $this->runAgainstServer(username: 'openregister', password: 'pa:ss w0rd');

		$this->assertSame('/api/v1/analyze', $seen['path']);
		$this->assertSame(
			'Basic ' . base64_encode('openregister:pa:ss w0rd'),
			$seen['headers']['authorization'] ?? null
		);

	}//end testConfiguredCredentialsAreSentAsHttpBasic()

	/**
	 * No user name configured sends no Authorization header, as before.
	 *
	 * @return void
	 */
	public function testNoCredentialsSendsNoAuthorizationHeader(): void {
		$seen = $this->runAgainstServer(username: '', password: '');

		$this->assertSame('/api/v1/analyze', $seen['path']);
		$this->assertArrayNotHasKey('authorization', $seen['headers']);

	}//end testNoCredentialsSendsNoAuthorizationHeader()

	/**
	 * The password is never written to a log line.
	 *
	 * @return void
	 */
	public function testThePasswordIsNeverLogged(): void {
		$this->runAgainstServer(username: 'openregister', password: 'never-in-a-log');

		$this->assertStringNotContainsString('never-in-a-log', implode("\n", $this->logged));
		$this->assertStringNotContainsString(base64_encode('openregister:never-in-a-log'), implode("\n", $this->logged));

	}//end testThePasswordIsNeverLogged()
}//end class
