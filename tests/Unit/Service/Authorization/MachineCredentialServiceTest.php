<?php

/**
 * Service accounts: a principal owned by a team, that cannot sign in (REQ-SAT-004).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Authorization
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/scoped-api-tokens-machine-callers/specs/auth-system/spec.md#requirement-a-service-account-is-a-principal-owned-by-a-team-req-sat-004
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Authorization;

use InvalidArgumentException;
use OCA\OpenRegister\Service\Authorization\MachineCredentialService;
use OCA\OpenRegister\Service\Authorization\ServiceAccountRequest;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see MachineCredentialService}.
 *
 * @covers \OCA\OpenRegister\Service\Authorization\MachineCredentialService
 * @uses   \OCA\OpenRegister\Service\Authorization\ServiceAccountRequest
 */
class MachineCredentialServiceTest extends TestCase {

	/**
	 * The user config, as [uid][key] => value.
	 *
	 * @var array<string, array<string, string>>
	 */
	private array $store = [];

	/**
	 * The users that exist, by uid.
	 *
	 * @var array<string, IUser&MockObject>
	 */
	private array $existing = [];

	private IUserManager&MockObject $users;
	private IGroupManager&MockObject $groups;
	private ISecureRandom&MockObject $random;
	private MachineCredentialService $service;

	protected function setUp(): void {
		$this->users = $this->createMock(IUserManager::class);
		$this->users->method('userExists')->willReturnCallback(fn (string $uid): bool => isset($this->existing[$uid]));
		$this->users->method('get')->willReturnCallback(fn (string $uid): ?IUser => ($this->existing[$uid] ?? null));

		$this->groups = $this->createMock(IGroupManager::class);
		$this->groups->method('groupExists')->willReturnCallback(static fn (string $gid): bool => $gid === 'integrations');

		$config = $this->createMock(IConfig::class);
		$config->method('setUserValue')->willReturnCallback(
			function (string $uid, string $app, string $key, string $value): void {
				$this->store[$uid][$app.'/'.$key] = $value;
			}
		);
		$config->method('getUserValue')->willReturnCallback(
			fn (string $uid, string $app, string $key, string $default = ''): string => ($this->store[$uid][$app.'/'.$key] ?? $default)
		);

		$this->random = $this->createMock(ISecureRandom::class);
		$this->random->method('generate')->willReturn(str_repeat('x', 72));

		$this->service = new MachineCredentialService(users: $this->users, groups: $this->groups, config: $config, random: $this->random);
	}

	/**
	 * A user double that records its display name and enabled state.
	 *
	 * @param string $uid The user id.
	 *
	 * @return IUser&MockObject The user.
	 */
	private function user(string $uid): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$name = $uid;
		$enabled = true;
		$user->method('getUID')->willReturn($uid);
		$user->method('setDisplayName')->willReturnCallback(function (string $value) use (&$name): bool {
			$name = $value;
			return true;
		});
		$user->method('getDisplayName')->willReturnCallback(function () use (&$name): string {
			return $name;
		});
		$user->method('setEnabled')->willReturnCallback(function (bool $value) use (&$enabled): void {
			$enabled = $value;
		});
		$user->method('isEnabled')->willReturnCallback(function () use (&$enabled): bool {
			return $enabled;
		});

		return $user;
	}

	/**
	 * Let createUser make the user it is asked for.
	 *
	 * @return void
	 */
	private function usersCanBeCreated(): void {
		$this->users->method('createUser')->willReturnCallback(function (string $uid, string $password): IUser {
			$this->existing[$uid] = $this->user($uid);
			return $this->existing[$uid];
		});
	}

	/**
	 * A complete request.
	 *
	 * @param array<string, string> $override Fields to replace.
	 *
	 * @return ServiceAccountRequest The request.
	 */
	private static function request(array $override = []): ServiceAccountRequest {
		$fields = array_merge(
			[
				'name' => 'Supplier Sync',
				'ownerGroup' => 'integrations',
				'contactName' => 'Petra de Vries',
				'contactEmail' => 'petra@example.org',
				'purpose' => 'Nightly supplier import',
			],
			$override
		);

		return new ServiceAccountRequest(...$fields);
	}

	/**
	 * 🔴 No account without an owning team that exists, a contact and a purpose — and nothing is created.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/scoped-api-tokens-machine-callers/specs/auth-system/spec.md#requirement-a-service-account-is-a-principal-owned-by-a-team-req-sat-004
	 */
	public function testProvisioningRequiresOwnerContactAndPurpose(): void {
		$this->users->expects(self::never())->method('createUser');

		$cases = [
			'name' => ['name' => ' !! '],
			'owner group' => ['ownerGroup' => ''],
			'contact name' => ['contactName' => '  '],
			'contact e-mail' => ['contactEmail' => 'not-an-address'],
			'purpose' => ['purpose' => ''],
		];
		foreach ($cases as $missing => $override) {
			try {
				$this->service->provision(request: self::request(override: $override), createdBy: 'admin');
				self::fail('Provisioned without a '.$missing);
			} catch (InvalidArgumentException $e) {
				self::assertSame('A service account needs a '.$missing.'.', $e->getMessage());
			}
		}

		$this->expectExceptionObject(new InvalidArgumentException('The owner group "nobody" does not exist.'));
		$this->service->provision(request: self::request(override: ['ownerGroup' => 'nobody']), createdBy: 'admin');
	}

	/**
	 * Provisioning creates a marked user with a password nobody is given, and records owner, contact and purpose.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/scoped-api-tokens-machine-callers/specs/auth-system/spec.md#requirement-a-service-account-is-a-principal-owned-by-a-team-req-sat-004
	 */
	public function testProvisioningCreatesAMarkedUserOwnedByTheTeam(): void {
		$this->users->expects(self::once())->method('createUser')
			->with('svc-supplier-sync', str_repeat('x', 72))
			->willReturnCallback(function (string $uid): IUser {
				$this->existing[$uid] = $this->user($uid);
				return $this->existing[$uid];
			});
		$this->random->expects(self::once())->method('generate')
			->with(72, ISecureRandom::CHAR_ALPHANUMERIC.ISecureRandom::CHAR_SYMBOLS);

		$account = $this->service->provision(request: self::request(), createdBy: 'admin');

		self::assertSame(
			[
				'uid' => 'svc-supplier-sync',
				'name' => 'Supplier Sync',
				'ownerGroup' => 'integrations',
				'contactName' => 'Petra de Vries',
				'contactEmail' => 'petra@example.org',
				'purpose' => 'Nightly supplier import',
				'createdBy' => 'admin',
			],
			$account
		);
		self::assertArrayNotHasKey('password', $account, 'the password is never handed out');
		self::assertTrue($this->service->isServiceAccount(uid: 'svc-supplier-sync'));
	}

	/**
	 * 🔴 The creator is a record, not an owner: disabling them leaves the account working.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/scoped-api-tokens-machine-callers/specs/auth-system/spec.md#requirement-a-service-account-is-a-principal-owned-by-a-team-req-sat-004
	 */
	public function testDisablingTheCreatorKeepsTheServiceAccountWorking(): void {
		$this->usersCanBeCreated();
		$creator = $this->user('jan');
		$this->existing['jan'] = $creator;

		$this->service->provision(request: self::request(), createdBy: 'jan');
		$creator->setEnabled(false);

		self::assertFalse($creator->isEnabled());
		self::assertTrue($this->existing['svc-supplier-sync']->isEnabled(), 'the account itself is untouched');
		self::assertTrue($this->service->isServiceAccount(uid: 'svc-supplier-sync'));
		self::assertSame('integrations', $this->service->describe(uid: 'svc-supplier-sync')['ownerGroup'], 'the team still owns it');
	}

	/**
	 * A user id that is taken is refused rather than turned into a service account.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/scoped-api-tokens-machine-callers/specs/auth-system/spec.md#requirement-a-service-account-is-a-principal-owned-by-a-team-req-sat-004
	 */
	public function testATakenUserIdIsRefused(): void {
		$this->existing['svc-supplier-sync'] = $this->user('svc-supplier-sync');
		$this->users->expects(self::never())->method('createUser');

		$this->expectExceptionObject(new InvalidArgumentException('A user "svc-supplier-sync" already exists.'));
		$this->service->provision(request: self::request(), createdBy: 'admin');
	}

	/**
	 * An ordinary user is not a service account and describes as nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/scoped-api-tokens-machine-callers/specs/auth-system/spec.md#requirement-a-service-account-is-a-principal-owned-by-a-team-req-sat-004
	 */
	public function testAnOrdinaryUserIsNotAServiceAccount(): void {
		self::assertFalse($this->service->isServiceAccount(uid: 'jan'));
		self::assertSame([], $this->service->describe(uid: 'jan'));
	}

	/**
	 * The user id reads as a service account and keeps to a safe charset.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/scoped-api-tokens-machine-callers/specs/auth-system/spec.md#requirement-a-service-account-is-a-principal-owned-by-a-team-req-sat-004
	 */
	public function testTheUserIdIsPrefixedAndSafe(): void {
		self::assertSame('svc-supplier-sync-v2', MachineCredentialService::uidFor(name: '  Supplier Sync (v2) '));
		self::assertSame(60, strlen(MachineCredentialService::uidFor(name: str_repeat('a', 100))));
	}
}
