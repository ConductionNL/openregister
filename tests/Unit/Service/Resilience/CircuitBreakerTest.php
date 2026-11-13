<?php

/**
 * The circuit breaker's states: opening on repeated failure, refusing while
 * open, the single half-open probe, the doubling cool-down, and failing open
 * when its cache throws.
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

use OCA\OpenRegister\Service\Resilience\BreakerThresholds;
use OCA\OpenRegister\Service\Resilience\CircuitBreaker;
use OCA\OpenRegister\Service\Resilience\DependencyUnavailableException;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once __DIR__ . '/FakeMemcache.php';

/**
 * @covers \OCA\OpenRegister\Service\Resilience\CircuitBreaker
 * @covers \OCA\OpenRegister\Service\Resilience\DependencyUnavailableException
 * @covers \OCA\OpenRegister\Service\Resilience\BreakerThresholds
 */
class CircuitBreakerTest extends TestCase {

	private FakeMemcache $cache;

	private int $now = 1_000_000;

	private const KEY = 'source:7';

	/**
	 * A fresh cache per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->cache = new FakeMemcache();
	}//end setUp()

	/**
	 * The breaker over the fake cache and a clock this test moves.
	 *
	 * @param bool $shared Whether the store is shared.
	 *
	 * @return CircuitBreaker The breaker.
	 */
	private function breaker(bool $shared = true): CircuitBreaker {
		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('getTime')->willReturnCallback(fn (): int => $this->now);

		return new CircuitBreaker(
			cache: $this->cache,
			thresholds: new BreakerThresholds(),
			clock: $clock,
			logger: new NullLogger(),
			shared: $shared
		);
	}//end breaker()

	/**
	 * Advance the clock.
	 *
	 * @param int $seconds The seconds to advance.
	 *
	 * @return void
	 */
	private function advance(int $seconds): void {
		$this->now += $seconds;
	}//end advance()

	/**
	 * Record `$calls` outcomes, `$failures` of them failures.
	 *
	 * @param CircuitBreaker $breaker  The breaker.
	 * @param int            $calls    The number of calls.
	 * @param int            $failures How many of them fail.
	 *
	 * @return void
	 */
	private function record(CircuitBreaker $breaker, int $calls, int $failures): void {
		// Successes first, then failures, so the last failure is the one that
		// crosses the window threshold (as interleaved traffic would).
		$successes = ($calls - $failures);
		for ($i = 0; $i < $successes; $i++) {
			$breaker->recordSuccess(key: self::KEY);
		}

		for ($i = 0; $i < $failures; $i++) {
			$breaker->recordFailure(key: self::KEY);
		}
	}//end record()

	/**
	 * Five failures in ten calls open the breaker, and it then refuses with a Retry-After.
	 *
	 * @return void
	 */
	public function testFiveOfTenOpensTheBreaker(): void {
		$breaker = $this->breaker();
		$this->record($breaker, 10, 5);

		$this->assertSame('open', $breaker->state(self::KEY)['state']);

		try {
			$breaker->allow(self::KEY);
			$this->fail('An open breaker must refuse.');
		} catch (DependencyUnavailableException $e) {
			$this->assertSame('source', $e->dependency());
			$this->assertSame(30, $e->retryAfter());
		}
	}//end testFiveOfTenOpensTheBreaker()

	/**
	 * Four failures in ten calls leave the breaker closed and passing.
	 *
	 * @return void
	 */
	public function testFourOfTenStaysClosed(): void {
		$breaker = $this->breaker();
		$this->record($breaker, 10, 4);

		$this->assertSame('closed', $breaker->state(self::KEY)['state']);
		$breaker->allow(self::KEY);
		$this->assertSame(['calls' => 10, 'failures' => 4], [
			'calls' => $breaker->state(self::KEY)['calls'],
			'failures' => $breaker->state(self::KEY)['failures'],
		]);
	}//end testFourOfTenStaysClosed()

	/**
	 * Too few calls never open the breaker however bad the ratio.
	 *
	 * @return void
	 */
	public function testTooFewCallsNeverOpen(): void {
		$breaker = $this->breaker();
		$this->record($breaker, 9, 9);

		$this->assertSame('closed', $breaker->state(self::KEY)['state']);
	}//end testTooFewCallsNeverOpen()

	/**
	 * After the cool-down exactly one caller probes; a second is refused. A good probe closes it.
	 *
	 * @return void
	 */
	public function testOneProbeAfterTheCoolDownThenClose(): void {
		$breaker = $this->breaker();
		$this->record($breaker, 10, 5);

		$this->advance(30);
		$this->assertSame('half-open', $breaker->state(self::KEY)['state']);

		// The first caller wins the probe slot.
		$breaker->allow(self::KEY);

		// A second caller in the same moment is still refused.
		$this->expectException(DependencyUnavailableException::class);
		try {
			$breaker->allow(self::KEY);
			$this->fail('Only one probe may pass.');
		} finally {
			// The probe succeeds and closes the breaker.
			$breaker->recordSuccess(self::KEY);
			$this->assertSame('closed', $breaker->state(self::KEY)['state']);
			$breaker->allow(self::KEY);
		}
	}//end testOneProbeAfterTheCoolDownThenClose()

	/**
	 * A failed probe reopens the breaker with the cool-down doubled, up to 300 seconds.
	 *
	 * @return void
	 */
	public function testAFailedProbeDoublesTheCoolDownUpToTheMaximum(): void {
		$breaker = $this->breaker();
		$this->record($breaker, 10, 5);

		$expected = 30;
		for ($round = 0; $round < 6; $round++) {
			$this->advance($expected);
			$breaker->allow(self::KEY);
			$breaker->recordFailure(self::KEY);

			$expected = min(300, ($expected * 2));
			try {
				$breaker->allow(self::KEY);
				$this->fail('The reopened breaker must refuse.');
			} catch (DependencyUnavailableException $e) {
				$this->assertSame($expected, $e->retryAfter(), 'Round ' . $round);
			}
		}

		$this->assertSame(300, $expected, 'The cool-down is capped at 300 seconds.');
	}//end testAFailedProbeDoublesTheCoolDownUpToTheMaximum()

	/**
	 * An administrator's reset closes an open breaker and clears its window.
	 *
	 * @return void
	 */
	public function testResetClosesAndClearsTheWindow(): void {
		$breaker = $this->breaker();
		$this->record($breaker, 10, 5);
		$this->assertSame('open', $breaker->state(self::KEY)['state']);

		$breaker->reset(self::KEY);

		$state = $breaker->state(self::KEY);
		$this->assertSame('closed', $state['state']);
		$this->assertSame(0, $state['calls']);
		$this->assertSame(0, $state['failures']);
		$breaker->allow(self::KEY);
	}//end testResetClosesAndClearsTheWindow()

	/**
	 * A throwing cache lets every call through and never opens.
	 *
	 * @return void
	 */
	public function testAThrowingCacheFailsOpen(): void {
		$breaker = $this->breaker();
		$this->cache->broken = true;

		for ($i = 0; $i < 20; $i++) {
			$breaker->recordFailure(self::KEY);
		}

		$breaker->allow(self::KEY);
		$this->assertSame('closed', $breaker->state(self::KEY)['state']);
	}//end testAThrowingCacheFailsOpen()

	/**
	 * call() refuses while open, counts the wrapped call, and rethrows its failure.
	 *
	 * @return void
	 */
	public function testCallCountsAndRefuses(): void {
		$breaker = $this->breaker();

		// A wrapped success passes through and is counted.
		$this->assertSame('ok', $breaker->call(self::KEY, static fn (): string => 'ok'));

		// A wrapped failure is counted and its exception rethrown unchanged.
		try {
			$breaker->call(self::KEY, static function (): void {
				throw new \RuntimeException('boom');
			});
			$this->fail('The call failure must be rethrown.');
		} catch (\RuntimeException $e) {
			$this->assertSame('boom', $e->getMessage());
		}

		// Open the breaker, then call() refuses without running the closure.
		$this->record($breaker, 10, 5);
		$ran = false;
		$this->expectException(DependencyUnavailableException::class);
		try {
			$breaker->call(self::KEY, static function () use (&$ran): string {
				$ran = true;
				return 'never';
			});
		} finally {
			$this->assertFalse($ran, 'An open breaker makes no call.');
		}
	}//end testCallCountsAndRefuses()

	/**
	 * The state reports whether it is shared.
	 *
	 * @return void
	 */
	public function testStateReportsSharedness(): void {
		$this->assertTrue($this->breaker(shared: true)->state(self::KEY)['shared']);
		$this->assertFalse($this->breaker(shared: false)->state(self::KEY)['shared']);
	}//end testStateReportsSharedness()
}//end class
