<?php

/**
 * Embedding calls go through the `llm` breaker; the handler wraps its
 * generators only when it has the breakers.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Search\Service\Vectorization\Handlers
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Search\Service\Vectorization\Handlers;

use LLPhant\Embeddings\Document;
use LLPhant\Embeddings\EmbeddingGenerator\EmbeddingGeneratorInterface;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\BreakerGuardedEmbeddingGenerator;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\EmbeddingGeneratorHandler;
use OCA\OpenRegister\Service\Resilience\BreakerRegistry;
use OCA\OpenRegister\Service\Resilience\BreakerThresholds;
use OCA\OpenRegister\Service\Resilience\CircuitBreaker;
use OCA\OpenRegister\Service\Resilience\DependencyBreaker;
use OCA\OpenRegister\Service\Resilience\DependencyUnavailableException;
use OCA\OpenRegister\Tests\Unit\Service\Resilience\FakeMemcache;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionProperty;
use RuntimeException;

require_once __DIR__ . '/../../../../Service/Resilience/FakeMemcache.php';

/**
 * @covers \OCA\OpenRegister\Search\Service\Vectorization\Handlers\BreakerGuardedEmbeddingGenerator
 * @covers \OCA\OpenRegister\Search\Service\Vectorization\Handlers\EmbeddingGeneratorHandler
 * @uses   \OCA\OpenRegister\Service\Resilience\BreakerRegistry
 * @uses   \OCA\OpenRegister\Service\Resilience\BreakerThresholds
 * @uses   \OCA\OpenRegister\Service\Resilience\CircuitBreaker
 * @uses   \OCA\OpenRegister\Service\Resilience\DependencyBreaker
 * @uses   \OCA\OpenRegister\Service\Resilience\DependencyUnavailableException
 */
class BreakerGuardedEmbeddingGeneratorTest extends TestCase {

	private int $now = 1_000_000;

	private CircuitBreaker $breaker;

	protected function setUp(): void {
		parent::setUp();
		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('getTime')->willReturnCallback(fn (): int => $this->now);
		$this->breaker = new CircuitBreaker(cache: new FakeMemcache(), thresholds: new BreakerThresholds(), clock: $clock, logger: new NullLogger(), shared: true);
	}

	private function open(): void {
		for ($i = 0; $i < 10; $i++) {
			$this->breaker->recordFailure(BreakerRegistry::llmKey());
		}
	}

	public function testAClosedBreakerPassesTheEmbeddingThroughAndCountsIt(): void {
		$inner = $this->createMock(EmbeddingGeneratorInterface::class);
		$inner->expects($this->once())->method('embedText')->with('hello')->willReturn([0.1, 0.2]);

		$guarded = new BreakerGuardedEmbeddingGenerator(inner: $inner, breaker: $this->guardBreaker());

		$this->assertSame([0.1, 0.2], $guarded->embedText('hello'));
		$state = $this->breaker->state(BreakerRegistry::llmKey());
		$this->assertSame(1, $state['calls']);
		$this->assertSame(0, $state['failures']);
	}

	public function testAProviderExceptionIsCountedAsAFailureAndRethrown(): void {
		$inner = $this->createMock(EmbeddingGeneratorInterface::class);
		$inner->method('embedDocuments')->willThrowException(new RuntimeException('provider down'));

		$guarded = new BreakerGuardedEmbeddingGenerator(inner: $inner, breaker: $this->guardBreaker());

		try {
			$guarded->embedDocuments([new Document()]);
			$this->fail('The provider exception must reach the caller.');
		} catch (RuntimeException $e) {
			$this->assertSame('provider down', $e->getMessage());
		}

		$this->assertSame(1, $this->breaker->state(BreakerRegistry::llmKey())['failures']);
	}

	public function testAnOpenBreakerRefusesEveryEmbeddingCallWithoutCallingTheProvider(): void {
		$this->open();
		$inner = $this->createMock(EmbeddingGeneratorInterface::class);
		$inner->expects($this->never())->method('embedText');
		$inner->expects($this->never())->method('embedDocument');
		$inner->expects($this->never())->method('embedDocuments');
		$inner->method('getEmbeddingLength')->willReturn(768);

		$guarded = new BreakerGuardedEmbeddingGenerator(inner: $inner, breaker: $this->guardBreaker());

		$refusals = 0;
		foreach ([fn () => $guarded->embedText('x'), fn () => $guarded->embedDocument(new Document()), fn () => $guarded->embedDocuments([])] as $call) {
			try {
				$call();
			} catch (DependencyUnavailableException $refused) {
				$this->assertSame('llm', $refused->dependency());
				$refusals++;
			}
		}

		$this->assertSame(3, $refusals);
		$this->assertSame(768, $guarded->getEmbeddingLength(), 'The vector size is not an embedding call and is not refused.');
	}

	public function testTheHandlerWrapsItsGeneratorOnlyWhenItHasTheBreakers(): void {
		$config   = ['provider' => 'ollama', 'model' => 'nomic-embed-text'];
		$provider = $this->createMock(EmbeddingGeneratorInterface::class);

		$plainHandler = new EmbeddingGeneratorHandler(new NullLogger());
		$this->seed(handler: $plainHandler, generator: $provider);
		$this->assertSame($provider, $plainHandler->getGenerator($config));

		$registry = $this->createMock(BreakerRegistry::class);
		$registry->method('breaker')->willReturn($this->breaker);
		$guardedHandler = new EmbeddingGeneratorHandler(logger: new NullLogger(), breakers: new DependencyBreaker($registry));
		$this->seed(handler: $guardedHandler, generator: $provider);
		$guarded = $guardedHandler->getGenerator($config);

		$this->assertInstanceOf(BreakerGuardedEmbeddingGenerator::class, $guarded);
		$this->assertSame($provider, $guarded->inner());
	}

	/**
	 * Put a generator in the handler's cache, so no provider is contacted to build one.
	 */
	private function seed(EmbeddingGeneratorHandler $handler, EmbeddingGeneratorInterface $generator): void {
		(new ReflectionProperty(EmbeddingGeneratorHandler::class, 'generatorCache'))->setValue($handler, ['ollama_nomic-embed-text' => $generator]);
	}

	/**
	 * The test's breaker behind the Kernel breaker port.
	 *
	 * @return DependencyBreaker The port.
	 */
	private function guardBreaker(): DependencyBreaker {
		$registry = $this->createMock(BreakerRegistry::class);
		$registry->method('breaker')->willReturn($this->breaker);

		return new DependencyBreaker($registry);
	}//end guardBreaker()
}
