<?php

/**
 * The language model provider choice: an administrator switches the
 * embedding provider to an Ollama server on their own network, the settings
 * read back that provider, address and model, and the next embedding is
 * requested from that address; an unknown provider is refused.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Settings
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Settings;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use Exception;
use LLPhant\Embeddings\EmbeddingGenerator\EmbeddingGeneratorInterface;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\EmbeddingGeneratorHandler;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\VectorEmbeddingHandlers;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\VectorSearchHandler;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\VectorStatsHandler;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\VectorStorageHandlerInterface;
use OCA\OpenRegister\Search\Service\Vectorization\VectorEmbeddings;
use OCA\OpenRegister\Service\Settings\LlmSettingsHandler;
use OCA\OpenRegister\Service\SettingsServiceInterface;
use OCP\IAppConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The provider choice, from the saved settings to the embedding request.
 *
 * @covers \OCA\OpenRegister\Service\Settings\LlmSettingsHandler
 * @covers \OCA\OpenRegister\Search\Service\Vectorization\VectorEmbeddings
 * @uses \OCA\OpenRegister\Search\Service\Vectorization\Handlers\EmbeddingGeneratorHandler
 * @uses \OCA\OpenRegister\Search\Service\Vectorization\Handlers\VectorEmbeddingHandlers
 *
 * @spec openspec/changes/capability-rows-verified-b/specs/chat-ai/spec.md
 */
class LlmProviderChoiceTest extends TestCase {

	private const LOCAL_OLLAMA = 'http://ollama.intern:11434';

	/**
	 * The stored app config values, by key.
	 *
	 * @var array<string, string>
	 */
	private array $stored = [];

	private function settings(): LlmSettingsHandler {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $this->stored["{$app}.{$key}"] ?? $default
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->stored["{$app}.{$key}"] = $value;
				return true;
			}
		);

		return new LlmSettingsHandler($appConfig);
	}//end settings()

	private function anInstanceOnOpenAi(): LlmSettingsHandler {
		$settings = $this->settings();
		$settings->updateLLMSettingsOnly(
			['enabled' => true, 'embeddingProvider' => 'openai', 'openaiConfig' => ['apiKey' => 'sk-test', 'model' => 'text-embedding-3-small']]
		);

		return $settings;
	}//end anInstanceOnOpenAi()

	private function pickLocalOllama(LlmSettingsHandler $settings): void {
		$settings->updateLLMSettingsOnly(
			['embeddingProvider' => 'ollama', 'ollamaConfig' => ['url' => self::LOCAL_OLLAMA, 'model' => 'nomic-embed-text']]
		);
	}//end pickLocalOllama()

	private function embeddings(LlmSettingsHandler $settings, EmbeddingGeneratorHandler $generator): VectorEmbeddings {
		$settingsService = $this->createMock(SettingsServiceInterface::class);
		$settingsService->method('getLLMSettingsOnly')->willReturnCallback(static fn (): array => $settings->getLLMSettingsOnly());
		$handlers = new VectorEmbeddingHandlers(
			$generator,
			$this->createMock(VectorStorageHandlerInterface::class),
			$this->createMock(VectorSearchHandler::class),
			$this->createMock(VectorStatsHandler::class)
		);

		return new VectorEmbeddings($this->createMock(IDBConnection::class), $settingsService, $handlers, $this->createMock(LoggerInterface::class));
	}//end embeddings()

	public function testAnAdministratorSwitchesToALocalOllamaServerAndReadsItBack(): void {
		$settings = $this->anInstanceOnOpenAi();

		$this->pickLocalOllama(settings: $settings);
		$read = $this->settings()->getLLMSettingsOnly();

		$this->assertSame('ollama', $read['embeddingProvider']);
		$this->assertSame(['url' => self::LOCAL_OLLAMA, 'model' => 'nomic-embed-text', 'chatModel' => null], $read['ollamaConfig']);
		// The other provider's settings are kept, so switching back needs no re-entry.
		$this->assertSame('sk-test', $read['openaiConfig']['apiKey']);
		$this->assertTrue($read['enabled']);
	}//end testAnAdministratorSwitchesToALocalOllamaServerAndReadsItBack()

	public function testTheNextEmbeddingIsRequestedFromThatAddress(): void {
		$settings = $this->anInstanceOnOpenAi();
		$this->pickLocalOllama(settings: $settings);

		$model = $this->createMock(EmbeddingGeneratorInterface::class);
		$model->expects($this->once())->method('embedText')->with('Melding over afval')->willReturn([0.25, 0.5]);
		$generator = $this->createMock(EmbeddingGeneratorHandler::class);
		$generator->method('getDefaultDimensions')->willReturnMap([['nomic-embed-text', 768]]);
		$generator->expects($this->once())->method('getGenerator')
			->with(['provider' => 'ollama', 'model' => 'nomic-embed-text', 'dimensions' => 768, 'api_key' => null, 'base_url' => self::LOCAL_OLLAMA])
			->willReturn($model);

		$result = $this->embeddings(settings: $settings, generator: $generator)->generateEmbedding('Melding over afval');

		$this->assertSame(['embedding' => [0.25, 0.5], 'model' => 'nomic-embed-text', 'dimensions' => 2], $result);
	}//end testTheNextEmbeddingIsRequestedFromThatAddress()

	public function testAnUnknownProviderIsRefused(): void {
		$settings = $this->settings();
		$settings->updateLLMSettingsOnly(['embeddingProvider' => 'cohere']);
		$embeddings = $this->embeddings(settings: $settings, generator: new EmbeddingGeneratorHandler($this->createMock(LoggerInterface::class)));

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Unsupported embedding provider: cohere');

		$embeddings->generateEmbedding('Melding over afval');
	}//end testAnUnknownProviderIsRefused()
}//end class
