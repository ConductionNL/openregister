<?php

/**
 * Tests that the record lets go of a held entry whose declaration the set no longer holds, and that a held change
 * writes a field again that reads back null where it was not there when the change first wrote it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/specs/example-sets/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

/**
 * A change or read state the set no longer declares is neither proved nor removed; a field that reads back null
 * counts as not set.
 *
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetInstaller
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetChanges
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetEngineRows
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetAppConfig
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetAppointments
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetArchival
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetAuditEntries
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetCasePlans
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetCatalogue
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetCollectivesPages
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetDeckCards
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetEmailLinks
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetEngineKinds
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetExportRuns
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetFiles
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetFlows
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetInstance
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetLock
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetNotes
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetOrder
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetParties
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetPaths
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetPeople
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetPrerequisites
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetProof
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetReadStates
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetRecord
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetRemover
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetStore
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetTalkConversations
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetTasks
 * @uses \OCA\OpenRegister\Notifications\Service\ExampleSet\ExampleSetTimelineEntries
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetUserPreferences
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetValues
 * @uses \OCA\OpenRegister\Search\Service\ExampleSet\ExampleSetViews
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetWatchers
 * @uses \OCA\OpenRegister\Service\WriteCause
 * @uses \OCA\OpenRegister\Db\ObjectReadState
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetAccessLinks
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetFlowRuns
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetGrants
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetRewriter
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetCastShape
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetConfigShape
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetFragmentFile
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetFragmentShape
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetProofPlace
 * @uses \OCA\OpenRegister\Kernel\Contracts\ObjectRef
 * @uses \OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef
 * @uses \OCA\OpenRegister\Service\CauseFrame
 * @uses \OCA\OpenRegister\Service\ExampleSet\CastOrganisationsRun
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetAccountTerms
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetAccountsInstall
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetAccountsRemoval
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetAppAndHistoryRowKinds
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetAppRowKinds
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetCastOrganisations
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetChangeFields
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetChangeFollower
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetChangeReadBack
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetCheckRun
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetConfigStore
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetFillRun
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetFillScope
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetFragmentCheck
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetFragmentRows
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetFragmentWrite
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetHistoryRowKinds
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetInstallRequest
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetInstallRun
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetInstallerObjects
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetInstallerSetup
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetInstallerWriters
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetLinkRowKinds
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetMadeEntry
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetNewAccount
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetOwnRowKinds
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetPathValue
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetPeopleRun
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetRecordPart
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetRemovalRun
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetRemoverObjects
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetRemoverRows
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetRemoverSettings
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetRestorePart
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetRowInstall
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetVouchedAccounts
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetWorkRowKinds
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetWrittenValue
 * @uses \OCA\OpenRegister\Service\ExampleSet\FragmentDeclarations
 * @uses \OCA\OpenRegister\Service\ExampleSet\FragmentOrdering
 * @uses \OCA\OpenRegister\Service\ExampleSet\FragmentPart
 * @uses \OCA\OpenRegister\Service\ExampleSet\ObjectCheck
 * @uses \OCA\OpenRegister\Service\ExampleSet\RecordLedger
 * @uses \OCA\OpenRegister\Service\ExampleSet\RowInstallRun
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetDateValue
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetFollowedEntry
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetHeldChange
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetKeptObject
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetResolveScope
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetResolvedObject
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetRow
 * @uses \OCA\OpenRegister\Service\ExampleSet\FilledRow
 * @uses \OCA\OpenRegister\Service\ExampleSet\RowInstallStep
 */
class ExampleSetGoneDeclarationsTest extends ExampleSetTestCase {

	/**
	 * Set up the cast.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->app(app: 'openregister', fragment: $this->castFile());
	}//end setUp()

	/**
	 * A fragment that changes a ticket another app shipped, or no change at all.
	 *
	 * @param array<string, mixed>|null $set The fields the change sets; null declares no change.
	 *
	 * @return void
	 */
	private function ticketChange(?array $set): void {
		$changes = [];
		if ($set !== null) {
			$changes[] = ['key' => 'ticket-eerst', 'register' => 'crm', 'schema' => 'ticket', 'where' => ['key' => 'afvalpas'], 'set' => $set];
		}

		$this->app(app: 'alpha', fragment: ['changes' => $changes]);
	}//end ticketChange()

	/**
	 * A change the set no longer declares is let go: the field it wrote now holds another value, and that is not
	 * reported lost; the report names the change as left, and remove does not put the old value back.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-the-record-lets-go-of-a-held-entry-the-set-no-longer-declares
	 */
	public function testAChangeTheSetNoLongerDeclaresIsLetGo(): void {
		$this->store->offer(register: 'crm', schema: 'ticket');
		$id = $this->store->seed(register: 'crm', schema: 'ticket', data: ['key' => 'afvalpas', 'slaDeadline' => '2026-10-20T17:00:00+00:00']);
		$this->ticketChange(set: ['slaDeadline' => '2026-10-15T17:00:00+00:00']);
		$this->assertTrue(condition: $this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today())['ok']);

		$this->ticketChange(set: null);
		$this->store->rows['crm ticket'][$id]['slaDeadline'] = '2026-10-16T17:00:00+00:00';
		$report = $this->installer->install(plan: ($this->catalogue->gather(set: 'demo') + ['refresh' => true]), today: $this->today());

		$this->assertTrue(condition: $report['ok'], message: (string) json_encode($report));
		$this->assertContains(needle: 'change alpha/ticket-eerst: the set no longer declares it', haystack: $report['left']);

		$this->remover->remove(set: 'demo');
		$this->assertSame(expected: '2026-10-16T17:00:00+00:00', actual: $this->store->rows['crm ticket'][$id]['slaDeadline']);
	}//end testAChangeTheSetNoLongerDeclaresIsLetGo()

	/**
	 * A read state the set no longer declares is let go: that it is gone from the instance is not reported missing.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-the-record-lets-go-of-a-held-entry-the-set-no-longer-declares
	 */
	public function testAReadStateTheSetNoLongerDeclaresIsLetGo(): void {
		$this->store->offer(register: 'cases', schema: 'case');
		$case = ['key' => 'case-1', 'register' => 'cases', 'schema' => 'case', 'data' => ['title' => 'Zaak']];
		$read = ['key' => 'esra-opende', 'data' => ['user' => '{{cast:esra.uid}}', 'objectUuid' => '{{object:case-1.id}}']];
		$this->app(app: 'alpha', fragment: ['objects' => [$case], 'readStates' => [$read]]);
		$this->assertTrue(condition: $this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today())['ok']);

		$this->app(app: 'alpha', fragment: ['objects' => [$case], 'readStates' => []]);
		$this->readStates = [];
		$report = $this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());

		$this->assertTrue(condition: $report['ok'], message: (string) json_encode($report));
		$this->assertNotContains(needle: 'read state alpha/esra-opende', haystack: $report['missing']);
		$this->assertContains(needle: 'read state alpha/esra-opende: the set no longer declares it', haystack: $report['left']);
	}//end testAReadStateTheSetNoLongerDeclaresIsLetGo()

	/**
	 * Only the entries of the fragments in the plan are let go: an app whose fragment is not gathered keeps its own.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-the-record-lets-go-of-a-held-entry-the-set-no-longer-declares
	 */
	public function testAnEntryOfAnAppOutsideThePlanIsKept(): void {
		$this->store->offer(register: 'crm', schema: 'ticket');
		$this->store->seed(register: 'crm', schema: 'ticket', data: ['key' => 'afvalpas', 'slaDeadline' => null]);
		$this->ticketChange(set: ['slaDeadline' => '2026-10-15T17:00:00+00:00']);
		$plan = $this->catalogue->gather(set: 'demo');
		$this->assertTrue(condition: $this->installer->install(plan: $plan, today: $this->today())['ok']);

		$plan['fragments'] = array_values(array_filter($plan['fragments'], static fn (array $fragment): bool => $fragment['app'] !== 'alpha'));
		$report = $this->installer->install(plan: $plan, today: $this->today());

		$this->assertSame(expected: [], actual: $report['left']);
		$this->assertTrue(condition: $report['ok'], message: (string) json_encode($report));
	}//end testAnEntryOfAnAppOutsideThePlanIsKept()

	/**
	 * A field the change wrote where the object had none, and that now reads back null, is back at what the change
	 * replaced: a refresh writes it again instead of reporting it lost.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-field-that-reads-back-null-is-not-set
	 */
	public function testARefreshWritesAgainAFieldThatReadsBackNull(): void {
		$this->store->offer(register: 'crm', schema: 'ticket');
		$id = $this->store->seed(register: 'crm', schema: 'ticket', data: ['key' => 'afvalpas']);
		$this->ticketChange(set: ['contactRef' => 'c32e7c7a-470d-42e4-9c07-e673bfce516e']);
		$plan = $this->catalogue->gather(set: 'demo');
		$this->assertTrue(condition: $this->installer->install(plan: $plan, today: $this->today())['ok']);

		$this->store->rows['crm ticket'][$id]['contactRef'] = null;
		$report = $this->installer->install(plan: ($plan + ['refresh' => true]), today: $this->today());

		$this->assertTrue(condition: $report['ok'], message: (string) json_encode($report));
		$this->assertSame(expected: 'c32e7c7a-470d-42e4-9c07-e673bfce516e', actual: $this->store->rows['crm ticket'][$id]['contactRef']);
		$this->assertSame(expected: 'refreshed', actual: $report['changes']['alpha/ticket-eerst']);
	}//end testARefreshWritesAgainAFieldThatReadsBackNull()
}//end class
