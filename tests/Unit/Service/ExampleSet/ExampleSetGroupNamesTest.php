<?php

/**
 * A group the set declares that already exists without a name of its own
 * (an app made it, showing its id) gets the declared display name; remove puts
 * the old name back while the group still carries the set's.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/specs/example-sets/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetPeople
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetRemover
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetRecord
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetAppConfig
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetAppointments
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetArchival
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetAuditEntries
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetCasePlans
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetCatalogue
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetChanges
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetCollectivesPages
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetDeckCards
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetEmailLinks
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetEngineKinds
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetEngineRows
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetExportRuns
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetFiles
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetFlows
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetInstaller
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetInstance
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetLock
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetNotes
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetOrder
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetParties
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetPrerequisites
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetReadStates
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetTalkConversations
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetTasks
 * @uses \OCA\OpenRegister\Notifications\Service\ExampleSet\ExampleSetTimelineEntries
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetUserPreferences
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetValues
 * @uses \OCA\OpenRegister\Search\Service\ExampleSet\ExampleSetViews
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetWatchers
 * @uses \OCA\OpenRegister\Service\WriteCause
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetAccessLinks
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetFlowRuns
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetGrants
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetCastShape
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetConfigShape
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetFragmentFile
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetFragmentShape
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
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-declared-group-without-a-name-gets-the-declared-one
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
