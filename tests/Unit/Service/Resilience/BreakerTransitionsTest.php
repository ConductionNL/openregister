<?php

/**
 * The breaker's console index and its open/close notifications, and the registry reporting transitions on declared connections.
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

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Resilience;

use OCA\OpenRegister\Service\Connection\ConnectionReporterInterface;
use OCA\OpenRegister\Service\Resilience\BreakerRegistry;
use OCA\OpenRegister\Service\Resilience\BreakerThresholds;
use OCA\OpenRegister\Service\Resilience\CircuitBreaker;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

require_once __DIR__ . '/FakeMemcache.php';

/**
 * @covers \OCA\OpenRegister\Service\Resilience\CircuitBreaker
 * @covers \OCA\OpenRegister\Service\Resilience\BreakerRegistry
 * @uses   \OCA\OpenRegister\Service\Resilience\BreakerThresholds
 * @uses   \OCA\OpenRegister\Service\Resilience\DependencyUnavailableException
 */
class BreakerTransitionsTest extends TestCase {

	private FakeMemcache $cache;

	private int $now = 1_000_000;

	/** @var list<array{0: string, 1: bool, 2: int}> */
	private array $changes = [];

	protected function setUp(): void {
		parent::setUp();
		$this->cache = new FakeMemcache();
	}

	private function clock(): ITimeFactory {
		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('getTime')->willReturnCallback(fn (): int => $this->now);
		return $clock;
	}

	private function breaker(): CircuitBreaker {
		return new CircuitBreaker(
			cache: $this->cache,
			thresholds: new BreakerThresholds(),
			clock: $this->clock(),
			logger: new NullLogger(),
			shared: true,
			onChange: function (string $key, bool $open, int $coolDown): void {
				$this->changes[] = [$key, $open, $coolDown];
			}
		);
	}

	private static function fail10(CircuitBreaker $breaker, string $key): void {
		for ($i = 0; $i < 10; $i++) {
			$breaker->recordFailure($key);
		}
	}

	public function testAKeyIsIndexedOnItsFirstFailureOnly(): void {
		$breaker = $this->breaker();
		$breaker->recordSuccess('outbound:healthy.example');
		$breaker->recordFailure('source:3');
		$breaker->recordFailure('source:3');
		$breaker->recordFailure('llm');

		$this->assertSame(['source:3', 'llm'], $breaker->knownKeys());
	}

	public function testOpeningAndClosingAreReportedOnce(): void {
		$breaker = $this->breaker();
		self::fail10($breaker, 'llm');
		$this->assertSame([['llm', true, 30]], $this->changes);

		$breaker->reset('llm');
		$breaker->reset('llm');
		$this->assertSame([['llm', true, 30], ['llm', false, 0]], $this->changes, 'Resetting a closed breaker reports nothing.');
	}

	public function testAFailedProbeReportsTheDoubledCoolDown(): void {
		$breaker = $this->breaker();
		self::fail10($breaker, 'llm');
		$this->now += 31;
		$breaker->allow('llm');
		$breaker->recordFailure('llm');

		$this->assertSame(['llm', true, 60], $this->changes[1]);
	}

	public function testAThrowingListenerDoesNotFailTheCall(): void {
		$breaker = new CircuitBreaker(
			cache: $this->cache,
			thresholds: new BreakerThresholds(),
			clock: $this->clock(),
			logger: new NullLogger(),
			shared: true,
			onChange: static function (): void {
				throw new RuntimeException('listener down');
			}
		);
		self::fail10($breaker, 'llm');

		$this->assertSame('open', $breaker->state('llm')['state']);
	}

	public function testABrokenCacheHasNoKnownKeys(): void {
		$breaker = $this->breaker();
		$breaker->recordFailure('llm');
		$this->cache->broken = true;

		$this->assertSame([], $breaker->knownKeys());
	}

	public function testConnectionOfNamesOnlyDeclaredConnections(): void {
		$this->assertSame('llm', BreakerRegistry::connectionOf('llm'));
		$this->assertSame('brp', BreakerRegistry::connectionOf('outbound:brp'));
		$this->assertNull(BreakerRegistry::connectionOf('outbound:api.example.org'));
		$this->assertNull(BreakerRegistry::connectionOf('source:3'));
		$this->assertNull(BreakerRegistry::connectionOf('webhook:brp'));
		$this->assertNull(BreakerRegistry::connectionOf('brp'));
	}

	public function testTheRegistryReportsADeclaredConnectionsTransitions(): void {
		$factory = $this->createStub(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(true);
		$factory->method('createDistributed')->willReturn($this->cache);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnCallback(fn (string $app, string $key, int $default): int => $default);

		$reports     = [];
		$connections = $this->createMock(ConnectionReporterInterface::class);
		$connections->method('report')->willReturnCallback(function (string $key, string $status, string $message = '') use (&$reports): bool {
			$reports[] = [$key, $status, $message];
			return true;
		});

		$registry = new BreakerRegistry(cacheFactory: $factory, appConfig: $appConfig, clock: $this->clock(), logger: new NullLogger(), connections: $connections);
		$breaker  = $registry->breaker();
		self::fail10($breaker, 'outbound:api.example.org');
		self::fail10($breaker, 'outbound:brp');
		$breaker->reset('outbound:brp');

		$this->assertSame(
			[
				['brp', 'unavailable', 'Not answering; Open Register tries again in 30 seconds.'],
				['brp', 'configured', 'Answering again.'],
			],
			$reports
		);
	}
}
