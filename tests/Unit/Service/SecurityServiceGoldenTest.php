<?php

/**
 * Golden test: what SecurityService allows and refuses, and the trace it leaves.
 *
 * Pins, on the code as it was before the A21 refactor, every allowed AND
 * refused outcome of the login and inbound-auth rate limits, the order of the
 * cache reads and writes behind them, the security events they log and at
 * which level, and the input/credential/URL checks. A refactor that keeps
 * this green has not changed what is allowed or refused.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Service\Hardening\HardeningPolicy;
use OCA\OpenRegister\Service\SecurityService;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\SecurityService
 */
final class SecurityServiceGoldenTest extends TestCase {

	/**
	 * Far-future lockout timestamp: always active, never time-dependent.
	 */
	private const FOREVER = PHP_INT_MAX;

	/**
	 * Cache contents.
	 *
	 * @var array<string, mixed>
	 */
	private array $store = [];

	/**
	 * Every cache call, in order: [op, key, value?, ttl?].
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $trace = [];

	/**
	 * Every log call, in order: [level, message, context without file/line/timestamp].
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $logs = [];

	/**
	 * The service under test.
	 *
	 * @var SecurityService
	 */
	private SecurityService $service;

	/**
	 * Build the service over an in-memory cache and a recording logger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(function (string $key) {
			$this->trace[] = ['get', $key];
			return $this->store[$key] ?? null;
		});
		$cache->method('set')->willReturnCallback(function (string $key, $value, int $ttl = 0) {
			$this->trace[] = ['set', $key, $value, $ttl];
			$this->store[$key] = $value;
			return true;
		});
		$cache->method('remove')->willReturnCallback(function (string $key) {
			$this->trace[] = ['remove', $key];
			unset($this->store[$key]);
			return true;
		});
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'info'] as $level) {
			$logger->method($level)->willReturnCallback(function ($message, array $context = []) use ($level) {
				unset($context['file'], $context['line'], $context['timestamp']);
				$this->logs[] = [$level, $message, $context];
			});
		}

		$policy = $this->createMock(HardeningPolicy::class);
		$policy->method('authRateLimit')->willReturn(
			['attemptsPerIdentity' => 3, 'attemptsPerAddress' => 10, 'windowSeconds' => 300, 'lockoutSeconds' => 600]
		);

		$this->service = new SecurityService($factory, $logger, $policy);
	}//end setUp()

	/**
	 * A clean login is allowed after four reads, in this order, and logs nothing.
	 *
	 * @return void
	 */
	public function testACleanLoginIsAllowed(): void {
		$this->assertSame(['allowed' => true], $this->service->checkLoginRateLimit('bob smith', '10.0.0.1'));
		$this->assertSame(
			[
				['get', 'openregister_user_lockout_bob_smith'],
				['get', 'openregister_ip_lockout_10.0.0.1'],
				['get', 'openregister_login_attempts_bob_smith'],
				['get', 'openregister_ip_attempts_10.0.0.1'],
			],
			$this->trace
		);
		$this->assertSame([], $this->logs);
	}//end testACleanLoginIsAllowed()

	/**
	 * Four attempts on each counter is still allowed.
	 *
	 * @return void
	 */
	public function testFourAttemptsAreStillAllowed(): void {
		$this->store['openregister_login_attempts_bob'] = 4;
		$this->store['openregister_ip_attempts_1.2.3.4'] = 4;
		$this->assertSame(['allowed' => true], $this->service->checkLoginRateLimit('bob', '1.2.3.4'));
	}//end testFourAttemptsAreStillAllowed()

	/**
	 * An active user lockout refuses before the IP lockout is even read.
	 *
	 * @return void
	 */
	public function testAnActiveUserLockoutRefuses(): void {
		$this->store['openregister_user_lockout_bob'] = self::FOREVER;
		$this->store['openregister_ip_lockout_1.2.3.4'] = self::FOREVER;
		$this->assertSame(
			[
				'allowed' => false,
				'lockout_until' => self::FOREVER,
				'reason' => 'Account temporarily locked due to too many failed login attempts',
			],
			$this->service->checkLoginRateLimit('bob', '1.2.3.4')
		);
		$this->assertSame([['get', 'openregister_user_lockout_bob']], $this->trace);
		$this->assertSame(
			[[
				'warning',
				'[SecurityService] Security event: login_attempt_during_lockout',
				['username' => 'bob', 'ip_address' => '1.2.3.4', 'lockout_until' => self::FOREVER, 'event' => 'login_attempt_during_lockout'],
			]],
			$this->logs
		);
	}//end testAnActiveUserLockoutRefuses()

	/**
	 * An expired user lockout does not refuse; an active IP lockout does, logged at info.
	 *
	 * @return void
	 */
	public function testAnActiveIpLockoutRefusesWhenTheUserLockoutExpired(): void {
		$this->store['openregister_user_lockout_bob'] = 1;
		$this->store['openregister_ip_lockout_1.2.3.4'] = self::FOREVER;
		$this->assertSame(
			[
				'allowed' => false,
				'lockout_until' => self::FOREVER,
				'reason' => 'IP address temporarily blocked due to suspicious activity',
			],
			$this->service->checkLoginRateLimit('bob', '1.2.3.4')
		);
		$this->assertSame(
			[['get', 'openregister_user_lockout_bob'], ['get', 'openregister_ip_lockout_1.2.3.4']],
			$this->trace
		);
		$this->assertSame('info', $this->logs[0][0]);
		$this->assertSame('login_attempt_from_blocked_ip', $this->logs[0][2]['event']);
	}//end testAnActiveIpLockoutRefusesWhenTheUserLockoutExpired()

	/**
	 * Five user attempts refuse with the base delay and double the stored delay.
	 *
	 * @return void
	 */
	public function testFiveUserAttemptsRefuseWithAProgressiveDelay(): void {
		$this->store['openregister_login_attempts_bob'] = 5;
		$this->assertSame(
			['allowed' => false, 'delay' => 2, 'reason' => 'Too many login attempts. Please wait before trying again.'],
			$this->service->checkLoginRateLimit('bob', '1.2.3.4')
		);
		$this->assertSame(
			[
				['get', 'openregister_user_lockout_bob'],
				['get', 'openregister_ip_lockout_1.2.3.4'],
				['get', 'openregister_login_attempts_bob'],
				['get', 'openregister_ip_attempts_1.2.3.4'],
				['get', 'openregister_progressive_delay_bob_1.2.3.4'],
				['set', 'openregister_progressive_delay_bob_1.2.3.4', 4, 900],
			],
			$this->trace
		);
		$this->assertSame(
			[[
				'info',
				'[SecurityService] Security event: rate_limit_exceeded',
				['username' => 'bob', 'ip_address' => '1.2.3.4', 'user_attempts' => 5, 'ip_attempts' => 0, 'delay' => 2, 'event' => 'rate_limit_exceeded'],
			]],
			$this->logs
		);
	}//end testFiveUserAttemptsRefuseWithAProgressiveDelay()

	/**
	 * Five IP attempts refuse too, and the next delay is capped at 60.
	 *
	 * @return void
	 */
	public function testFiveIpAttemptsRefuseAndTheDelayIsCapped(): void {
		$this->store['openregister_ip_attempts_1.2.3.4'] = 5;
		$this->store['openregister_progressive_delay_bob_1.2.3.4'] = 40;
		$result = $this->service->checkLoginRateLimit('bob', '1.2.3.4');
		$this->assertFalse($result['allowed']);
		$this->assertSame(40, $result['delay']);
		$this->assertSame(60, $this->store['openregister_progressive_delay_bob_1.2.3.4']);
	}//end testFiveIpAttemptsRefuseAndTheDelayIsCapped()

	/**
	 * The fifth failed login locks the user out, warns, then logs the failure.
	 *
	 * @return void
	 */
	public function testTheFifthFailedLoginLocksTheUserOut(): void {
		$this->store['openregister_login_attempts_bob'] = 4;
		$before = time();
		$this->service->recordFailedLoginAttempt('bob', '1.2.3.4');
		$until = $this->store['openregister_user_lockout_bob'];
		$this->assertGreaterThanOrEqual($before + 3600, $until);
		$this->assertLessThanOrEqual(time() + 3600, $until);
		$this->assertSame(
			[
				['get', 'openregister_login_attempts_bob'],
				['set', 'openregister_login_attempts_bob', 5, 900],
				['get', 'openregister_ip_attempts_1.2.3.4'],
				['set', 'openregister_ip_attempts_1.2.3.4', 1, 900],
				['set', 'openregister_user_lockout_bob', $until, 3600],
			],
			$this->trace
		);
		$this->assertSame(
			[
				[
					'warning',
					'[SecurityService] Security event: user_locked_out',
					['username' => 'bob', 'ip_address' => '1.2.3.4', 'attempts' => 5, 'lockout_until' => $until, 'event' => 'user_locked_out'],
				],
				[
					'info',
					'[SecurityService] Security event: failed_login_attempt',
					['username' => 'bob', 'ip_address' => '1.2.3.4', 'reason' => 'invalid_credentials', 'user_attempts' => 5, 'ip_attempts' => 1, 'event' => 'failed_login_attempt'],
				],
			],
			$this->logs
		);
	}//end testTheFifthFailedLoginLocksTheUserOut()

	/**
	 * Both counters crossing lock out both; the IP lockout is logged at info.
	 *
	 * @return void
	 */
	public function testBothCountersCrossingLockOutUserAndIp(): void {
		$this->store['openregister_login_attempts_bob'] = 4;
		$this->store['openregister_ip_attempts_1.2.3.4'] = 4;
		$this->service->recordFailedLoginAttempt('bob', '1.2.3.4', 'bad');
		$this->assertArrayHasKey('openregister_user_lockout_bob', $this->store);
		$this->assertArrayHasKey('openregister_ip_lockout_1.2.3.4', $this->store);
		$this->assertSame(
			[['warning', 'user_locked_out'], ['info', 'ip_locked_out'], ['info', 'failed_login_attempt']],
			array_map(static fn (array $log): array => [$log[0], $log[2]['event']], $this->logs)
		);
		$this->assertSame('bad', $this->logs[2][2]['reason']);
	}//end testBothCountersCrossingLockOutUserAndIp()

	/**
	 * A failure below the limit sets no lockout.
	 *
	 * @return void
	 */
	public function testAFailureBelowTheLimitSetsNoLockout(): void {
		$this->service->recordFailedLoginAttempt('bob', '1.2.3.4');
		$this->assertSame(
			['openregister_login_attempts_bob' => 1, 'openregister_ip_attempts_1.2.3.4' => 1],
			$this->store
		);
		$this->assertCount(1, $this->logs);
	}//end testAFailureBelowTheLimitSetsNoLockout()

	/**
	 * A successful login clears five keys in this order and logs at info.
	 *
	 * @return void
	 */
	public function testASuccessfulLoginClearsEveryCounter(): void {
		$this->service->recordSuccessfulLogin('bob', '1.2.3.4');
		$this->assertSame(
			[
				['remove', 'openregister_login_attempts_bob'],
				['remove', 'openregister_user_lockout_bob'],
				['remove', 'openregister_ip_attempts_1.2.3.4'],
				['remove', 'openregister_ip_lockout_1.2.3.4'],
				['remove', 'openregister_progressive_delay_bob_1.2.3.4'],
			],
			$this->trace
		);
		$this->assertSame(
			[['info', '[SecurityService] Security event: successful_login', ['username' => 'bob', 'ip_address' => '1.2.3.4', 'event' => 'successful_login']]],
			$this->logs
		);
	}//end testASuccessfulLoginClearsEveryCounter()

	/**
	 * The inbound-auth lockout refuses while active and allows once expired.
	 *
	 * @return void
	 */
	public function testTheAuthLockoutRefusesWhileActive(): void {
		$this->store['openregister_auth_lockout_anonymous_1.2.3.4'] = self::FOREVER;
		$this->assertSame(
			[
				'allowed' => false,
				'lockout_until' => self::FOREVER,
				'reason' => 'Too many failed authentication attempts. Please wait before trying again.',
			],
			$this->service->checkAuthRateLimit('', '1.2.3.4')
		);
		$this->store['openregister_auth_lockout_anonymous_1.2.3.4'] = 1;
		$this->assertSame(['allowed' => true], $this->service->checkAuthRateLimit('', '1.2.3.4'));
		$this->assertSame(['allowed' => true], $this->service->checkAuthRateLimit('tok', '1.2.3.4'));
	}//end testTheAuthLockoutRefusesWhileActive()

	/**
	 * An inbound-auth failure below the ceiling counts without locking out.
	 *
	 * @return void
	 */
	public function testAnAuthFailureBelowTheCeilingCountsOnly(): void {
		$this->service->recordFailedAuthAttempt('tok', '1.2.3.4');
		$this->assertSame(
			[
				['get', 'openregister_auth_attempts_tok_1.2.3.4'],
				['set', 'openregister_auth_attempts_tok_1.2.3.4', 1, 300],
				['get', 'openregister_auth_ip_attempts_1.2.3.4'],
				['set', 'openregister_auth_ip_attempts_1.2.3.4', 1, 300],
			],
			$this->trace
		);
		$this->assertSame(
			[[
				'info',
				'[SecurityService] Security event: failed_auth_attempt',
				['identity' => 'tok', 'ip_address' => '1.2.3.4', 'reason' => 'inbound_auth_failed', 'attempts' => 1, 'ip_attempts' => 1, 'lockout_triggered' => false, 'event' => 'failed_auth_attempt'],
			]],
			$this->logs
		);
	}//end testAnAuthFailureBelowTheCeilingCountsOnly()

	/**
	 * Crossing the per-identity ceiling locks the identity+address pair out.
	 *
	 * @return void
	 */
	public function testCrossingTheIdentityCeilingLocksOut(): void {
		$this->store['openregister_auth_attempts_tok_1.2.3.4'] = 2;
		$this->service->recordFailedAuthAttempt('tok', '1.2.3.4', 'r');
		$until = $this->store['openregister_auth_lockout_tok_1.2.3.4'];
		$this->assertSame(['set', 'openregister_auth_lockout_tok_1.2.3.4', $until, 600], $this->trace[4]);
		$this->assertSame(
			[
				'warning',
				'[SecurityService] Security event: auth_locked_out',
				['identity' => 'tok', 'ip_address' => '1.2.3.4', 'attempts' => 3, 'ip_attempts' => 1, 'lockout_until' => $until, 'event' => 'auth_locked_out'],
			],
			$this->logs[0]
		);
		$this->assertTrue($this->logs[1][2]['lockout_triggered']);
	}//end testCrossingTheIdentityCeilingLocksOut()

	/**
	 * Crossing the per-address ceiling locks the pair out too.
	 *
	 * @return void
	 */
	public function testCrossingTheAddressCeilingLocksOut(): void {
		$this->store['openregister_auth_ip_attempts_1.2.3.4'] = 9;
		$this->service->recordFailedAuthAttempt('other', '1.2.3.4');
		$this->assertArrayHasKey('openregister_auth_lockout_other_1.2.3.4', $this->store);
		$this->assertSame(
			['remove', 'openregister_auth_attempts_other_1.2.3.4'],
			$this->traceOf(fn () => $this->service->recordSuccessfulAuth('other', '1.2.3.4'))[0]
		);
	}//end testCrossingTheAddressCeilingLocksOut()

	/**
	 * Clearing user and IP limits removes their keys and logs at info.
	 *
	 * @return void
	 */
	public function testClearingLimitsRemovesTheirKeys(): void {
		$this->service->clearIpRateLimits('1.2.3.4');
		$this->service->clearUserRateLimits('bob');
		$this->assertSame(
			[
				['remove', 'openregister_ip_attempts_1.2.3.4'],
				['remove', 'openregister_ip_lockout_1.2.3.4'],
				['remove', 'openregister_login_attempts_bob'],
				['remove', 'openregister_user_lockout_bob'],
			],
			$this->trace
		);
		$this->assertSame(['ip_rate_limits_cleared', 'user_rate_limits_cleared'], array_map(static fn (array $l): string => $l[2]['event'], $this->logs));
	}//end testClearingLimitsRemovesTheirKeys()

	/**
	 * Input sanitisation: arrays recurse, non-strings pass, strings are trimmed, cut, escaped and stripped.
	 *
	 * @return void
	 */
	public function testSanitizeInput(): void {
		$this->assertSame(42, $this->service->sanitizeInput(42));
		$this->assertNull($this->service->sanitizeInput(null));
		$this->assertSame(['a' => 'x', 'b' => [1, 'y']], $this->service->sanitizeInput(['a' => ' x ', 'b' => [1, "y\0"]]));
		$this->assertSame('abc', $this->service->sanitizeInput('abcdef', 3));
		$this->assertSame('&lt;b&gt;', $this->service->sanitizeInput('<b>'));
		$this->assertSame('alert(1)', $this->service->sanitizeInput('javascript:alert(1)'));
		$this->assertSame(' x', $this->service->sanitizeInput('ONLOAD = x'));
		$this->assertSame('&quot;a&quot;&apos;', $this->service->sanitizeInput('"a"\''));
	}//end testSanitizeInput()

	/**
	 * Credential validation: each refusal, and the sanitised success.
	 *
	 * @return void
	 */
	public function testValidateLoginCredentials(): void {
		$required = ['valid' => false, 'error' => 'Username and password are required'];
		$this->assertSame($required, $this->service->validateLoginCredentials([]));
		$this->assertSame($required, $this->service->validateLoginCredentials(['username' => 'bob']));
		$this->assertSame($required, $this->service->validateLoginCredentials(['username' => '', 'password' => 'p']));
		$this->assertSame(
			['valid' => false, 'error' => 'Username must be at least 2 characters long'],
			$this->service->validateLoginCredentials(['username' => 'b', 'password' => 'p'])
		);
		$this->assertSame(
			['valid' => false, 'error' => 'Username contains invalid characters'],
			$this->service->validateLoginCredentials(['username' => 'bo/b', 'password' => 'p'])
		);
		$this->assertSame(
			['valid' => false, 'error' => 'Password is too long'],
			$this->service->validateLoginCredentials(['username' => 'bob', 'password' => str_repeat('p', 1001)])
		);
		$this->assertSame(
			['valid' => true, 'credentials' => ['username' => 'bob', 'password' => str_repeat('p', 1000)]],
			$this->service->validateLoginCredentials(['username' => ' bob ', 'password' => str_repeat('p', 1000)])
		);
	}//end testValidateLoginCredentials()

	/**
	 * The fetch guard refuses malformed, non-http and internal-address URLs without DNS.
	 *
	 * @return void
	 */
	public function testAssertSafeFetchUrlRefusals(): void {
		$cases = [
			'not a url'               => 'Invalid URL.',
			'/relative/path'          => 'Invalid URL.',
			'ftp://example.org/x'     => 'Only http and https URLs are allowed.',
			'http://127.0.0.1/x'      => 'URL resolves to a non-public address and cannot be fetched.',
			'https://[::1]/x'         => 'URL resolves to a non-public address and cannot be fetched.',
			'HTTP://10.1.2.3/'        => 'URL resolves to a non-public address and cannot be fetched.',
		];
		foreach ($cases as $url => $message) {
			try {
				SecurityService::assertSafeFetchUrl($url);
				$this->fail($url . ' was allowed');
			} catch (InvalidArgumentException $e) {
				$this->assertSame($message, $e->getMessage(), $url);
			}
		}

		SecurityService::assertSafeFetchUrl('https://93.184.216.34/ok');
		$this->addToAssertionCount(1);
	}//end testAssertSafeFetchUrlRefusals()

	/**
	 * The cache trace one call leaves.
	 *
	 * @param callable $call The call.
	 *
	 * @return list<array<int, mixed>>
	 */
	private function traceOf(callable $call): array {
		$this->trace = [];
		$call();
		return $this->trace;
	}//end traceOf()
}//end class
