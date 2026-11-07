<?php

/**
 * Golden test for AppRegisterImportRunner.
 *
 * Runs document() and run() for an app whose container, SettingsService
 * shape and register files vary per scenario, and records each result plus
 * every app-manager, container, SettingsService and importer call in
 * fixtures/app-register-import-runner-golden.json. Set UPDATE_GOLDEN=1 to
 * rewrite it.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\AppHost\AppContainerLocator;
use OCA\OpenRegister\Service\AppRegisterImportRunner;
use OCA\OpenRegister\Service\ConfigurationServiceInterface;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\AppRegisterImportRunner
 */
class AppRegisterImportRunnerGoldenTest extends TestCase {

	private const APP = 'demoapp';

	/**
	 * Ordered call log.
	 *
	 * @var array<int, mixed>
	 */
	private array $log = [];

	/**
	 * Temporary app root.
	 *
	 * @var string
	 */
	private string $appRoot = '';

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->appRoot = sys_get_temp_dir().'/app-register-import-golden';
		$this->cleanApp();
	}//end setUp()

	/**
	 * @return void
	 */
	protected function tearDown(): void {
		$this->cleanApp();
	}//end tearDown()

	/**
	 * Scenarios: options.
	 *
	 * Options: namespace, container (none|ok), has (true|false|throws), service
	 * (a shape name, "string" for a non-object), reply (what the method returns,
	 * or "throws"), files (none|base|fragments|invalid), import (ok|empty|throws).
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public static function scenarios(): array {
		return [
			'no namespace, no files' => [['namespace' => '']],
			'no namespace, base file' => [['namespace' => '', 'files' => 'base']],
			'no namespace, fragments' => [['namespace' => '', 'files' => 'fragments']],
			'no namespace, invalid json' => [['namespace' => '', 'files' => 'invalid']],
			'fallback import empty' => [['namespace' => '', 'files' => 'base', 'import' => 'empty']],
			'fallback import throws' => [['namespace' => '', 'files' => 'base', 'import' => 'throws']],
			'no container' => [['container' => 'none', 'files' => 'base']],
			'container lacks service' => [['has' => false, 'files' => 'base']],
			'container has throws' => [['has' => 'throws', 'files' => 'base']],
			'service not an object' => [['service' => 'string', 'files' => 'base']],
			'service without entry point' => [['service' => 'none', 'files' => 'base']],
			'forced method' => [['service' => 'forced']],
			'reload with required param is skipped' => [['service' => 'reloadRequired', 'files' => 'base']],
			'reload without params' => [['service' => 'reload']],
			'private forced method is skipped' => [['service' => 'privateForced']],
			'load configuration force' => [['service' => 'loadForce']],
			'load configuration wrong param name' => [['service' => 'loadWrongName', 'files' => 'base']],
			'load configuration no param' => [['service' => 'loadNoParam', 'files' => 'base']],
			'load settings force' => [['service' => 'settingsForce']],
			'app reply throws' => [['service' => 'forced', 'reply' => 'throws']],
			'app reply not array' => [['service' => 'forced', 'reply' => 'done']],
			'app reply success false' => [['service' => 'loadForce', 'reply' => ['success' => false, 'message' => 'nope']]],
			'app reply error' => [['service' => 'forced', 'reply' => ['error' => 'bad thing']]],
			'app reply empty error' => [['service' => 'forced', 'reply' => ['error' => '', 'message' => 'ok']]],
			'app reply empty' => [['service' => 'settingsForce', 'reply' => []]],
		];
	}//end scenarios()

	/**
	 * @dataProvider scenarios
	 *
	 * @param array<string, mixed> $options Scenario options.
	 *
	 * @return void
	 */
	public function testRunnerMatchesGolden(array $options): void {
		$this->log = [];
		$options = array_merge(
			['namespace' => 'DemoApp', 'container' => 'ok', 'has' => true, 'service' => 'forced', 'reply' => ['success' => true, 'message' => 'imported'], 'files' => 'none', 'import' => 'ok'],
			$options
		);
		$this->writeFiles($options['files']);

		$runner = new AppRegisterImportRunner($this->appManager($options['namespace']), $this->locator($options), $this->importer($options['import']));
		$actual = ['document' => $runner->document(self::APP), 'run' => $runner->run(self::APP), 'log' => $this->log];
		$actual = json_decode(str_replace($this->appRoot, '<app>', json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), true);

		$file = __DIR__.'/fixtures/app-register-import-runner-golden.json';
		$name = $this->dataName();
		$all = is_file($file) === true ? json_decode(file_get_contents($file), true) : [];
		if (getenv('UPDATE_GOLDEN') === '1') {
			$all[$name] = $actual;
			ksort($all);
			file_put_contents($file, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
		}

		$this->assertArrayHasKey($name, $all);
		$this->assertSame($all[$name], $actual);
	}//end testRunnerMatchesGolden()

	/**
	 * Write the app's register files.
	 *
	 * @param string $mode none|base|fragments|invalid.
	 *
	 * @return void
	 */
	private function writeFiles(string $mode): void {
		if ($mode === 'none') {
			return;
		}

		mkdir($this->appRoot.'/lib/Settings/register.d', 0777, true);
		$base = $mode === 'invalid' ? '{broken' : json_encode(['info' => ['version' => '1.2.3'], 'components' => ['registers' => ['r' => ['slug' => 'r']]]]);
		file_put_contents($this->appRoot.'/lib/Settings/'.self::APP.'_register.json', $base);
		if ($mode === 'fragments') {
			file_put_contents($this->appRoot.'/lib/Settings/register.d/10-a.json', json_encode(['components' => ['schemas' => ['s' => ['slug' => 's']]]]));
			file_put_contents($this->appRoot.'/lib/Settings/register.d/20-b.json', json_encode(['components' => ['schemas' => ['t' => ['slug' => 't']]]]));
		}
	}//end writeFiles()

	/**
	 * Remove the temporary app root.
	 *
	 * @return void
	 */
	private function cleanApp(): void {
		foreach (glob($this->appRoot.'/lib/Settings/register.d/*.json') ?: [] as $fragment) {
			unlink($fragment);
		}

		foreach (glob($this->appRoot.'/lib/Settings/*.json') ?: [] as $base) {
			unlink($base);
		}

		foreach ([$this->appRoot.'/lib/Settings/register.d', $this->appRoot.'/lib/Settings', $this->appRoot.'/lib', $this->appRoot] as $dir) {
			if (is_dir($dir) === true) {
				rmdir($dir);
			}
		}
	}//end cleanApp()

	/**
	 * App manager fake.
	 *
	 * @param string $namespace The app namespace.
	 *
	 * @return IAppManager
	 */
	private function appManager(string $namespace): IAppManager {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppPath')->willReturnCallback(
			function ($appId): string {
				$this->log[] = ['apps.getAppPath', $appId];
				return $this->appRoot;
			}
		);
		$apps->method('getAppInfo')->willReturnCallback(
			function ($appId) use ($namespace): array {
				$this->log[] = ['apps.getAppInfo', $appId];
				return ['id' => $appId, 'namespace' => $namespace];
			}
		);
		return $apps;
	}//end appManager()

	/**
	 * Container locator fake.
	 *
	 * @param array<string, mixed> $options Scenario options.
	 *
	 * @return AppContainerLocator
	 */
	private function locator(array $options): AppContainerLocator {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturnCallback(
			function ($id) use ($options): bool {
				$this->log[] = ['container.has', $id];
				if ($options['has'] === 'throws') {
					throw new RuntimeException('container broken');
				}

				return $options['has'];
			}
		);
		$container->method('get')->willReturnCallback(
			function ($id) use ($options) {
				$this->log[] = ['container.get', $id];
				return $this->settingsService($options['service'], $options['reply']);
			}
		);

		$locator = $this->createMock(AppContainerLocator::class);
		$locator->method('find')->willReturnCallback(
			function ($appId) use ($options, $container): ?ContainerInterface {
				$this->log[] = ['locator.find', $appId];
				return $options['container'] === 'ok' ? $container : null;
			}
		);
		return $locator;
	}//end locator()

	/**
	 * A SettingsService stand-in of the given shape.
	 *
	 * @param string $shape The shape name.
	 * @param mixed  $reply What its entry point returns, or "throws".
	 *
	 * @return mixed
	 */
	private function settingsService(string $shape, mixed $reply): mixed {
		$sink = function (string $method, array $args) use ($reply) {
			$this->log[] = ['settings.'.$method, $args];
			if ($reply === 'throws') {
				throw new RuntimeException('app import failed');
			}

			return $reply;
		};

		return match ($shape) {
			'string' => 'not an object',
			'none' => new class {
				public function other(): void {
				}
			},
			'forced' => new class ($sink) {
				public function __construct(private $sink) {
				}

				public function loadConfigurationForced() {
					return ($this->sink)('loadConfigurationForced', func_get_args());
				}

				public function loadConfiguration(bool $force = false) {
					return ($this->sink)('loadConfiguration', func_get_args());
				}
			},
			'reloadRequired' => new class ($sink) {
				public function __construct(private $sink) {
				}

				public function reloadConfiguration(int $id) {
					return ($this->sink)('reloadConfiguration', func_get_args());
				}
			},
			'reload' => new class ($sink) {
				public function __construct(private $sink) {
				}

				public function reloadConfiguration(int $id = 0) {
					return ($this->sink)('reloadConfiguration', func_get_args());
				}
			},
			'privateForced' => new class ($sink) {
				public function __construct(private $sink) {
				}

				public function loadSettings(bool $force = false) {
					return ($this->sink)('loadSettings', func_get_args());
				}

				private function loadConfigurationForced() {
					return ($this->sink)('loadConfigurationForced', func_get_args());
				}
			},
			'loadForce' => new class ($sink) {
				public function __construct(private $sink) {
				}

				public function loadConfiguration(bool $force = false) {
					return ($this->sink)('loadConfiguration', func_get_args());
				}
			},
			'loadWrongName' => new class ($sink) {
				public function __construct(private $sink) {
				}

				public function loadConfiguration(bool $refresh = false) {
					return ($this->sink)('loadConfiguration', func_get_args());
				}
			},
			'loadNoParam' => new class ($sink) {
				public function __construct(private $sink) {
				}

				public function loadConfiguration() {
					return ($this->sink)('loadConfiguration', func_get_args());
				}
			},
			'settingsForce' => new class ($sink) {
				public function __construct(private $sink) {
				}

				public function loadSettings(bool $force = false) {
					return ($this->sink)('loadSettings', func_get_args());
				}
			},
		};
	}//end settingsService()

	/**
	 * Configuration importer fake.
	 *
	 * @param string $mode ok|empty|throws.
	 *
	 * @return ConfigurationServiceInterface
	 */
	private function importer(string $mode): ConfigurationServiceInterface {
		$importer = $this->createMock(ConfigurationServiceInterface::class);
		$importer->method('importFromApp')->willReturnCallback(
			function (...$args) use ($mode): array {
				$this->log[] = ['importer.importFromApp', $args];
				if ($mode === 'throws') {
					throw new RuntimeException('import failed');
				}

				return $mode === 'empty' ? [] : ['registers' => ['r']];
			}
		);
		return $importer;
	}//end importer()
}//end class
