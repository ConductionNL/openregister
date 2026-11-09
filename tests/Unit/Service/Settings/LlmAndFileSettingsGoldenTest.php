<?php

/**
 * Golden test for the LLM and file management settings sections.
 *
 * Records the result, every app config write and every change recorder call of
 * LlmSettingsHandler and FileSettingsHandler get and update, over a set of stored
 * states, in fixtures/llm-and-file-settings-golden.json. Set UPDATE_GOLDEN=1 to rewrite it.
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

use OCA\OpenRegister\Service\Settings\FileSettingsHandler;
use OCA\OpenRegister\Service\Settings\LlmSettingsHandler;
use OCA\OpenRegister\Service\Settings\OwnSettingsChangeRecorder;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * @covers \OCA\OpenRegister\Service\Settings\LlmSettingsHandler
 * @covers \OCA\OpenRegister\Service\Settings\FileSettingsHandler
 * @uses   \OCA\OpenRegister\Service\Decision\ReviewPolicy
 * @uses   \OCA\OpenRegister\Service\Decision\ReviewAnnotationValidator
 */
class LlmAndFileSettingsGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/llm-and-file-settings-golden.json';

	/**
	 * Stored app config states.
	 *
	 * @return array<string, array<string, string>>
	 */
	private static function stores(): array {
		return [
			'empty' => [],
			'stored' => [
				'llm' => '{"embeddingProvider":"openai","openaiConfig":{"apiKey":"k-1","model":"m"},"vectorConfig":{"solrField":"f","solrCollection":"c","dims":3}}',
				'fileManagement' => '{"batchSize":0,"openAnonymiserPassword":"p","provider":"x","reviewDefault":{"owner":"reviewer"}}',
				'openAnonymiserPassword' => 'secret',
			],
			'stored full' => [
				'llm' => '{"enabled":true,"chatProvider":"ollama","ollamaConfig":{"url":"http://o"},"fireworksConfig":"text","vectorConfig":{"backend":"db"}}',
				'fileManagement' => '{"batchSize":"9999","openAnonymiserUsername":5}',
			],
			'no vector backend' => [
				'llm' => '{"enabled":false,"vectorConfig":[]}',
				'fileManagement' => '{"chunkSize":5}',
			],
			'password only' => [
				'openAnonymiserPassword' => 'secret',
			],
			'null json' => [
				'llm' => 'null',
				'fileManagement' => 'null',
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
			$out[$store . ' llm get'] = [$store, 'llm', 'getLLMSettingsOnly', null];
			$out[$store . ' file get'] = [$store, 'file', 'getFileSettingsOnly', null];
		}

		$llmUpdates = [
			'nothing' => [],
			'top level' => ['enabled' => true, 'embeddingProvider' => 'fireworks', 'chatProvider' => null],
			'nested' => ['openaiConfig' => ['apiKey' => 'new', 'organizationId' => 'o'], 'ollamaConfig' => 'text', 'fireworksConfig' => ['baseUrl' => 'http://f', 'chatModel' => 'c'], 'vectorConfig' => ['backend' => 'solr', 'x' => 1]],
			'extra keys' => ['other' => 1, 'openaiConfig' => ['other' => 2]],
		];
		$fileUpdates = [
			'nothing' => [],
			'values' => ['vectorizationEnabled' => true, 'chunkSize' => 50, 'enabledFileTypes' => ['pdf'], 'batchSize' => 600, 'openAnonymiserUsername' => '  user ', 'dolphinApiKey' => 'd', 'other' => 1],
			'batch low' => ['batchSize' => -3, 'openAnonymiserPassword' => ''],
			'batch text' => ['batchSize' => '25', 'openAnonymiserPassword' => 'new-secret'],
			'password not string' => ['openAnonymiserPassword' => 5, 'reviewDefault' => null],
			'null values' => ['provider' => null, 'chunkOverlap' => null, 'openAnonymiserUsername' => null, 'entityRecognitionMethod' => null],
		];
		foreach (['empty', 'stored', 'stored full', 'null json'] as $store) {
			foreach ($llmUpdates as $name => $data) {
				$out[$store . ' llm update ' . $name] = [$store, 'llm', 'updateLLMSettingsOnly', $data];
			}
		}

		foreach (['empty', 'stored'] as $store) {
			foreach ($fileUpdates as $name => $data) {
				$out[$store . ' file update ' . $name] = [$store, 'file', 'updateFileSettingsOnly', $data];
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

		if ($kind === 'llm') {
			$handler = new LlmSettingsHandler($appConfig, $recorder);
		} else {
			$handler = new FileSettingsHandler($appConfig, 'openregister', $recorder);
		}

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
