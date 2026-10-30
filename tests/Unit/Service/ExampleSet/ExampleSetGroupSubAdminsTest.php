<?php

/**
 * Tests for a group's sub-admins from the cast: made on install, proved,
 * kept on a second install and taken away on remove.
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
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetPeople
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetRemover
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetRecord
 */
class ExampleSetGroupSubAdminsTest extends ExampleSetTestCase {

	/**
	 * A fragment group naming Sanne as its sub-admin, Esra as its member.
	 *
	 * @param array<int, string> $subAdmins The cast keys named as sub-admins.
	 *
	 * @return void
	 */
	private function handlers(array $subAdmins): void {
		$this->app(app: 'openregister', fragment: $this->castFile());
		$this->app(
			app: 'cases',
			fragment: ['groups' => [['group' => 'cases-handlers', 'members' => ['esra'], 'subAdmins' => $subAdmins]]]
		);
	}//end handlers()

	/**
	 * Install makes the sub-admin without making them a member; a second install keeps it; remove takes it away.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-set-group-subadmins/specs/example-sets/spec.md#requirement-a-group-may-name-its-sub-admins-from-the-cast
	 */
	public function testASubAdminIsMadeKeptAndTakenAway(): void {
		$this->handlers(subAdmins: ['sanne']);

		$report = $this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());
		$this->assertTrue(condition: $report['ok'], message: (string) json_encode($report));
		$this->assertSame(expected: ['cases-handlers / sanne.devries' => 'created'], actual: $report['subAdmins']);
		$this->assertSame(expected: ['sanne.devries'], actual: $this->accounts->subAdmins['cases-handlers']);
		$this->assertSame(expected: ['esra.demir'], actual: $this->accounts->groups['cases-handlers'], message: 'a sub-admin is not made a member');

		$again = $this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());
		$this->assertSame(expected: ['cases-handlers / sanne.devries' => 'kept'], actual: $again['subAdmins']);
		$this->assertSame(expected: ['sanne.devries'], actual: $this->accounts->subAdmins['cases-handlers']);

		$removed = $this->remover->remove(set: 'demo');
		$this->assertSame(expected: [], actual: $removed['failed']);
		$this->assertSame(expected: [], actual: $this->accounts->subAdmins['cases-handlers']);
	}//end testASubAdminIsMadeKeptAndTakenAway()

	/**
	 * A sub-admin that is no cast person with an account refuses the set before anything is written.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-set-group-subadmins/specs/example-sets/spec.md#requirement-a-group-may-name-its-sub-admins-from-the-cast
	 */
	public function testAnUnknownSubAdminRefusesTheSet(): void {
		$this->handlers(subAdmins: ['nobody']);

		$report = $this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());

		$this->assertContains(needle: 'group "cases-handlers": sub-admin "nobody" is not a cast person with an account', haystack: $report['refusals']);
		$this->assertSame(expected: 0, actual: $this->accounts->userCreates);
		$this->assertSame(expected: [], actual: $this->accounts->subAdmins);
	}//end testAnUnknownSubAdminRefusesTheSet()

	/**
	 * Sub-admins (and members) on a group that exists and the set did not create refuse the set.
	 *
	 * @return void
	 */
	public function testASubAdminOnAGroupTheSetDidNotCreateRefusesTheSet(): void {
		$this->handlers(subAdmins: ['sanne']);
		$this->accounts->groups['cases-handlers'] = ['someone'];

		$report = $this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());

		$this->assertContains(
			needle: 'group "cases-handlers": it exists and this set did not create it, so the set may not add members or sub-admins to it',
			haystack: $report['refusals']
		);
		$this->assertSame(expected: [], actual: $this->accounts->subAdmins);
		$this->assertSame(expected: ['someone'], actual: $this->accounts->groups['cases-handlers']);
	}//end testASubAdminOnAGroupTheSetDidNotCreateRefusesTheSet()
}//end class
