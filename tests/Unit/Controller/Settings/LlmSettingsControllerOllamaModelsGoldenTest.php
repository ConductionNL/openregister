<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller\Settings;

use Exception;
use OCA\OpenRegister\Controller\Settings\LlmSettingsController;
use OCA\OpenRegister\Service\Connection\ConnectionReporter;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\VectorizationService;
use OCP\IDBConnection;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Golden test for LlmSettingsController::getOllamaModels().
 *
 * A PHP built-in server (fixtures/ollama-router.php) stands in for Ollama;
 * the first path segment of the configured URL picks its answer. Every case
 * records the settings and formatting calls and the response, and compares
 * the recording with fixtures/llm-ollama-models-golden.json. The port and
 * curl's own error text are replaced by markers. Set UPDATE_GOLDEN=1 to rewrite the
 * fixture.
 *
 * @covers \OCA\OpenRegister\Controller\Settings\LlmSettingsController
 */
class LlmSettingsControllerOllamaModelsGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/llm-ollama-models-golden.json';

	/** @var resource|null */
	private static $server = null;

	private static int $port = 0;

	/** @var list<array<string, mixed>> */
	private array $calls = [];

	/** @var array<string, mixed>|null */
	private static ?array $recorded = null;

	/** @var array<string, mixed> */
	private static array $golden = [];

	public static function setUpBeforeClass(): void {
		$socket = stream_socket_server('tcp://127.0.0.1:0');
		self::$port = (int)substr((string)strrchr((string)stream_socket_get_name($socket, false), ':'), 1);
		fclose($socket);

		$command = [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, __DIR__ . '/fixtures/ollama-router.php'];
		self::$server = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
		for ($try = 0; $try < 100; $try++) {
			$probe = @fsockopen('127.0.0.1', self::$port, $errno, $errstr, 0.1);
			if ($probe !== false) {
				fclose($probe);
				return;
			}

			usleep(50000);
		}
	}

	public static function tearDownAfterClass(): void {
		if (self::$server !== null) {
			proc_terminate(self::$server);
			proc_close(self::$server);
			self::$server = null;
		}
	}

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		return [
			'models are formatted and sorted' => [['path' => 'ok']],
			'trailing slash on the url' => [['path' => 'ok/']],
			'redirect is followed' => [['path' => 'redirect']],
			'no models' => [['path' => 'empty']],
			'response without models' => [['path' => 'nomodels']],
			'models is not a list' => [['path' => 'notarray']],
			'response is not json' => [['path' => 'notjson']],
			'http error' => [['path' => 'fail']],
			'unknown path' => [['path' => 'missing']],
			'connection refused' => [['url' => 'http://127.0.0.1:1']],
			'settings throw' => [['settingsThrow' => true]],
		];
	}

	#[DataProvider('cases')]
	public function testGetOllamaModelsMatchesTheRecording(array $case): void {
		$this->calls = [];
		$url = $case['url'] ?? ('http://127.0.0.1:' . self::$port . '/' . ($case['path'] ?? ''));

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getLLMSettingsOnly')->willReturnCallback(function () use ($case, $url) {
			$this->calls[] = ['settings.getLLMSettingsOnly' => []];
			if (($case['settingsThrow'] ?? false) === true) {
				throw new Exception('settings unavailable');
			}

			return ['ollamaConfig' => ['url' => $url]];
		});
		$settings->method('formatBytes')->willReturnCallback(function (int $bytes) {
			$this->calls[] = ['settings.formatBytes' => [$bytes]];
			return $bytes . ' bytes';
		});

		$controller = new LlmSettingsController(
			'openregister',
			$this->createMock(IRequest::class),
			$this->createMock(IDBConnection::class),
			$this->createMock(ContainerInterface::class),
			$settings,
			$this->createMock(VectorizationService::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(ConnectionReporter::class)
		);

		$response = $controller->getOllamaModels();
		$actual = ['calls' => $this->calls, 'status' => $response->getStatus(), 'data' => $response->getData()];
		$this->compare($this->dataName(), $this->masked($actual));
	}

	/**
	 * @param array<string, mixed> $actual
	 *
	 * @return array<string, mixed>
	 */
	private function masked(array $actual): array {
		$json = (string)json_encode($actual, JSON_UNESCAPED_SLASHES);
		$json = str_replace((string)self::$port, '(port)', $json);
		$json = (string)preg_replace('/(Failed to connect to Ollama: )[^"]+/', '$1(curl error)', $json);
		return json_decode($json, true);
	}

	/**
	 * @param array<string, mixed> $actual
	 */
	private function compare(string $key, array $actual): void {
		if (getenv('UPDATE_GOLDEN') === '1') {
			if (self::$recorded === null) {
				self::$recorded = [];
			}

			self::$recorded[$key] = $actual;
			ksort(self::$recorded);
			file_put_contents(
				self::FIXTURE,
				json_encode(self::$recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
			);
			self::$golden = self::$recorded;
		}

		if (self::$golden === []) {
			self::$golden = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		$this->assertArrayHasKey($key, self::$golden);
		$this->assertSame(self::$golden[$key], $actual);
	}
}
