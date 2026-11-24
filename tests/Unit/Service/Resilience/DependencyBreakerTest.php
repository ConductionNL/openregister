<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Resilience;

use OCA\OpenRegister\Kernel\Contracts\DependencyBreakerInterface;
use OCA\OpenRegister\Kernel\Contracts\DependencyRefusalInterface;
use OCA\OpenRegister\Service\Connection\ConnectionReporter;
use OCA\OpenRegister\Service\PlatformServiceBindings;
use OCA\OpenRegister\Service\Resilience\BreakerRegistry;
use OCA\OpenRegister\Service\Resilience\BreakerThresholds;
use OCA\OpenRegister\Service\Resilience\CircuitBreaker;
use OCA\OpenRegister\Service\Resilience\DependencyBreaker;
use OCA\OpenRegister\Service\Resilience\DependencyUnavailableException;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClassConstant;

/**
 * The Kernel breaker port answers with the registry's shared breaker.
 *
 * @covers \OCA\OpenRegister\Service\Resilience\DependencyBreaker
 * @uses   \OCA\OpenRegister\Service\Resilience\BreakerRegistry
 * @uses   \OCA\OpenRegister\Service\Resilience\BreakerThresholds
 * @uses   \OCA\OpenRegister\Service\Resilience\CircuitBreaker
 * @uses   \OCA\OpenRegister\Service\Resilience\DependencyUnavailableException
 *
 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
 */
class DependencyBreakerTest extends TestCase {

	private CircuitBreaker $breaker;

	private DependencyBreaker $port;

	protected function setUp(): void {
		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('getTime')->willReturn(1_000_000);
		$this->breaker = new CircuitBreaker(cache: new FakeMemcache(), thresholds: new BreakerThresholds(), clock: $clock, logger: new NullLogger(), shared: true);
		$registry = $this->createMock(BreakerRegistry::class);
		$registry->method('breaker')->willReturn($this->breaker);
		$this->port = new DependencyBreaker($registry);
	}

	public function testTheKeysAreTheRegistrysOwnSpelling(): void {
		$this->assertSame(BreakerRegistry::llmKey(), $this->port->llmKey());
		$this->assertSame(BreakerRegistry::webhookKey(host: 'Hooks.Example'), $this->port->webhookKey(host: 'Hooks.Example'));
		$this->assertSame(BreakerRegistry::sourceKey(sourceId: 12), $this->port->sourceKey(sourceId: 12));
	}

	public function testCallsAndOutcomesReachTheSharedBreaker(): void {
		$this->assertSame('answer', $this->port->call(key: 'llm', call: static fn (): string => 'answer'));
		$this->port->recordSuccess(key: 'webhook:a');
		$this->port->recordFailure(key: 'webhook:a');

		$this->assertSame(1, $this->breaker->state('webhook:a')['failures']);
	}

	public function testAnOpenBreakerRefusesWithTheKernelRefusal(): void {
		for ($i = 0; $i < 50; $i++) {
			$this->port->recordFailure(key: 'source:3');
		}

		try {
			$this->port->allow(key: 'source:3');
			$this->fail('An open breaker must refuse.');
		} catch (DependencyRefusalInterface $refusal) {
			$this->assertInstanceOf(DependencyUnavailableException::class, $refusal);
			$this->assertSame('source', $refusal->dependency());
			$this->assertGreaterThanOrEqual(1, $refusal->retryAfter());
		}
	}

	public function testThePortIsBoundByThePlatformArea(): void {
		$this->assertSame(DependencyBreaker::class, PlatformServiceBindings::BINDINGS[DependencyBreakerInterface::class] ?? null);
	}

	public function testTheRestatedConnectionKeysMatchTheReporter(): void {
		$keys = (new ReflectionClassConstant(BreakerRegistry::class, 'CONNECTION_KEYS'))->getValue();

		$this->assertSame(ConnectionReporter::KEYS, $keys);
	}
}
