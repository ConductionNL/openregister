<?php

/**
 * Golden test for the LlmSettingsController actions other than getOllamaModels().
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

use OCA\OpenRegister\Mcp\Service\Chat\ChatProbe;
use Exception;
use OCA\OpenRegister\Kernel\Contracts\ChatServiceInterface;
use OCA\OpenRegister\Search\Service\VectorizationService;
use OCA\OpenRegister\Service\Connection\ConnectionReporter;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\LlmSettingsControllerFactory;
use OCP\IDBConnection;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Search\Service\Vectorization\EmbeddingProbe;

/**
 * Pins every collaborator call and the response of each LLM settings action.
 *
 * Each case records the calls made on the settings service, vectorization
 * service, chat service and connection reporter, plus the response status and
 * data, and compares the recording with fixtures/llm-actions-golden.json.
 * Set UPDATE_GOLDEN=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Controller\Settings\LlmSettingsController
 * @uses \OCA\OpenRegister\Controller\Support\LlmSettingsPlatform
 * @uses \OCA\OpenRegister\Controller\Support\LlmSettingsDomain
 * @uses \OCA\OpenRegister\Controller\Support\LlmSettingsControllerServices
 */
class LlmSettingsControllerActionsGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/llm-actions-golden.json';

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
		$objects = [
			'fireworksConfig' => ['embeddingModel' => ['id' => 'fw-e', 'name' => 'x'], 'chatModel' => ['id' => 'fw-c']],
			'openaiConfig' => ['model' => ['id' => 'oa-e'], 'chatModel' => ['name' => 'no id'], 'apiKey' => 'k'],
			'ollamaConfig' => ['model' => ['id' => 'ol-e'], 'chatModel' => ['id' => 'ol-c'], 'url' => 'u'],
			'chatProvider' => 'openai',
		];
		$strings = [
			'fireworksConfig' => ['embeddingModel' => 'fw-e', 'chatModel' => null],
			'openaiConfig' => ['model' => 'oa-e', 'chatModel' => 'oa-c'],
			'ollamaConfig' => ['model' => 0, 'chatModel' => ''],
		];
		return [
			'get settings' => ['getLLMSettings', []],
			'get settings throws' => ['getLLMSettings', ['settingsThrow' => true]],
			'update with model objects' => ['updateLLMSettings', ['params' => $objects]],
			'update with plain ids' => ['updateLLMSettings', ['params' => $strings]],
			'update with nothing' => ['updateLLMSettings', ['params' => []]],
			'update result without providers' => ['updateLLMSettings', ['params' => $objects, 'bareResult' => true]],
			'update throws' => ['updateLLMSettings', ['params' => $objects, 'settingsThrow' => true]],
			'patch with model objects' => ['patchLLMSettings', ['params' => $objects]],
			'embedding missing provider' => ['testEmbedding', ['param' => ['config' => ['a' => 1]]]],
			'embedding provider zero' => ['testEmbedding', ['param' => ['provider' => '0', 'config' => ['a' => 1]]]],
			'embedding empty config' => ['testEmbedding', ['param' => ['provider' => 'openai']]],
			'embedding string config' => ['testEmbedding', ['param' => ['provider' => 'openai', 'config' => 'x']]],
			'embedding success' => ['testEmbedding', ['param' => ['provider' => 'openai', 'config' => ['a' => 1]]]],
			'embedding custom text' => ['testEmbedding', ['param' => ['provider' => 'o', 'config' => ['a' => 1], 'testText' => 'hi']]],
			'embedding failure' => ['testEmbedding', ['param' => ['provider' => 'openai', 'config' => ['a' => 1]], 'fail' => true]],
			'embedding throws' => ['testEmbedding', ['param' => ['provider' => 'openai', 'config' => ['a' => 1]], 'throw' => true]],
			'chat missing provider' => ['testChat', ['param' => ['config' => ['a' => 1]]]],
			'chat empty config' => ['testChat', ['param' => ['provider' => 'openai', 'config' => []]]],
			'chat string config' => ['testChat', ['param' => ['provider' => 'openai', 'config' => 'x']]],
			'chat success' => ['testChat', ['param' => ['provider' => 'openai', 'config' => ['a' => 1]]]],
			'chat custom message' => ['testChat', ['param' => ['provider' => 'o', 'config' => ['a' => 1], 'testMessage' => 'yo']]],
			'chat failure' => ['testChat', ['param' => ['provider' => 'openai', 'config' => ['a' => 1]], 'fail' => true]],
			'chat throws' => ['testChat', ['param' => ['provider' => 'openai', 'config' => ['a' => 1]], 'throw' => true]],
			'mismatch' => ['checkEmbeddingModelMismatch', []],
			'mismatch throws' => ['checkEmbeddingModelMismatch', ['throw' => true]],
			'clear success' => ['clearAllEmbeddings', []],
			'clear failure' => ['clearAllEmbeddings', ['fail' => true]],
			'clear throws' => ['clearAllEmbeddings', ['throw' => true]],
			'vector stats' => ['getVectorStats', []],
			'vector stats throws' => ['getVectorStats', ['throw' => true]],
		];
	}

	#[DataProvider('cases')]
	public function testActionMatchesTheRecording(string $action, array $case): void {
		$this->calls = [];
		$controller = LlmSettingsControllerFactory::create(
			'openregister',
			$this->request($case),
			$this->createMock(IDBConnection::class),
			$this->container($case),
			$this->settings($case),
			$this->vectors($case),
			$this->createMock(LoggerInterface::class),
			$this->reporter()
		);

		$response = $controller->$action();
		$actual = ['calls' => $this->calls, 'status' => $response->getStatus(), 'data' => $response->getData()];
		$this->compare($this->dataName(), $actual);
	}

	private function request(array $case): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($case['params'] ?? []);
		$request->method('getParam')->willReturnCallback(
			function (string $key, $default = null) use ($case) {
				$this->calls[] = ['request.getParam' => [$key, $default]];
				return $case['param'][$key] ?? $default;
			}
		);
		return $request;
	}

	private function settings(array $case): SettingsService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getLLMSettingsOnly')->willReturnCallback(function () use ($case) {
			$this->calls[] = ['settings.getLLMSettingsOnly' => []];
			if (($case['settingsThrow'] ?? false) === true) {
				throw new Exception('settings unavailable');
			}

			return ['chatProvider' => 'openai', 'ollamaConfig' => ['url' => 'u']];
		});
		$settings->method('updateLLMSettingsOnly')->willReturnCallback(function (array $data) use ($case) {
			$this->calls[] = ['settings.updateLLMSettingsOnly' => [$data]];
			if (($case['settingsThrow'] ?? false) === true) {
				throw new Exception('save failed');
			}

			if (($case['bareResult'] ?? false) === true) {
				return ['saved' => true];
			}

			return ['chatProvider' => 'openai', 'embeddingProvider' => 'ollama', 'saved' => true];
		});
		return $settings;
	}

	private function vectors(array $case): VectorizationService {
		$vectors = $this->createMock(VectorizationService::class);
		$vectors->method('testEmbedding')->willReturnCallback(function (EmbeddingProbe $probe) use ($case) {
			$this->calls[] = ['vectors.testEmbedding' => [$probe->provider, $probe->config, $probe->testText]];
			return $this->outcome($case, ['dimensions' => 3]);
		});
		$vectors->method('checkEmbeddingModelMismatch')->willReturnCallback(function () use ($case) {
			$this->calls[] = ['vectors.checkEmbeddingModelMismatch' => []];
			return $this->outcome($case, ['has_vectors' => true, 'mismatch' => true]);
		});
		$vectors->method('clearAllEmbeddings')->willReturnCallback(function () use ($case) {
			$this->calls[] = ['vectors.clearAllEmbeddings' => []];
			return $this->outcome($case, ['deleted' => 4]);
		});
		$vectors->method('getVectorStats')->willReturnCallback(function () use ($case) {
			$this->calls[] = ['vectors.getVectorStats' => []];
			return $this->outcome($case, ['total' => 7, 'success' => 'overridden?']);
		});
		return $vectors;
	}

	private function container(array $case): ContainerInterface {
		$chat = $this->createMock(ChatServiceInterface::class);
		$chat->method('testChat')->willReturnCallback(function (ChatProbe $probe) use ($case) {
			$this->calls[] = ['chat.testChat' => [$probe->provider, $probe->config, $probe->testMessage]];
			return $this->outcome($case, ['reply' => 'hello']);
		});
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(function (string $id) use ($chat) {
			$this->calls[] = ['container.get' => [$id]];
			return $chat;
		});
		return $container;
	}

	private function reporter(): ConnectionReporter {
		$reporter = $this->createMock(ConnectionReporter::class);
		$reporter->method('reportLlmProviders')->willReturnCallback(function ($chat, $embedding) {
			$this->calls[] = ['reporter.reportLlmProviders' => [$chat, $embedding]];
			return true;
		});
		return $reporter;
	}

	/**
	 * Build a backend result, or throw when the case asks for it.
	 *
	 * @param array<string, mixed> $case The case options.
	 * @param array<string, mixed> $extra Fields merged after the success flag.
	 *
	 * @return array<string, mixed> The result.
	 */
	private function outcome(array $case, array $extra): array {
		if (($case['throw'] ?? false) === true) {
			throw new Exception('backend down');
		}

		return array_merge(['success' => (($case['fail'] ?? false) === false)], $extra);
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
