<?php

declare(strict_types=1);

/**
 * LlmSettingsHandler Unit Tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Settings
 * @author   OpenRegister Team
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/OpenRegister/OpenRegister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Settings;

use OCA\OpenRegister\Service\Settings\LlmSettingsHandler;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for LlmSettingsHandler
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) Comprehensive coverage requires many test methods
 */
class LlmSettingsHandlerTest extends TestCase
{
    /** @var LlmSettingsHandler */
    private LlmSettingsHandler $handler;

    /** @var IAppConfig&MockObject */
    private IAppConfig $appConfig;

    /**
     * Set up test fixtures
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->handler = new LlmSettingsHandler($this->appConfig, 'openregister');
    }

    /**
     * Test getLLMSettingsOnly returns default config when empty.
     *
     * @return void
     */
    public function testGetLlmSettingsReturnsDefaultWhenEmpty(): void
    {
        $this->appConfig->method('getValueString')
            ->with('openregister', 'llm', '')
            ->willReturn('');

        $result = $this->handler->getLLMSettingsOnly();

        $this->assertFalse($result['enabled']);
        $this->assertNull($result['embeddingProvider']);
        $this->assertNull($result['chatProvider']);
        $this->assertSame('', $result['openaiConfig']['apiKey']);
        $this->assertNull($result['openaiConfig']['model']);
        $this->assertNull($result['openaiConfig']['chatModel']);
        $this->assertSame('', $result['openaiConfig']['organizationId']);
        $this->assertSame('http://localhost:11434', $result['ollamaConfig']['url']);
        $this->assertNull($result['ollamaConfig']['model']);
        $this->assertNull($result['ollamaConfig']['chatModel']);
        $this->assertSame('', $result['fireworksConfig']['apiKey']);
        $this->assertNull($result['fireworksConfig']['embeddingModel']);
        $this->assertNull($result['fireworksConfig']['chatModel']);
        $this->assertSame('https://api.fireworks.ai/inference/v1', $result['fireworksConfig']['baseUrl']);
        $this->assertSame('php', $result['vectorConfig']['backend']);
        $this->assertSame('_embedding_', $result['vectorConfig']['solrField']);
    }

    /**
     * Test getLLMSettingsOnly returns decoded config.
     *
     * @return void
     */
    public function testGetLlmSettingsReturnsDecodedConfig(): void
    {
        $config = [
            'enabled'           => true,
            'embeddingProvider' => 'openai',
            'chatProvider'      => 'ollama',
            'openaiConfig'      => ['apiKey' => 'sk-123', 'model' => 'text-embedding-3-small', 'chatModel' => 'gpt-4', 'organizationId' => 'org-1'],
            'ollamaConfig'      => ['url' => 'http://ollama:11434', 'model' => 'nomic-embed', 'chatModel' => 'llama3'],
            'fireworksConfig'   => ['apiKey' => 'fw-key', 'embeddingModel' => 'thenlper', 'chatModel' => 'llama-v3', 'baseUrl' => 'https://custom.api'],
            'vectorConfig'      => ['backend' => 'solr', 'solrField' => '_vec_'],
        ];

        $this->appConfig->method('getValueString')
            ->willReturn(json_encode($config));

        $result = $this->handler->getLLMSettingsOnly();

        $this->assertTrue($result['enabled']);
        $this->assertSame('openai', $result['embeddingProvider']);
        $this->assertSame('ollama', $result['chatProvider']);
        $this->assertSame('sk-123', $result['openaiConfig']['apiKey']);
        $this->assertSame('solr', $result['vectorConfig']['backend']);
        $this->assertSame('_vec_', $result['vectorConfig']['solrField']);
    }

    /**
     * Test getLLMSettingsOnly adds enabled field for backward compatibility.
     *
     * @return void
     */
    public function testGetLlmSettingsAddsEnabledFieldIfMissing(): void
    {
        $config = ['embeddingProvider' => 'openai', 'vectorConfig' => ['backend' => 'php', 'solrField' => '_embedding_']];

        $this->appConfig->method('getValueString')
            ->willReturn(json_encode($config));

        $result = $this->handler->getLLMSettingsOnly();

        $this->assertFalse($result['enabled']);
    }

    /**
     * Test getLLMSettingsOnly adds vectorConfig for backward compatibility.
     *
     * @return void
     */
    public function testGetLlmSettingsAddsVectorConfigIfMissing(): void
    {
        $config = ['enabled' => true, 'embeddingProvider' => 'openai'];

        $this->appConfig->method('getValueString')
            ->willReturn(json_encode($config));

        $result = $this->handler->getLLMSettingsOnly();

        $this->assertSame('php', $result['vectorConfig']['backend']);
        $this->assertSame('_embedding_', $result['vectorConfig']['solrField']);
    }

    /**
     * Test getLLMSettingsOnly fills missing vectorConfig sub-fields.
     *
     * @return void
     */
    public function testGetLlmSettingsFillsMissingVectorSubFields(): void
    {
        $config = ['enabled' => true, 'vectorConfig' => []];

        $this->appConfig->method('getValueString')
            ->willReturn(json_encode($config));

        $result = $this->handler->getLLMSettingsOnly();

        $this->assertSame('php', $result['vectorConfig']['backend']);
        $this->assertSame('_embedding_', $result['vectorConfig']['solrField']);
    }

    /**
     * Test getLLMSettingsOnly removes deprecated solrCollection.
     *
     * @return void
     */
    public function testGetLlmSettingsRemovesDeprecatedSolrCollection(): void
    {
        $config = [
            'enabled'      => true,
            'vectorConfig' => [
                'backend'        => 'solr',
                'solrField'      => '_vec_',
                'solrCollection' => 'old_collection',
            ],
        ];

        $this->appConfig->method('getValueString')
            ->willReturn(json_encode($config));

        $result = $this->handler->getLLMSettingsOnly();

        $this->assertArrayNotHasKey('solrCollection', $result['vectorConfig']);
    }

    /**
     * Test getLLMSettingsOnly throws RuntimeException on error.
     *
     * @return void
     */
    public function testGetLlmSettingsThrowsRuntimeExceptionOnError(): void
    {
        $this->appConfig->method('getValueString')
            ->willThrowException(new \Exception('Database error'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to retrieve LLM settings: Database error');

        $this->handler->getLLMSettingsOnly();
    }

    /**
     * Test updateLLMSettingsOnly with full data.
     *
     * @return void
     */
    public function testUpdateLlmSettingsWithFullData(): void
    {
        // First call for getLLMSettingsOnly inside update (returns empty = defaults).
        $this->appConfig->method('getValueString')
            ->willReturn('');

        $this->appConfig->expects($this->once())
            ->method('setValueString')
            ->with('openregister', 'llm', $this->isType('string'));

        $data = [
            'enabled'           => true,
            'embeddingProvider' => 'openai',
            'chatProvider'      => 'ollama',
            'openaiConfig'      => ['apiKey' => 'sk-new'],
            'ollamaConfig'      => ['url' => 'http://ollama:1234'],
            'fireworksConfig'   => ['apiKey' => 'fw-new'],
            'vectorConfig'      => ['backend' => 'solr'],
        ];

        $result = $this->handler->updateLLMSettingsOnly($data);

        $this->assertTrue($result['enabled']);
        $this->assertSame('openai', $result['embeddingProvider']);
        $this->assertSame('ollama', $result['chatProvider']);
        $this->assertSame('sk-new', $result['openaiConfig']['apiKey']);
        $this->assertSame('http://ollama:1234', $result['ollamaConfig']['url']);
        $this->assertSame('fw-new', $result['fireworksConfig']['apiKey']);
        $this->assertSame('solr', $result['vectorConfig']['backend']);
    }

    /**
     * Test updateLLMSettingsOnly merges with existing config (PATCH behavior).
     *
     * @return void
     */
    public function testUpdateLlmSettingsPatchBehavior(): void
    {
        $existingConfig = [
            'enabled'           => true,
            'embeddingProvider' => 'openai',
            'chatProvider'      => 'ollama',
            'openaiConfig'      => ['apiKey' => 'sk-old', 'model' => 'ada', 'chatModel' => 'gpt-4', 'organizationId' => 'org-1'],
            'ollamaConfig'      => ['url' => 'http://ollama:11434', 'model' => 'nomic', 'chatModel' => 'llama'],
            'fireworksConfig'   => ['apiKey' => 'fw-old', 'embeddingModel' => 'em-1', 'chatModel' => 'cm-1', 'baseUrl' => 'https://fw.api'],
            'vectorConfig'      => ['backend' => 'php', 'solrField' => '_embedding_'],
        ];

        $this->appConfig->method('getValueString')
            ->willReturn(json_encode($existingConfig));

        $this->appConfig->expects($this->once())
            ->method('setValueString');

        // Only update chatProvider, leave everything else.
        $result = $this->handler->updateLLMSettingsOnly(['chatProvider' => 'openai']);

        $this->assertTrue($result['enabled']);
        $this->assertSame('openai', $result['chatProvider']);
        $this->assertSame('openai', $result['embeddingProvider']);
        $this->assertSame('sk-old', $result['openaiConfig']['apiKey']);
    }

    /**
     * Test updateLLMSettingsOnly with empty data uses defaults.
     *
     * @return void
     */
    public function testUpdateLlmSettingsWithEmptyDataUsesDefaults(): void
    {
        $this->appConfig->method('getValueString')->willReturn('');
        $this->appConfig->expects($this->once())->method('setValueString');

        $result = $this->handler->updateLLMSettingsOnly([]);

        $this->assertFalse($result['enabled']);
        $this->assertNull($result['embeddingProvider']);
        $this->assertNull($result['chatProvider']);
        $this->assertSame('php', $result['vectorConfig']['backend']);
    }

    /**
     * Test updateLLMSettingsOnly throws RuntimeException on error.
     *
     * @return void
     */
    public function testUpdateLlmSettingsThrowsRuntimeExceptionOnError(): void
    {
        $this->appConfig->method('getValueString')
            ->willThrowException(new \Exception('Write error'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to update LLM settings: Failed to retrieve LLM settings: Write error');

        $this->handler->updateLLMSettingsOnly(['enabled' => true]);
    }
}
