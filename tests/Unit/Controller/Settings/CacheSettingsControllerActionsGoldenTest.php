<?php

/**
 * Golden test for the CacheSettingsController actions other than clearAppStoreCache().
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller\Settings;

use Exception;
use OCA\OpenRegister\Tests\Unit\Controller\Support\CacheSettingsControllerFactory;
use OCP\Files\AppData\IAppDataFactory as Factory;
use OCA\OpenRegister\Controller\Settings\CacheSettingsController;
use OCA\OpenRegister\Service\SettingsService;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins every collaborator call and the response of the cache actions other than clearAppStoreCache().
 *
 * Each case records the request, settings service, app config and logger
 * calls, plus the response status and data, and compares the recording with
 * fixtures/cache-settings-actions-golden.json. The log context's file and
 * line belong to the source layout and are left out.
 * Set UPDATE_GOLDEN=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Controller\Settings\CacheSettingsController
 */
class CacheSettingsControllerActionsGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/cache-settings-actions-golden.json';

	/** @var list<array<string, mixed>> */
	private array $calls = [];

	/** @var array<string, mixed>|null */
	private static ?array $recorded = null;

	/** @var array<string, mixed> */
	private static array $golden = [];

	/**
	 * @return array<string, array{0: string, 1: array<string, mixed>}>
	 */
	public static function cases(): array {
		$saved = ['cache_warmup_interval' => '900', 'cache_warmup_last_run' => '2026-01-02T03:04:05+00:00'];
		return [
			'stats' => ['getCacheStats', []],
			'stats throws' => ['getCacheStats', ['throw' => true]],
			'clear default type' => ['clearCache', []],
			'clear named type' => ['clearCache', ['params' => ['type' => 'schema']]],
			'clear throws' => ['clearCache', ['params' => ['type' => 'facet'], 'throw' => true]],
			'warmup' => ['warmupNamesCache', []],
			'warmup throws' => ['warmupNamesCache', ['throw' => true]],
			'interval defaults' => ['getWarmupInterval', []],
			'interval saved' => ['getWarmupInterval', ['config' => $saved]],
			'interval disabled' => ['getWarmupInterval', ['config' => ['cache_warmup_interval' => '0', 'cache_warmup_last_run' => '0']]],
			'interval not a number' => ['getWarmupInterval', ['config' => ['cache_warmup_interval' => 'soon']]],
			'interval throws' => ['getWarmupInterval', ['throw' => true]],
			'set default' => ['setWarmupInterval', []],
			'set zero' => ['setWarmupInterval', ['params' => ['interval' => 0]]],
			'set minimum' => ['setWarmupInterval', ['params' => ['interval' => '300']]],
			'set too small' => ['setWarmupInterval', ['params' => ['interval' => 299]]],
			'set negative' => ['setWarmupInterval', ['params' => ['interval' => -5]]],
			'set text' => ['setWarmupInterval', ['params' => ['interval' => 'abc']]],
			'set null' => ['setWarmupInterval', ['params' => ['interval' => null]]],
			'set throws' => ['setWarmupInterval', ['params' => ['interval' => 600], 'throw' => true]],
		];
	}

	#[DataProvider('cases')]
	public function testActionMatchesTheRecording(string $action, array $case): void {
		$this->calls = [];
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturnCallback(function () use ($case) {
			$this->calls[] = ['request.getParams' => []];
			return $case['params'] ?? [];
		});

		$controller = CacheSettingsControllerFactory::create(
			'openregister',
			$request,
			$this->settings($case),
			$this->logger(),
			$this->createMock(Factory::class),
			$this->config($case)
		);

		$response = $controller->$action();
		$this->compare($this->dataName(), ['calls' => $this->calls, 'status' => $response->getStatus(), 'data' => $response->getData()]);
	}

	private function settings(array $case): SettingsService {
		$settings = $this->createMock(SettingsService::class);
		foreach (['getCacheStats', 'clearCache', 'warmupNamesCache'] as $method) {
			$settings->method($method)->willReturnCallback(function (...$args) use ($case, $method) {
				$this->calls[] = ['settings.' . $method => $args];
				if (($case['throw'] ?? false) === true) {
					throw new Exception($method . ' failed');
				}

				return ['done' => $method];
			});
		}

		return $settings;
	}

	private function config(array $case): IAppConfig {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(function (string $app, string $key, string $default = '') use ($case) {
			$this->calls[] = ['config.getValueString' => [$app, $key, $default]];
			if (($case['throw'] ?? false) === true) {
				throw new Exception('config unavailable');
			}

			return $case['config'][$key] ?? $default;
		});
		$config->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value) use ($case) {
			$this->calls[] = ['config.setValueString' => [$app, $key, $value]];
			if (($case['throw'] ?? false) === true) {
				throw new Exception('config read-only');
			}

			return true;
		});
		return $config;
	}

	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error', 'debug', 'info'] as $level) {
			$logger->method($level)->willReturnCallback(function (...$args) use ($level) {
				unset($args[1]['line'], $args[1]['file']);
				$this->calls[] = ['logger.' . $level => $args];
			});
		}

		return $logger;
	}

	/**
	 * Compare a recording with the fixture, or rewrite it under UPDATE_GOLDEN=1.
	 *
	 * @param string $key The case name.
	 * @param array<string, mixed> $actual The recording.
	 *
	 * @return void
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
