<?php

/**
 * `--refresh` writes what the schema lets a write change: a field kept as
 * written keeps its stored value, a lens is never sent, an append-only row is
 * kept as it is; the record and the proof follow what the refresh wrote.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/specs/example-sets/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use OCA\OpenRegister\Service\ExampleSet\ExampleSetProof;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetRewriter
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetInstaller
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetProof
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
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetInstance
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetLock
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetNotes
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetOrder
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetParties
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetPaths
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetPeople
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetPrerequisites
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
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetAccessLinks
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetFlowRuns
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetGrants
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetCastShape
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetConfigShape
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetFragmentFile
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetFragmentShape
 * @uses \OCA\OpenRegister\Kernel\Contracts\ObjectRef
 * @uses \OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef
 * @uses \OCA\OpenRegister\Service\CauseFrame
 * @uses \OCA\OpenRegister\Service\ExampleSet\CastOrganisationsRun
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetAccountTerms
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetAccountsInstall
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
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetKeptObject
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetLinkRowKinds
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetMoment
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetNewAccount
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetOwnRowKinds
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetPeopleRun
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetRecordPart
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetRemoverObjects
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetRemoverRows
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetRemoverSettings
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetResolveScope
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetResolvedObject
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetRowInstall
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetVouchedAccounts
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetWorkRowKinds
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetWrittenValue
 * @uses \OCA\OpenRegister\Service\ExampleSet\FragmentDeclarations
 * @uses \OCA\OpenRegister\Service\ExampleSet\FragmentOrdering
 * @uses \OCA\OpenRegister\Service\ExampleSet\FragmentPart
 * @uses \OCA\OpenRegister\Service\ExampleSet\KeptObjectRewrite
 * @uses \OCA\OpenRegister\Service\ExampleSet\ObjectCheck
 * @uses \OCA\OpenRegister\Service\ExampleSet\RecordLedger
 * @uses \OCA\OpenRegister\Service\ExampleSet\RewriteRun
 * @uses \OCA\OpenRegister\Service\ExampleSet\RewriteTally
 * @uses \OCA\OpenRegister\Service\ExampleSet\RewrittenObject
 * @uses \OCA\OpenRegister\Service\ExampleSet\RowInstallRun
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetAccountsRemoval
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetRecordedObject
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetRemovalRun
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetRestorePart
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetProofPlace
 */
class ExampleSetRefreshWritesTest extends ExampleSetTestCase {
	/**
	 * A theme, and an incident whose history quotes it with a date.
	 *
	 * @param array<string, mixed> $incident Extra fields of the incident.
	 *
	 * @return void
	 */
	private function incidentWith(array $incident = []): void {
		$this->store->offer(register: 'pub', schema: 'theme');
		$this->store->offer(register: 'pub', schema: 'incident');
		$this->app(app: 'openregister', fragment: $this->castFile());
		$this->app(app: 'alpha', fragment: ['objects' => [
			['key' => 'theme-1', 'register' => 'pub', 'schema' => 'theme', 'data' => ['slug' => 'wonen']],
			[
				'key'      => 'incident-1',
				'register' => 'pub',
				'schema'   => 'incident',
				'lookups'  => ['theme' => ['schema' => 'theme', 'where' => ['slug' => 'wonen']]],
				'data'     => ([
					'title'   => 'Lamp kapot',
					'theme'   => '{{lookup:theme}}',
					'history' => [['theme' => '{{lookup:theme}}', 'at' => '{{date:-2}}']],
				] + $incident),
			],
		]]);
	}//end incidentWith()

	/**
	 * A refresh that rewrites an object moves its references record along, so
	 * the proof does not read the object's new values as lost.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-refresh-keeps-the-record-of-what-it-wrote
	 */
	public function testARewriteMovesTheReferencesRecordAlong(): void {
		$this->incidentWith();
		$plan = $this->catalogue->gather(set: 'demo');
		$this->assertTrue($this->installer->install(plan: $plan, today: $this->today())['ok']);

		// The theme is written again with a new id, so the kept incident's references are refreshed and recorded.
		$this->store->undeletable = $this->store->ids(register: 'pub', schema: 'incident');
		$this->remover->remove(set: 'demo');
		$this->store->undeletable = [];
		$again = $this->installer->install(plan: $plan, today: $this->today());
		$this->assertSame('refreshed', $again['changes']['alpha/incident-1#references'], (string) json_encode($again));

		$report = $this->installer->install(plan: ($plan + ['refresh' => true]), today: $this->today()->modify('+3 days'));

		$this->assertTrue($report['ok'], (string) json_encode($report));
		$incident = $this->store->row(ref: new ObjectRef(register: 'pub', schema: 'incident', id: $this->store->ids(register: 'pub', schema: 'incident')[0]));
		$this->assertSame('2026-10-14', $incident['history'][0]['at']);
		$set = $this->record->read(set: 'demo')['changes']['alpha/incident-1#references']['set'];
		$this->assertSame('2026-10-14', array_column($set, 'new', 'path')['history'][0]['at']);
	}//end testARewriteMovesTheReferencesRecordAlong()

	/**
	 * A field the schema keeps as written keeps its stored value, a lens is not
	 * sent back, and the rest of the object is refreshed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-refresh-sends-only-what-the-schema-lets-a-write-change
	 */
	public function testAKeptFieldKeepsItsValueAndALensIsNotSent(): void {
		$this->incidentWith(incident: ['since' => '{{date:-1}}', 'reported' => '{{date:-1}}']);
		$this->store->keptFields['pub incident'] = ['since'];
		$this->store->lensFields['pub incident'] = ['objectTitle'];
		$plan = $this->catalogue->gather(set: 'demo');
		$this->assertTrue($this->installer->install(plan: $plan, today: $this->today())['ok']);

		$report = $this->installer->install(plan: ($plan + ['refresh' => true]), today: $this->today()->modify('+3 days'));

		$this->assertTrue($report['ok'], (string) json_encode($report));
		$this->assertSame(1, $report['types']['pub incident']['refreshed']);
		$this->assertSame(1, $report['types']['pub theme']['unchanged']);
		$stored = $this->store->rows['pub incident'][$this->store->ids(register: 'pub', schema: 'incident')[0]];
		$this->assertSame('2026-10-12', $stored['since'], 'kept as written');
		$this->assertSame('2026-10-15', $stored['reported'], 'refreshed');
		$this->assertArrayNotHasKey('objectTitle', $stored, 'a lens is never stored');
	}//end testAKeptFieldKeepsItsValueAndALensIsNotSent()

	/**
	 * A row of an append-only schema is kept as it is: never updated, never a failure.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-refresh-sends-only-what-the-schema-lets-a-write-change
	 */
	public function testAnAppendOnlyRowIsKept(): void {
		$this->incidentWith();
		$this->store->appendOnly['pub incident'] = true;
		$plan = $this->catalogue->gather(set: 'demo');
		$this->assertTrue($this->installer->install(plan: $plan, today: $this->today())['ok']);
		$updates = $this->store->updates;

		$report = $this->installer->install(plan: ($plan + ['refresh' => true]), today: $this->today()->modify('+3 days'));

		$this->assertTrue($report['ok'], (string) json_encode($report));
		$this->assertArrayNotHasKey('refreshed', $report['types']['pub incident']);
		$this->assertSame($updates, $this->store->updates, 'the incident is append-only and the theme is unchanged');
	}//end testAnAppendOnlyRowIsKept()

	/**
	 * A field a schema keeps as JSON text, declared as that text, holds it when
	 * the store answers it decoded; another value is still lost.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-refresh-keeps-the-record-of-what-it-wrote
	 */
	public function testJsonTextHoldsWhenTheStoreAnswersItDecoded(): void {
		$proof = new ExampleSetProof();
		$point = '{"type":"Point","coordinates":[4.6612,51.8148]}';

		$this->assertSame([], $proof->lostPaths(declared: $point, stored: ['type' => 'Point', 'coordinates' => [4.6612, 51.8148]]));
		$this->assertSame([], $proof->lostPaths(declared: $point, stored: $point));
		$this->assertSame(['coordinates.0'], $proof->lostPaths(declared: $point, stored: ['type' => 'Point', 'coordinates' => [5.1, 51.8148]]));
		$this->assertSame([''], $proof->lostPaths(declared: 'plain text', stored: ['plain text']));
	}//end testJsonTextHoldsWhenTheStoreAnswersItDecoded()

	/**
	 * A field the declaration does not name reads back as null (OpenRegister answers every schema property); the
	 * refresh leaves it out instead of sending a null the schema refuses.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-refresh-does-not-send-fields-the-declaration-leaves-unset
	 */
	public function testARefreshLeavesAFieldTheDeclarationDoesNotNameUnset(): void {
		$this->incidentWith();
		$this->store->nonNullFields['pub incident'] = ['intakeChannel'];
		$plan = $this->catalogue->gather(set: 'demo');
		$this->assertTrue($this->installer->install(plan: $plan, today: $this->today())['ok']);

		$report = $this->installer->install(plan: ($plan + ['refresh' => true]), today: $this->today()->modify('+3 days'));

		$this->assertTrue($report['ok'], (string) json_encode($report));
		$this->assertSame(1, $report['types']['pub incident']['refreshed']);
	}//end testARefreshLeavesAFieldTheDeclarationDoesNotNameUnset()
}//end class
