<?php

/**
 * A cast person's function, role, absence and status reach the account the
 * set created; an adopted account only gains the function and role it lacks.
 * Absence days count from the install day.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-cast-person-may-carry-a-function-a-role-an-absence-and-a-status
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use DateTimeImmutable;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetPeople;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetProfiles;

/**
 * Profiles of the cast's accounts.
 */
class ExampleSetProfilesTest extends ExampleSetTestCase {

	/**
	 * The profile of a person: function as headline, roles joined, absence days from the install day.
	 *
	 * @return void
	 */
	public function testAProfileIsReadFromTheCastPerson(): void {
		$profiles = (new \ReflectionClass(objectOrClass: ExampleSetProfiles::class))->newInstanceWithoutConstructor();
		$profile  = $profiles->fromPerson(
			person: [
				'function'   => 'Vergunningverlener',
				'department' => 'Vergunningen',
				'roles'      => ['Vergunningverlener', 'teamleider'],
				'absence'  => ['fromDay' => -2, 'untilDay' => 7, 'status' => 'Verlof', 'message' => 'Terug op 14 oktober'],
				'status'   => ['status' => 'away', 'message' => 'Buiten'],
			],
			today: new DateTimeImmutable('2026-10-07')
		);

		$this->assertSame(expected: 'Vergunningverlener', actual: $profile['headline']);
		$this->assertSame(expected: 'Vergunningverlener, teamleider', actual: $profile['role']);
		$this->assertSame(expected: 'Vergunningen', actual: $profile['organisation']);
		$this->assertSame(expected: ['2026-10-05', '2026-10-14'], actual: [$profile['absence']['firstDay'], $profile['absence']['lastDay']]);
		$this->assertSame(expected: ['away', 'Buiten'], actual: [$profile['status']['status'], $profile['status']['message']]);
		$this->assertNull(actual: $profiles->fromPerson(person: ['function' => 'Intaker'], today: new DateTimeImmutable())['absence']);
	}//end testAProfileIsReadFromTheCastPerson()

	/**
	 * The install writes the profile of an account it created; an adopted account only gains what it lacks.
	 *
	 * @return void
	 */
	public function testACreatedAccountGetsItsProfileAndAnAdoptedOneWhatItLacks(): void {
		$written  = [];
		$filled   = [];
		$profiles = new class($written, $filled) extends ExampleSetProfiles {

			/**
			 * Records instead of writing.
			 *
			 * @param array<string, mixed> $written The profiles written, by uid.
			 */
			public function __construct(private array &$written, private array &$filled) {
			}

			/**
			 * Record the profile an adopted account may gain.
			 * @param string               $uid     The account.
			 * @param array<string, mixed> $profile The profile.
			 * @return array<int, string>
			 */
			public function fillMissing(string $uid, array $profile): array {
				$this->filled[$uid] = $profile;
				return [];
			}

			/**
			 * Record the profile.
			 *
			 * @param string               $uid     The account.
			 * @param array<string, mixed> $profile The profile.
			 *
			 * @return array<int, string>
			 */
			public function apply(string $uid, array $profile): array {
				$this->written[$uid] = $profile;
				return [];
			}
		};

		$this->accounts->createUser(uid: 'adopted.one', displayName: 'Adopted', email: '', password: 'x');
		$people = new ExampleSetPeople(accounts: $this->accounts, record: $this->record, profiles: $profiles);
		$plan   = [
			'set'       => 'demo',
			'timezone'  => 'Europe/Amsterdam',
			'fragments' => [],
			'cast'      => [
				'groups' => [],
				'people' => [
					'sanne'   => ['uid' => 'sanne.bakker', 'displayName' => 'Sanne Bakker', 'function' => 'Vergunningverlener'],
					'adopted' => ['uid' => 'adopted.one', 'displayName' => 'Adopted', 'function' => 'Iets'],
				],
			],
		];
		$record = $this->record->read(set: 'demo');

		$report = ['users' => [], 'groups' => [], 'memberships' => [], 'dropped' => [], 'passwords' => []];
		$people->install(plan: $plan, adopt: ['adopted.one'], password: 'pw', report: $report, record: $record);

		$this->assertSame(expected: ['sanne.bakker'], actual: array_keys($written));
		$this->assertSame(expected: 'Vergunningverlener', actual: $written['sanne.bakker']['headline']);
		$this->assertSame(expected: ['adopted.one'], actual: array_keys($filled));
		$this->assertSame(expected: 'Iets', actual: $filled['adopted.one']['headline']);
	}//end testACreatedAccountGetsItsProfileAndAnAdoptedOneWhatItLacks()

	/**
	 * Filling in writes only the properties the account has empty, and never touches one it holds.
	 *
	 * @return void
	 */
	public function testFillingInWritesOnlyEmptyProperties(): void {
		$values  = ['headline' => '', 'role' => 'Woo-coördinator'];
		$account = $this->createMock(\OCP\Accounts\IAccount::class);
		$account->method('getProperty')->willReturnCallback(function (string $name) use (&$values): \OCP\Accounts\IAccountProperty {
			$property = $this->createMock(\OCP\Accounts\IAccountProperty::class);
			$property->method('getValue')->willReturn($values[$name] ?? '');
			return $property;
		});
		$account->method('setProperty')->willReturnCallback(function (string $name, string $value) use (&$values, $account): \OCP\Accounts\IAccount {
			$values[$name] = $value;
			return $account;
		});
		$accounts = $this->createMock(\OCP\Accounts\IAccountManager::class);
		$accounts->method('getAccount')->willReturn($account);
		$users = $this->createMock(\OCP\IUserManager::class);
		$users->method('get')->willReturn($this->createMock(\OCP\IUser::class));
		$profiles = new ExampleSetProfiles(users: $users, profiles: $accounts, container: $this->createMock(\Psr\Container\ContainerInterface::class));

		$reasons = $profiles->fillMissing(uid: 'pieter', profile: ['headline' => 'Woo-coördinator', 'role' => 'Iets anders', 'organisation' => 'Woo en informatie', 'absence' => null, 'status' => null]);

		$this->assertSame(expected: [], actual: $reasons);
		$this->assertSame(expected: ['headline' => 'Woo-coördinator', 'role' => 'Woo-coördinator', 'organisation' => 'Woo en informatie'], actual: $values);
	}//end testFillingInWritesOnlyEmptyProperties()
}//end class
