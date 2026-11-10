<?php

/**
 * Golden test: what TenantLifecycleService provisions, allows and refuses.
 *
 * Pins, on the code as it was before the A21 refactor, every refusal of
 * provision() and validateTransition() (code and message, and that a refusal
 * creates no group and writes nothing), and on success the exact order of
 * group-manager and user-manager calls, the groups, members and default
 * authorization written to the organisation, the log lines, and the rethrow
 * of a failing write.
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

use Exception;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapperInterface;
use OCA\OpenRegister\Service\TenantDirectory;
use OCA\OpenRegister\Service\TenantLifecycleService;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\TenantLifecycleService
 */
final class TenantLifecycleServiceGoldenTest extends TestCase {

	/**
	 * Every collaborator call, in order.
	 *
	 * @var list<string>
	 */
	private array $calls = [];

	/**
	 * Groups that already exist.
	 *
	 * @var list<string>
	 */
	private array $existing = [];

	/**
	 * Groups the manager returns (id => present).
	 *
	 * @var array<string, bool>
	 */
	private array $resolvable = ['acme-admin' => true, 'acme-users' => true];

	/**
	 * Whether the admin user exists.
	 *
	 * @var bool
	 */
	private bool $userExists = true;

	/**
	 * Whether the mapper write fails.
	 *
	 * @var bool
	 */
	private bool $writeFails = false;

	/**
	 * The service under test, over recording collaborators.
	 *
	 * @return TenantLifecycleService
	 */
	private function service(): TenantLifecycleService {
		$user = $this->createMock(IUser::class);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('groupExists')->willReturnCallback(function (string $id): bool {
			$this->calls[] = 'groupExists ' . $id;
			return in_array($id, $this->existing, true);
		});
		$groups->method('createGroup')->willReturnCallback(function (string $id) {
			$this->calls[] = 'createGroup ' . $id;
			return null;
		});
		$groups->method('get')->willReturnCallback(function (string $id) use ($user) {
			$this->calls[] = 'getGroup ' . $id;
			if (($this->resolvable[$id] ?? false) === false) {
				return null;
			}

			$group = $this->createMock(IGroup::class);
			$group->method('addUser')->willReturnCallback(function ($added) use ($id, $user): void {
				$this->calls[] = 'addUser ' . $id . ' ' . ($added === $user ? 'admin' : 'other');
			});
			return $group;
		});
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(function (string $uid) use ($user) {
			$this->calls[] = 'getUser ' . $uid;
			return ($this->userExists === true ? $user : null);
		});
		$mapper = $this->createMock(OrganisationMapperInterface::class);
		$mapper->method('update')->willReturnCallback(function ($org) {
			$this->calls[] = 'update';
			if ($this->writeFails === true) {
				throw new RuntimeException('write failed');
			}

			return $org;
		});
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(function ($message, array $context = []) use ($level): void {
				$this->calls[] = $level . ' ' . $message . ' ' . json_encode($context);
			});
		}

		return new TenantLifecycleService($mapper, new TenantDirectory($groups, $users), $logger);
	}//end service()

	/**
	 * An organisation ready to provision.
	 *
	 * @param string|null $status The status.
	 *
	 * @return Organisation
	 */
	private function organisation(?string $status = 'provisioning'): Organisation {
		$org = new Organisation();
		if ($status !== null) {
			$org->setStatus($status);
		}

		$org->setUuid('org-uuid');
		$org->setSlug('acme');
		$org->setAuthorization([
			'register' => ['create' => ['x'], 'read' => ['x'], 'update' => ['x'], 'delete' => ['x'], 'publish' => ['p']],
			'schema' => ['read' => ['public']],
			'flag' => true,
		]);
		return $org;
	}//end organisation()

	/**
	 * The exception a call throws.
	 *
	 * @param callable $call The call.
	 *
	 * @return Exception
	 */
	private function refusal(callable $call): Exception {
		try {
			$call();
		} catch (Exception $e) {
			return $e;
		}

		$this->fail('was allowed');
	}//end refusal()

	/**
	 * Provisioning writes groups, members and the default authorization, in this order.
	 *
	 * @return void
	 */
	public function testProvisioningActivatesTheOrganisation(): void {
		$this->existing = ['acme-users'];
		$org = $this->organisation();
		$result = $this->service()->provision($org, 'alice');
		$this->assertSame($org, $result);
		$this->assertSame(
			[
				'groupExists acme-admin',
				'createGroup acme-admin',
				'groupExists acme-users',
				'getGroup acme-admin',
				'getGroup acme-users',
				'getUser alice',
				'addUser acme-admin admin',
				'addUser acme-users admin',
				'update',
				'info [TenantLifecycleService] Organisation provisioned and activated {"uuid":"org-uuid","slug":"acme"}',
			],
			$this->calls
		);
		$this->assertSame('active', $org->getStatus());
		$this->assertNotNull($org->getProvisionedAt());
		$this->assertSame(['acme-admin', 'acme-users'], $org->getGroups());
		$this->assertContains('alice', $org->getUsers());
		$this->assertSame(
			[
				'register' => [
					'create' => ['acme-admin', 'acme-users'],
					'read' => ['acme-admin', 'acme-users'],
					'update' => ['acme-admin', 'acme-users'],
					'delete' => ['acme-admin'],
					'publish' => ['p'],
				],
				'schema' => ['read' => ['public']],
				'flag' => true,
			],
			$org->getAuthorization()
		);
	}//end testProvisioningActivatesTheOrganisation()

	/**
	 * A missing admin group skips the user lookup; a missing user or users group skips the add.
	 *
	 * @return void
	 */
	public function testMissingGroupsOrUserAreSkipped(): void {
		$this->resolvable = ['acme-admin' => false, 'acme-users' => true];
		$this->service()->provision($this->organisation(), 'alice');
		$this->assertNotContains('getUser alice', $this->calls);

		$this->calls = [];
		$this->resolvable = ['acme-admin' => true, 'acme-users' => false];
		$this->service()->provision($this->organisation(), 'alice');
		$this->assertContains('addUser acme-admin admin', $this->calls);
		$this->assertNotContains('addUser acme-users admin', $this->calls);

		$this->calls = [];
		$this->resolvable = ['acme-admin' => true, 'acme-users' => true];
		$this->userExists = false;
		$org = $this->organisation();
		$this->service()->provision($org, 'ghost');
		$this->assertSame([], array_values(array_filter($this->calls, static fn (string $c): bool => str_starts_with($c, 'addUser'))));
		$this->assertContains('ghost', $org->getUsers());
		$this->assertSame('active', $org->getStatus());
	}//end testMissingGroupsOrUserAreSkipped()

	/**
	 * Provisioning refuses any status but provisioning, and an unknown environment, before touching anything.
	 *
	 * @return void
	 */
	public function testProvisioningRefusals(): void {
		foreach (['active', 'suspended', 'archived', 'retained', 'deprovisioning', 'bogus'] as $status) {
			$e = $this->refusal(fn () => $this->service()->provision($this->organisation($status), 'alice'));
			$this->assertSame('Organisation must be in provisioning state to provision', $e->getMessage());
			$this->assertSame(409, $e->getCode());
		}

		$org = $this->organisation();
		$org->setEnvironment('staging');
		$e = $this->refusal(fn () => $this->service()->provision($org, 'alice'));
		$this->assertSame("Unknown target environment 'staging'.", $e->getMessage());
		$this->assertSame(422, $e->getCode());
		$this->assertSame([], $this->calls);
		$this->assertSame('provisioning', $org->getStatus());

		$org = $this->organisation();
		$org->setEnvironment('test');
		$this->service()->provision($org, 'alice');
		$this->assertSame('active', $org->getStatus());
	}//end testProvisioningRefusals()

	/**
	 * A failing write is logged and rethrown.
	 *
	 * @return void
	 */
	public function testAFailingWriteIsLoggedAndRethrown(): void {
		$this->writeFails = true;
		$e = $this->refusal(fn () => $this->service()->provision($this->organisation(), 'alice'));
		$this->assertSame('write failed', $e->getMessage());
		$this->assertSame(
			'error [TenantLifecycleService] Provisioning failed {"uuid":"org-uuid","error":"write failed"}',
			end($this->calls)
		);
	}//end testAFailingWriteIsLoggedAndRethrown()

	/**
	 * Transition validation: unknown statuses 422, disallowed transitions 409, with the allowed list.
	 *
	 * @return void
	 */
	public function testTransitionRefusals(): void {
		$service = $this->service();
		$e = $this->refusal(fn () => $service->validateTransition('bogus', 'active'));
		$this->assertSame("Unknown status 'bogus' or 'active'.", $e->getMessage());
		$this->assertSame(422, $e->getCode());
		$e = $this->refusal(fn () => $service->validateTransition('active', 'nope'));
		$this->assertSame(422, $e->getCode());
		$e = $this->refusal(fn () => $service->validateTransition('active', 'archived'));
		$this->assertSame("Invalid state transition from 'active' to 'archived'. Valid transitions: suspended, deprovisioning, retained", $e->getMessage());
		$this->assertSame(409, $e->getCode());
		$e = $this->refusal(fn () => $service->validateTransition('archived', 'active'));
		$this->assertSame("Invalid state transition from 'archived' to 'active'. Valid transitions: ", $e->getMessage());
		$service->validateTransition('retained', 'deprovisioning');
		$service->validateTransition('provisioning', 'active');
		$this->addToAssertionCount(2);
	}//end testTransitionRefusals()
}//end class
