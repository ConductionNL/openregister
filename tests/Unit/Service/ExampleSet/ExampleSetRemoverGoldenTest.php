<?php

/**
 * Golden: the exact report of removing an installed example set, in order, when one object is refused its delete
 * and an account was adopted, and of removing it again; the record keeps exactly what stays.
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
 * @spec openspec/specs/example-sets/spec.md#requirement-remove-deletes-exactly-what-the-install-made-req-exs-007
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use OCA\OpenRegister\Kernel\Contracts\ExampleSetInstallInput;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetRemover
 */
class ExampleSetRemoverGoldenTest extends ExampleSetTestCase {
	/**
	 * A set with a cast, a person, a case type lookup, two cases (one pointing at the other), a group with a member.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store->offer(register: 'brp', schema: 'person');
		$this->store->offer(register: 'cases', schema: 'caseType');
		$this->store->offer(register: 'cases', schema: 'case');
		$this->store->seed(register: 'cases', schema: 'caseType', data: ['identifier' => 'woo']);
		$this->app(
			app: 'openregister',
			fragment: $this->castFile(
				objects: [
					['key' => 'brp-sanne', 'register' => 'brp', 'schema' => 'person', 'data' => ['bsn' => '{{cast:sanne.bsn}}']],
				]
			)
		);
		$this->app(
			app: 'cases',
			fragment: [
				'objects' => [
					[
						'key'      => 'case-1',
						'register' => 'cases',
						'schema'   => 'case',
						'lookups'  => ['type' => ['schema' => 'caseType', 'where' => ['identifier' => 'woo']]],
						'data'     => ['type' => '{{lookup:type}}', 'start' => '2026-01-01'],
					],
					['key' => 'note-1', 'register' => 'cases', 'schema' => 'case', 'data' => ['case' => '{{object:case-1.id}}']],
				],
				'groups'  => [['group' => 'cases-handlers', 'members' => ['esra']]],
			]
		);
	}//end setUp()

	/**
	 * Remove what the install made: what is refused stays, named with its reason, and stays in the record; an adopted
	 * account is left; a second remove finds what stayed and nothing else.
	 *
	 * @return void
	 */
	public function testRemoveReportsExactlyWhatWentAndWhatStays(): void {
		$this->accounts->users['sanne.devries'] = ['displayName' => 'Sanne', 'email' => '', 'password' => 'theirs'];
		$report = $this->installer->install(input: new ExampleSetInstallInput(plan: $this->catalogue->gather(set: 'demo'), adopt: ['sanne.devries'], today: $this->today()));
		$this->assertTrue($report['ok']);
		$ids                      = array_column($this->record->read(set: 'demo')['objects'], 'id');
		$this->store->undeletable = [$ids[0]];

		$refused = 'brp person openregister/brp-sanne ('.$ids[0].'): the register refused it';
		$this->assertSame(
			['installed' => true, 'removed' => self::counts(objects: 2, memberships: 2, groups: 2, accounts: 1), 'left' => ['sanne.devries'], 'failed' => [$refused]],
			$this->remover->remove(set: 'demo')
		);
		$record = $this->record->read(set: 'demo');
		$this->assertSame(['openregister/brp-sanne' => ['register' => 'brp', 'schema' => 'person', 'id' => $ids[0]]], $record['objects']);
		$this->assertSame([[], [], []], [$record['users'], $record['groups'], $record['memberships']]);
		$this->assertSame('theirs', $this->accounts->users['sanne.devries']['password']);

		$this->assertSame(
			['installed' => true, 'removed' => self::counts(), 'left' => [], 'failed' => [$refused]],
			$this->remover->remove(set: 'demo')
		);
	}//end testRemoveReportsExactlyWhatWentAndWhatStays()

	/**
	 * Nothing installed: nothing to remove, every count zero.
	 *
	 * @return void
	 */
	public function testRemoveOfASetNotInstalled(): void {
		$this->assertSame(['installed' => false, 'removed' => self::counts(), 'left' => [], 'failed' => []], $this->remover->remove(set: 'demo'));
	}//end testRemoveOfASetNotInstalled()

	/**
	 * The removed counts, every kind in report order, zero unless given.
	 *
	 * @param int $objects     Objects removed.
	 * @param int $memberships Memberships removed.
	 * @param int $groups      Groups removed.
	 * @param int $accounts    Accounts removed.
	 *
	 * @return array<string, int>
	 */
	private static function counts(int $objects=0, int $memberships=0, int $groups=0, int $accounts=0): array {
		return [
			'files'            => 0,
			'objects'          => $objects,
			'changes'          => 0,
			'app config'       => 0,
			'user preferences' => 0,
			'tasks'            => 0,
			'views'            => 0,
			'watchers'         => 0,
			'parties'          => 0,
			'notes'            => 0,
			'readStates'       => 0,
			'flows'            => 0,
			'memberships'      => $memberships,
			'groups'           => $groups,
			'accounts'         => $accounts,
			'register imports' => 0,
			'organisations'    => 0,
		];
	}//end counts()
}//end class
