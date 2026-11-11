<?php

/**
 * Characterisation of embedding with a custom configuration.
 *
 * Pins the config normalisation, each validation refusal, the success path
 * and the logged and rethrown failure, so the method can be split into named
 * steps without changing an exception message or a log call.
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

namespace OCA\OpenRegister\Tests\Unit\Search\Service\Vectorization;

use Exception;
use LLPhant\Embeddings\EmbeddingGenerator\EmbeddingGeneratorInterface;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\EmbeddingGeneratorHandler;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\VectorEmbeddingHandlers;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\VectorSearchHandler;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\VectorStatsHandler;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\VectorStorageHandlerInterface;
use OCA\OpenRegister\Search\Service\Vectorization\VectorEmbeddings;
use OCA\OpenRegister\Service\SettingsServiceInterface;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * generateEmbeddingWithCustomConfig.
 *
 * @covers \OCA\OpenRegister\Search\Service\Vectorization\VectorEmbeddings
 */
class VectorEmbeddingsCustomConfigTest extends TestCase {

	private EmbeddingGeneratorHandler&MockObject $generator;
	private LoggerInterface&MockObject $logger;

	/**
	 * Build the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->generator = $this->createMock(EmbeddingGeneratorHandler::class);
		$this->logger    = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @return VectorEmbeddings The service.
	 */
	private function service(): VectorEmbeddings {
		return new VectorEmbeddings(
			$this->createMock(IDBConnection::class),
			$this->createMock(SettingsServiceInterface::class),
			new VectorEmbeddingHandlers(
				$this->generator,
				$this->createMock(VectorStorageHandlerInterface::class),
				$this->createMock(VectorSearchHandler::class),
				$this->createMock(VectorStatsHandler::class)
			),
			$this->logger
		);
	}//end service()

	/**
	 * The config is normalised (url falls back for baseUrl) and the embedding returned and logged.
	 *
	 * @return void
	 */
	public function testSuccess(): void {
		$embedder = $this->createMock(EmbeddingGeneratorInterface::class);
		$embedder->method('embedText')->with('hello')->willReturn([0.1, 0.2, 0.3]);
		$this->generator->expects($this->once())
			->method('getGenerator')
			->with(['provider' => 'openai', 'model' => 'm', 'api_key' => 'k', 'base_url' => 'http://u'])
			->willReturn($embedder);

		$debug = [];
		$this->logger->method('debug')->willReturnCallback(
			static function (string $message, array $context) use (&$debug): void {
				unset($context['line']);
				$debug[] = [$message, $context];
			}
		);
		$this->logger->expects($this->never())->method('error');

		$result = $this->service()->generateEmbeddingWithCustomConfig('hello', ['provider' => 'openai', 'model' => 'm', 'apiKey' => 'k', 'url' => 'http://u']);

		$this->assertSame([0.1, 0.2, 0.3], $result);
		$this->assertSame('[VectorEmbeddings] Generating embedding with custom config', $debug[0][0]);
		$this->assertSame(['text_length' => 5, 'provider' => 'openai', 'model' => 'm'], array_slice($debug[0][1], 1));
		$this->assertSame('[VectorEmbeddings] Embedding generated successfully with custom config', $debug[1][0]);
		$this->assertSame(['dimensions' => 3, 'model' => 'm'], array_slice($debug[1][1], 1));
	}//end testSuccess()

	/**
	 * baseUrl wins over url; a provider without a key requirement needs no key.
	 *
	 * @return void
	 */
	public function testBaseUrlWinsAndOllamaNeedsNoKey(): void {
		$embedder = $this->createMock(EmbeddingGeneratorInterface::class);
		$embedder->method('embedText')->willReturn([1.0]);
		$this->generator->expects($this->once())
			->method('getGenerator')
			->with(['provider' => 'ollama', 'model' => 'm', 'api_key' => null, 'base_url' => 'http://b'])
			->willReturn($embedder);

		$this->assertSame([1.0], $this->service()->generateEmbeddingWithCustomConfig('t', ['provider' => 'ollama', 'model' => 'm', 'baseUrl' => 'http://b', 'url' => 'http://u']));
	}//end testBaseUrlWinsAndOllamaNeedsNoKey()

	/**
	 * Each refusal, wrapped and logged as an error with the provider and text length.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string, 2: string}> Config, message, logged provider.
	 */
	public static function refusals(): array {
		return [
			'no provider'         => [['provider' => '', 'model' => 'm'], 'Embedding generation failed: Provider is required', ''],
			'no model'            => [['provider' => 'ollama'], 'Embedding generation failed: Model is required', 'ollama'],
			'openai without key'  => [['provider' => 'openai', 'model' => 'm'], 'Embedding generation failed: API key is required for openai', 'openai'],
			'fireworks blank key' => [['provider' => 'fireworks', 'model' => 'm', 'apiKey' => ''], 'Embedding generation failed: API key is required for fireworks', 'fireworks'],
		];
	}//end refusals()

	/**
	 * A refused config never reaches the generator.
	 *
	 * @param array<string, mixed> $config   The config.
	 * @param string               $message  The thrown message.
	 * @param string               $provider The provider in the error log.
	 *
	 * @dataProvider refusals
	 *
	 * @return void
	 */
	public function testRefusal(array $config, string $message, string $provider): void {
		$this->generator->expects($this->never())->method('getGenerator');
		$this->logger->expects($this->once())->method('error')->with(
			'[VectorEmbeddings] Failed to generate embedding with custom config',
			$this->callback(
				static fn (array $context): bool => array_keys($context) === ['file', 'line', 'error', 'provider', 'text_length']
					&& 'Embedding generation failed: ' . $context['error'] === $message
					&& $context['provider'] === $provider
					&& $context['text_length'] === 4
			)
		);

		try {
			$this->service()->generateEmbeddingWithCustomConfig('abcd', $config);
			$this->fail('expected a refusal');
		} catch (Exception $e) {
			$this->assertSame($message, $e->getMessage());
		}
	}//end testRefusal()

	/**
	 * A generator failure is wrapped the same way.
	 *
	 * @return void
	 */
	public function testGeneratorFailure(): void {
		$this->generator->method('getGenerator')->willThrowException(new Exception('boom'));
		$this->logger->expects($this->once())->method('error');
		$this->expectExceptionMessage('Embedding generation failed: boom');

		$this->service()->generateEmbeddingWithCustomConfig('abcd', ['provider' => 'ollama', 'model' => 'm']);
	}//end testGeneratorFailure()
}//end class
