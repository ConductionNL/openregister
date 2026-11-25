<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use Exception;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Joining and leaving organisations: the membership, the cached list, the
 * last-organisation guard and the active organisation that was left.
 *
 * @spec openspec/changes/unit-test-coverage/specs/unit-test-coverage/spec.md
 */
class OrganisationServiceMembershipTest extends TestCase {

	private OrganisationMapper&MockObject $mapper;

	private ISession&MockObject $session;

	private IConfig&MockObject $config;

	private IUserManager&MockObject $users;

	private OrganisationService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->mapper  = $this->createMock(OrganisationMapper::class);
		$this->session = $this->createMock(ISession::class);
		$this->config  = $this->createMock(IConfig::class);
		$this->users   = $this->createMock(IUserManager::class);

		$alice = $this->createMock(IUser::class);
		$alice->method('getUID')->willReturn('alice');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($alice);

		$this->service = new OrganisationService(
			organisationMapper: $this->mapper,
			userSession: $userSession,
			session: $this->session,
			config: $this->config,
			appConfig: $this->createMock(IAppConfig::class),
			groupManager: $this->createMock(IGroupManager::class),
			userManager: $this->users,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	public function testJoiningAddsTheMembershipAndDropsTheCachedList(): void {
		$this->mapper->expects($this->once())->method('addUserToOrganisation')->with('org-x', 'alice');
		$this->session->expects($this->once())->method('remove')->with('openregister_user_organisations_alice');

		$this->assertTrue($this->service->joinOrganisation('org-x'));
	}//end testJoiningAddsTheMembershipAndDropsTheCachedList()

	public function testJoiningForAnotherUserNeedsThatUserToExist(): void {
		$this->users->method('get')->with('bob')->willReturn(null);
		$this->mapper->expects($this->never())->method('addUserToOrganisation');

		$this->expectExceptionMessage('Target user not found');
		$this->service->joinOrganisation('org-x', 'bob');
	}//end testJoiningForAnotherUserNeedsThatUserToExist()

	public function testJoiningAMissingOrganisationKeepsTheCachedList(): void {
		$this->mapper->method('addUserToOrganisation')->willThrowException(new DoesNotExistException('gone'));
		$this->session->expects($this->never())->method('remove');

		$this->expectExceptionMessage('Organisation not found');
		$this->service->joinOrganisation('org-x');
	}//end testJoiningAMissingOrganisationKeepsTheCachedList()

	public function testTheLastOrganisationCannotBeLeft(): void {
		$this->mapper->method('findByUserId')->with('alice')->willReturn([$this->organisation(uuid: 'org-x')]);
		$this->mapper->expects($this->never())->method('removeUserFromOrganisation');

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Cannot leave last organisation');
		$this->service->leaveOrganisation('org-x');
	}//end testTheLastOrganisationCannotBeLeft()

	public function testLeavingTheActiveOrganisationClearsIt(): void {
		$this->memberOfTwoWithActive(active: 'org-x');
		$this->mapper->expects($this->once())->method('removeUserFromOrganisation')->with('org-x', 'alice');
		$this->config->expects($this->once())->method('deleteUserValue')->with('alice', 'openregister', 'active_organisation');

		$this->assertTrue($this->service->leaveOrganisation('org-x'));
	}//end testLeavingTheActiveOrganisationClearsIt()

	public function testLeavingAnotherOrganisationKeepsTheActiveOne(): void {
		$this->memberOfTwoWithActive(active: 'org-y');
		$this->mapper->expects($this->once())->method('removeUserFromOrganisation')->with('org-x', 'alice');
		$this->config->expects($this->never())->method('deleteUserValue');

		$this->assertTrue($this->service->leaveOrganisation('org-x'));
	}//end testLeavingAnotherOrganisationKeepsTheActiveOne()

	public function testRemovingAnotherUserSkipsTheLastOrganisationGuard(): void {
		$this->memberOfTwoWithActive(active: 'org-y');
		$this->mapper->expects($this->never())->method('findByUserId');
		$this->mapper->expects($this->once())->method('removeUserFromOrganisation')->with('org-x', 'bob');
		$this->session->expects($this->once())->method('remove')->with('openregister_user_organisations_bob');

		$this->assertTrue($this->service->leaveOrganisation('org-x', 'bob'));
	}//end testRemovingAnotherUserSkipsTheLastOrganisationGuard()

	private function memberOfTwoWithActive(string $active): void {
		$orgs = ['org-x' => $this->organisation(uuid: 'org-x'), 'org-y' => $this->organisation(uuid: 'org-y')];
		$this->mapper->method('findByUserId')->with('alice')->willReturn(array_values($orgs));
		$this->mapper->method('findByUuid')->willReturnCallback(static fn (string $uuid): Organisation => $orgs[$uuid]);
		$this->config->method('getUserValue')->willReturnCallback(
			static fn (string $user, string $app, string $key, mixed $default = ''): mixed => ($key === 'active_organisation' ? $active : $default)
		);
	}//end memberOfTwoWithActive()

	private function organisation(string $uuid): Organisation {
		$organisation = new Organisation();
		$organisation->setUuid($uuid);
		$organisation->setName(strtoupper($uuid));
		$organisation->setUsers(['alice']);
		$organisation->setActive(true);

		return $organisation;
	}//end organisation()
}//end class
