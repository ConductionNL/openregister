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

/**
 * Branch coverage tests for LlmSettingsController — targets remaining uncovered
 * branches in updateLLMSettings, testEmbedding, testChat, clearAllEmbeddings,
 * getVectorStats, checkEmbeddingModelMismatch, patchLLMSettings.
 */
class LlmSettingsControllerBranchTest extends TestCase
{
    private LlmSettingsController $controller;
    private IRequest&MockObject $request;
    private IDBConnection&MockObject $db;
    private ContainerInterface&MockObject $container;
    private SettingsService&MockObject $settingsService;
    private VectorizationService&MockObject $vectorizationService;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
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
            $this->logger
        );
    }

    // =========================================================================
    // getLLMSettings
    // =========================================================================

    public function testGetLLMSettingsSuccess(): void
    {
        $this->settingsService->method('getLLMSettingsOnly')
            ->willReturn(['enabled' => true, 'embeddingProvider' => 'openai']);

        $response = $this->controller->getLLMSettings();
        $data = $response->getData();

        $this->assertTrue($data['enabled']);
    }

    public function testGetLLMSettingsException(): void
    {
        $this->settingsService->method('getLLMSettingsOnly')
            ->willThrowException(new \Exception('Settings error'));

        $response = $this->controller->getLLMSettings();
        $this->assertSame(500, $response->getStatus());
    }

    // =========================================================================
    // updateLLMSettings — model ID extraction from objects
    // =========================================================================

    public function testUpdateLLMSettingsExtractsModelIds(): void
    {
        $this->request->method('getParams')->willReturn([
            'fireworksConfig' => [
                'embeddingModel' => ['id' => 'nomic-embed', 'name' => 'Nomic'],
                'chatModel' => ['id' => 'mixtral', 'name' => 'Mixtral'],
            ],
            'openaiConfig' => [
                'model' => ['id' => 'text-embed-3', 'name' => 'Text Embed 3'],
                'chatModel' => ['id' => 'gpt-4', 'name' => 'GPT-4'],
            ],
            'ollamaConfig' => [
                'model' => ['id' => 'nomic', 'name' => 'Nomic'],
                'chatModel' => ['id' => 'llama3', 'name' => 'Llama 3'],
            ],
        ]);

        $this->settingsService->expects($this->once())
            ->method('updateLLMSettingsOnly')
            ->with($this->callback(function ($data) {
                return $data['fireworksConfig']['embeddingModel'] === 'nomic-embed'
                    && $data['openaiConfig']['model'] === 'text-embed-3'
                    && $data['ollamaConfig']['chatModel'] === 'llama3';
            }))
            ->willReturn(['enabled' => true]);

        $response = $this->controller->updateLLMSettings();
        $data = $response->getData();

        $this->assertTrue($data['success']);
    }

    public function testUpdateLLMSettingsException(): void
    {
        $this->request->method('getParams')->willReturn(['enabled' => true]);
        $this->settingsService->method('updateLLMSettingsOnly')
            ->willThrowException(new \Exception('Update failed'));

        $response = $this->controller->updateLLMSettings();
        $data = $response->getData();

        $this->assertFalse($data['success']);
        $this->assertSame(500, $response->getStatus());
    }

    // =========================================================================
    // patchLLMSettings delegates to updateLLMSettings
    // =========================================================================

    public function testPatchLLMSettingsDelegatesToUpdate(): void
    {
        $this->request->method('getParams')->willReturn(['enabled' => true]);
        $this->settingsService->method('updateLLMSettingsOnly')
            ->willReturn(['enabled' => true]);

        $response = $this->controller->patchLLMSettings();
        $data = $response->getData();

        $this->assertTrue($data['success']);
    }

    // =========================================================================
    // testEmbedding
    // =========================================================================

    public function testTestEmbeddingMissingProvider(): void
    {
        $this->request->method('getParam')->willReturnMap([
            ['provider', null, ''],
            ['config', [], ['apiKey' => 'key']],
            ['testText', 'This is a test embedding to verify the LLM configuration.', 'test'],
        ]);

        $response = $this->controller->testEmbedding();
        $data = $response->getData();

        $this->assertFalse($data['success']);
        $this->assertSame(400, $response->getStatus());
    }

    public function testTestEmbeddingInvalidConfig(): void
    {
        $this->request->method('getParam')->willReturnMap([
            ['provider', null, 'openai'],
            ['config', [], []],
            ['testText', 'This is a test embedding to verify the LLM configuration.', 'test'],
        ]);

        $response = $this->controller->testEmbedding();
        $data = $response->getData();

        $this->assertFalse($data['success']);
        $this->assertSame(400, $response->getStatus());
    }

    public function testTestEmbeddingSuccess(): void
    {
        $this->request->method('getParam')->willReturnMap([
            ['provider', null, 'openai'],
            ['config', [], ['apiKey' => 'key']],
            ['testText', 'This is a test embedding to verify the LLM configuration.', 'test'],
        ]);

        $this->vectorizationService->method('testEmbedding')
            ->willReturn(['success' => true, 'dimensions' => 1536]);

        $response = $this->controller->testEmbedding();
        $data = $response->getData();

        $this->assertTrue($data['success']);
        $this->assertSame(200, $response->getStatus());
    }

    public function testTestEmbeddingFailure(): void
    {
        $this->request->method('getParam')->willReturnMap([
            ['provider', null, 'openai'],
            ['config', [], ['apiKey' => 'bad-key']],
            ['testText', 'This is a test embedding to verify the LLM configuration.', 'test'],
        ]);

        $this->vectorizationService->method('testEmbedding')
            ->willReturn(['success' => false, 'error' => 'Invalid key']);

        $response = $this->controller->testEmbedding();
        $this->assertSame(400, $response->getStatus());
    }

    public function testTestEmbeddingException(): void
    {
        $this->request->method('getParam')->willReturnMap([
            ['provider', null, 'openai'],
            ['config', [], ['apiKey' => 'key']],
            ['testText', 'This is a test embedding to verify the LLM configuration.', 'test'],
        ]);

        $this->vectorizationService->method('testEmbedding')
            ->willThrowException(new \Exception('Connection refused'));

        $response = $this->controller->testEmbedding();
        $data = $response->getData();

        $this->assertFalse($data['success']);
        $this->assertSame(400, $response->getStatus());
    }

    // =========================================================================
    // testChat
    // =========================================================================

    public function testTestChatMissingProvider(): void
    {
        $this->request->method('getParam')->willReturnMap([
            ['provider', null, ''],
            ['config', [], ['apiKey' => 'key']],
            ['testMessage', 'Hello! Please respond with a brief greeting.', 'Hi'],
        ]);

        $response = $this->controller->testChat();
        $data = $response->getData();

        $this->assertFalse($data['success']);
        $this->assertSame(400, $response->getStatus());
    }

    public function testTestChatInvalidConfig(): void
    {
        $this->request->method('getParam')->willReturnMap([
            ['provider', null, 'openai'],
            ['config', [], []],
            ['testMessage', 'Hello! Please respond with a brief greeting.', 'Hi'],
        ]);

        $response = $this->controller->testChat();
        $data = $response->getData();

        $this->assertFalse($data['success']);
    }

    // =========================================================================
    // checkEmbeddingModelMismatch
    // =========================================================================

    public function testCheckEmbeddingModelMismatchSuccess(): void
    {
        $this->vectorizationService->method('checkEmbeddingModelMismatch')
            ->willReturn(['has_vectors' => true, 'mismatch' => false]);

        $response = $this->controller->checkEmbeddingModelMismatch();
        $data = $response->getData();

        $this->assertTrue($data['has_vectors']);
        $this->assertFalse($data['mismatch']);
    }

    public function testCheckEmbeddingModelMismatchException(): void
    {
        $this->vectorizationService->method('checkEmbeddingModelMismatch')
            ->willThrowException(new \Exception('DB error'));

        $response = $this->controller->checkEmbeddingModelMismatch();
        $data = $response->getData();

        $this->assertFalse($data['has_vectors']);
        $this->assertSame(500, $response->getStatus());
    }

    // =========================================================================
    // clearAllEmbeddings
    // =========================================================================

    public function testClearAllEmbeddingsSuccess(): void
    {
        $this->vectorizationService->method('clearAllEmbeddings')
            ->willReturn(['success' => true, 'deleted' => 50]);

        $response = $this->controller->clearAllEmbeddings();
        $data = $response->getData();

        $this->assertTrue($data['success']);
    }

    public function testClearAllEmbeddingsFailure(): void
    {
        $this->vectorizationService->method('clearAllEmbeddings')
            ->willReturn(['success' => false, 'error' => 'Permission denied']);

        $response = $this->controller->clearAllEmbeddings();
        $this->assertSame(500, $response->getStatus());
    }

    public function testClearAllEmbeddingsException(): void
    {
        $this->vectorizationService->method('clearAllEmbeddings')
            ->willThrowException(new \Exception('DB error'));

        $response = $this->controller->clearAllEmbeddings();
        $this->assertSame(500, $response->getStatus());
    }

    // =========================================================================
    // getVectorStats
    // =========================================================================

    public function testGetVectorStatsSuccess(): void
    {
        $this->vectorizationService->method('getVectorStats')
            ->willReturn(['total' => 100, 'models' => ['nomic']]);

        $response = $this->controller->getVectorStats();
        $data = $response->getData();

        $this->assertTrue($data['success']);
        $this->assertSame(100, $data['stats']['total']);
    }

    public function testGetVectorStatsException(): void
    {
        $this->vectorizationService->method('getVectorStats')
            ->willThrowException(new \Exception('Stats error'));

        $response = $this->controller->getVectorStats();
        $data = $response->getData();

        $this->assertFalse($data['success']);
        $this->assertSame(500, $response->getStatus());
    }
}
