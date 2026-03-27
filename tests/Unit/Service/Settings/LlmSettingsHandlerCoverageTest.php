<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Settings;

use OCA\OpenRegister\Service\Settings\LlmSettingsHandler;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Coverage tests for LlmSettingsHandler — targets backward compatibility branches
 * in getLLMSettingsOnly and updateLLMSettingsOnly.
 */
class LlmSettingsHandlerCoverageTest extends TestCase
{
    private LlmSettingsHandler $handler;
    private IAppConfig&MockObject $appConfig;

    protected function setUp(): void
    {
        parent::setUp();
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->handler = new LlmSettingsHandler($this->appConfig, 'openregister');
    }

    public function testGetLLMSettingsOnlyReturnsDefaultsWhenEmpty(): void
    {
        $this->appConfig->method('getValueString')
            ->with('openregister', 'llm', '')
            ->willReturn('');

        $result = $this->handler->getLLMSettingsOnly();

        $this->assertFalse($result['enabled']);
        $this->assertNull($result['embeddingProvider']);
        $this->assertNull($result['chatProvider']);
        $this->assertSame('php', $result['vectorConfig']['backend']);
        $this->assertSame('_embedding_', $result['vectorConfig']['solrField']);
    }

    public function testGetLLMSettingsOnlyAddsEnabledIfMissing(): void
    {
        $config = json_encode([
            'embeddingProvider' => 'openai',
            'openaiConfig' => ['apiKey' => 'test'],
        ]);
        $this->appConfig->method('getValueString')
            ->with('openregister', 'llm', '')
            ->willReturn($config);

        $result = $this->handler->getLLMSettingsOnly();

        $this->assertFalse($result['enabled']);
        $this->assertSame('openai', $result['embeddingProvider']);
    }

    public function testGetLLMSettingsOnlyAddsVectorConfigIfMissing(): void
    {
        $config = json_encode([
            'enabled' => true,
            'embeddingProvider' => 'ollama',
        ]);
        $this->appConfig->method('getValueString')
            ->with('openregister', 'llm', '')
            ->willReturn($config);

        $result = $this->handler->getLLMSettingsOnly();

        $this->assertTrue($result['enabled']);
        $this->assertSame('php', $result['vectorConfig']['backend']);
        $this->assertSame('_embedding_', $result['vectorConfig']['solrField']);
    }

    public function testGetLLMSettingsOnlyFillsMissingVectorConfigFields(): void
    {
        $config = json_encode([
            'enabled' => true,
            'vectorConfig' => [
                'solrCollection' => 'old-collection',
            ],
        ]);
        $this->appConfig->method('getValueString')
            ->with('openregister', 'llm', '')
            ->willReturn($config);

        $result = $this->handler->getLLMSettingsOnly();

        $this->assertSame('php', $result['vectorConfig']['backend']);
        $this->assertSame('_embedding_', $result['vectorConfig']['solrField']);
        // solrCollection should be removed
        $this->assertArrayNotHasKey('solrCollection', $result['vectorConfig']);
    }

    public function testGetLLMSettingsOnlyPreservesExistingVectorConfigFields(): void
    {
        $config = json_encode([
            'enabled' => true,
            'vectorConfig' => [
                'backend' => 'solr',
                'solrField' => 'custom_field',
            ],
        ]);
        $this->appConfig->method('getValueString')
            ->with('openregister', 'llm', '')
            ->willReturn($config);

        $result = $this->handler->getLLMSettingsOnly();

        $this->assertSame('solr', $result['vectorConfig']['backend']);
        $this->assertSame('custom_field', $result['vectorConfig']['solrField']);
    }

    public function testUpdateLLMSettingsOnlyMergesWithExisting(): void
    {
        // First call is to getLLMSettingsOnly (reads existing)
        // Second call is setValueString (writes)
        $existingConfig = json_encode([
            'enabled' => false,
            'embeddingProvider' => 'openai',
            'openaiConfig' => ['apiKey' => 'old-key', 'model' => 'old-model'],
            'ollamaConfig' => ['url' => 'http://localhost:11434'],
            'fireworksConfig' => ['apiKey' => 'fw-key'],
            'vectorConfig' => ['backend' => 'php', 'solrField' => '_embedding_'],
        ]);

        $this->appConfig->method('getValueString')
            ->willReturn($existingConfig);

        $this->appConfig->expects($this->once())
            ->method('setValueString')
            ->with('openregister', 'llm', $this->isType('string'));

        $result = $this->handler->updateLLMSettingsOnly([
            'enabled' => true,
            'openaiConfig' => ['apiKey' => 'new-key'],
        ]);

        $this->assertTrue($result['enabled']);
        $this->assertSame('new-key', $result['openaiConfig']['apiKey']);
        // old model preserved via PATCH
        $this->assertSame('old-model', $result['openaiConfig']['model']);
    }

    public function testUpdateLLMSettingsOnlyWithAllProviders(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturn('');
        $this->appConfig->expects($this->once())
            ->method('setValueString');

        $result = $this->handler->updateLLMSettingsOnly([
            'enabled' => true,
            'embeddingProvider' => 'fireworks',
            'chatProvider' => 'ollama',
            'openaiConfig' => [
                'apiKey' => 'oai-key',
                'model' => 'text-embedding-3-small',
                'chatModel' => 'gpt-4',
                'organizationId' => 'org-123',
            ],
            'ollamaConfig' => [
                'url' => 'http://ollama:11434',
                'model' => 'nomic-embed',
                'chatModel' => 'llama3',
            ],
            'fireworksConfig' => [
                'apiKey' => 'fw-key',
                'embeddingModel' => 'nomic-ai',
                'chatModel' => 'mixtral',
                'baseUrl' => 'https://custom.api.com',
            ],
            'vectorConfig' => [
                'backend' => 'solr',
                'solrField' => 'vector_field',
            ],
        ]);

        $this->assertTrue($result['enabled']);
        $this->assertSame('fireworks', $result['embeddingProvider']);
        $this->assertSame('ollama', $result['chatProvider']);
        $this->assertSame('oai-key', $result['openaiConfig']['apiKey']);
        $this->assertSame('solr', $result['vectorConfig']['backend']);
    }
}
