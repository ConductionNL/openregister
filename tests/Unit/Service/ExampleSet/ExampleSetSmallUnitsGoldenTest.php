<?php

/**
 * Golden: the export-run, task-claim, profile, account and placeholder-fill units of an example set, pinned on
 * every branch they answer, before they are split into smaller steps.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-give-an-account-an-export-it-made
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use DateTime;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetAccountDirectory;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetAccounts;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetExportRuns;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetProfiles;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetTaskClaim;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetValues;
use OCP\Accounts\IAccount;
use OCP\Accounts\IAccountManager;
use OCP\Accounts\IAccountProperty;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetExportRuns
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetTaskClaim
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetProfiles
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetAccounts
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetValues
 */
class ExampleSetSmallUnitsGoldenTest extends TestCase {

	/**
	 * Each refusal an export row can get, in the order the checks run, and the empty answer.
	 *
	 * @return void
	 */
	public function testExportRunRefusals(): void {
		$runs  = new ExampleSetExportRuns($this->createMock(ContainerInterface::class));
		$users = ['alice'];
		$ok    = ['user' => 'alice', 'fileName' => 'a.csv', 'content' => 'x'];

		$cases = [
			[['nope' => 1, 'also' => 2] + $ok, 'field "nope", "also" is not an export field a set may write'],
			[['user' => 'bob'] + $ok, 'user "bob" is not an account the set creates or adopts'],
			[['fileName' => 'a.csv', 'content' => 'x'], 'user "" is not an account the set creates or adopts'],
			[['fileName' => 'sub/a.csv'] + $ok, 'fileName is a plain file name in the Exports folder'],
			[['fileName' => '.hidden'] + $ok, 'fileName is a plain file name in the Exports folder'],
			[['user' => 'alice', 'content' => 'x'], 'fileName is a plain file name in the Exports folder'],
			[['user' => 'alice', 'fileName' => 'a.csv'], 'an export needs its content (text, or contentBase64 for a binary file), at most 65536 bytes'],
			[['content' => ''] + $ok, 'an export needs its content (text, or contentBase64 for a binary file), at most 65536 bytes'],
			[['content' => str_repeat('x', 65537)] + $ok, 'an export needs its content (text, or contentBase64 for a binary file), at most 65536 bytes'],
			[['user' => 'alice', 'fileName' => 'a.csv', 'contentBase64' => '!!!'], 'an export needs its content (text, or contentBase64 for a binary file), at most 65536 bytes'],
			[['producedAt' => 'not a date at all'] + $ok, 'producedAt is a date and time'],
			[['producedAt' => ''] + $ok, 'producedAt is a date and time'],
			[['producedAt' => 7] + $ok, 'producedAt is a date and time'],
			[['producedAt' => null] + $ok, ''],
			[['producedAt' => '2024-01-02T03:04:05+00:00'] + $ok, ''],
			[['user' => 'alice', 'fileName' => 'a.bin', 'contentBase64' => base64_encode('bin')], ''],
			[$ok, ''],
		];
		foreach ($cases as $index => [$data, $expected]) {
			$this->assertSame($expected, $runs->refusal(data: $data, users: $users), 'case '.$index);
		}
	}//end testExportRunRefusals()

	/**
	 * Each refusal a claim on a task row can get, and the empty answers.
	 *
	 * @return void
	 */
	public function testTaskClaimRefusals(): void {
		$claim  = new ExampleSetTaskClaim($this->createMock(ContainerInterface::class));
		$past   = new DateTime('2001-02-03T04:05:06+00:00');
		$future = (new DateTime())->modify('+10 years');

		$this->assertSame('', $claim->refusal(data: [], moment: null));
		$this->assertSame('', $claim->refusal(data: ['claimedBy' => ''], moment: $past));
		$this->assertSame('claimedAt needs claimedBy', $claim->refusal(data: ['claimedAt' => 'x'], moment: null));
		$this->assertSame('', $claim->refusal(data: ['claimedBy' => '', 'claimedAt' => null], moment: null));
		$this->assertSame('claimedBy is the assignee: "bob" is not', $claim->refusal(data: ['claimedBy' => 'bob', 'assignee' => 'alice'], moment: $past));
		$this->assertSame('claimedBy is the assignee: "bob" is not', $claim->refusal(data: ['claimedBy' => 'bob'], moment: null));
		$this->assertSame('claimedAt lies in the future', $claim->refusal(data: ['claimedBy' => 'alice', 'assignee' => 'alice'], moment: $future));
		$this->assertSame('', $claim->refusal(data: ['claimedBy' => 'alice', 'assignee' => 'alice'], moment: $past));
		$this->assertSame('', $claim->refusal(data: ['claimedBy' => 'alice', 'assignee' => 'alice'], moment: null));
	}//end testTaskClaimRefusals()

	/**
	 * Filling in an adopted account: a missing account, nothing to fill, a partial fill and a failing profile store.
	 *
	 * @return void
	 */
	public function testFillMissingBranches(): void {
		$profile = ['headline' => 'Kop', 'role' => '', 'organisation' => 'Org', 'absence' => null, 'status' => null];

		$none = new ExampleSetProfiles($this->users(user: null), $this->createMock(IAccountManager::class), $this->createMock(ContainerInterface::class));
		$this->assertSame(['account ghost is not there'], $none->fillMissing(uid: 'ghost', profile: $profile));

		$log     = [];
		$account = $this->account(values: ['headline' => 'held', 'role' => '', 'organisation' => 'held too'], log: $log);
		$manager = $this->manager(account: $account, log: $log);
		$full    = new ExampleSetProfiles($this->users(user: $this->createMock(IUser::class)), $manager, $this->createMock(ContainerInterface::class));
		$this->assertSame([], $full->fillMissing(uid: 'pieter', profile: $profile));
		$this->assertSame(['getAccount'], $log);

		$log     = [];
		$account = $this->account(values: ['headline' => '  ', 'role' => '', 'organisation' => 'held'], log: $log);
		$manager = $this->manager(account: $account, log: $log);
		$partial = new ExampleSetProfiles($this->users(user: $this->createMock(IUser::class)), $manager, $this->createMock(ContainerInterface::class));
		$this->assertSame([], $partial->fillMissing(uid: 'pieter', profile: $profile));
		$this->assertSame(['getAccount', 'set headline=Kop', 'updateAccount'], $log);

		$failing = $this->createMock(IAccountManager::class);
		$failing->method('getAccount')->willThrowException(new RuntimeException('store down'));
		$broken = new ExampleSetProfiles($this->users(user: $this->createMock(IUser::class)), $failing, $this->createMock(ContainerInterface::class));
		$this->assertSame(['profile of pieter: store down'], $broken->fillMissing(uid: 'pieter', profile: $profile));
	}//end testFillMissingBranches()

	/**
	 * Writing a status: none, a status alone, a message without and with an icon, and a failing status service.
	 *
	 * @return void
	 */
	public function testStatusBranches(): void {
		$bare = ['headline' => '', 'role' => '', 'organisation' => '', 'absence' => null];

		$log = [];
		$this->assertSame([], $this->withStatus(log: $log, fail: false)->apply(uid: 'u', profile: $bare + ['status' => null]));
		$this->assertSame([], $log);

		$log = [];
		$this->assertSame([], $this->withStatus(log: $log, fail: false)->apply(uid: 'u', profile: $bare + ['status' => ['status' => 'away', 'message' => '', 'icon' => '🌴']]));
		$this->assertSame([['setStatus', 'u', 'away', null, true]], $log);

		$log = [];
		$this->assertSame([], $this->withStatus(log: $log, fail: false)->apply(uid: 'u', profile: $bare + ['status' => ['status' => 'dnd', 'message' => 'Focus', 'icon' => '']]));
		$this->assertSame([['setStatus', 'u', 'dnd', null, true], ['setCustomMessage', 'u', null, 'Focus', null]], $log);

		$log = [];
		$this->assertSame([], $this->withStatus(log: $log, fail: false)->apply(uid: 'u', profile: $bare + ['status' => ['status' => 'online', 'message' => 'Hi', 'icon' => '👋']]));
		$this->assertSame([['setStatus', 'u', 'online', null, true], ['setCustomMessage', 'u', '👋', 'Hi', null]], $log);

		$log = [];
		$this->assertSame(['status of u: no status app'], $this->withStatus(log: $log, fail: true)->apply(uid: 'u', profile: $bare + ['status' => ['status' => 'online', 'message' => 'Hi', 'icon' => '']]));
	}//end testStatusBranches()

	/**
	 * Creating an account: taken, refused by a throw, refused by false, created without and with an e-mail address.
	 *
	 * @return void
	 */
	public function testCreateUserBranches(): void {
		$warnings = [];

		$this->assertSame(ExampleSetAccounts::ALREADY_EXISTS, $this->accounts(made: new RuntimeException('Duplicate entry for key'), warnings: $warnings)->createUser(uid: 'a', displayName: 'A', email: '', password: 'p'));
		$this->assertSame([], $warnings);

		$this->assertSame('backend down', $this->accounts(made: new RuntimeException('backend down'), warnings: $warnings)->createUser(uid: 'a', displayName: 'A', email: '', password: 'p'));
		$this->assertSame([['OpenRegister: an example set account was not created', ['uid' => 'a', 'reason' => 'backend down']]], $warnings);

		$this->assertSame('Nextcloud refused the account', $this->accounts(made: false, warnings: $warnings)->createUser(uid: 'a', displayName: 'A', email: '', password: 'p'));

		$calls = [];
		$user  = $this->createMock(IUser::class);
		$user->method('setDisplayName')->willReturnCallback(function (string $name) use (&$calls): bool {
			$calls[] = 'name '.$name;
			return true;
		});
		$user->method('setSystemEMailAddress')->willReturnCallback(function (string $email) use (&$calls): void {
			$calls[] = 'email '.$email;
		});
		$this->assertSame('', $this->accounts(made: $user, warnings: $warnings)->createUser(uid: 'a', displayName: 'Anna', email: '', password: 'p'));
		$this->assertSame(['name Anna'], $calls);
		$this->assertSame('', $this->accounts(made: $user, warnings: $warnings)->createUser(uid: 'a', displayName: 'Anna', email: 'a@example.org', password: 'p'));
		$this->assertSame(['name Anna', 'name Anna', 'email a@example.org'], $calls);
	}//end testCreateUserBranches()

	/**
	 * Filling values: arrays at any depth, non-strings, strings without a placeholder, a whole placeholder and an
	 * embedded one that cannot be resolved.
	 *
	 * @return void
	 */
	public function testFillBranches(): void {
		$values = new ExampleSetValues();

		$gaps = [];
		$this->assertSame(7, $values->fill(value: 7, context: [], gaps: $gaps));
		$this->assertSame(null, $values->fill(value: null, context: [], gaps: $gaps));
		$this->assertSame('plain {text}', $values->fill(value: 'plain {text}', context: [], gaps: $gaps));
		$this->assertSame(['a', 1, ['b' => true]], $values->fill(value: ['a', 1, ['b' => true]], context: [], gaps: $gaps));
		$this->assertSame([], $gaps);

		$gaps = [];
		$this->assertSame('{{nope:thing}}', $values->fill(value: '{{nope:thing}}', context: [], gaps: $gaps));
		$this->assertSame(['{{nope:thing}}'], $gaps);

		$gaps = [];
		$this->assertSame(['x {{nope:thing}} y'], $values->fill(value: ['x {{nope:thing}} y'], context: [], gaps: $gaps));
		$this->assertSame(['{{nope:thing}}'], $gaps);
	}//end testFillBranches()

	/**
	 * A user manager that hands out one user, or none.
	 *
	 * @param IUser|null $user The user, or null.
	 *
	 * @return IUserManager
	 */
	private function users(?IUser $user): IUserManager {
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($user);
		return $users;
	}//end users()

	/**
	 * An account whose properties hold the given values, logging every write.
	 *
	 * @param array<string, string> $values The property values by name.
	 * @param array<int, string>    $log    The log.
	 *
	 * @return IAccount
	 */
	private function account(array $values, array &$log): IAccount {
		$account = $this->createMock(IAccount::class);
		$account->method('getProperty')->willReturnCallback(function (string $name) use ($values): IAccountProperty {
			$property = $this->createMock(IAccountProperty::class);
			$property->method('getValue')->willReturn($values[$name] ?? '');
			return $property;
		});
		$account->method('setProperty')->willReturnCallback(function (string $name, string $value) use (&$log, $account): IAccount {
			$log[] = 'set '.$name.'='.$value;
			return $account;
		});
		return $account;
	}//end account()

	/**
	 * An account manager that hands out the account and logs each call.
	 *
	 * @param IAccount           $account The account.
	 * @param array<int, string> $log     The log.
	 *
	 * @return IAccountManager
	 */
	private function manager(IAccount $account, array &$log): IAccountManager {
		$manager = $this->createMock(IAccountManager::class);
		$manager->method('getAccount')->willReturnCallback(function () use (&$log, $account): IAccount {
			$log[] = 'getAccount';
			return $account;
		});
		$manager->method('updateAccount')->willReturnCallback(function () use (&$log): void {
			$log[] = 'updateAccount';
		});
		return $manager;
	}//end manager()

	/**
	 * Profiles whose container hands out a recording status service, or fails to.
	 *
	 * @param array<int, array<int, mixed>> $log  The status calls.
	 * @param bool                          $fail Whether the status service is missing.
	 *
	 * @return ExampleSetProfiles
	 */
	private function withStatus(array &$log, bool $fail): ExampleSetProfiles {
		$service = new class($log) {
			/**
			 * @param array<int, array<int, mixed>> $log The calls.
			 */
			public function __construct(private array &$log) {
			}

			public function setStatus(string $uid, string $status, ?int $at, bool $explicit): void {
				$this->log[] = ['setStatus', $uid, $status, $at, $explicit];
			}

			public function setCustomMessage(string $uid, ?string $icon, string $message, ?int $clearAt): void {
				$this->log[] = ['setCustomMessage', $uid, $icon, $message, $clearAt];
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		if ($fail === true) {
			$container->method('get')->willThrowException(new RuntimeException('no status app'));
		} else {
			$container->method('get')->willReturn($service);
		}

		return new ExampleSetProfiles($this->users(user: $this->createMock(IUser::class)), $this->createMock(IAccountManager::class), $container);
	}//end withStatus()

	/**
	 * Accounts whose user manager makes the given user, returns false or throws, logging warnings.
	 *
	 * @param IUser|false|\Throwable $made     What createUser gives.
	 * @param array<int, mixed>      $warnings The warnings logged.
	 *
	 * @return ExampleSetAccounts
	 */
	private function accounts(IUser|false|\Throwable $made, array &$warnings): ExampleSetAccounts {
		$users = $this->createMock(IUserManager::class);
		if ($made instanceof \Throwable) {
			$users->method('createUser')->willThrowException($made);
		} else {
			$users->method('createUser')->willReturn($made);
		}

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $message, array $context) use (&$warnings): void {
			$warnings[] = [$message, $context];
		});

		return new ExampleSetAccounts(new ExampleSetAccountDirectory($users, $this->createMock(IGroupManager::class)), $this->createMock(ISecureRandom::class), $logger);
	}//end accounts()
}//end class
