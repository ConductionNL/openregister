<?php

/**
 * Pins which accounts an install keeps, adopts, creates or refuses, for cast
 * people and for service accounts: an existing account is only adopted when
 * the administrator lets the set use it and the record does not hold it yet,
 * and an account another writer made in between is never taken over for a
 * cast person.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-accounts-and-groups-come-from-the-cast-and-are-never-taken-over-req-exs-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use OCA\OpenRegister\Service\ExampleSet\ExampleSetAccountTerms;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetAccountsInstall;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetPeople;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetNewAccount;

class ExampleSetPeopleAccountsGoldenTest extends ExampleSetTestCase {
	/**
	 * Every allowed and refused outcome of the account step, with an administrator password.
	 *
	 * @return void
	 */
	public function testAccountsAreKeptAdoptedCreatedOrRefused(): void {
		$this->accounts->createAccount(account: new ExampleSetNewAccount(uid: 'kept.one', displayName: 'Kept', email: '', password: 'theirs-kept'));
		$this->accounts->createAccount(account: new ExampleSetNewAccount(uid: 'adopted.one', displayName: 'Adopted', email: '', password: 'theirs-adopted'));
		$this->accounts->createAccount(account: new ExampleSetNewAccount(uid: 'recorded.one', displayName: 'Recorded', email: '', password: 'theirs-recorded'));
		$this->accounts->createAccount(account: new ExampleSetNewAccount(uid: 'svc.kept', displayName: 'Svc kept', email: '', password: 'theirs-svc-kept'));
		$this->accounts->createAccount(account: new ExampleSetNewAccount(uid: 'svc.adopted', displayName: 'Svc adopted', email: '', password: 'theirs-svc-adopted'));
		$this->accounts->unseenUsers = ['raced.one', 'svc.raced', 'svc.raced.recorded'];

		$record          = $this->record->read(set: 'demo');
		$record['users'] = ['recorded.one' => 'created', 'svc.raced.recorded' => 'created'];
		$adopt           = ['adopted.one', 'recorded.one', 'svc.adopted', 'raced.one'];

		$report = $this->people()->install(install: new ExampleSetAccountsInstall(plan: $this->plan(), terms: new ExampleSetAccountTerms(set: (string) $this->plan()['set'], adopt: $adopt, password: 'pw')), record: $record);

		$this->assertSame(
			[
				'kept.one'     => 'kept',
				'adopted.one'  => 'adopted',
				'recorded.one' => 'kept',
				'new.one'      => 'created',
				'svc.kept'     => 'kept',
				'svc.adopted'  => 'adopted',
				'svc.raced'    => 'adopted',
				'svc.new'      => 'created',
			],
			$report['users']
		);
		$this->assertSame(
			[
				'account raced.one: the account already exists',
				'service account svc.raced.recorded: the account already exists',
			],
			$report['dropped']
		);
		$this->assertSame([], $report['passwords']);
		$this->assertSame(
			[
				'recorded.one'       => 'created',
				'svc.raced.recorded' => 'created',
				'adopted.one'        => 'adopted',
				'new.one'            => 'created',
				'svc.adopted'        => 'adopted',
				'svc.raced'          => 'adopted',
				'svc.new'            => 'created',
			],
			$record['users']
		);
		$this->assertSame($record['users'], $this->record->read(set: 'demo')['users']);

		// An existing account's password is never touched; a new one gets the administrator's.
		$this->assertSame('theirs-kept', $this->accounts->users['kept.one']['password']);
		$this->assertSame('theirs-adopted', $this->accounts->users['adopted.one']['password']);
		$this->assertSame('theirs', $this->accounts->users['raced.one']['password']);
		$this->assertSame('pw', $this->accounts->users['new.one']['password']);
		// Every service account create asks for two passwords, also the ones another writer beat.
		$this->assertSame('generated-5generated-6', $this->accounts->users['svc.new']['password']);
	}//end testAccountsAreKeptAdoptedCreatedOrRefused()

	/**
	 * Without an administrator password each new cast account gets its own, which the report hands back; a service
	 * account's never is.
	 *
	 * @return void
	 */
	public function testGeneratedPasswordsAreReportedForCastAccountsOnly(): void {
		$record = $this->record->read(set: 'demo');
		$plan   = $this->plan();
		$plan['cast']['people'] = ['new' => ['uid' => 'new.one', 'displayName' => 'New']];
		$plan['fragments'][0]['serviceAccounts'] = [['key' => 'svc', 'uid' => 'svc.new', 'displayName' => 'Svc new', 'groups' => []]];

		$report = $this->people()->install(install: new ExampleSetAccountsInstall(plan: $plan, terms: new ExampleSetAccountTerms(set: (string) $plan['set'], adopt: [], password: '')), record: $record);

		$this->assertSame(['new.one' => 'generated-1'], $report['passwords']);
		$this->assertSame('generated-1', $this->accounts->users['new.one']['password']);
		$this->assertSame('generated-2generated-3', $this->accounts->users['svc.new']['password']);
		$this->assertSame(['new.one' => 'created', 'svc.new' => 'created'], $report['users']);
	}//end testGeneratedPasswordsAreReportedForCastAccountsOnly()

	/**
	 * The people step under test, without profiles.
	 *
	 * @return ExampleSetPeople
	 */
	private function people(): ExampleSetPeople {
		return new ExampleSetPeople(accounts: $this->accounts, record: $this->record);
	}//end people()

	/**
	 * A plan with one of each cast and service account case.
	 *
	 * @return array<string, mixed>
	 */
	private function plan(): array {
		return [
			'set'       => 'demo',
			'timezone'  => 'Europe/Amsterdam',
			'fragments' => [
				[
					'app'             => 'demo',
					'file'            => 'demo.json',
					'groups'          => [],
					'serviceAccounts' => [
						['key' => 'k1', 'uid' => 'svc.kept', 'displayName' => 'Svc kept', 'groups' => []],
						['key' => 'k2', 'uid' => 'svc.adopted', 'displayName' => 'Svc adopted', 'groups' => []],
						['key' => 'k3', 'uid' => 'svc.raced', 'displayName' => 'Svc raced', 'groups' => []],
						['key' => 'k4', 'uid' => 'svc.raced.recorded', 'displayName' => 'Svc raced recorded', 'groups' => []],
						['key' => 'k5', 'uid' => 'svc.new', 'displayName' => 'Svc new', 'groups' => []],
					],
				],
			],
			'cast'      => [
				'groups' => [],
				'people' => [
					'kept'     => ['uid' => 'kept.one', 'displayName' => 'Kept'],
					'adopted'  => ['uid' => 'adopted.one', 'displayName' => 'Adopted'],
					'recorded' => ['uid' => 'recorded.one', 'displayName' => 'Recorded'],
					'raced'    => ['uid' => 'raced.one', 'displayName' => 'Raced'],
					'new'      => ['uid' => 'new.one', 'displayName' => 'New', 'email' => 'new@example.org'],
					'nouid'    => ['displayName' => 'No account'],
				],
			],
		];
	}//end plan()
}//end class
