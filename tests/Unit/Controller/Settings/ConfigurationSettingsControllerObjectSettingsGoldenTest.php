<?php

/**
 * Golden test for the ConfigurationSettingsController object settings actions.
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
use OCA\OpenRegister\Controller\Settings\ConfigurationSettingsController;
use OCA\OpenRegister\Kernel\Contracts\AppSettingsInterface;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins every collaborator call and the response of the object settings actions.
 *
 * Each case records the settings service calls, plus the response status and
 * data, and compares the recording with
 * fixtures/configuration-object-settings-golden.json.
 * Set UPDATE_GOLDEN=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Controller\Settings\ConfigurationSettingsController
 */
class ConfigurationSettingsControllerObjectSettingsGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/configuration-object-settings-golden.json';

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
		return [
			'get' => ['getObjectSettings', []],
			'get throws' => ['getObjectSettings', ['throw' => true]],
			'update provider object' => ['updateObjectSettings', ['params' => ['provider' => ['id' => 'solr', 'label' => 'Solr'], 'x' => 1]]],
			'update provider object without id' => ['updateObjectSettings', ['params' => ['provider' => ['label' => 'Solr']]]],
			'update provider id' => ['updateObjectSettings', ['params' => ['provider' => 'solr']]],
			'update provider null' => ['updateObjectSettings', ['params' => ['provider' => null]]],
			'update nothing' => ['updateObjectSettings', []],
			'update throws' => ['updateObjectSettings', ['params' => ['provider' => 'solr'], 'throw' => true]],
			'patch provider object' => ['patchObjectSettings', ['params' => ['provider' => ['id' => 'db']]]],
		];
	}

	#[DataProvider('cases')]
	public function testActionMatchesTheRecording(string $action, array $case): void {
		$this->calls = [];
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($case['params'] ?? []);

		$settings = $this->createMock(AppSettingsInterface::class);
		$settings->method('getObjectSettingsOnly')->willReturnCallback(function () use ($case) {
			$this->calls[] = ['settings.getObjectSettingsOnly' => []];
			if (($case['throw'] ?? false) === true) {
				throw new Exception('settings unavailable');
			}

			return ['provider' => 'database'];
		});
		$settings->method('updateObjectSettingsOnly')->willReturnCallback(function (array $data) use ($case) {
			$this->calls[] = ['settings.updateObjectSettingsOnly' => [$data]];
			if (($case['throw'] ?? false) === true) {
				throw new Exception('save failed');
			}

			return ['saved' => $data];
		});

		$controller = new ConfigurationSettingsController('openregister', $request, $settings, $this->createMock(LoggerInterface::class));
		$response = $controller->$action();
		$this->compare($this->dataName(), ['calls' => $this->calls, 'status' => $response->getStatus(), 'data' => $response->getData()]);
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
