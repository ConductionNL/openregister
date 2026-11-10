<?php

/**
 * Golden test for the object, retention and archival settings sections.
 *
 * Records the result, every app config write and every change recorder call of
 * ObjectRetentionHandler get and update, over a set of stored
 * states, in fixtures/object-retention-handler-golden.json. Set UPDATE_GOLDEN=1 to rewrite it.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Settings;

use OCA\OpenRegister\Service\Settings\ObjectRetentionHandler;
use OCA\OpenRegister\Service\Settings\OwnSettingsChangeRecorder;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * @covers \OCA\OpenRegister\Service\Settings\ObjectRetentionHandler
 * @uses   \OCA\OpenRegister\Service\Settings\SettingsSectionDefaults
 */
class ObjectRetentionHandlerGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/object-retention-handler-golden.json';

	/**
	 * Stored app config states.
	 *
	 * @return array<string, array<string, string>>
	 */
	private static function stores(): array {
		return [
			'empty' => [],
			'stored' => [
				'objectManagement' => '{"vectorizationEnabled":true,"vectorizeAllSchemas":false,"enabledSchemas":["s1"],"batchSize":null,"other":1}',
				'retention' => '{"readLogRetention":5,"auditTrailsEnabled":"yes","searchTrailsEnabled":"0","searchTrailRecordingMode":"ALL","x":1}',
				'archival' => '{"notificationLeadDays":7,"selectielijstRegister":"r","destructionBatchSize":null}',
			],
			'current keys' => [
				'objectManagement' => '{"vectorizeAllViews":true,"vectorizeAllSchemas":false,"enabledViews":["v"],"enabledSchemas":["s"]}',
				'retention' => '{"auditTrailsEnabled":0,"searchTrailsEnabled":2.5,"searchTrailRecordingMode":"bogus"}',
				'archival' => '[]',
			],
			'odd values' => [
				'objectManagement' => '{"vectorizeAllViews":null,"enabledViews":null,"enabledSchemas":"text"}',
				'retention' => '{"auditTrailsEnabled":null,"searchTrailsEnabled":[],"searchTrailRecordingMode":5}',
				'archival' => '"text"',
			],
			'scalar json' => [
				'objectManagement' => '"text"',
				'retention' => '7',
				'archival' => 'true',
			],
			'null json' => [
				'objectManagement' => 'null',
				'retention' => 'null',
				'archival' => 'null',
			],
		];
	}

	/**
	 * Scenarios: [store, handler, call, argument].
	 *
	 * @return array<string, array>
	 */
	public static function scenarios(): array {
		$out = [];
		foreach (array_keys(self::stores()) as $store) {
			foreach (['getObjectSettingsOnly', 'getRetentionSettingsOnly', 'getArchivalSettingsOnly', 'getVersionInfoOnly'] as $call) {
				$out[$store . ' ' . $call] = [$store, 'object', $call, null];
			}
		}

		$updates = [
			'nothing' => [],
			'values' => [
				'vectorizationEnabled' => true, 'vectorizeAllSchemas' => false, 'enabledViews' => ['a'], 'batchSize' => 3,
				'readLogRetention' => 1, 'auditTrailsEnabled' => 'no', 'searchTrailRecordingMode' => 'None',
				'notificationLeadDays' => 2, 'archivalRegister' => 'ar', 'other' => 1,
			],
			'nulls' => ['maxNestingDepth' => null, 'searchTrailRecordingMode' => null, 'defaultExtensionPeriod' => null],
			'bad mode' => ['searchTrailRecordingMode' => ['all']],
		];
		foreach ($updates as $name => $data) {
			foreach (['updateObjectSettingsOnly', 'updateRetentionSettingsOnly', 'updateArchivalSettingsOnly'] as $call) {
				$out[$call . ' ' . $name] = ['stored', 'object', $call, $data];
			}
		}

		return $out;
	}

	/**
	 * @dataProvider scenarios
	 */
	public function testSettingsGolden(string $store, string $kind, string $call, ?array $argument): void {
		$log = [];
		$values = self::stores()[$store];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use (&$values) {
				return $values[$key] ?? $default;
			}
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value, bool $lazy = false, bool $sensitive = false) use (&$log, &$values) {
				$log[] = ['setValueString', $key, $value, $sensitive];
				$values[$key] = $value;
				return true;
			}
		);
		$appConfig->method('deleteKey')->willReturnCallback(
			function (string $app, string $key) use (&$log, &$values) {
				$log[] = ['deleteKey', $key];
				unset($values[$key]);
			}
		);

		$recorder = $this->createMock(OwnSettingsChangeRecorder::class);
		$recorder->method('snapshot')->willReturnCallback(
			function (array $keys) use (&$log) {
				$log[] = ['snapshot', $keys];
				return ['settings' => [], 'security' => []];
			}
		);
		$recorder->method('record')->willReturnCallback(
			function (array $before, array $keys) use (&$log) {
				$log[] = ['record', $keys];
				return 0;
			}
		);

		$handler = new ObjectRetentionHandler($appConfig, $recorder);

		try {
			if ($argument === null) {
				$result = $handler->$call();
			} else {
				$result = $handler->$call($argument);
			}
		} catch (Throwable $e) {
			$result = ['thrown' => get_class($e), 'message' => $e->getMessage()];
		}

		$actual = ['result' => $result, 'writes' => $log];
		$name = $this->dataName();
		$golden = [];
		if (file_exists(self::FIXTURE) === true) {
			$golden = json_decode((string) file_get_contents(self::FIXTURE), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . "\n");
		}

		$this->assertArrayHasKey($name, $golden, 'No golden recorded for ' . $name);
		$this->assertSame($golden[$name], json_decode(json_encode($actual, JSON_PRESERVE_ZERO_FRACTION), true));
	}
}
