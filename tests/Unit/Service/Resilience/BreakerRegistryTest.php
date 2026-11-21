<?php

/**
 * The breaker registry: how breaker keys are spelled, how thresholds are read
 * from appconfig (with defaults for unreadable or out-of-range values), and
 * which cache the shared breaker keeps its state in.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Resilience
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

namespace OCA\OpenRegister\Tests\Unit\Service\Resilience;

use OCA\OpenRegister\Service\Resilience\BreakerRegistry;
use OCA\OpenRegister\Service\Resilience\DependencyUnavailableException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

require_once __DIR__ . '/FakeMemcache.php';

/**
 * @covers \OCA\OpenRegister\Service\Resilience\BreakerRegistry
 * @uses   \OCA\OpenRegister\Service\Resilience\BreakerThresholds
 * @uses   \OCA\OpenRegister\Service\Resilience\CircuitBreaker
 * @uses   \OCA\OpenRegister\Service\Resilience\DependencyUnavailableException
 */
class BreakerRegistryTest extends TestCase {

	/** @var ICacheFactory&MockObject */
	private ICacheFactory $cacheFactory;

	/** @var IAppConfig&MockObject */
	private IAppConfig $appConfig;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	/** @var array<string, int> appconfig key => value; absent keys return the caller's default */
	private array $config = [];

	/**
	 * The registry under test.
	 *
	 * @var BreakerRegistry
	 */
	private BreakerRegistry $registry;

	/**
	 * A registry over mocked config, cache factory and a fixed clock.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->cacheFactory = $this->createMock(ICacheFactory::class);
		$this->appConfig    = $this->createMock(IAppConfig::class);
		$this->logger       = $this->createMock(LoggerInterface::class);

		$this->appConfig->method('getValueInt')->willReturnCallback(
			fn (string $app, string $key, int $default = 0): int => $this->config[$key] ?? $default
		);

		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('getTime')->willReturn(1_000_000);

		$this->registry = new BreakerRegistry(
			cacheFactory: $this->cacheFactory,
			appConfig: $this->appConfig,
			clock: $clock,
			logger: $this->logger
		);
	}//end setUp()

	/**
	 * Keys are prefixed by dependency class; hosts are trimmed and lower-cased.
	 *
	 * @return void
	 */
	public function testKeysAreSpelledPerDependencyClass(): void {
		$this->assertSame('outbound:api.example.com', BreakerRegistry::outboundKey(hostOrName: '  API.Example.COM '));
		$this->assertSame('webhook:hooks.example.com', BreakerRegistry::webhookKey(host: "Hooks.Example.com\n"));
		$this->assertSame('source:42', BreakerRegistry::sourceKey(sourceId: 42));
		$this->assertSame('source:abc', BreakerRegistry::sourceKey(sourceId: 'abc'));
		$this->assertSame('llm', BreakerRegistry::llmKey());
	}//end testKeysAreSpelledPerDependencyClass()

	/**
	 * Without any appconfig values the design defaults apply, and nothing is logged.
	 *
	 * @return void
	 */
	public function testThresholdsDefaultWhenNothingIsConfigured(): void {
		$this->logger->expects($this->never())->method('warning');

		$thresholds = $this->registry->thresholds();

		$this->assertSame(10, $thresholds->minCalls);
		$this->assertSame(5, $thresholds->minFailures);
		$this->assertSame(50, $thresholds->failurePercent);
		$this->assertSame(30, $thresholds->coolDown);
		$this->assertSame(300, $thresholds->maxCoolDown);
		$this->assertSame(60, $thresholds->window);
		$this->assertSame(10, $thresholds->bucket);
	}//end testThresholdsDefaultWhenNothingIsConfigured()

	/**
	 * Configured values are read from the load_shedding group of the app's config.
	 *
	 * @return void
	 */
	public function testThresholdsAreReadFromTheLoadSheddingGroup(): void {
		$this->config = [
			'load_shedding_breaker_min_calls' => 4,
			'load_shedding_breaker_min_failures' => 3,
			'load_shedding_breaker_failure_percent' => 100,
			'load_shedding_breaker_cool_down' => 7,
			'load_shedding_breaker_max_cool_down' => 70,
		];

		$thresholds = $this->registry->thresholds();

		$this->assertSame(4, $thresholds->minCalls);
		$this->assertSame(3, $thresholds->minFailures);
		$this->assertSame(100, $thresholds->failurePercent);
		$this->assertSame(7, $thresholds->coolDown);
		$this->assertSame(70, $thresholds->maxCoolDown);
		// Window and bucket are not configurable.
		$this->assertSame(60, $thresholds->window);
		$this->assertSame(10, $thresholds->bucket);
	}//end testThresholdsAreReadFromTheLoadSheddingGroup()

	/**
	 * Out-of-range values fall back to their default, each with a warning naming the key.
	 *
	 * @return void
	 */
	public function testOutOfRangeValuesFallBackToTheDefaultWithAWarning(): void {
		$this->config = [
			'load_shedding_breaker_min_calls' => 0,
			'load_shedding_breaker_failure_percent' => 101,
			'load_shedding_breaker_cool_down' => -5,
		];

		$warnings = [];
		$this->logger->method('warning')->willReturnCallback(
			function (string $message) use (&$warnings): void {
				$warnings[] = $message;
			}
		);

		$thresholds = $this->registry->thresholds();

		$this->assertSame(10, $thresholds->minCalls);
		$this->assertSame(50, $thresholds->failurePercent);
		$this->assertSame(30, $thresholds->coolDown);
		$this->assertSame(
			[
				'[BreakerRegistry] breaker_min_calls=0 out of range, using default 10',
				'[BreakerRegistry] breaker_failure_percent=101 out of range, using default 50',
				'[BreakerRegistry] breaker_cool_down=-5 out of range, using default 30',
			],
			$warnings
		);
	}//end testOutOfRangeValuesFallBackToTheDefaultWithAWarning()

	/**
	 * A failure percent of exactly 1 is the lowest accepted value.
	 *
	 * @return void
	 */
	public function testTheLowestFailurePercentIsAccepted(): void {
		$this->config = ['load_shedding_breaker_failure_percent' => 1];
		$this->logger->expects($this->never())->method('warning');

		$this->assertSame(1, $this->registry->thresholds()->failurePercent);
	}//end testTheLowestFailurePercentIsAccepted()

	/**
	 * An unreadable value falls back to its default and logs why, never throws.
	 *
	 * @return void
	 */
	public function testAnUnreadableValueFallsBackToItsDefault(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willThrowException(new RuntimeException('type mismatch'));

		$warnings = [];
		$this->logger->method('warning')->willReturnCallback(
			function (string $message) use (&$warnings): void {
				$warnings[] = $message;
			}
		);

		$registry = new BreakerRegistry(
			cacheFactory: $this->cacheFactory,
			appConfig: $appConfig,
			clock: $this->createMock(ITimeFactory::class),
			logger: $this->logger
		);

		$thresholds = $registry->thresholds();

		$this->assertSame(10, $thresholds->minCalls);
		$this->assertSame(300, $thresholds->maxCoolDown);
		$this->assertCount(5, $warnings);
		$this->assertSame('[BreakerRegistry] breaker_min_calls unreadable, using default 10: type mismatch', $warnings[0]);
	}//end testAnUnreadableValueFallsBackToItsDefault()

	/**
	 * With a distributed memcache the breaker is shared and keeps its state there.
	 *
	 * @return void
	 */
	public function testTheDistributedMemcacheIsUsedAndShared(): void {
		$cache = new FakeMemcache();
		$this->config = [
			'load_shedding_breaker_min_calls' => 2,
			'load_shedding_breaker_min_failures' => 2,
		];
		$this->cacheFactory->method('isAvailable')->willReturn(true);
		$this->cacheFactory->expects($this->once())->method('createDistributed')
			->with('openregister_breakers')->willReturn($cache);
		$this->cacheFactory->expects($this->never())->method('createLocal');

		$breaker = $this->registry->breaker();
		$this->assertTrue($breaker->shared);

		// The configured thresholds reach the breaker: two failures open it.
		$breaker->recordFailure(key: 'llm');
		$breaker->recordFailure(key: 'llm');
		$this->assertSame('open', $breaker->state(key: 'llm')['state']);

		$this->expectException(DependencyUnavailableException::class);
		$breaker->allow(key: 'llm');
	}//end testTheDistributedMemcacheIsUsedAndShared()

	/**
	 * The breaker is built once per request: a second call returns the same instance.
	 *
	 * @return void
	 */
	public function testTheBreakerIsBuiltOnce(): void {
		$this->cacheFactory->method('isAvailable')->willReturn(true);
		$this->cacheFactory->expects($this->once())->method('createDistributed')->willReturn(new FakeMemcache());

		$this->assertSame($this->registry->breaker(), $this->registry->breaker());
	}//end testTheBreakerIsBuiltOnce()

	/**
	 * A distributed cache that is not a memcache falls back to the local memcache, unshared.
	 *
	 * @return void
	 */
	public function testANonMemcacheDistributedCacheFallsBackToLocal(): void {
		$this->cacheFactory->method('isAvailable')->willReturn(true);
		$this->cacheFactory->method('createDistributed')->willReturn($this->createMock(ICache::class));
		$this->cacheFactory->expects($this->once())->method('createLocal')
			->with('openregister_breakers')->willReturn(new FakeMemcache());

		$this->assertFalse($this->registry->breaker()->shared);
	}//end testANonMemcacheDistributedCacheFallsBackToLocal()

	/**
	 * Without a distributed cache the local memcache is used directly.
	 *
	 * @return void
	 */
	public function testWithoutADistributedCacheTheLocalOneIsUsed(): void {
		$this->cacheFactory->method('isAvailable')->willReturn(false);
		$this->cacheFactory->expects($this->never())->method('createDistributed');
		$this->cacheFactory->expects($this->once())->method('createLocal')->willReturn(new FakeMemcache());

		$this->assertFalse($this->registry->breaker()->shared);
	}//end testWithoutADistributedCacheTheLocalOneIsUsed()

	/**
	 * With no memcache at all the breaker has no store and lets every call through.
	 *
	 * @return void
	 */
	public function testWithoutAnyMemcacheEveryCallPasses(): void {
		$this->cacheFactory->method('isAvailable')->willReturn(false);
		$this->cacheFactory->method('createLocal')->willReturn($this->createMock(ICache::class));

		$breaker = $this->registry->breaker();
		for ($i = 0; $i < 20; $i++) {
			$breaker->recordFailure(key: 'llm');
		}

		$this->assertFalse($breaker->shared);
		$this->assertSame('closed', $breaker->state(key: 'llm')['state']);
		$this->assertSame('ok', $breaker->call(key: 'llm', call: fn (): string => 'ok'));
	}//end testWithoutAnyMemcacheEveryCallPasses()

	/**
	 * A cache factory that throws leaves the breaker storeless with a warning, never failing the request.
	 *
	 * @return void
	 */
	public function testAThrowingCacheFactoryIsLoggedAndEveryCallPasses(): void {
		$this->cacheFactory->method('isAvailable')->willThrowException(new RuntimeException('redis down'));
		$this->logger->expects($this->once())->method('warning')
			->with('[BreakerRegistry] no breaker cache, every call passes: redis down');

		$breaker = $this->registry->breaker();

		$this->assertFalse($breaker->shared);
		$this->assertSame(3, $breaker->call(key: 'source:1', call: fn (): int => 3));
	}//end testAThrowingCacheFactoryIsLoggedAndEveryCallPasses()
}//end class
