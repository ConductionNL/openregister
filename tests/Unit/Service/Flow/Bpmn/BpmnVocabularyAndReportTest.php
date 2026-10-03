<?php

/**
 * The mapping both directions read, and the report that must not stay silent.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Bpmn
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-bpmn-interchange/specs/flow-bpmn-interchange/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Bpmn;

use OCA\OpenRegister\Service\Flow\Bpmn\BpmnMappingReport;
use OCA\OpenRegister\Service\Flow\Bpmn\BpmnVocabulary;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the import requirement's three declared ways, and the round-trip
 * property the export mapping rests on.
 */
class BpmnVocabularyAndReportTest extends TestCase {

	/**
	 * The vocabulary.
	 *
	 * @return BpmnVocabulary The subject.
	 */
	private function vocabulary(): BpmnVocabulary {
		return new BpmnVocabulary();
	}//end vocabulary()

	/**
	 * Each declared node type exports to the element the design's table names.
	 *
	 * @return void
	 */
	public function testTheDeclaredNodeTypesExportToTheirElements(): void {
		$vocabulary = $this->vocabulary();

		$this->assertSame('startEvent', $vocabulary->elementFor(nodeType: 'openregister.trigger-manual'));
		$this->assertSame('timerStartEvent', $vocabulary->elementFor(nodeType: 'openregister.trigger-schedule'));
		$this->assertSame('exclusiveGateway', $vocabulary->elementFor(nodeType: 'openregister.switch'));
		$this->assertSame('callActivity', $vocabulary->elementFor(nodeType: 'openregister.sub-flow'));
		$this->assertSame('endEvent', $vocabulary->elementFor(nodeType: 'openregister.end'));
	}//end testTheDeclaredNodeTypesExportToTheirElements()

	/**
	 * 🔴 A node type with no row exports as a task — declared, not accidental.
	 *
	 * @return void
	 */
	public function testAnUndeclaredNodeTypeExportsAsATask(): void {
		$vocabulary = $this->vocabulary();

		$this->assertFalse($vocabulary->exportsDirectly(nodeType: 'openregister.send-email'));
		$this->assertSame(
			BpmnVocabulary::FALLBACK,
			$vocabulary->elementFor(nodeType: 'openregister.send-email'),
			'the fallback is a decision somebody made and can change, not a hole that happens to behave'
		);
	}//end testAnUndeclaredNodeTypeExportsAsATask()

	/**
	 * 🔴 The import table is NOT the export table flipped.
	 *
	 * `switch` and `route` both export to an exclusive gateway, so a flip
	 * would silently pick whichever came last in array order and turn every
	 * imported route into a switch, or the reverse.
	 *
	 * @return void
	 */
	public function testTheImportTableIsNotTheExportTableFlipped(): void {
		$flipped = array_flip(BpmnVocabulary::EXPORT);

		$this->assertSame(
			'openregister.route',
			$flipped['exclusiveGateway'],
			'a flip resolves the collision by array order, which is why the reverse direction is declared'
		);
		$this->assertSame(
			'openregister.switch',
			BpmnVocabulary::IMPORT['exclusiveGateway'],
			'and the declared reading is the other one'
		);
	}//end testTheImportTableIsNotTheExportTableFlipped()

	/**
	 * Every element the exporter emits is readable by the importer, so our own
	 * files round-trip.
	 *
	 * @return void
	 */
	public function testEveryExportedElementIsReadableOnImport(): void {
		$vocabulary = $this->vocabulary();

		foreach (BpmnVocabulary::EXPORT as $nodeType => $element) {
			$reading = $vocabulary->readingFor(element: $element);

			$this->assertNotSame(
				BpmnMappingReport::REFUSED,
				$reading['verdict'],
				sprintf('%s exports to %s, which the importer refuses — our own file would not round-trip', $nodeType, $element)
			);
		}
	}//end testEveryExportedElementIsReadableOnImport()

	/**
	 * A construct with no honest mapping is refused, with a reason an author
	 * can act on.
	 *
	 * @return void
	 */
	public function testARefusedConstructCarriesAReasonAnAuthorCanActOn(): void {
		$reading = $this->vocabulary()->readingFor(element: 'compensation');

		$this->assertSame(BpmnMappingReport::REFUSED, $reading['verdict']);
		$this->assertStringContainsString(
			'does not undo',
			$reading['note'],
			'"refused: compensation" tells an author their file was wrong; this tells them what the engine does instead'
		);
	}//end testARefusedConstructCarriesAReasonAnAuthorCanActOn()

	/**
	 * A tolerated widening is APPROXIMATED, and says what was lost.
	 *
	 * @return void
	 */
	public function testAToleratedWideningIsApproximatedAndSaysWhatWasLost(): void {
		$reading = $this->vocabulary()->readingFor(element: 'userTask');

		$this->assertSame(BpmnMappingReport::APPROXIMATED, $reading['verdict']);
		$this->assertSame('openregister.await-signal', $reading['type']);
		$this->assertStringContainsString('assignee', $reading['note'], 'the report must say what did not come across');
	}//end testAToleratedWideningIsApproximatedAndSaysWhatWasLost()

	/**
	 * 🔴 A task with no openregister type imports TYPELESS and is listed.
	 *
	 * The importer must never guess a type from the task's NAME: a flow that
	 * runs something because a box was labelled "send email" is a flow nobody
	 * authorised.
	 *
	 * @return void
	 */
	public function testATaskWithNoEngineTypeImportsTypelessAndIsListed(): void {
		$reading = $this->vocabulary()->readingFor(element: BpmnVocabulary::FALLBACK);

		$this->assertSame('', $reading['type'], 'no type is guessed');
		$this->assertSame(BpmnMappingReport::APPROXIMATED, $reading['verdict']);
		$this->assertStringContainsString('refuse to run', $reading['note']);
	}//end testATaskWithNoEngineTypeImportsTypelessAndIsListed()

	/**
	 * An element nobody declared is refused by name, not ignored.
	 *
	 * @return void
	 */
	public function testAnUnknownElementIsRefusedByName(): void {
		$reading = $this->vocabulary()->readingFor(element: 'adHocSubProcess');

		$this->assertSame(BpmnMappingReport::REFUSED, $reading['verdict']);
		$this->assertStringContainsString('adHocSubProcess', $reading['note']);
	}//end testAnUnknownElementIsRefusedByName()

	/**
	 * 🔴 An entry with no element id is refused by the report itself.
	 *
	 * "An unsupported construct was dropped" without saying which one is a
	 * report an author cannot act on.
	 *
	 * @return void
	 */
	public function testAnEntryWithNoElementIdIsRefused(): void {
		$report = new BpmnMappingReport();

		$this->assertFalse($report->record(elementId: '  ', kind: 'subProcess', verdict: BpmnMappingReport::REFUSED));
		$this->assertSame([], $report->entries(), 'a report that cannot name the element records nothing');
	}//end testAnEntryWithNoElementIdIsRefused()

	/**
	 * The control: a well-formed entry is recorded.
	 *
	 * @return void
	 */
	public function testAWellFormedEntryIsRecorded(): void {
		$report = new BpmnMappingReport();

		$this->assertTrue(
			$report->record(elementId: 'Activity_1', kind: 'userTask', verdict: BpmnMappingReport::APPROXIMATED, action: 'assign a type'),
			'the control: an entry naming its element is recorded'
		);
		$this->assertCount(1, $report->entries());
	}//end testAWellFormedEntryIsRecorded()

	/**
	 * A verdict outside the closed set is refused.
	 *
	 * @return void
	 */
	public function testAVerdictOutsideTheClosedSetIsRefused(): void {
		$report = new BpmnMappingReport();

		$this->assertFalse(
			$report->record(elementId: 'Activity_1', kind: 'userTask', verdict: 'partially-ok'),
			'a fourth verdict invented at a call site is a fourth way of losing something'
		);
	}//end testAVerdictOutsideTheClosedSetIsRefused()

	/**
	 * 🔴 An APPROXIMATION counts as a loss.
	 *
	 * It is the verdict most likely to read as "fine": the construct did
	 * import, and only the sentence beside it says the semantics are narrower.
	 *
	 * @return void
	 */
	public function testAnApproximationCountsAsALoss(): void {
		$report = new BpmnMappingReport();
		$report->record(elementId: 'Activity_1', kind: 'userTask', verdict: BpmnMappingReport::APPROXIMATED);

		$this->assertTrue($report->lostSomething(), 'a narrowed import is still an import that lost something');
		$this->assertFalse($report->failsStrict(), 'but strict fails on refusals, or it would be unusable on real files');
	}//end testAnApproximationCountsAsALoss()

	/**
	 * The control: a report with only mapped entries lost nothing.
	 *
	 * @return void
	 */
	public function testAReportWithOnlyMappedEntriesLostNothing(): void {
		$report = new BpmnMappingReport();
		$report->record(elementId: 'StartEvent_1', kind: 'startEvent', verdict: BpmnMappingReport::MAPPED);

		$this->assertFalse($report->lostSomething(), 'the control: a faithful import must not claim a loss');
		$this->assertFalse($report->failsStrict());
	}//end testAReportWithOnlyMappedEntriesLostNothing()

	/**
	 * 🔴 A refusal fails a strict import.
	 *
	 * @return void
	 */
	public function testARefusalFailsAStrictImport(): void {
		$report = new BpmnMappingReport();
		$report->record(elementId: 'SubProcess_1', kind: 'subProcess:event', verdict: BpmnMappingReport::REFUSED);

		$this->assertTrue($report->failsStrict());
		$this->assertSame(
			['mapped' => 0, 'approximated' => 0, 'refused' => 1],
			$report->summary()
		);
	}//end testARefusalFailsAStrictImport()

	/**
	 * The serialised report carries the entries and the summary together.
	 *
	 * @return void
	 */
	public function testTheSerialisedReportCarriesEverythingTheCallerRenders(): void {
		$report = new BpmnMappingReport();
		$report->record(elementId: 'Activity_1', kind: 'userTask', verdict: BpmnMappingReport::APPROXIMATED, action: 'check the reading');

		$serialised = $report->jsonSerialize();

		$this->assertTrue($serialised['lostSomething']);
		$this->assertSame(1, $serialised['summary']['approximated']);
		$this->assertSame('Activity_1', $serialised['entries'][0]['elementId']);
		$this->assertSame('check the reading', $serialised['entries'][0]['action']);
	}//end testTheSerialisedReportCarriesEverythingTheCallerRenders()

	/**
	 * Entries keep the order the file presented them in.
	 *
	 * @return void
	 */
	public function testEntriesKeepFileOrder(): void {
		$report = new BpmnMappingReport();
		$report->record(elementId: 'A', kind: 'startEvent', verdict: BpmnMappingReport::MAPPED);
		$report->record(elementId: 'B', kind: 'userTask', verdict: BpmnMappingReport::APPROXIMATED);
		$report->record(elementId: 'C', kind: 'transaction', verdict: BpmnMappingReport::REFUSED);

		$this->assertSame(['A', 'B', 'C'], array_column($report->entries(), 'elementId'));
	}//end testEntriesKeepFileOrder()
}//end class
