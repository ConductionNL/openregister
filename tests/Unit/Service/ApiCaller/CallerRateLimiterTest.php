<?php

/**
 * CallerRateLimiterTest — the ceiling, and what happens as the window rolls.
 *
 * The clock is a parameter precisely so this file can test the rolling, which
 * a limiter reading `time()` can only be tested for by sleeping, which in
 * practice means it is never tested.
 *
 * The fake cache below is a real counter, not a stub that returns what the test
 * wants: a double that answers "allowed" on demand would pass whatever the
 * limiter did, including nothing.
 *
 * 🔑 IT DOUBLES `IMemcache`, NOT `ICache`, AND THAT CAUGHT A REAL BUG. The
 * first version of this file doubled `ICache`, and PHPUnit refused to configure
 * `inc()` because `ICache` does not declare it. The limiter was calling it
 * anyway: `ICacheFactory::createDistributed()` is declared to return `ICache`,
 * so on a backend that is only an `ICache` the first bounded call would have
 * fatalled. The refusal was the bug report, which is exactly what a double that
 * cannot invent methods is for.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ApiCaller
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ApiCaller;

use OCA\OpenRegister\Service\ApiCaller\CallerPolicy;
use OCA\OpenRegister\Service\ApiCaller\CallerRateLimiter;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use OCP\IMemcache;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\ApiCaller\CallerRateLimiter
 */
class CallerRateLimiterTest extends TestCase {

	/**
	 * Counters the fake cache holds, shared with the test.
	 *
	 * @var array<string, int>
	 */
	private array $counters = [];

	/**
	 * Build a limiter over an administered ceiling and a real counting cache.
	 *
	 * @param string $limits The raw api_caller_limits value.
	 * @param bool $cacheAvailable Whether this instance has a distributed counter store.
	 *
	 * @return CallerRateLimiter The limiter.
	 */
	private function limiter(string $limits, bool $cacheAvailable = true): CallerRateLimiter {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key): string => (($key === CallerPolicy::LIMITS_KEY) ? $limits : '')
		);

		$cache = $this->createMock(IMemcache::class);
		$cache->method('inc')->willReturnCallback(
			function (string $key): int {
				$this->counters[$key] = (($this->counters[$key] ?? 0) + 1);

				return $this->counters[$key];
			}
		);
		$cache->method('set')->willReturn(true);

		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn($cacheAvailable);
		$factory->method('createDistributed')->willReturn($cache);

		return new CallerRateLimiter($factory, new CallerPolicy($appConfig));
	}//end limiter()

	/**
	 * Sixty calls a minute for one leverancier.
	 *
	 * @return string The raw configuration value.
	 */
	private static function sixtyAMinute(): string {
		return json_encode(['leverancier' => ['limit' => 60, 'windowSeconds' => 60]]);
	}//end sixtyAMinute()

	public function testACallerWithNoAdministeredCeilingIsNotCounted(): void {
		$outcome = $this->limiter(limits: '')->consume(principal: 'leverancier', now: 1_000_000);

		$this->assertNull($outcome);
		$this->assertSame([], $this->counters, 'An unbounded caller must not even cost a cache write.');
	}//end testACallerWithNoAdministeredCeilingIsNotCounted()

	public function testARunawayIntegrationIsBoundedOnTheCallOverTheLimit(): void {
		$limiter = $this->limiter(limits: self::sixtyAMinute());
		$now = 1_000_000;

		for ($call = 1; $call <= 60; $call++) {
			$outcome = $limiter->consume(principal: 'leverancier', now: $now);
			$this->assertTrue($outcome['allowed'], 'Call ' . $call . ' is inside the ceiling of sixty.');
		}

		$overTheLimit = $limiter->consume(principal: 'leverancier', now: $now);

		$this->assertFalse($overTheLimit['allowed'], 'The sixty first call inside the minute is refused.');
		$this->assertSame(60, $overTheLimit['limit']);
		$this->assertSame(0, $overTheLimit['remaining']);
	}//end testARunawayIntegrationIsBoundedOnTheCallOverTheLimit()

	public function testTheRefusalNamesWhenTheWindowRolls(): void {
		$limiter = $this->limiter(limits: self::sixtyAMinute());
		// 17 seconds into a minute-aligned window.
		$now = (1_000_020 - (1_000_020 % 60) + 17);

		$outcome = $limiter->consume(principal: 'leverancier', now: $now);

		$this->assertSame(
			($now - ($now % 60) + 60),
			$outcome['resetAt'],
			'A 429 that cannot say when to come back tells an integrator to retry, which makes it worse.'
		);
	}//end testTheRefusalNamesWhenTheWindowRolls()

	public function testRemainingCountsDown(): void {
		$limiter = $this->limiter(limits: json_encode(['leverancier' => ['limit' => 3, 'windowSeconds' => 60]]));
		$now = 1_000_000;

		$this->assertSame(2, $limiter->consume(principal: 'leverancier', now: $now)['remaining']);
		$this->assertSame(1, $limiter->consume(principal: 'leverancier', now: $now)['remaining']);
		$this->assertSame(0, $limiter->consume(principal: 'leverancier', now: $now)['remaining']);
	}//end testRemainingCountsDown()

	public function testTheBudgetComesBackWhenTheWindowRolls(): void {
		$limiter = $this->limiter(limits: json_encode(['leverancier' => ['limit' => 2, 'windowSeconds' => 60]]));
		$windowStart = 1_000_020 - (1_000_020 % 60);

		$limiter->consume(principal: 'leverancier', now: $windowStart);
		$limiter->consume(principal: 'leverancier', now: $windowStart);
		$refused = $limiter->consume(principal: 'leverancier', now: $windowStart);
		$this->assertFalse($refused['allowed']);

		$nextWindow = $limiter->consume(principal: 'leverancier', now: ($windowStart + 60));

		$this->assertTrue($nextWindow['allowed'], 'A ceiling a caller never recovers from is a ban, not a limit.');
		$this->assertSame(1, $nextWindow['remaining']);
	}//end testTheBudgetComesBackWhenTheWindowRolls()

	public function testTwoCallersDoNotShareABudget(): void {
		$limiter = $this->limiter(
			limits: json_encode(['*' => ['limit' => 2, 'windowSeconds' => 60]])
		);
		$now = 1_000_000;

		$limiter->consume(principal: 'leverancier-a', now: $now);
		$limiter->consume(principal: 'leverancier-a', now: $now);
		$this->assertFalse($limiter->consume(principal: 'leverancier-a', now: $now)['allowed']);

		$this->assertTrue(
			$limiter->consume(principal: 'leverancier-b', now: $now)['allowed'],
			'One supplier exhausting its budget must not refuse another supplier.'
		);
	}//end testTwoCallersDoNotShareABudget()

	public function testAnInstanceWithNoCountingCacheFailsOpen(): void {
		$outcome = $this->limiter(limits: self::sixtyAMinute(), cacheAvailable: false)
			->consume(principal: 'leverancier', now: 1_000_000);

		$this->assertNull(
			$outcome,
			'Refusing traffic because the counter store is unreachable converts an infrastructure problem into an outage.'
		);
	}//end testAnInstanceWithNoDistributedCacheFailsOpen()

	public function testAnAnonymousCallerGetsItsOwnBudget(): void {
		$limiter = $this->limiter(
			limits: json_encode(['anonymous' => ['limit' => 1, 'windowSeconds' => 60]])
		);
		$now = 1_000_000;

		$this->assertTrue($limiter->consume(principal: '', now: $now)['allowed']);
		$this->assertFalse($limiter->consume(principal: '', now: $now)['allowed']);
	}//end testAnAnonymousCallerGetsItsOwnBudget()
}//end class
