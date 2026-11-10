<?php

/**
 * HardeningPolicyTest — what is administered, and what the floor is.
 *
 * The case worth having: a corrupt or half-written floor map must leave every
 * control on its shipped baseline, never on whatever the caller hoped for.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Hardening
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Hardening;

use OCA\OpenRegister\Service\Hardening\HardeningPolicy;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Hardening\HardeningPolicy
 */
class HardeningPolicyTest extends TestCase {

	/**
	 * Build a policy over a stubbed configuration.
	 *
	 * @param array<string, int> $ints The stored integers.
	 * @param array<string, string> $strings The stored strings.
	 * @param bool $throws Whether every read throws.
	 *
	 * @return HardeningPolicy The policy.
	 */
	private function policy(array $ints = [], array $strings = [], bool $throws = false): HardeningPolicy {
		$appConfig = $this->createMock(IAppConfig::class);

		$appConfig->method('getValueInt')->willReturnCallback(
			static function (string $app, string $key, int $default) use ($ints, $throws): int {
				if ($throws === true) {
					throw new RuntimeException('configuration unavailable');
				}

				return ($ints[$key] ?? $default);
			}
		);

		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default) use ($strings, $throws): string {
				if ($throws === true) {
					throw new RuntimeException('configuration unavailable');
				}

				return ($strings[$key] ?? $default);
			}
		);

		return new HardeningPolicy($appConfig);
	}

	public function testAuthCeilingFallsBackToTheShippedDefaults(): void {
		$ceiling = $this->policy()->authRateLimit();

		$this->assertSame(20, $ceiling['attemptsPerIdentity']);
		$this->assertSame(100, $ceiling['attemptsPerAddress']);
		$this->assertSame(900, $ceiling['windowSeconds']);
		$this->assertSame(900, $ceiling['lockoutSeconds']);
	}

	public function testAnAdministeredCeilingIsWhatIsReturned(): void {
		$ceiling = $this->policy(ints: ['hardening_auth_lockout_seconds' => 1800])->authRateLimit();

		$this->assertSame(1800, $ceiling['lockoutSeconds']);
	}

	public function testAConfigurationThatThrowsLeavesTheBaselineInForce(): void {
		$ceiling = $this->policy(throws: true)->authRateLimit();

		$this->assertSame(900, $ceiling['lockoutSeconds']);
	}

	public function testOriginsAreTrimmedLowercasedAndDeduplicated(): void {
		$policy = $this->policy(strings: ['hardening_allowed_origins' => ' https://Example.nl , https://example.nl,, https://other.nl ']);

		$this->assertSame(['https://example.nl', 'https://other.nl'], $policy->allowedOrigins());
	}

	public function testNoAllowlistMeansNoEntries(): void {
		$this->assertSame([], $this->policy()->allowedOrigins());
	}

	public function testADeclaredFloorOverridesTheBaseline(): void {
		$policy = $this->policy(strings: [HardeningPolicy::FLOORS_KEY => '{"auth.rateLimit.lockoutSeconds":1800}']);

		$this->assertSame(1800, $policy->floor(control: 'auth.rateLimit.lockoutSeconds'));
	}

	public function testACorruptFloorMapLeavesEveryControlOnItsBaseline(): void {
		$policy = $this->policy(strings: [HardeningPolicy::FLOORS_KEY => 'not json at all']);

		$this->assertSame([], $policy->declaredFloors());
		$this->assertSame(900, $policy->floor(control: 'auth.rateLimit.lockoutSeconds'));
	}

	public function testANonIntegerFloorIsDroppedRatherThanCoerced(): void {
		$policy = $this->policy(strings: [HardeningPolicy::FLOORS_KEY => '{"auth.rateLimit.lockoutSeconds":"1800"}']);

		$this->assertSame([], $policy->declaredFloors());
		$this->assertSame(900, $policy->floor(control: 'auth.rateLimit.lockoutSeconds'));
	}

	public function testPlatformControlsCarryABaselineAndAComparator(): void {
		$this->assertSame(10, HardeningPolicy::baseline(control: 'password.minimumLength'));
		$this->assertSame('atLeast', HardeningPolicy::comparator(control: 'password.minimumLength'));
		$this->assertSame('atMost', HardeningPolicy::comparator(control: 'session.lifetimeSeconds'));
		$this->assertTrue(HardeningPolicy::isKnown(control: 'upload.ceilingBytes'));
		$this->assertFalse(HardeningPolicy::isKnown(control: 'invented.control'));
	}

	public function testFloorsCoverEveryReportedControl(): void {
		$floors = $this->policy()->floors();

		$expected = count(HardeningPolicy::CONTROLS) + count(HardeningPolicy::REPORTED_CONTROLS);
		$this->assertCount($expected, $floors);
	}
}
