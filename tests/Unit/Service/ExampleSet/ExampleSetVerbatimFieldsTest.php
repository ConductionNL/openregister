<?php

/**
 * A declared object may name fields that hold placeholders of their own (a
 * mail template's {{zaakNummer}}): `verbatimFields` are written as declared,
 * and every other field still refuses a placeholder that does not resolve.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/specs/example-sets/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetInstaller
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetValues
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
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetProof
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetReadStates
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetRecord
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetRemover
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetTalkConversations
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetTasks
 * @uses \OCA\OpenRegister\Notifications\Service\ExampleSet\ExampleSetTimelineEntries
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetUserPreferences
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
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetProofPlace
 */
class ExampleSetVerbatimFieldsTest extends ExampleSetTestCase {
	/**
	 * A mail template with its own placeholders in subject and body.
	 *
	 * @param array<string, mixed> $template Extra declaration keys.
	 * @param array<string, mixed> $data     Extra fields.
	 *
	 * @return void
	 */
	private function template(array $template = [], array $data = []): void {
		$this->store->offer(register: 'mail', schema: 'emailTemplate');
		$this->app(app: 'openregister', fragment: $this->castFile());
		$this->app(app: 'alpha', fragment: ['objects' => [([
			'key'      => 'ontvangst',
			'register' => 'mail',
			'schema'   => 'emailTemplate',
			'data'     => ([
				'subject' => 'Uw verzoek {{zaakNummer}} is ontvangen',
				'body'    => 'Geachte {{contactNaam}}, wij beslissen uiterlijk op {{deadline}}.',
				'author'  => '{{cast:esra.uid}}',
			] + $data),
		] + $template)]]);
	}//end template()

	/**
	 * The template's own placeholders are written as declared; the set's tokens in other fields still resolve.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-declared-object-may-keep-fields-verbatim
	 */
	public function testVerbatimFieldsAreWrittenAsDeclared(): void {
		$this->template(template: ['verbatimFields' => ['subject', 'body']]);
		$plan = $this->catalogue->gather(set: 'demo');

		$this->assertSame([], $this->installer->check(plan: $plan, today: $this->today())['refusals']);
		$report = $this->installer->install(plan: $plan, today: $this->today());

		$this->assertTrue($report['ok'], (string) json_encode($report));
		$row = $this->store->find(register: 'mail', schema: 'emailTemplate', filters: [])[0];
		$this->assertSame('Uw verzoek {{zaakNummer}} is ontvangen', $row['subject']);
		$this->assertSame('Geachte {{contactNaam}}, wij beslissen uiterlijk op {{deadline}}.', $row['body']);
		$this->assertSame('esra.demir', $row['author']);
	}//end testVerbatimFieldsAreWrittenAsDeclared()

	/**
	 * Without the field named, its placeholders refuse the set; a typo in a real token outside the verbatim fields
	 * refuses it too.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-declared-object-may-keep-fields-verbatim
	 */
	public function testEveryOtherFieldStillRefusesAnUnresolvedPlaceholder(): void {
		$this->template();
		$refusals = $this->installer->check(plan: $this->catalogue->gather(set: 'demo'), today: $this->today())['refusals'];
		$this->assertCount(1, $refusals);
		$this->assertStringContainsString('{{zaakNummer}}', $refusals[0]);

		$this->template(template: ['verbatimFields' => ['subject', 'body']], data: ['owner' => '{{cast:esraa.uid}}']);
		$refusals = $this->installer->check(plan: $this->catalogue->gather(set: 'demo'), today: $this->today())['refusals'];
		$this->assertCount(1, $refusals);
		$this->assertStringContainsString('{{cast:esraa.uid}}', $refusals[0]);
		$this->assertSame([], $this->store->ids(register: 'mail', schema: 'emailTemplate'));
	}//end testEveryOtherFieldStillRefusesAnUnresolvedPlaceholder()
}//end class
