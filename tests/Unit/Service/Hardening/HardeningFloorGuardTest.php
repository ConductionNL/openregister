<?php

/**
 * HardeningFloorGuardTest — the refusal, in both directions.
 *
 * The direction of "stronger" is the thing to get wrong quietly: more attempts
 * is weaker, a longer lockout is stronger, and a guard that compares both the
 * same way passes every weakening of one of them.
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

use OCA\OpenRegister\Service\Hardening\HardeningFloorException;
use OCA\OpenRegister\Service\Hardening\HardeningFloorGuard;
use OCA\OpenRegister\Service\Hardening\HardeningPolicy;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Hardening\HardeningFloorGuard
 */
class HardeningFloorGuardTest extends TestCase {

	/**
	 * Build a guard over a stubbed floor map.
	 *
	 * @param string $floors The stored floor map, as JSON.
	 *
	 * @return HardeningFloorGuard The guard.
	 */
	private function guard(string $floors = ''): HardeningFloorGuard {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default) use ($floors): string {
				if ($key === HardeningPolicy::FLOORS_KEY) {
					return $floors;
				}

				return $default;
			}
		);
		$appConfig->method('getValueInt')->willReturnCallback(
			static fn (string $app, string $key, int $default): int => $default
		);

		return new HardeningFloorGuard(new HardeningPolicy($appConfig));
	}

	public function testALongerLockoutIsAllowedAndAShorterOneIsRefused(): void {
		$guard = $this->guard();

		$guard->assertValue(control: 'auth.rateLimit.lockoutSeconds', proposed: 3600);
		$this->addToAssertionCount(1);

		$this->expectException(HardeningFloorException::class);
		$guard->assertValue(control: 'auth.rateLimit.lockoutSeconds', proposed: 60);
	}

	public function testFewerAttemptsAreAllowedAndMoreAreRefused(): void {
		$guard = $this->guard();

		$guard->assertValue(control: 'auth.rateLimit.attemptsPerIdentity', proposed: 5);
		$this->addToAssertionCount(1);

		$this->expectException(HardeningFloorException::class);
		$guard->assertValue(control: 'auth.rateLimit.attemptsPerIdentity', proposed: 200);
	}

	public function testTheRefusalNamesTheControlTheFloorAndWhatWasAsked(): void {
		$guard = $this->guard(floors: '{"auth.rateLimit.lockoutSeconds":1800}');

		try {
			$guard->assertValue(control: 'auth.rateLimit.lockoutSeconds', proposed: 60);
			$this->fail('The guard accepted a lockout below the declared floor.');
		} catch (HardeningFloorException $refusal) {
			$this->assertSame('auth.rateLimit.lockoutSeconds', $refusal->control);
			$this->assertSame(1800, $refusal->floor);
			$this->assertSame(60, $refusal->proposed);
			$this->assertStringContainsString('1800', $refusal->getMessage());
			$this->assertStringContainsString('auth.rateLimit.lockoutSeconds', $refusal->getMessage());
		}
	}

	public function testARaisedFloorRefusesAValueThatTheBaselineWouldHaveAllowed(): void {
		$permissive = $this->guard();
		$permissive->assertValue(control: 'auth.rateLimit.lockoutSeconds', proposed: 900);
		$this->addToAssertionCount(1);

		$strict = $this->guard(floors: '{"auth.rateLimit.lockoutSeconds":1800}');
		$this->expectException(HardeningFloorException::class);
		$strict->assertValue(control: 'auth.rateLimit.lockoutSeconds', proposed: 900);
	}

	public function testAFloorMayBeRaisedButNotDeclaredWeakerThanTheBaseline(): void {
		$guard = $this->guard();

		$guard->assertFloor(control: 'auth.rateLimit.attemptsPerIdentity', proposed: 5);
		$this->addToAssertionCount(1);

		$this->expectException(HardeningFloorException::class);
		$guard->assertFloor(control: 'auth.rateLimit.attemptsPerIdentity', proposed: 200);
	}

	public function testAWeakerSessionFloorIsRefusedTheOtherWayRound(): void {
		$guard = $this->guard();

		$guard->assertFloor(control: 'session.lifetimeSeconds', proposed: 3600);
		$this->addToAssertionCount(1);

		$this->expectException(HardeningFloorException::class);
		$guard->assertFloor(control: 'session.lifetimeSeconds', proposed: 604800);
	}

	public function testRequiringAnAllowlistRefusesAnEmptyOne(): void {
		$guard = $this->guard(floors: '{"origins.allowlistEntries":1}');

		$guard->assertValue(control: 'origins.allowlistEntries', proposed: 2);
		$this->addToAssertionCount(1);

		$this->expectException(HardeningFloorException::class);
		$guard->assertValue(control: 'origins.allowlistEntries', proposed: 0);
	}
}
