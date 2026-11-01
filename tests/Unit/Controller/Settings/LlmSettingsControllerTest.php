<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Settings;

use OCA\OpenRegister\Controller\Settings\LlmSettingsController;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\VectorizationService;
use OCP\IDBConnection;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class LlmSettingsControllerTest extends TestCase {
	private LlmSettingsController $controller;
	private IRequest&MockObject $request;
	private IDBConnection&MockObject $db;
	private ContainerInterface&MockObject $container;
	private SettingsService&MockObject $settingsService;
	private VectorizationService&MockObject $vectorizationService;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->vectorizationService = $this->createMock(VectorizationService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->controller = new LlmSettingsController(
			'openregister',
			$this->request,
			$this->db,
			$this->container,
			$this->settingsService,
			$this->vectorizationService,
			$this->logger,
			$this->createMock(originalClassName: \OCA\OpenRegister\Service\Connection\ConnectionReporter::class)
		);
	}

	public function testGetLLMSettingsSuccess(): void {
		$data = ['provider' => 'openai'];
		$this->settingsService->method('getLLMSettingsOnly')->willReturn($data);

		$result = $this->controller->getLLMSettings();

		$this->assertEquals(200, $result->getStatus());
		$this->assertEquals($data, $result->getData());
	}

	public function testGetLLMSettingsException(): void {
		$this->settingsService->method('getLLMSettingsOnly')
			->willThrowException(new \Exception('Error'));

		$result = $this->controller->getLLMSettings();

		$this->assertEquals(500, $result->getStatus());
	}

	public function testUpdateLLMSettingsSuccess(): void {
		$this->request->method('getParams')->willReturn(['provider' => 'ollama']);
		$this->settingsService->method('updateLLMSettingsOnly')
			->willReturn(['provider' => 'ollama']);

		$result = $this->controller->updateLLMSettings();

		$this->assertEquals(200, $result->getStatus());
		$this->assertTrue($result->getData()['success']);
	}

	public function testUpdateLLMSettingsExtractsModelIds(): void {
		$this->request->method('getParams')->willReturn([
			'fireworksConfig' => [
				'embeddingModel' => ['id' => 'nomic-embed', 'name' => 'Nomic'],
				'chatModel' => ['id' => 'llama3', 'name' => 'Llama 3'],
			],
			'openaiConfig' => [
				'model' => ['id' => 'text-embedding-3-small'],
				'chatModel' => ['id' => 'gpt-4'],
			],
			'ollamaConfig' => [
				'model' => ['id' => 'nomic-embed-text'],
				'chatModel' => ['id' => 'llama3'],
			],
		]);
		$this->settingsService->expects($this->once())
			->method('updateLLMSettingsOnly')
			->with($this->callback(function ($data) {
				return $data['fireworksConfig']['embeddingModel'] === 'nomic-embed'
					&& $data['fireworksConfig']['chatModel'] === 'llama3'
					&& $data['openaiConfig']['model'] === 'text-embedding-3-small'
					&& $data['openaiConfig']['chatModel'] === 'gpt-4'
					&& $data['ollamaConfig']['model'] === 'nomic-embed-text'
					&& $data['ollamaConfig']['chatModel'] === 'llama3';
			}))
			->willReturn(['updated' => true]);

		$this->controller->updateLLMSettings();
	}

	public function testUpdateLLMSettingsException(): void {
		$this->request->method('getParams')->willReturn([]);
		$this->settingsService->method('updateLLMSettingsOnly')
			->willThrowException(new \Exception('Failed'));

		$result = $this->controller->updateLLMSettings();

		$this->assertEquals(500, $result->getStatus());
		$this->assertFalse($result->getData()['success']);
	}

	public function testPatchLLMSettingsDelegatesToUpdate(): void {
		$this->request->method('getParams')->willReturn(['provider' => 'ollama']);
		$this->settingsService->method('updateLLMSettingsOnly')
			->willReturn(['provider' => 'ollama']);

		$result = $this->controller->patchLLMSettings();

		$this->assertEquals(200, $result->getStatus());
		$this->assertTrue($result->getData()['success']);
	}

	public function testTestEmbeddingMissingProvider(): void {
		$this->request->method('getParam')
			->willReturnMap([
				['provider', null, ''],
				['config', [], []],
				['testText', 'This is a test embedding to verify the LLM configuration.', 'test'],
			]);

		$result = $this->controller->testEmbedding();

		$this->assertEquals(400, $result->getStatus());
		$this->assertFalse($result->getData()['success']);
	}

	public function testTestEmbeddingInvalidConfig(): void {
		$this->request->method('getParam')
			->willReturnMap([
				['provider', null, 'openai'],
				['config', [], []],
				['testText', 'This is a test embedding to verify the LLM configuration.', 'test'],
			]);

		$result = $this->controller->testEmbedding();

		$this->assertEquals(400, $result->getStatus());
	}

	public function testTestEmbeddingSuccess(): void {
		$this->request->method('getParam')
			->willReturnMap([
				['provider', null, 'openai'],
				['config', [], ['apiKey' => 'key']],
				['testText', 'This is a test embedding to verify the LLM configuration.', 'test'],
			]);
		$this->vectorizationService->method('testEmbedding')
			->willReturn(['success' => true, 'dimensions' => 1536]);

		$result = $this->controller->testEmbedding();

		$this->assertEquals(200, $result->getStatus());
	}

	public function testTestEmbeddingFailure(): void {
		$this->request->method('getParam')
			->willReturnMap([
				['provider', null, 'openai'],
				['config', [], ['apiKey' => 'key']],
				['testText', 'This is a test embedding to verify the LLM configuration.', 'test'],
			]);
		$this->vectorizationService->method('testEmbedding')
			->willReturn(['success' => false, 'error' => 'Invalid key']);

		$result = $this->controller->testEmbedding();

		$this->assertEquals(400, $result->getStatus());
	}

	public function testTestChatMissingProvider(): void {
		$this->request->method('getParam')
			->willReturnMap([
				['provider', null, ''],
				['config', [], []],
				['testMessage', 'Hello! Please respond with a brief greeting.', 'test'],
			]);

		$result = $this->controller->testChat();

		$this->assertEquals(400, $result->getStatus());
	}

	public function testTestEmbeddingException(): void {
		$this->request->method('getParam')
			->willReturnMap([
				['provider', null, 'openai'],
				['config', [], ['apiKey' => 'key']],
				['testText', 'This is a test embedding to verify the LLM configuration.', 'test'],
			]);
		$this->vectorizationService->method('testEmbedding')
			->willThrowException(new \Exception('Connection failed'));

		$result = $this->controller->testEmbedding();

		$this->assertEquals(400, $result->getStatus());
		$this->assertFalse($result->getData()['success']);
		$this->assertStringContainsString('Connection failed', $result->getData()['error']);
	}

	public function testTestChatInvalidConfig(): void {
		$this->request->method('getParam')
			->willReturnMap([
				['provider', null, 'openai'],
				['config', [], []],
				['testMessage', 'Hello! Please respond with a brief greeting.', 'test'],
			]);

		$result = $this->controller->testChat();

		$this->assertEquals(400, $result->getStatus());
		$this->assertFalse($result->getData()['success']);
	}

	/**
	 * The controller passes the request values to ChatService::testChat() by
	 * the service's own parameter names, so the call reaches the service.
	 */
	public function testTestChatPassesTheRequestToChatService(): void {
		$chatServiceMock = $this->createMock(\OCA\OpenRegister\Service\ChatService::class);
		$chatServiceMock->expects($this->once())
			->method('testChat')
			->with('openai', ['apiKey' => 'key'], 'Hi')
			->willReturn(['success' => true, 'message' => 'ok']);

		$this->request->method('getParam')
			->willReturnMap([
				['provider', null, 'openai'],
				['config', [], ['apiKey' => 'key']],
				['testMessage', 'Hello! Please respond with a brief greeting.', 'Hi'],
			]);
		$this->container->method('get')
			->with('OCA\OpenRegister\Service\ChatService')
			->willReturn($chatServiceMock);

		$result = $this->controller->testChat();

		$this->assertSame(200, $result->getStatus());
		$this->assertSame(['success' => true, 'message' => 'ok'], $result->getData());
	}

	/**
	 * The status follows the chat test result: 200 on success, 400 otherwise.
	 *
	 * The container answers an object with ChatService's parameter names,
	 * so the result handling runs.
	 */
	public function testTestChatStatusFollowsTheResult(): void {
		$this->request->method('getParam')
			->willReturnMap([
				['provider', null, 'openai'],
				['config', [], ['apiKey' => 'key']],
				['testMessage', 'Hello! Please respond with a brief greeting.', 'Hi'],
			]);
		$chat = new class {
			/** @var list<bool> */
			public array $answers = [true, false];

			public function testChat(string $provider, array $config, string $_testMessage): array {
				return ['success' => array_shift($this->answers), 'provider' => $provider, 'message' => $_testMessage];
			}
		};
		$this->container->method('get')->willReturn($chat);

		$ok = $this->controller->testChat();
		$this->assertSame(200, $ok->getStatus());
		$this->assertSame(['success' => true, 'provider' => 'openai', 'message' => 'Hi'], $ok->getData());

		$failed = $this->controller->testChat();
		$this->assertSame(400, $failed->getStatus());
		$this->assertFalse($failed->getData()['success']);
	}

	public function testTestChatException(): void {
		$this->request->method('getParam')
			->willReturnMap([
				['provider', null, 'openai'],
				['config', [], ['apiKey' => 'key']],
				['testMessage', 'Hello! Please respond with a brief greeting.', 'Hi'],
			]);
		$this->container->method('get')
			->willThrowException(new \Exception('Service not found'));

		$result = $this->controller->testChat();

		$this->assertEquals(400, $result->getStatus());
		$this->assertFalse($result->getData()['success']);
	}

	public function testUpdateLLMSettingsWithStringModelValues(): void {
		// When model values are already strings (not arrays), they should pass through unchanged
		$this->request->method('getParams')->willReturn([
			'fireworksConfig' => [
				'embeddingModel' => 'nomic-embed',
				'chatModel' => 'llama3',
			],
			'openaiConfig' => [
				'model' => 'text-embedding-3-small',
				'chatModel' => 'gpt-4',
			],
			'ollamaConfig' => [
				'model' => 'nomic-embed-text',
				'chatModel' => 'llama3',
			],
		]);
		$this->settingsService->expects($this->once())
			->method('updateLLMSettingsOnly')
			->with($this->callback(function ($data) {
				return $data['fireworksConfig']['embeddingModel'] === 'nomic-embed'
					&& $data['openaiConfig']['model'] === 'text-embedding-3-small'
					&& $data['ollamaConfig']['chatModel'] === 'llama3';
			}))
			->willReturn(['updated' => true]);

		$result = $this->controller->updateLLMSettings();
		$this->assertEquals(200, $result->getStatus());
	}

	public function testUpdateLLMSettingsWithNullModelValues(): void {
		$this->request->method('getParams')->willReturn([
			'fireworksConfig' => [
				'embeddingModel' => null,
				'chatModel' => null,
			],
		]);
		$this->settingsService->method('updateLLMSettingsOnly')
			->willReturn(['updated' => true]);

		$result = $this->controller->updateLLMSettings();
		$this->assertEquals(200, $result->getStatus());
	}

	public function testGetOllamaModelsException(): void {
		$this->settingsService->method('getLLMSettingsOnly')
			->willThrowException(new \Exception('Settings error'));

		$result = $this->controller->getOllamaModels();

		$this->assertEquals(500, $result->getStatus());
		$this->assertFalse($result->getData()['success']);
	}

	public function testUpdateLLMSettingsWithArrayModelMissingId(): void {
		// When array model has no 'id' key, it should be set to null.
		$this->request->method('getParams')->willReturn([
			'fireworksConfig' => [
				'embeddingModel' => ['name' => 'no-id-model'],
			],
		]);
		$this->settingsService->expects($this->once())
			->method('updateLLMSettingsOnly')
			->with($this->callback(function ($data) {
				return $data['fireworksConfig']['embeddingModel'] === null;
			}))
			->willReturn(['updated' => true]);

		$result = $this->controller->updateLLMSettings();
		$this->assertEquals(200, $result->getStatus());
	}

	public function testUpdateLLMSettingsResponseContainsMessage(): void {
		$this->request->method('getParams')->willReturn([]);
		$this->settingsService->method('updateLLMSettingsOnly')->willReturn(['provider' => 'ollama']);

		$result = $this->controller->updateLLMSettings();

		$data = $result->getData();
		$this->assertEquals('LLM settings updated successfully', $data['message']);
		$this->assertArrayHasKey('data', $data);
	}

	public function testTestEmbeddingWithDefaultTestText(): void {
		// When testText is not provided, the default text is used.
		$this->request->method('getParam')
			->willReturnMap([
				['provider', null, 'ollama'],
				['config', [], ['url' => 'http://localhost:11434']],
				['testText', 'This is a test embedding to verify the LLM configuration.', 'This is a test embedding to verify the LLM configuration.'],
			]);
		$this->vectorizationService->method('testEmbedding')
			->willReturn(['success' => true, 'dimensions' => 768]);

		$result = $this->controller->testEmbedding();

		$this->assertEquals(200, $result->getStatus());
		$this->assertTrue($result->getData()['success']);
	}

	public function testTestChatWithDefaultMessage(): void {
		// When testMessage is not provided, the default message is used.
		// Container.get throws, which exercises the exception path.
		$this->request->method('getParam')
			->willReturnMap([
				['provider', null, 'openai'],
				['config', [], ['apiKey' => 'test-key']],
				['testMessage', 'Hello! Please respond with a brief greeting.', 'Hello! Please respond with a brief greeting.'],
			]);
		$this->container->method('get')
			->willThrowException(new \Exception('ChatService unavailable'));

		$result = $this->controller->testChat();

		$this->assertEquals(400, $result->getStatus());
		$this->assertFalse($result->getData()['success']);
		$this->assertStringContainsString('ChatService unavailable', $result->getData()['error']);
	}

	public function testCheckEmbeddingModelMismatchReturnsMismatchData(): void {
		$mismatchData = [
			'has_vectors' => true,
			'mismatch' => true,
			'current_model' => 'text-embedding-3-small',
			'stored_model' => 'text-embedding-ada-002',
		];
		$this->vectorizationService->method('checkEmbeddingModelMismatch')
			->willReturn($mismatchData);

		$result = $this->controller->checkEmbeddingModelMismatch();

		$this->assertEquals(200, $result->getStatus());
		$this->assertTrue($result->getData()['mismatch']);
	}

	public function testClearAllEmbeddingsReturns500WhenSuccessFalse(): void {
		$this->vectorizationService->method('clearAllEmbeddings')
			->willReturn(['success' => false, 'error' => 'Permission denied']);

		$result = $this->controller->clearAllEmbeddings();

		$this->assertEquals(500, $result->getStatus());
		$this->assertFalse($result->getData()['success']);
	}

	public function testPatchLLMSettingsWithAllModelConfigs(): void {
		// patchLLMSettings delegates to updateLLMSettings — test end-to-end with all configs.
		$this->request->method('getParams')->willReturn([
			'fireworksConfig' => ['embeddingModel' => ['id' => 'fw-embed'], 'chatModel' => ['id' => 'fw-chat']],
			'openaiConfig' => ['model' => ['id' => 'oai-embed'], 'chatModel' => ['id' => 'oai-chat']],
			'ollamaConfig' => ['model' => ['id' => 'oll-embed'], 'chatModel' => ['id' => 'oll-chat']],
		]);
		$this->settingsService->method('updateLLMSettingsOnly')->willReturn(['ok' => true]);

		$result = $this->controller->patchLLMSettings();

		$this->assertEquals(200, $result->getStatus());
		$this->assertTrue($result->getData()['success']);
	}

	public function testCheckEmbeddingModelMismatchSuccess(): void {
		$this->vectorizationService->method('checkEmbeddingModelMismatch')
			->willReturn(['has_vectors' => true, 'mismatch' => false]);

		$result = $this->controller->checkEmbeddingModelMismatch();

		$this->assertEquals(200, $result->getStatus());
	}

	public function testCheckEmbeddingModelMismatchException(): void {
		$this->vectorizationService->method('checkEmbeddingModelMismatch')
			->willThrowException(new \Exception('Failed'));

		$result = $this->controller->checkEmbeddingModelMismatch();

		$this->assertEquals(500, $result->getStatus());
	}

	public function testClearAllEmbeddingsSuccess(): void {
		$this->vectorizationService->method('clearAllEmbeddings')
			->willReturn(['success' => true, 'deleted' => 100]);

		$result = $this->controller->clearAllEmbeddings();

		$this->assertEquals(200, $result->getStatus());
	}

	public function testClearAllEmbeddingsFailure(): void {
		$this->vectorizationService->method('clearAllEmbeddings')
			->willReturn(['success' => false, 'error' => 'DB error']);

		$result = $this->controller->clearAllEmbeddings();

		$this->assertEquals(500, $result->getStatus());
	}

	public function testClearAllEmbeddingsException(): void {
		$this->vectorizationService->method('clearAllEmbeddings')
			->willThrowException(new \Exception('Failed'));

		$result = $this->controller->clearAllEmbeddings();

		$this->assertEquals(500, $result->getStatus());
	}

	public function testGetVectorStatsSuccess(): void {
		$stats = [
			'total_vectors' => 100,
			'by_type' => ['object' => 70, 'file' => 30],
			'by_model' => ['text-embedding-3-small' => 60, 'nomic-embed-text' => 40],
			'object_vectors' => 70,
			'file_vectors' => 30,
		];
		$this->vectorizationService->expects($this->once())
			->method('getVectorStats')
			->willReturn($stats);

		$result = $this->controller->getVectorStats();

		$this->assertEquals(200, $result->getStatus());
		$this->assertSame(array_merge(['success' => true], $stats), $result->getData());
	}

	public function testGetVectorStatsException(): void {
		$this->vectorizationService->method('getVectorStats')
			->willThrowException(new \Exception('DB error'));

		$result = $this->controller->getVectorStats();
		$data = $result->getData();

		$this->assertEquals(500, $result->getStatus());
		$this->assertFalse($data['success']);
		$this->assertSame('DB error', $data['error']);
		$this->assertArrayNotHasKey('trace', $data);
	}

	public function testGetVectorStatsRouteIsRegistered(): void {
		$routes = require __DIR__ . '/../../../../appinfo/routes.php';
		$matches = array_filter(
			$routes['routes'],
			static fn (array $route): bool => $route['url'] === '/api/vectors/stats'
		);

		$this->assertCount(1, $matches);
		$route = array_values($matches)[0];
		$this->assertSame('Settings\\LlmSettings#getVectorStats', $route['name']);
		$this->assertSame('GET', $route['verb']);
	}

	// =========================================================================
	// getOllamaModels() — curl execution paths
	// =========================================================================

	/**
	 * Test getOllamaModels with settings returned — curl connects to
	 * unreachable host, covering the curl error path (lines 336-363).
	 */
	public function testGetOllamaModelsCurlErrorPath(): void {
		$this->settingsService->method('getLLMSettingsOnly')->willReturn([
			'ollamaConfig' => ['url' => 'http://192.0.2.1:19999'],
		]);

		$result = $this->controller->getOllamaModels();

		$this->assertEquals(200, $result->getStatus());
		$data = $result->getData();
		$this->assertFalse($data['success']);
		$this->assertStringContainsString('Failed to connect to Ollama', $data['error']);
		$this->assertEmpty($data['models']);
	}

	/**
	 * Test getOllamaModels with URL returning non-200 HTTP code
	 * (covers HTTP status check path, lines 366-373).
	 */
	public function testGetOllamaModelsHttpNon200(): void {
		$this->settingsService->method('getLLMSettingsOnly')->willReturn([
			'ollamaConfig' => ['url' => 'http://localhost:80'],
		]);

		$result = $this->controller->getOllamaModels();

		$this->assertEquals(200, $result->getStatus());
		$data = $result->getData();
		$this->assertFalse($data['success']);
		$this->assertEmpty($data['models']);
		$this->assertArrayHasKey('error', $data);
	}
}
