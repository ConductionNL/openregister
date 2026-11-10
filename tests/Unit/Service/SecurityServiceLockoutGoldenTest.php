<?php

/**
 * Characterisation golden for the login and inbound-auth lockouts of SecurityService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\FailedAttempt;
use OCA\OpenRegister\Service\Hardening\HardeningPolicy;
use OCA\OpenRegister\Service\SecurityService;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the allowed and refused outcomes, the cache keys they rest on and the
 * security events they log, before the lockout helpers are restructured.
 *
 * No clock is pinned: a lockout-until value is recorded as "future" when it lies
 * after the moment the call started.
 */
class SecurityServiceLockoutGoldenTest extends TestCase {

	/**
	 * The fake distributed cache, key to value.
	 *
	 * @var array<string, mixed>
	 */
	private array $store = [];

	/**
	 * The security events logged, as [level, event, context without file/line/timestamp].
	 *
	 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $events = [];

	/**
	 * The service under test.
	 *
	 * @var SecurityService
	 */
	private SecurityService $service;

	/**
	 * The moment the test started.
	 *
	 * @var int
	 */
	private int $start = 0;

	/**
	 * Build the service over an in-memory cache and a recording logger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->start = time();

		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(fn (string $key): mixed => ($this->store[$key] ?? null));
		$cache->method('set')->willReturnCallback(
			function (string $key, mixed $value, int $ttl = 0): bool {
				$this->store[$key] = $value;
				return true;
			}
		);
		$cache->method('remove')->willReturnCallback(
			function (string $key): bool {
				unset($this->store[$key]);
				return true;
			}
		);
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(fn (string $message, array $context = []) => $this->record('warning', $context));
		$logger->method('info')->willReturnCallback(fn (string $message, array $context = []) => $this->record('info', $context));

		$policy = $this->createMock(HardeningPolicy::class);
		$policy->method('authRateLimit')->willReturn(
			['attemptsPerIdentity' => 3, 'attemptsPerAddress' => 5, 'windowSeconds' => 60, 'lockoutSeconds' => 120]
		);

		$this->service = new SecurityService($factory, $logger, $policy);
	}//end setUp()

	/**
	 * Record one logged security event with its volatile fields normalised.
	 *
	 * @param string               $level   The log level.
	 * @param array<string, mixed> $context The log context.
	 *
	 * @return void
	 */
	private function record(string $level, array $context): void {
		$event = (string)$context['event'];
		unset($context['file'], $context['line'], $context['timestamp'], $context['event']);
		$this->events[] = [$level, $event, $this->normalise($context)];
	}//end record()

	/**
	 * Replace a lockout-until timestamp by "future" when it lies after the start.
	 *
	 * @param array<string, mixed> $values The values.
	 *
	 * @return array<string, mixed>
	 */
	private function normalise(array $values): array {
		if (isset($values['lockout_until']) === true) {
			$this->assertGreaterThan($this->start, $values['lockout_until']);
			$values['lockout_until'] = 'future';
		}

		return $values;
	}//end normalise()

	/**
	 * The cache keys that hold a value, sorted.
	 *
	 * @return array<int, string>
	 */
	private function keys(): array {
		$keys = array_keys($this->store);
		sort($keys);
		return $keys;
	}//end keys()

	/**
	 * Five failed logins lock out the user and the address; success clears both.
	 *
	 * @return void
	 */
	public function testFiveFailedLoginsLockOutUserAndAddress(): void {
		for ($i = 1; $i <= 4; $i++) {
			$this->service->recordFailedLoginAttempt('alice', '10.0.0.1');
		}

		$this->assertSame(['allowed' => true], $this->service->checkLoginRateLimit('alice', '10.0.0.1'));
		$this->assertSame(
			[['info', 'failed_login_attempt', ['username' => 'alice', 'ip_address' => '10.0.0.1', 'reason' => 'invalid_credentials', 'user_attempts' => 4, 'ip_attempts' => 4]]],
			array_slice($this->events, -1)
		);

		$this->events = [];
		$this->service->recordFailedLoginAttempt('alice', '10.0.0.1', 'bad_password');
		$this->assertSame(
			[
				['warning', 'user_locked_out', ['username' => 'alice', 'ip_address' => '10.0.0.1', 'attempts' => 5, 'lockout_until' => 'future']],
				['info', 'ip_locked_out', ['username' => 'alice', 'ip_address' => '10.0.0.1', 'attempts' => 5, 'lockout_until' => 'future']],
				['info', 'failed_login_attempt', ['username' => 'alice', 'ip_address' => '10.0.0.1', 'reason' => 'bad_password', 'user_attempts' => 5, 'ip_attempts' => 5]],
			],
			$this->events
		);
		$this->assertSame(
			[
				'openregister_ip_attempts_10.0.0.1',
				'openregister_ip_lockout_10.0.0.1',
				'openregister_login_attempts_alice',
				'openregister_user_lockout_alice',
			],
			$this->keys()
		);

		$this->events = [];
		$refusal      = $this->service->checkLoginRateLimit('alice', '10.0.0.1');
		$this->assertSame(
			['allowed' => false, 'lockout_until' => 'future', 'reason' => 'Account temporarily locked due to too many failed login attempts'],
			$this->normalise($refusal)
		);
		$this->assertSame(
			[['warning', 'login_attempt_during_lockout', ['username' => 'alice', 'ip_address' => '10.0.0.1', 'lockout_until' => 'future']]],
			$this->events
		);

		$this->service->recordSuccessfulLogin('alice', '10.0.0.1');
		$this->assertSame([], $this->keys());
		$this->assertSame(['allowed' => true], $this->service->checkLoginRateLimit('alice', '10.0.0.1'));
	}//end testFiveFailedLoginsLockOutUserAndAddress()

	/**
	 * A blocked address refuses another user; a lapsed lockout falls back to the progressive delay.
	 *
	 * @return void
	 */
	public function testBlockedAddressAndProgressiveDelay(): void {
		for ($i = 1; $i <= 5; $i++) {
			$this->service->recordFailedLoginAttempt('user' . $i, '10.0.0.2');
		}

		$refusal = $this->service->checkLoginRateLimit('bob', '10.0.0.2');
		$this->assertSame(
			['allowed' => false, 'lockout_until' => 'future', 'reason' => 'IP address temporarily blocked due to suspicious activity'],
			$this->normalise($refusal)
		);

		// The lockout lapsed, the counter did not: the progressive delay refuses, doubling each time.
		unset($this->store['openregister_ip_lockout_10.0.0.2']);
		$this->events = [];
		$this->assertSame(
			['allowed' => false, 'delay' => 2, 'reason' => 'Too many login attempts. Please wait before trying again.'],
			$this->service->checkLoginRateLimit('bob', '10.0.0.2')
		);
		$this->assertSame(
			[['info', 'rate_limit_exceeded', ['username' => 'bob', 'ip_address' => '10.0.0.2', 'user_attempts' => 0, 'ip_attempts' => 5, 'delay' => 2]]],
			$this->events
		);
		$this->assertSame(4, $this->service->checkLoginRateLimit('bob', '10.0.0.2')['delay']);

		// A cache-key-unsafe username is sanitised before it reaches a key.
		$this->service->recordFailedLoginAttempt('a b/c', '10.0.0.3');
		$this->assertArrayHasKey('openregister_login_attempts_a_b_c', $this->store);
	}//end testBlockedAddressAndProgressiveDelay()

	/**
	 * The identity+address ceiling locks out at the third failure, not the second.
	 *
	 * @return void
	 */
	public function testInboundAuthLocksOutAtTheIdentityCeiling(): void {
		$this->service->recordFailedAuthAttempt(new FailedAttempt('key-1', '10.0.0.9', 'inbound_auth_failed'));
		$this->service->recordFailedAuthAttempt(new FailedAttempt('key-1', '10.0.0.9', 'inbound_auth_failed'));
		$this->assertSame(['allowed' => true], $this->service->checkAuthRateLimit('key-1', '10.0.0.9'));
		$this->assertSame(
			['info', 'failed_auth_attempt', ['identity' => 'key-1', 'ip_address' => '10.0.0.9', 'reason' => 'inbound_auth_failed', 'attempts' => 2, 'ip_attempts' => 2, 'lockout_triggered' => false]],
			$this->events[1]
		);

		$this->events = [];
		$this->service->recordFailedAuthAttempt(new FailedAttempt('key-1', '10.0.0.9', 'bad_token'));
		$this->assertSame(
			[
				['warning', 'auth_locked_out', ['identity' => 'key-1', 'ip_address' => '10.0.0.9', 'attempts' => 3, 'ip_attempts' => 3, 'lockout_until' => 'future']],
				['info', 'failed_auth_attempt', ['identity' => 'key-1', 'ip_address' => '10.0.0.9', 'reason' => 'bad_token', 'attempts' => 3, 'ip_attempts' => 3, 'lockout_triggered' => true]],
			],
			$this->events
		);
		$this->assertSame(
			['allowed' => false, 'lockout_until' => 'future', 'reason' => 'Too many failed authentication attempts. Please wait before trying again.'],
			$this->normalise($this->service->checkAuthRateLimit('key-1', '10.0.0.9'))
		);
		// Another identity from the same address is not locked out by it.
		$this->assertSame(['allowed' => true], $this->service->checkAuthRateLimit('key-2', '10.0.0.9'));

		$this->service->recordSuccessfulAuth('key-1', '10.0.0.9');
		$this->assertSame(['allowed' => true], $this->service->checkAuthRateLimit('key-1', '10.0.0.9'));
		$this->assertSame(['openregister_auth_ip_attempts_10.0.0.9'], $this->keys());
	}//end testInboundAuthLocksOutAtTheIdentityCeiling()

	/**
	 * The per-address backstop locks out the identity whose failure crosses it; an empty identity is anonymous.
	 *
	 * @return void
	 */
	public function testInboundAuthAddressBackstop(): void {
		for ($i = 1; $i <= 4; $i++) {
			$this->service->recordFailedAuthAttempt(new FailedAttempt('id' . $i, '10.0.0.8', 'inbound_auth_failed'));
		}

		$this->assertSame(['allowed' => true], $this->service->checkAuthRateLimit('id4', '10.0.0.8'));
		$this->service->recordFailedAuthAttempt(new FailedAttempt('', '10.0.0.8', 'inbound_auth_failed'));
		$this->assertSame(false, $this->service->checkAuthRateLimit('', '10.0.0.8')['allowed']);
		$this->assertArrayHasKey('openregister_auth_lockout_anonymous_10.0.0.8', $this->store);
		$this->assertSame(['allowed' => true], $this->service->checkAuthRateLimit('id1', '10.0.0.8'));
	}//end testInboundAuthAddressBackstop()
}//end class
