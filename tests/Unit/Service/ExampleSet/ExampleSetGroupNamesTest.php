<?php

/**
 * A group the set declares that already exists without a name of its own
 * (an app made it, showing its id) gets the declared display name; remove puts
 * the old name back while the group still carries the set's.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/example-set-group-display-names/specs/example-sets/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetPeople
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetRemover
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetRecord
 */
class ExampleSetGroupNamesTest extends ExampleSetTestCase {
	/**
	 * A fragment declaring two existing groups.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->app(app: 'openregister', fragment: $this->castFile());
		$this->app(app: 'alpha', fragment: ['groups' => [
			['group' => 'behandelaars', 'displayName' => 'Behandelaars', 'members' => ['esra']],
			['group' => 'redactie', 'displayName' => 'Redactie', 'members' => []],
		]]);
		$this->accounts->groups['behandelaars'] = [];
		$this->accounts->groupNames['behandelaars'] = 'behandelaars';
		$this->accounts->groups['redactie'] = [];
		$this->accounts->groupNames['redactie'] = 'Webredactie';
	}

	/**
	 * An unnamed group is named, a named one is left, and remove puts the old name back.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-set-group-display-names/specs/example-sets/spec.md#requirement-a-declared-group-without-a-name-gets-the-declared-one
	 */
	public function testAnUnnamedExistingGroupGetsTheDeclaredName(): void {
		$plan   = $this->catalogue->gather(set: 'demo');
		$report = $this->installer->install(plan: $plan, today: $this->today());

		$this->assertTrue($report['ok'], (string) json_encode($report));
		$this->assertSame('Behandelaars', $this->accounts->groupNames['behandelaars']);
		$this->assertSame('Webredactie', $this->accounts->groupNames['redactie'], 'a name somebody gave stays');
		$this->assertSame('named', $report['groups']['behandelaars']);

		$this->assertSame([], $this->remover->remove(set: 'demo')['failed']);
		$this->assertSame('behandelaars', $this->accounts->groupNames['behandelaars']);
		$this->assertArrayHasKey('behandelaars', $this->accounts->groups, 'the group itself was not the set\'s');
	}//end testAnUnnamedExistingGroupGetsTheDeclaredName()
}//end class
