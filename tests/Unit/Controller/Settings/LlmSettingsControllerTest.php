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

class LlmSettingsControllerTest extends TestCase
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

    public function testGetLLMSettingsSuccess(): void
    {
        $data = ['provider' => 'openai'];
        $this->settingsService->method('getLLMSettingsOnly')->willReturn($data);

        $result = $this->controller->getLLMSettings();

        $this->assertEquals(200, $result->getStatus());
        $this->assertEquals($data, $result->getData());
    }

    public function testGetLLMSettingsException(): void
    {
        $this->settingsService->method('getLLMSettingsOnly')
            ->willThrowException(new \Exception('Error'));

        $result = $this->controller->getLLMSettings();

        $this->assertEquals(500, $result->getStatus());
    }

    public function testUpdateLLMSettingsSuccess(): void
    {
        $this->request->method('getParams')->willReturn(['provider' => 'ollama']);
        $this->settingsService->method('updateLLMSettingsOnly')
            ->willReturn(['provider' => 'ollama']);

        $result = $this->controller->updateLLMSettings();

        $this->assertEquals(200, $result->getStatus());
        $this->assertTrue($result->getData()['success']);
    }

    public function testUpdateLLMSettingsExtractsModelIds(): void
    {
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

    public function testUpdateLLMSettingsException(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->settingsService->method('updateLLMSettingsOnly')
            ->willThrowException(new \Exception('Failed'));

        $result = $this->controller->updateLLMSettings();

        $this->assertEquals(500, $result->getStatus());
        $this->assertFalse($result->getData()['success']);
    }

    public function testPatchLLMSettingsDelegatesToUpdate(): void
    {
        $this->request->method('getParams')->willReturn(['provider' => 'ollama']);
        $this->settingsService->method('updateLLMSettingsOnly')
            ->willReturn(['provider' => 'ollama']);

        $result = $this->controller->patchLLMSettings();

        $this->assertEquals(200, $result->getStatus());
        $this->assertTrue($result->getData()['success']);
    }

    public function testTestEmbeddingMissingProvider(): void
    {
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

    public function testTestEmbeddingInvalidConfig(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['provider', null, 'openai'],
                ['config', [], []],
                ['testText', 'This is a test embedding to verify the LLM configuration.', 'test'],
            ]);

        $result = $this->controller->testEmbedding();

        $this->assertEquals(400, $result->getStatus());
    }

    public function testTestEmbeddingSuccess(): void
    {
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

    public function testTestEmbeddingFailure(): void
    {
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

    public function testTestChatMissingProvider(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['provider', null, ''],
                ['config', [], []],
                ['testMessage', 'Hello! Please respond with a brief greeting.', 'test'],
            ]);

        $result = $this->controller->testChat();

        $this->assertEquals(400, $result->getStatus());
    }

    public function testCheckEmbeddingModelMismatchSuccess(): void
    {
        $this->vectorizationService->method('checkEmbeddingModelMismatch')
            ->willReturn(['has_vectors' => true, 'mismatch' => false]);

        $result = $this->controller->checkEmbeddingModelMismatch();

        $this->assertEquals(200, $result->getStatus());
    }

    public function testCheckEmbeddingModelMismatchException(): void
    {
        $this->vectorizationService->method('checkEmbeddingModelMismatch')
            ->willThrowException(new \Exception('Failed'));

        $result = $this->controller->checkEmbeddingModelMismatch();

        $this->assertEquals(500, $result->getStatus());
    }

    public function testClearAllEmbeddingsSuccess(): void
    {
        $this->vectorizationService->method('clearAllEmbeddings')
            ->willReturn(['success' => true, 'deleted' => 100]);

        $result = $this->controller->clearAllEmbeddings();

        $this->assertEquals(200, $result->getStatus());
    }

    public function testClearAllEmbeddingsFailure(): void
    {
        $this->vectorizationService->method('clearAllEmbeddings')
            ->willReturn(['success' => false, 'error' => 'DB error']);

        $result = $this->controller->clearAllEmbeddings();

        $this->assertEquals(500, $result->getStatus());
    }

    public function testClearAllEmbeddingsException(): void
    {
        $this->vectorizationService->method('clearAllEmbeddings')
            ->willThrowException(new \Exception('Failed'));

        $result = $this->controller->clearAllEmbeddings();

        $this->assertEquals(500, $result->getStatus());
    }

    public function testGetVectorStatsSuccess(): void
    {
        $stats = ['total_vectors' => 500];
        $this->vectorizationService->method('getVectorStats')->willReturn($stats);

        $result = $this->controller->getVectorStats();

        $this->assertEquals(200, $result->getStatus());
        $this->assertTrue($result->getData()['success']);
        $this->assertEquals($stats, $result->getData()['stats']);
    }

    public function testGetVectorStatsException(): void
    {
        $this->vectorizationService->method('getVectorStats')
            ->willThrowException(new \Exception('Failed'));

        $result = $this->controller->getVectorStats();

        $this->assertEquals(500, $result->getStatus());
        $this->assertFalse($result->getData()['success']);
    }
}
