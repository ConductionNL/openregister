<?php

/**
 * Unit tests for VectorEmbeddings' embedding probe, batch embedding and per-provider config.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Search\Service\Vectorization
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Search\Service\Vectorization;

use Exception;
use LLPhant\Embeddings\EmbeddingGenerator\EmbeddingGeneratorInterface;
use OCA\OpenRegister\Search\Service\Vectorization\EmbeddingProbe;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\EmbeddingGeneratorHandler;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\VectorEmbeddingHandlers;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\VectorSearchHandler;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\VectorStatsHandler;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\VectorStorageHandlerInterface;
use OCA\OpenRegister\Search\Service\Vectorization\VectorEmbeddings;
use OCA\OpenRegister\Service\SettingsServiceInterface;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * testEmbedding, generateBatchEmbeddings and the provider config both read from settings.
 *
 * @covers \OCA\OpenRegister\Search\Service\Vectorization\VectorEmbeddings
 * @uses \OCA\OpenRegister\Search\Service\Vectorization\Handlers\VectorEmbeddingHandlers
 * @uses \OCA\OpenRegister\Search\Service\Vectorization\EmbeddingProbe
 */
final class VectorEmbeddingsProbeAndBatchTest extends TestCase {

	/**
	 * The generator factory.
	 *
	 * @var EmbeddingGeneratorHandler&MockObject
	 */
	private EmbeddingGeneratorHandler&MockObject $generator;

	/**
	 * The settings the provider config is read from.
	 *
	 * @var SettingsServiceInterface&MockObject
	 */
	private SettingsServiceInterface&MockObject $settings;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		$this->generator = $this->createMock(EmbeddingGeneratorHandler::class);
		$this->settings  = $this->createMock(SettingsServiceInterface::class);
		$this->logger    = $this->createMock(LoggerInterface::class);
	}//end setUp()

	private function service(): VectorEmbeddings {
		return new VectorEmbeddings(
			$this->createMock(IDBConnection::class),
			$this->settings,
			new VectorEmbeddingHandlers(
				$this->generator,
				$this->createMock(VectorStorageHandlerInterface::class),
				$this->createMock(VectorSearchHandler::class),
				$this->createMock(VectorStatsHandler::class)
			),
			$this->logger
		);
	}//end service()

	public function testASuccessfulProbeReportsTheVectorAndAFiveValueSample(): void {
		$embedder = $this->createMock(EmbeddingGeneratorInterface::class);
		$embedder->expects($this->once())->method('embedText')->with('Hallo.')->willReturn([0.1, 0.2, 0.3, 0.4, 0.5, 0.6, 0.7]);
		$this->generator->expects($this->once())
			->method('getGenerator')
			->with(['provider' => 'fireworks', 'model' => 'thenlper/gte-base', 'api_key' => 'fw', 'base_url' => null])
			->willReturn($embedder);
		$this->logger->expects($this->never())->method('error');

		$infos = [];
		$this->logger->method('info')->willReturnCallback(
			static function (string $message, array $context) use (&$infos): void {
				$infos[] = $message;
			}
		);

		$result = $this->service()->testEmbedding(
			new EmbeddingProbe(provider: 'fireworks', config: ['model' => 'thenlper/gte-base', 'apiKey' => 'fw'], testText: 'Hallo.')
		);

		$this->assertSame(
			[
				'success' => true,
				'message' => 'Embedding test successful',
				'data' => [
					'provider' => 'fireworks',
					'model' => 'thenlper/gte-base',
					'vectorLength' => 7,
					'sampleValues' => [0.1, 0.2, 0.3, 0.4, 0.5],
					'testText' => 'Hallo.',
				],
			],
			$result
		);
		$this->assertSame(
			['[VectorEmbeddings] Testing embedding generation', '[VectorEmbeddings] Embedding test successful'],
			$infos
		);
	}//end testASuccessfulProbeReportsTheVectorAndAFiveValueSample()

	public function testAFailedProbeReportsTheErrorInsteadOfThrowing(): void {
		$this->generator->expects($this->never())->method('getGenerator');

		$errors = [];
		$this->logger->method('error')->willReturnCallback(
			static function (string $message, array $context) use (&$errors): void {
				$errors[] = [$message, $context['provider'] ?? null, $context['error'] ?? null];
			}
		);

		$result = $this->service()->testEmbedding(new EmbeddingProbe(provider: 'openai', config: ['model' => 'm']));

		$this->assertSame(
			[
				'success' => false,
				'error' => 'Embedding generation failed: API key is required for openai',
				'message' => 'Failed to generate embedding: Embedding generation failed: API key is required for openai',
			],
			$result
		);
		$this->assertSame(
			[
				['[VectorEmbeddings] Failed to generate embedding with custom config', 'openai', 'API key is required for openai'],
				['[VectorEmbeddings] Embedding test failed', 'openai', 'Embedding generation failed: API key is required for openai'],
			],
			$errors
		);
	}//end testAFailedProbeReportsTheErrorInsteadOfThrowing()

	public function testABatchKeepsGoingPastATextThatFails(): void {
		$this->settings->method('getLLMSettingsOnly')->willReturn(
			['embeddingProvider' => 'openai', 'openaiConfig' => ['model' => 'text-embedding-3-small', 'apiKey' => 'sk']]
		);
		$this->generator->method('getDefaultDimensions')->with('text-embedding-3-small')->willReturn(1536);

		$embedder = $this->createMock(EmbeddingGeneratorInterface::class);
		$embedder->expects($this->exactly(3))->method('embedText')->willReturnCallback(
			static function (string $text): array {
				if ($text === 'bad') {
					throw new Exception('quota exceeded');
				}

				if ($text === 'one') {
					return [1.0];
				}

				return [1.0, 2.0];
			}
		);
		$this->generator->expects($this->once())
			->method('getGenerator')
			->with(['provider' => 'openai', 'model' => 'text-embedding-3-small', 'dimensions' => 1536, 'api_key' => 'sk', 'base_url' => null])
			->willReturn($embedder);

		$warnings = [];
		$this->logger->method('warning')->willReturnCallback(
			static function (string $message, array $context) use (&$warnings): void {
				$warnings[] = [$context['index'], $context['error']];
			}
		);
		$completed = null;
		$this->logger->method('info')->willReturnCallback(
			static function (string $message, array $context) use (&$completed): void {
				if ($message === '[VectorEmbeddings] Batch embedding generation completed') {
					$completed = [$context['total'], $context['successful'], $context['failed']];
				}
			}
		);

		$results = $this->service()->generateBatchEmbeddings(['one', 'bad', 'two']);

		$this->assertSame(
			[
				['embedding' => [1.0], 'model' => 'text-embedding-3-small', 'dimensions' => 1],
				['embedding' => null, 'model' => 'text-embedding-3-small', 'dimensions' => 0, 'error' => 'quota exceeded'],
				['embedding' => [1.0, 2.0], 'model' => 'text-embedding-3-small', 'dimensions' => 2],
			],
			$results
		);
		$this->assertSame([[1, 'quota exceeded']], $warnings);
		$this->assertSame([3, 2, 1], $completed);
	}//end testABatchKeepsGoingPastATextThatFails()

	public function testABatchWhoseGeneratorCannotBeBuiltThrows(): void {
		$this->settings->method('getLLMSettingsOnly')->willReturn([]);
		$this->generator->method('getGenerator')->willThrowException(new Exception('unknown provider'));
		$this->logger->expects($this->once())
			->method('error')
			->with(
				'[VectorEmbeddings] Failed to generate batch embeddings',
				$this->callback(static fn (array $c): bool => $c['error'] === 'unknown provider' && $c['count'] === 2)
			);

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Batch embedding generation failed: unknown provider');
		$this->service()->generateBatchEmbeddings(['a', 'b']);
	}//end testABatchWhoseGeneratorCannotBeBuiltThrows()

	/**
	 * Settings, requested provider, and the config the generator receives.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string|null, 2: array<string, mixed>}>
	 */
	public static function providerConfigs(): array {
		return [
			'fireworks from settings' => [
				[
					'embeddingProvider' => 'fireworks',
					'fireworksConfig' => ['embeddingModel' => 'nomic-ai/nomic-embed-text-v1.5', 'apiKey' => 'fw', 'baseUrl' => 'https://fw.test'],
				],
				null,
				['provider' => 'fireworks', 'model' => 'nomic-ai/nomic-embed-text-v1.5', 'dimensions' => 42, 'api_key' => 'fw', 'base_url' => 'https://fw.test'],
			],
			'fireworks defaults' => [
				[],
				'fireworks',
				['provider' => 'fireworks', 'model' => 'thenlper/gte-base', 'dimensions' => 42, 'api_key' => null, 'base_url' => null],
			],
			'ollama url as base' => [
				['ollamaConfig' => ['model' => 'mxbai-embed-large', 'url' => 'http://ollama.test']],
				'ollama',
				['provider' => 'ollama', 'model' => 'mxbai-embed-large', 'dimensions' => 42, 'api_key' => null, 'base_url' => 'http://ollama.test'],
			],
			'ollama defaults' => [
				[],
				'ollama',
				['provider' => 'ollama', 'model' => 'nomic-embed-text', 'dimensions' => 42, 'api_key' => null, 'base_url' => null],
			],
			'openai is the default provider' => [
				[],
				null,
				['provider' => 'openai', 'model' => 'text-embedding-ada-002', 'dimensions' => 42, 'api_key' => null, 'base_url' => null],
			],
			'unknown provider' => [
				['openaiConfig' => ['model' => 'ignored', 'apiKey' => 'ignored']],
				'acme',
				['provider' => 'acme', 'model' => 'text-embedding-ada-002', 'dimensions' => 42, 'api_key' => null, 'base_url' => null],
			],
		];
	}//end providerConfigs()

	/**
	 * @param array<string, mixed> $settings The LLM settings.
	 * @param string|null          $provider The provider asked for.
	 * @param array<string, mixed> $expected The config the generator receives.
	 *
	 * @return void
	 */
	#[DataProvider('providerConfigs')]
	public function testTheProviderConfigIsReadFromSettings(array $settings, ?string $provider, array $expected): void {
		$this->settings->method('getLLMSettingsOnly')->willReturn($settings);
		$this->generator->expects($this->once())->method('getDefaultDimensions')->with($expected['model'])->willReturn(42);

		$embedder = $this->createMock(EmbeddingGeneratorInterface::class);
		$embedder->method('embedText')->willReturn([0.5]);
		$this->generator->expects($this->once())->method('getGenerator')->with($expected)->willReturn($embedder);

		$this->assertSame(
			[['embedding' => [0.5], 'model' => $expected['model'], 'dimensions' => 1]],
			$this->service()->generateBatchEmbeddings(['x'], $provider)
		);
	}//end testTheProviderConfigIsReadFromSettings()
}//end class
