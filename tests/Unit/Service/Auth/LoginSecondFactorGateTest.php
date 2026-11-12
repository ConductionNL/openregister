<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Auth;

use OCA\OpenRegister\Service\Auth\LoginSecondFactorGate;
use OCP\Authentication\TwoFactorAuth\IRegistry;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

class LoginSecondFactorGateTest extends TestCase {

	/**
	 * @param array<string, bool>  $providers
	 * @param array<string, mixed> $system
	 * @param list<string>         $groups
	 */
	private function gate(array $providers = [], array $system = [], array $groups = []): LoginSecondFactorGate {
		$registry = $this->createMock(IRegistry::class);
		$registry->method('getProviderStates')->willReturn($providers);

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturnCallback(
			static fn (string $key, mixed $default = ''): mixed => ($system[$key] ?? $default)
		);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $group): bool => in_array($group, $groups, true)
		);

		return new LoginSecondFactorGate($registry, $config, $groupManager);
	}//end gate()

	private function user(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');

		return $user;
	}//end user()

	public function testAPasswordOnlyUserPasses(): void {
		$this->assertFalse($this->gate(providers: ['totp' => false])->requiresSecondFactor($this->user()));
	}

	public function testAnEnabledProviderNeedsASecondFactor(): void {
		$this->assertTrue($this->gate(providers: ['totp' => true])->requiresSecondFactor($this->user()));
	}

	public function testBackupCodesAloneAreNotASecondFactor(): void {
		$this->assertFalse($this->gate(providers: ['backup_codes' => true])->requiresSecondFactor($this->user()));
	}

	public function testEnforcementForEveryoneNeedsASecondFactor(): void {
		$this->assertTrue($this->gate(system: ['twofactor_enforced' => 'true'])->requiresSecondFactor($this->user()));
	}

	public function testEnforcementAsABooleanTrueNeedsASecondFactor(): void {
		$this->assertTrue($this->gate(system: ['twofactor_enforced' => true])->requiresSecondFactor($this->user()));
	}

	public function testEnforcementSwitchedOffDoesNotNeedASecondFactor(): void {
		$this->assertFalse($this->gate(system: ['twofactor_enforced' => 'false'])->requiresSecondFactor($this->user()));
		$this->assertFalse($this->gate(system: ['twofactor_enforced' => false])->requiresSecondFactor($this->user()));
		$this->assertFalse($this->gate()->requiresSecondFactor($this->user()));
	}

	public function testEnforcedWithoutAnActiveProviderNeedsASecondFactor(): void {
		$providers = ['totp' => false, 'backup_codes' => true];

		$this->assertTrue($this->gate(providers: $providers, system: ['twofactor_enforced' => 'true'])->requiresSecondFactor($this->user()));
		$this->assertTrue($this->gate(providers: $providers, system: ['twofactor_enforced' => true])->requiresSecondFactor($this->user()));
	}

	public function testAnExcludedGroupIsNotEnforced(): void {
		$gate = $this->gate(system: ['twofactor_enforced' => 'true', 'twofactor_enforced_excluded_groups' => ['guests']], groups: ['guests']);

		$this->assertFalse($gate->requiresSecondFactor($this->user()));
	}

	public function testEnforcedGroupsLimitTheEnforcement(): void {
		$system = ['twofactor_enforced' => 'true', 'twofactor_enforced_groups' => ['staff']];

		$this->assertTrue($this->gate(system: $system, groups: ['staff'])->requiresSecondFactor($this->user()));
		$this->assertFalse($this->gate(system: $system, groups: ['other'])->requiresSecondFactor($this->user()));
	}

	public function testAnUnreadableRegistryFailsClosed(): void {
		$registry = $this->createMock(IRegistry::class);
		$registry->method('getProviderStates')->willThrowException(new \RuntimeException('db down'));
		$gate = new LoginSecondFactorGate($registry, $this->createMock(IConfig::class), $this->createMock(IGroupManager::class));

		$this->assertTrue($gate->requiresSecondFactor($this->user()));
	}
}//end class
