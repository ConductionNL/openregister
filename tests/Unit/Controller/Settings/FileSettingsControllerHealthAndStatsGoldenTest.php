<?php

/**
 * Characterisation of FileSettingsController's connection health checks against a
 * local HTTP server (success, non-success status, unreachable host, custom headers)
 * and of the zeroed file extraction statistics served when measuring fails.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller\Settings
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Settings;

use Exception;
use OCA\OpenRegister\Files\Controller\Settings\FileSettingsController;
use OCA\OpenRegister\Files\Controller\Support\FileSettingsControllerServices;
use OCA\OpenRegister\Service\SettingsService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Golden health check results and zeroed extraction statistics.
 *
 * @covers \OCA\OpenRegister\Files\Controller\Settings\FileSettingsController
 */
final class FileSettingsControllerHealthAndStatsGoldenTest extends TestCase {

	/**
	 * The built-in PHP server process.
	 *
	 * @var resource|null
	 */
	private static $server = null;

	/**
	 * Base URL of the local server.
	 *
	 * @var string
	 */
	private static string $baseUrl = '';

	/**
	 * The router script directory.
	 *
	 * @var string
	 */
	private static string $docRoot = '';

	/**
	 * The container the controller reads its backends from.
	 *
	 * @var ContainerInterface
	 */
	private ContainerInterface $container;

	/**
	 * The controller under test.
	 *
	 * @var FileSettingsController
	 */
	private FileSettingsController $controller;

	/**
	 * Start a local server that answers by path: /ok/health 200, /created/health 201,
	 * /teapot/health 418, /auth/health 200 only with the Dolphin bearer header,
	 * /ok/supportedentities a JSON list.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		self::$docRoot = sys_get_temp_dir() . '/or-health-' . bin2hex(random_bytes(4));
		mkdir(self::$docRoot);
		$router = <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$headers = function_exists('getallheaders') ? getallheaders() : [];
$auth = $headers['Authorization'] ?? '';
$type = $headers['Content-Type'] ?? '';
$codes = ['/ok/health' => 200, '/created/health' => 201, '/teapot/health' => 418];
if ($path === '/auth/health') {
	http_response_code(($auth === 'Bearer k-1' && $type === 'application/json') ? 200 : 401);
	return true;
}
if ($path === '/typed/health') {
	http_response_code($type === 'application/json' ? 200 : 415);
	return true;
}
if ($path === '/ok/supportedentities') {
	header('Content-Type: application/json');
	echo json_encode(['PERSON', 'EMAIL_ADDRESS']);
	return true;
}
http_response_code($codes[$path] ?? 404);
return true;
PHP;
		file_put_contents(self::$docRoot . '/router.php', $router);

		$socket = stream_socket_server('tcp://127.0.0.1:0');
		$port = (int) substr((string) stream_socket_get_name($socket, false), strlen('127.0.0.1:'));
		fclose($socket);

		self::$server = proc_open(
			[PHP_BINARY, '-S', '127.0.0.1:' . $port, self::$docRoot . '/router.php'],
			[['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
			$pipes
		);
		self::$baseUrl = 'http://127.0.0.1:' . $port;

		for ($attempt = 0; $attempt < 50; $attempt++) {
			$probe = @fsockopen('127.0.0.1', $port);
			if ($probe !== false) {
				fclose($probe);
				return;
			}

			usleep(100000);
		}
	}//end setUpBeforeClass()

	/**
	 * Stop the local server and remove its router.
	 *
	 * @return void
	 */
	public static function tearDownAfterClass(): void {
		if (is_resource(self::$server) === true) {
			proc_terminate(self::$server);
			proc_close(self::$server);
		}

		@unlink(self::$docRoot . '/router.php');
		@rmdir(self::$docRoot);
	}//end tearDownAfterClass()

	/**
	 * Build the controller.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->container = $this->createMock(ContainerInterface::class);
		$this->controller = new FileSettingsController(
			$this->createMock(IRequest::class),
			new FileSettingsControllerServices(
				$this->container,
				$this->createMock(SettingsService::class),
				$this->createMock(LoggerInterface::class)
			)
		);
	}//end setUp()

	/**
	 * A 200 answer is a successful Presidio check, with the supported entities attached.
	 *
	 * @return void
	 */
	public function testPresidioHealthyServiceReportsSuccessAndCapabilities(): void {
		$response = $this->controller->testPresidioConnection(self::$baseUrl . '/ok');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(
			[
				'success' => true,
				'message' => 'Presidio connection successful',
				'capabilities' => ['supported_entities' => ['PERSON', 'EMAIL_ADDRESS']],
			],
			$response->getData()
		);
	}//end testPresidioHealthyServiceReportsSuccessAndCapabilities()

	/**
	 * A 201 answer also counts as healthy.
	 *
	 * @return void
	 */
	public function testPresidioCreatedAnswerCountsAsHealthy(): void {
		$data = $this->controller->testPresidioConnection(self::$baseUrl . '/created')->getData();

		$this->assertSame(true, $data['success']);
		$this->assertSame('Presidio connection successful', $data['message']);
	}//end testPresidioCreatedAnswerCountsAsHealthy()

	/**
	 * Without headers the probe sends a JSON content type.
	 *
	 * @return void
	 */
	public function testPresidioProbeSendsJsonContentTypeByDefault(): void {
		$data = $this->controller->testPresidioConnection(self::$baseUrl . '/typed')->getData();

		$this->assertSame(true, $data['success']);
	}//end testPresidioProbeSendsJsonContentTypeByDefault()

	/**
	 * Any other status is reported with the service name and the code, without capabilities.
	 *
	 * @return void
	 */
	public function testPresidioOtherStatusReportsTheCode(): void {
		$response = $this->controller->testPresidioConnection(self::$baseUrl . '/teapot');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(
			['success' => false, 'error' => 'Presidio API returned HTTP 418'],
			$response->getData()
		);
	}//end testPresidioOtherStatusReportsTheCode()

	/**
	 * An unreachable host is a connection failure carrying the cURL error.
	 *
	 * @return void
	 */
	public function testDolphinUnreachableHostReportsConnectionFailure(): void {
		$socket = stream_socket_server('tcp://127.0.0.1:0');
		$closedUrl = 'http://' . stream_socket_get_name($socket, false);
		fclose($socket);

		$data = $this->controller->testDolphinConnection($closedUrl, 'k-1')->getData();

		$this->assertSame(['success', 'error'], array_keys($data));
		$this->assertSame(false, $data['success']);
		$this->assertStringStartsWith('Connection failed: ', $data['error']);
		$this->assertGreaterThan(strlen('Connection failed: '), strlen($data['error']));
	}//end testDolphinUnreachableHostReportsConnectionFailure()

	/**
	 * Dolphin sends its bearer token and JSON content type instead of the default header.
	 *
	 * @return void
	 */
	public function testDolphinSendsItsOwnHeaders(): void {
		$this->assertSame(
			['success' => true, 'message' => 'Dolphin connection successful'],
			$this->controller->testDolphinConnection(self::$baseUrl . '/auth', 'k-1')->getData()
		);
		$this->assertSame(
			['success' => false, 'error' => 'Dolphin API returned HTTP 401'],
			$this->controller->testDolphinConnection(self::$baseUrl . '/auth', 'other')->getData()
		);
	}//end testDolphinSendsItsOwnHeaders()

	/**
	 * When measuring fails the statistics are all zero, with the error last, in this order.
	 *
	 * @return void
	 */
	public function testExtractionStatsAreZeroedWithTheErrorWhenMeasuringFails(): void {
		$this->container->method('get')->willThrowException(new Exception('no chunk table'));

		$response = $this->controller->getFileExtractionStats();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(
			[
				'success' => true,
				'totalFiles' => 0,
				'processedFiles' => 0,
				'pendingFiles' => 0,
				'untrackedFiles' => 0,
				'extractedTextStorageMB' => '0.00',
				'totalFilesStorageMB' => '0.00',
				'completed' => 0,
				'failed' => 0,
				'indexed' => 0,
				'processing' => 0,
				'vectorized' => 0,
				'error' => 'no chunk table',
			],
			$response->getData()
		);
	}//end testExtractionStatsAreZeroedWithTheErrorWhenMeasuringFails()
}//end class
