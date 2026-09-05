<?php

/**
 * Unit tests for FlowRunAsScope — the run's acting identity, validated and applied.
 *
 * The refusals are the tests that matter: an identity that resolves to no
 * account or to a disabled one must stop the step loudly, because the silent
 * alternative is a write performed as whoever the ambient session happens to
 * carry — under a worker, nobody, and on an interactive request somebody
 * else entirely.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/flow-engine-consumer-seams/specs/flow-engine-consumer-seams/spec.md#requirement-a-contributed-node-executes-under-the-runs-acting-identity
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowRunAsScope;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class FlowRunAsScopeTest extends TestCase {

	/**
	 * A user manager resolving exactly one uid.
	 *
	 * @param string $uid The uid that resolves.
	 * @param boolean $enabled Whether the account is enabled.
	 *
	 * @return IUserManager The manager.
	 */
	private function userManagerWith(string $uid, bool $enabled): IUserManager {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('isEnabled')->willReturn($enabled);

		$manager = $this->createMock(IUserManager::class);
		$manager->method('get')->willReturnCallback(
			static function (string $asked) use ($uid, $user): ?IUser {
				if ($asked === $uid) {
					return $user;
				}

				return null;
			}
		);

		return $manager;
	}//end userManagerWith()

	public function testAValidIdentityRunsTheOperationInsideRunAs(): void {
		$scopedAs = null;
		$objects = $this->createMock(ObjectService::class);
		$objects->method('runAs')->willReturnCallback(
			static function (IUser $user, callable $operation) use (&$scopedAs): mixed {
				$scopedAs = $user->getUID();

				return $operation();
			}
		);

		$scope = new FlowRunAsScope(
			userManager: $this->userManagerWith(uid: 'alice', enabled: true),
			objectService: $objects
		);

		$out = $scope->call(
			context: [FlowRunService::RUN_AS_CONTEXT_KEY => 'alice'],
			operation: static fn (): string => 'wrote'
		);

		$this->assertSame('wrote', $out);
		$this->assertSame('alice', $scopedAs, 'the work must run inside runAs(alice)');
	}//end testAValidIdentityRunsTheOperationInsideRunAs()

	/**
	 * No identity declared is the interactive path: the operation runs bare,
	 * under the ambient session, and runAs is never involved.
	 */
	public function testNoIdentityRunsBare(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->expects($this->never())->method('runAs');

		$scope = new FlowRunAsScope(
			userManager: $this->createMock(IUserManager::class),
			objectService: $objects
		);

		$this->assertSame('bare', $scope->call(context: [], operation: static fn (): string => 'bare'));
		$this->assertSame(
			'bare',
			$scope->call(
				context: [FlowRunService::RUN_AS_CONTEXT_KEY => '  '],
				operation: static fn (): string => 'bare'
			)
		);
	}//end testNoIdentityRunsBare()

	/**
	 * 🔴 An identity that resolves to NO account refuses loudly, and the
	 * operation never runs.
	 */
	public function testAnUnknownIdentityRefusesLoudly(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->expects($this->never())->method('runAs');

		$scope = new FlowRunAsScope(
			userManager: $this->userManagerWith(uid: 'alice', enabled: true),
			objectService: $objects
		);

		$ran = false;

		try {
			$scope->call(
				context: [FlowRunService::RUN_AS_CONTEXT_KEY => 'ghost'],
				operation: static function () use (&$ran): void {
					$ran = true;
				}
			);
			$this->fail('Expected the step to be refused.');
		} catch (RuntimeException $refused) {
			$this->assertStringContainsString('ghost', $refused->getMessage());
		}

		$this->assertFalse($ran, 'a refused step must not have run its work');
	}//end testAnUnknownIdentityRefusesLoudly()

	/**
	 * 🔴 A DISABLED account still resolves, and must refuse anyway: rights are
	 * re-checked at the moment work runs so that offboarding takes effect on a
	 * run parked for weeks.
	 */
	public function testADisabledIdentityRefusesLoudly(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->expects($this->never())->method('runAs');

		$scope = new FlowRunAsScope(
			userManager: $this->userManagerWith(uid: 'former', enabled: false),
			objectService: $objects
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/disabled/');

		$scope->call(
			context: [FlowRunService::RUN_AS_CONTEXT_KEY => 'former'],
			operation: static fn (): string => 'never'
		);
	}//end testADisabledIdentityRefusesLoudly()
}//end class
