<?php

/**
 * Golden characterisation test for JwtValidator::validateClaims().
 *
 * Pins, on the code before the claim checks were split into named steps, the
 * allowed and the refused outcomes: each refusal's message and full details
 * (times taken relative to the moment of the call, never a fixed clock), the
 * clock-skew boundaries of iat and nbf, the default one-hour expiry, and the
 * lifetime (plus the skew) the jti is remembered for.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Consumer
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/authorization-service-public-hardened/specs/auth-system/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

namespace OCA\OpenRegister\Tests\Unit\Service\Consumer;

use OCA\OpenRegister\Exception\AuthenticationException;
use OCA\OpenRegister\Service\Consumer\JwtValidator;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;

final class JwtClaimsGoldenTest extends TestCase {

	private const SKEW = JwtValidator::CLOCK_SKEW_SECONDS;

	/**
	 * Run validateClaims and return the refusal with the clock window it ran in.
	 *
	 * @return array{0: AuthenticationException|null, 1: int, 2: int}
	 */
	private function attempt(JwtValidator $validator, array $payload): array {
		$before = time();
		try {
			$validator->validateClaims($payload);
			$refusal = null;
		} catch (AuthenticationException $e) {
			$refusal = $e;
		}

		return [$refusal, $before, time()];
	}

	/**
	 * Assert a refusal's message and details; 'time checked' must fall in the call's clock window.
	 */
	private function assertRefused(array $attempt, string $message, array $details): void {
		[$refusal, $before, $after] = $attempt;
		$this->assertNotNull($refusal, 'expected a refusal: ' . $message);
		$this->assertSame($message, $refusal->getMessage());

		$actual = $refusal->getDetails();
		if (array_key_exists('time checked', $details) === true) {
			$this->assertGreaterThanOrEqual($before, $actual['time checked']);
			$this->assertLessThanOrEqual($after, $actual['time checked']);
			$details['time checked'] = $actual['time checked'];
		}

		$this->assertSame($details, $actual);
	}

	public function testEachRefusalCarriesItsMessageAndDetails(): void {
		$validator = new JwtValidator();
		$now       = time();

		$this->assertRefused($this->attempt($validator, []), 'The token has no time of creation', ['iat' => null]);
		$this->assertRefused($this->attempt($validator, ['exp' => $now + 600]), 'The token has no time of creation', ['iat' => null]);

		$future = $now + self::SKEW + 120;
		$this->assertRefused(
			$this->attempt($validator, ['iat' => $future, 'exp' => $future + 600]),
			'The token has an invalid issue time',
			['iat' => $future, 'time checked' => 0]
		);

		$old = $now - 7200;
		$this->assertRefused(
			$this->attempt($validator, ['iat' => $old]),
			'The token has expired',
			['iat' => $old, 'exp' => $old + 3600, 'time checked' => 0]
		);
		$this->assertRefused(
			$this->attempt($validator, ['iat' => $now - 600, 'exp' => $now - 300]),
			'The token has expired',
			['iat' => $now - 600, 'exp' => $now - 300, 'time checked' => 0]
		);
		$this->assertRefused(
			$this->attempt($validator, ['iat' => $now - 600, 'exp' => (string)($now - 300), 'nbf' => $now + 900]),
			'The token has expired',
			['iat' => $now - 600, 'exp' => $now - 300, 'time checked' => 0]
		);

		$this->assertRefused(
			$this->attempt($validator, ['iat' => $now, 'nbf' => (string)($now + self::SKEW + 120)]),
			'The token is not yet valid',
			['nbf' => $now + self::SKEW + 120, 'time checked' => 0]
		);
	}

	public function testTokensInsideTheSkewAndTheirLifetimeAreAllowed(): void {
		$validator = new JwtValidator();
		$now       = time();

		$allowed = [
			['iat' => $now],
			['iat' => $now - 3000],
			['iat' => $now + self::SKEW - 5],
			['iat' => $now, 'nbf' => $now + self::SKEW - 5],
			['iat' => $now, 'nbf' => $now - 10, 'exp' => $now + 30],
			['iat' => $now - 7200, 'exp' => $now + 30],
			['iat' => (string)$now, 'exp' => (string)($now + 600), 'jti' => ''],
		];
		foreach ($allowed as $payload) {
			[$refusal] = $this->attempt($validator, $payload);
			$this->assertNull($refusal, 'allowed: ' . json_encode($payload));
		}
	}

	public function testTheJtiIsRememberedForTheTokensRemainingLifetime(): void {
		$ttls  = [];
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturn(null);
		$cache->method('set')->willReturnCallback(
			static function (string $key, mixed $value, int $ttl) use (&$ttls): bool {
				$ttls[] = $ttl;
				return true;
			}
		);
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);
		$validator = new JwtValidator($factory);

		$before = time();
		$validator->validateClaims(['iat' => $before, 'jti' => 'default-expiry']);
		$validator->validateClaims(['iat' => $before - 100, 'exp' => $before + 1000, 'jti' => 'explicit-expiry']);
		$after = time();

		$this->assertCount(2, $ttls);
		$this->assertGreaterThanOrEqual(3600 + self::SKEW - ($after - $before), $ttls[0]);
		$this->assertLessThanOrEqual(3600 + self::SKEW, $ttls[0]);
		$this->assertGreaterThanOrEqual(1000 + self::SKEW - ($after - $before), $ttls[1]);
		$this->assertLessThanOrEqual(1000 + self::SKEW, $ttls[1]);
	}
}//end class
