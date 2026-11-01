<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller\Settings;

use Exception;
use OC\Files\AppData\Factory;
use OCA\OpenRegister\Controller\Settings\CacheSettingsController;
use OCA\OpenRegister\Service\SettingsService;
use OCP\Files\GenericFileException;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden test for CacheSettingsController::clearAppStoreCache().
 *
 * Each case serves the app store cache files from a table keyed by file
 * name, records every app data, folder, file and logger call and the
 * response, and compares the recording with
 * fixtures/cache-appstore-golden.json. Log line numbers and file paths are
 * left out. Set UPDATE_GOLDEN=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Controller\Settings\CacheSettingsController
 */
class CacheSettingsControllerAppStoreGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/cache-appstore-golden.json';

	/** @var list<array<string, mixed>> */
	private array $calls = [];

	/** @var array<string, mixed>|null */
	private static ?array $recorded = null;

	/** @var array<string, mixed> */
	private static array $golden = [];

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$all = [
			'apps.json' => '{"timestamp":1700000000,"data":[1,2]}',
			'categories.json' => '{"timestamp":1700000001}',
			'discover.json' => '{"timestamp":1700000002,"x":"y"}',
		];
		return [
			'default type' => [['params' => [], 'files' => $all]],
			'apps' => [['params' => ['type' => 'apps'], 'files' => $all]],
			'categories' => [['params' => ['type' => 'categories'], 'files' => $all]],
			'discover' => [['params' => ['type' => 'discover'], 'files' => $all]],
			'all' => [['params' => ['type' => 'all'], 'files' => $all]],
			'unknown type' => [['params' => ['type' => 'nope'], 'files' => $all]],
			'all with missing, unreadable and invalid files' => [['params' => ['type' => 'all'], 'files' => [
				'apps.json' => '{"data":[]}',
				'categories.json' => 'not json',
				'discover.json' => null,
			]]],
			'all with a generic file error' => [['params' => ['type' => 'all'], 'files' => ['apps.json' => 'generic', 'categories.json' => '[]', 'discover.json' => '"text"']]],
			'timestamp of null is invalid' => [['params' => ['type' => 'apps'], 'files' => ['apps.json' => '{"timestamp":null}']]],
			'no appstore folder' => [['params' => [], 'folder' => 'missing']],
			'app data fails' => [['params' => [], 'folder' => 'error']],
			'write fails' => [['params' => [], 'files' => $all, 'writeFails' => true]],
		];
	}

	#[DataProvider('cases')]
	public function testClearAppStoreCacheMatchesTheRecording(array $case): void {
		$this->calls = [];
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturnCallback(function () use ($case) {
			$this->calls[] = ['request.getParams' => []];
			return $case['params'];
		});

		$factory = $this->createMock(Factory::class);
		$factory->method('get')->willReturnCallback(function (string $app) use ($case) {
			$this->calls[] = ['appDataFactory.get' => [$app]];
			$appData = $this->createMock(IAppData::class);
			$appData->method('getFolder')->willReturnCallback(function (string $path) use ($case) {
				$this->calls[] = ['appData.getFolder' => [$path]];
				if (($case['folder'] ?? null) === 'missing') {
					throw new NotFoundException('no folder');
				}

				if (($case['folder'] ?? null) === 'error') {
					throw new RuntimeException('app data broken');
				}

				return $this->folder($case);
			});
			return $appData;
		});

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error', 'debug', 'info'] as $level) {
			$logger->method($level)->willReturnCallback(function (...$args) use ($level) {
				unset($args[1]['line'], $args[1]['file']);
				$args[1]['exception'] = get_class($args[1]['exception']) . ': ' . $args[1]['exception']->getMessage();
				$this->calls[] = ['logger.' . $level => $args];
			});
		}

		$controller = new CacheSettingsController(
			'openregister',
			$request,
			$this->createMock(SettingsService::class),
			$logger,
			$factory,
			$this->createMock(IAppConfig::class)
		);

		$response = $controller->clearAppStoreCache();
		$this->compare($this->dataName(), ['calls' => $this->calls, 'status' => $response->getStatus(), 'data' => $response->getData()]);
	}

	/**
	 * @param array<string, mixed> $case
	 */
	private function folder(array $case): ISimpleFolder {
		$folder = $this->createMock(ISimpleFolder::class);
		$folder->method('getFile')->willReturnCallback(function (string $name) use ($case) {
			$this->calls[] = ['folder.getFile' => [$name]];
			$content = $case['files'][$name] ?? null;
			if ($content === null) {
				throw new NotFoundException($name);
			}

			$file = $this->createMock(ISimpleFile::class);
			$file->method('getContent')->willReturnCallback(function () use ($name, $content) {
				$this->calls[] = ['file.getContent' => [$name]];
				if ($content === 'generic') {
					throw new GenericFileException('unreadable');
				}

				return $content;
			});
			$file->method('putContent')->willReturnCallback(function ($data) use ($name, $case) {
				$this->calls[] = ['file.putContent' => [$name, $data]];
				if (($case['writeFails'] ?? false) === true) {
					throw new Exception('read-only');
				}
			});
			return $file;
		});
		return $folder;
	}

	/**
	 * @param array<string, mixed> $actual
	 */
	private function compare(string $key, array $actual): void {
		$actual = json_decode((string)json_encode($actual), true);
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
