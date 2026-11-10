<?php

/**
 * Golden test for DemoDataService.
 *
 * Runs isAvailable / listChoices / install against a temporary app directory
 * holding a descriptor variant, per scenario, and records each result (or
 * exception) plus every app-manager, container, importer and logger call in
 * fixtures/demo-data-service-golden.json. Set UPDATE_GOLDEN=1 to rewrite it.
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

use OCA\OpenRegister\Service\DemoDataService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @covers \OCA\OpenRegister\Service\DemoDataService
 */
class DemoDataServiceGoldenTest extends TestCase {

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
		$this->appRoot = sys_get_temp_dir().'/demo-data-golden-app';
		$this->removeDescriptor();
	}//end setUp()

	/**
	 * @return void
	 */
	protected function tearDown(): void {
		$this->removeDescriptor();
	}//end tearDown()

	/**
	 * Scenarios: [descriptor content (null = none), options].
	 *
	 * Options: installed (bool), result (importer reply).
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public static function scenarios(): array {
		$three = json_encode(['components' => ['objects' => [['a' => 1], ['b' => 2], ['c' => 3]], 'registers' => ['r']]]);
		return [
			'no descriptor' => [null, []],
			'invalid json' => ['{not json', []],
			'json scalar' => ['"text"', []],
			'no components' => ['{"x": 1}', []],
			'components not array' => ['{"components": "x"}', []],
			'objects not array' => ['{"components": {"objects": "x"}}', []],
			'empty objects' => ['{"components": {"objects": []}}', ['result' => []]],
			'three objects' => [$three, ['result' => ['registers' => ['r1', 'r2'], 'schemas' => ['s1']]]],
			'importer scalar counts' => [$three, ['result' => ['registers' => 'one', 'schemas' => null]]],
			'openregister missing' => [$three, ['installed' => false]],
		];
	}//end scenarios()

	/**
	 * @dataProvider scenarios
	 *
	 * @param string|null          $descriptor The descriptor content.
	 * @param array<string, mixed> $options    Scenario options.
	 *
	 * @return void
	 */
	public function testDemoDataMatchesGolden(?string $descriptor, array $options): void {
		$this->log = [];
		$options = array_merge(['installed' => true, 'result' => ['registers' => [], 'schemas' => []]], $options);
		if ($descriptor !== null) {
			mkdir($this->appRoot.'/lib/Settings', 0777, true);
			file_put_contents($this->descriptorFile(), $descriptor);
		}

		$service = new DemoDataService($this->appManager($options['installed']), $this->container($options['result']), $this->logger());

		$results = [];
		foreach (['isAvailable', 'listChoices', 'install'] as $call) {
			try {
				$results[$call] = ['result' => $service->$call()];
			} catch (Throwable $e) {
				$results[$call] = ['exception' => get_class($e), 'message' => str_replace($this->appRoot, '<app>', $e->getMessage())];
			}
		}

		$actual = ['results' => $results, 'log' => $this->log];
		$actual = json_decode(str_replace($this->appRoot, '<app>', json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), true);

		$file = __DIR__.'/fixtures/demo-data-service-golden.json';
		$name = $this->dataName();
		$all = is_file($file) === true ? json_decode(file_get_contents($file), true) : [];
		if (getenv('UPDATE_GOLDEN') === '1') {
			$all[$name] = $actual;
			ksort($all);
			file_put_contents($file, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
		}

		$this->assertArrayHasKey($name, $all);
		$this->assertSame($all[$name], $actual);
	}//end testDemoDataMatchesGolden()

	/**
	 * The descriptor path inside the temporary app root.
	 *
	 * @return string
	 */
	private function descriptorFile(): string {
		return $this->appRoot.'/lib/Settings/openregister_mock_register.json';
	}//end descriptorFile()

	/**
	 * Remove the temporary descriptor and its directories.
	 *
	 * @return void
	 */
	private function removeDescriptor(): void {
		if (is_file($this->descriptorFile()) === true) {
			unlink($this->descriptorFile());
		}

		foreach ([$this->appRoot.'/lib/Settings', $this->appRoot.'/lib', $this->appRoot] as $dir) {
			if (is_dir($dir) === true) {
				rmdir($dir);
			}
		}
	}//end removeDescriptor()

	/**
	 * App manager fake.
	 *
	 * @param bool $installed Whether OpenRegister is installed.
	 *
	 * @return IAppManager
	 */
	private function appManager(bool $installed): IAppManager {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppPath')->willReturnCallback(
			function ($appId): string {
				$this->log[] = ['apps.getAppPath', $appId];
				return $this->appRoot;
			}
		);
		$apps->method('getAppVersion')->willReturnCallback(
			function ($appId): string {
				$this->log[] = ['apps.getAppVersion', $appId];
				return '9.8.7';
			}
		);
		$apps->method('getInstalledApps')->willReturnCallback(
			function () use ($installed): array {
				$this->log[] = ['apps.getInstalledApps'];
				return $installed === true ? ['files', 'openregister'] : ['files'];
			}
		);
		return $apps;
	}//end appManager()

	/**
	 * Container handing out a recording configuration importer.
	 *
	 * @param mixed $result The importer reply.
	 *
	 * @return ContainerInterface
	 */
	private function container(mixed $result): ContainerInterface {
		$importer = new class ($this, $result) {
			/**
			 * @param DemoDataServiceGoldenTest $test   Log sink.
			 * @param mixed                     $result The reply.
			 */
			public function __construct(private $test, private $result) {
			}

			public function importFromApp(...$args) {
				$this->test->record(['importer.importFromApp', $args]);
				return $this->result;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function ($id) use ($importer) {
				$this->log[] = ['container.get', $id];
				return $importer;
			}
		);
		return $container;
	}//end container()

	/**
	 * Append to the call log.
	 *
	 * @param array<int, mixed> $entry The entry.
	 *
	 * @return void
	 */
	public function record(array $entry): void {
		$this->log[] = $entry;
	}//end record()

	/**
	 * Logger recording every call.
	 *
	 * @return LoggerInterface
	 */
	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function ($message, array $context = []) use ($level): void {
					$this->log[] = ['log.'.$level, $message, $context];
				}
			);
		}

		return $logger;
	}//end logger()
}//end class
