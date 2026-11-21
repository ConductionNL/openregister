<?php

/**
 * Unit tests for ExampleSetAccounts — the accounts, groups and sub-admins an example set makes.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/example-sets/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\ExampleSet\ExampleSetAccountDirectory;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetAccounts;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetNewAccount;
use OCP\Group\ISubAdmin;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class ExampleSetAccountsTest extends TestCase {

	private IUserManager&MockObject $users;
	private IGroupManager&MockObject $groups;
	private ISubAdmin&MockObject $subAdmins;
	private ISecureRandom&MockObject $random;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		$this->users     = $this->createMock(IUserManager::class);
		$this->groups    = $this->createMock(IGroupManager::class);
		$this->subAdmins = $this->createMock(ISubAdmin::class);
		$this->random    = $this->createMock(ISecureRandom::class);
		$this->logger    = $this->createMock(LoggerInterface::class);
	}

	private function accounts(bool $withSubAdmins = true): ExampleSetAccounts {
		return new ExampleSetAccounts(
			new ExampleSetAccountDirectory($this->users, $this->groups, $withSubAdmins ? $this->subAdmins : null),
			$this->random,
			$this->logger
		);
	}

	private function newAccount(string $email = 'ann@example.org'): ExampleSetNewAccount {
		return new ExampleSetNewAccount('ann', 'Ann Smit', $email, 'secret');
	}

	public function testANewPasswordIsTwentyFourReadableCharactersWithAFixedSuffix(): void {
		$this->random->expects($this->once())->method('generate')
			->with(24, ISecureRandom::CHAR_HUMAN_READABLE)
			->willReturn('abc');

		$this->assertSame('abc-Ex7!', $this->accounts()->newPassword());
	}

	public function testACreatedAccountGetsItsNameAndEmail(): void {
		$user = $this->createMock(IUser::class);
		$user->expects($this->once())->method('setDisplayName')->with('Ann Smit');
		$user->expects($this->once())->method('setSystemEMailAddress')->with('ann@example.org');
		$this->users->expects($this->once())->method('createUser')->with('ann', 'secret')->willReturn($user);

		$this->assertSame('', $this->accounts()->createAccount($this->newAccount()));
	}

	public function testAnEmptyEmailIsNotSet(): void {
		$user = $this->createMock(IUser::class);
		$user->expects($this->never())->method('setSystemEMailAddress');
		$this->users->method('createUser')->willReturn($user);

		$this->assertSame('', $this->accounts()->createAccount($this->newAccount('')));
	}

	public function testARefusedAccountIsReported(): void {
		$this->users->method('createUser')->willReturn(false);

		$this->assertSame('Nextcloud refused the account', $this->accounts()->createAccount($this->newAccount()));
	}

	public function testADuplicateAccountIsRecognisedThroughTheCauseChain(): void {
		$this->users->method('createUser')->willThrowException(
			new RuntimeException('could not create', 0, new RuntimeException('SQLSTATE[23000]: Duplicate entry \'ann\''))
		);
		$this->logger->expects($this->never())->method('warning');

		$this->assertSame(ExampleSetAccounts::ALREADY_EXISTS, $this->accounts()->createAccount($this->newAccount()));
	}

	public function testAUniqueConstraintDbExceptionIsADuplicate(): void {
		$dbError = $this->getMockBuilder(\OCP\DB\Exception::class)->disableOriginalConstructor()->onlyMethods(['getReason'])->getMock();
		$dbError->method('getReason')->willReturn(\OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION);
		$this->users->method('createUser')->willThrowException($dbError);

		$this->assertSame(ExampleSetAccounts::ALREADY_EXISTS, $this->accounts()->createAccount($this->newAccount()));
	}

	public function testAnyOtherFailureIsLoggedAndReturnedAsItsMessage(): void {
		$this->users->method('createUser')->willThrowException(new RuntimeException('password too weak'));
		$this->logger->expects($this->once())->method('warning')
			->with('OpenRegister: an example set account was not created', ['uid' => 'ann', 'reason' => 'password too weak']);

		$this->assertSame('password too weak', $this->accounts()->createAccount($this->newAccount()));
	}

	public function testDeletingAnAbsentUserSucceedsAndAPresentOneReportsTheDelete(): void {
		$user = $this->createMock(IUser::class);
		$user->method('delete')->willReturn(false);
		$this->users->method('get')->willReturnMap([['gone', null], ['ann', $user]]);

		$this->assertTrue($this->accounts()->deleteUser('gone'));
		$this->assertFalse($this->accounts()->deleteUser('ann'));
	}

	public function testCreatingAGroupSetsItsNameOnlyWhenGiven(): void {
		$group = $this->createMock(IGroup::class);
		$group->expects($this->once())->method('setDisplayName')->with('Team');
		$this->groups->method('createGroup')->willReturnMap([['team', $group], ['bare', $group], ['bad', null]]);

		$this->assertTrue($this->accounts()->createGroup('team', 'Team'));
		$this->assertTrue($this->accounts()->createGroup('bare', ''));
		$this->assertFalse($this->accounts()->createGroup('bad', 'Bad'));
	}

	public function testGroupNameOfAMissingGroupIsEmpty(): void {
		$group = $this->createMock(IGroup::class);
		$group->method('getDisplayName')->willReturn('Team');
		$this->groups->method('get')->willReturnMap([['team', $group], ['gone', null]]);

		$this->assertSame('Team', $this->accounts()->groupName('team'));
		$this->assertSame('', $this->accounts()->groupName('gone'));
		$this->assertFalse($this->accounts()->setGroupName('gone', 'x'));
	}

	public function testMembershipNeedsBothGroupAndUser(): void {
		$group = $this->createMock(IGroup::class);
		$user  = $this->createMock(IUser::class);
		$group->expects($this->once())->method('addUser')->with($this->identicalTo($user));
		$this->groups->method('get')->willReturnMap([['team', $group], ['gone', null]]);
		$this->users->method('get')->willReturnMap([['ann', $user], ['nobody', null]]);

		$this->assertTrue($this->accounts()->addToGroup('team', 'ann'));
		$this->assertFalse($this->accounts()->addToGroup('team', 'nobody'));
		$this->assertFalse($this->accounts()->addToGroup('gone', 'ann'));
	}

	public function testRemovingAMembershipThatCannotExistSucceeds(): void {
		$group = $this->createMock(IGroup::class);
		$group->expects($this->never())->method('removeUser');
		$this->groups->method('get')->willReturn($group);
		$this->users->method('get')->willReturn(null);

		$this->assertTrue($this->accounts()->removeFromGroup('team', 'nobody'));
	}

	public function testExistenceChecksAskTheDirectory(): void {
		$this->users->method('userExists')->with('ann')->willReturn(true);
		$this->groups->method('groupExists')->with('team')->willReturn(false);
		$this->groups->method('isInGroup')->with('ann', 'team')->willReturn(true);

		$this->assertTrue($this->accounts()->userExists('ann'));
		$this->assertFalse($this->accounts()->groupExists('team'));
		$this->assertTrue($this->accounts()->inGroup('team', 'ann'));
	}

	public function testSubAdminRightsNeedTheSubAdminService(): void {
		$this->groups->method('get')->willReturn($this->createMock(IGroup::class));
		$this->users->method('get')->willReturn($this->createMock(IUser::class));

		$accounts = $this->accounts(false);
		$this->assertFalse($accounts->isSubAdmin('team', 'ann'));
		$this->assertFalse($accounts->addSubAdmin('team', 'ann'));
		$this->assertTrue($accounts->removeSubAdmin('team', 'ann'));
	}

	public function testAddingASubAdminCallsTheServiceAndAFailureIsLogged(): void {
		$group = $this->createMock(IGroup::class);
		$user  = $this->createMock(IUser::class);
		$this->groups->method('get')->willReturn($group);
		$this->users->method('get')->willReturn($user);
		$this->subAdmins->expects($this->exactly(2))->method('createSubAdmin')
			->with($this->identicalTo($user), $this->identicalTo($group))
			->willReturnOnConsecutiveCalls(null, $this->throwException(new RuntimeException('nope')));
		$this->subAdmins->method('isSubAdminOfGroup')->willReturn(true);
		$this->logger->expects($this->once())->method('warning')
			->with('[ExampleSetAccounts] Could not make ann sub-admin of team: nope');

		$accounts = $this->accounts();
		$this->assertTrue($accounts->addSubAdmin('team', 'ann'));
		$this->assertFalse($accounts->addSubAdmin('team', 'ann'));
		$this->assertTrue($accounts->isSubAdmin('team', 'ann'));
	}

	public function testRemovingASubAdminReportsAFailedDelete(): void {
		$this->groups->method('get')->willReturn($this->createMock(IGroup::class));
		$this->users->method('get')->willReturn($this->createMock(IUser::class));
		$this->subAdmins->method('deleteSubAdmin')->willReturnOnConsecutiveCalls(null, $this->throwException(new RuntimeException('x')));

		$accounts = $this->accounts();
		$this->assertTrue($accounts->removeSubAdmin('team', 'ann'));
		$this->assertFalse($accounts->removeSubAdmin('team', 'ann'));
	}
}
