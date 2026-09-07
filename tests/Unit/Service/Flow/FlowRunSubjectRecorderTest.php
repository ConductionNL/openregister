<?php

/**
 * Recording a subject from inside a step, and attaching a task to one.
 *
 * 🔴 RECORDING MUST NOT FAIL THE STEP THAT DID THE WORK. The object HAS been
 * written or locked by the time this runs; failing here would report a step as
 * failed after its effect landed, which is the worst of both.
 *
 * 🔴 ATTACHING IS THE OPPOSITE. An unheld role must fail BEFORE the task
 * exists: a task attached to nothing looks fine in every list and is wrong in
 * the one place it matters.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-run-subjects-and-answers/specs/flow-run-subjects/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Service\Flow\FlowRunContext;
use OCA\OpenRegister\Service\Flow\FlowRunSubjectRecorder;
use OCA\OpenRegister\Service\Flow\FlowRunSubjects;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use UnexpectedValueException;

/**
 * Tests for {@see FlowRunSubjectRecorder}.
 *
 * @covers \OCA\OpenRegister\Service\Flow\FlowRunSubjectRecorder
 * @uses \OCA\OpenRegister\Service\Flow\FlowRunSubjects
 * @uses \OCA\OpenRegister\Db\FlowRun
 */
final class FlowRunSubjectRecorderTest extends TestCase {

	/**
	 * The run the fixture instance holds.
	 *
	 * @var FlowRun|null
	 */
	private ?FlowRun $stored = null;

	/**
	 * Whether the run was written back.
	 *
	 * @var boolean
	 */
	private bool $written = false;

	/**
	 * A recorder over a run that can or cannot be read.
	 *
	 * @param FlowRun|null $run The run, or null when it cannot be read.
	 *
	 * @return FlowRunSubjectRecorder The recorder.
	 */
	private function recorder(?FlowRun $run): FlowRunSubjectRecorder {
		$mapper = $this->createMock(FlowRunMapper::class);

		if ($run === null) {
			$mapper->method('findByUuid')->willThrowException(new RuntimeException('no such run'));
		} else {
			$mapper->method('findByUuid')->willReturn($run);
		}

		$mapper->method('update')->willReturnCallback(
			function (FlowRun $updated): FlowRun {
				$this->written = true;
				$this->stored = $updated;

				return $updated;
			}
		);

		return new FlowRunSubjectRecorder(
			$mapper,
			new FlowRunSubjects($this->createMock(LoggerInterface::class)),
			$this->createMock(LoggerInterface::class)
		);
	}//end recorder()

	/**
	 * A run context naming a run.
	 *
	 * @return array<string, mixed> The context.
	 */
	private function context(): array {
		return [FlowRunContext::CONTEXT_RUN => 'run-1', 'runUuid' => 'run-1'];
	}//end context()

	/**
	 * A run with a uuid.
	 *
	 * @return FlowRun The run.
	 */
	private function aRun(): FlowRun {
		$run = new FlowRun();
		$run->setUuid('run-1');

		return $run;
	}//end aRun()

	/**
	 * 🔑 NO ROLE, NO RECORDING.
	 *
	 * Every step this is wired into already did its work without it, and a
	 * subject nobody asked for would be addressable by `attachTo` — inventing a
	 * declaration the author never made.
	 *
	 * @return void
	 */
	public function testNoRoleMeansNoRecording(): void {
		$this->recorder($this->aRun())->record(context: $this->context(), role: '', uuid: 'obj-1');

		$this->assertFalse($this->written, 'a step that declares no role must write nothing');
	}//end testNoRoleMeansNoRecording()

	/**
	 * A declared role records the object and stores the run.
	 *
	 * @return void
	 */
	public function testADeclaredRoleRecordsAndStores(): void {
		$this->recorder($this->aRun())->record(
			context: $this->context(),
			role: 'case',
			uuid: 'obj-1',
			register: '3',
			schema: '7'
		);

		$this->assertTrue($this->written);
		$this->assertSame('obj-1', $this->stored->getSubjects()['case']['uuid']);
	}//end testADeclaredRoleRecordsAndStores()

	/**
	 * 🔴 A RUN THAT CANNOT BE READ DOES NOT FAIL THE STEP.
	 *
	 * The object has already been written by the time this runs. Failing here
	 * would report a step as failed after its effect landed.
	 *
	 * @return void
	 */
	public function testAnUnreadableRunDoesNotFailTheStep(): void {
		$this->expectNotToPerformAssertions();

		$this->recorder(null)->record(context: $this->context(), role: 'case', uuid: 'obj-1');
	}//end testAnUnreadableRunDoesNotFailTheStep()

	/**
	 * A context with no run uuid records nothing and raises nothing.
	 *
	 * @return void
	 */
	public function testAContextWithNoRunRecordsNothing(): void {
		$this->recorder($this->aRun())->record(context: [], role: 'case', uuid: 'obj-1');

		$this->assertFalse($this->written);
	}//end testAContextWithNoRunRecordsNothing()

	/**
	 * Attaching to a held role fills the task's own object fields.
	 *
	 * 🔑 THE TASK'S EXISTING FIELDS, NOT A NEW COLUMN. Three things already
	 * read them — the subject-anchored inbox query, the case sidebar and the
	 * portal visibility rule — so the attachment works everywhere immediately.
	 *
	 * @return void
	 */
	public function testAttachingFillsTheTasksOwnObjectFields(): void {
		$run = $this->aRun();
		(new FlowRunSubjects())->record(run: $run, role: 'case', uuid: 'obj-1', register: '3', schema: '7');

		$anchor = $this->recorder($run)->anchorFor(context: $this->context(), role: 'case');

		$this->assertSame('obj-1', $anchor['objectUuid']);
		$this->assertSame(3, $anchor['registerId']);
		$this->assertSame(7, $anchor['schemaId']);
	}//end testAttachingFillsTheTasksOwnObjectFields()

	/**
	 * 🔴 AN UNHELD ROLE THROWS, SO THE STEP FAILS AND NO TASK IS CREATED.
	 *
	 * And the refusal names the roles the run DOES hold, which is what makes an
	 * unregistered role vocabulary workable.
	 *
	 * @return void
	 */
	public function testAnUnheldRoleRefusesNamingWhatIsHeld(): void {
		$run = $this->aRun();
		(new FlowRunSubjects())->record(run: $run, role: 'case', uuid: 'obj-1');

		try {
			$this->recorder($run)->anchorFor(context: $this->context(), role: 'besluit');
			$this->fail('an unheld role should refuse');
		} catch (UnexpectedValueException $e) {
			$this->assertStringContainsString('besluit', $e->getMessage());
			$this->assertStringContainsString('case', $e->getMessage());
		}
	}//end testAnUnheldRoleRefusesNamingWhatIsHeld()

	/**
	 * No `attachTo` means no anchor, and the task keeps the item's own.
	 *
	 * @return void
	 */
	public function testNoAttachToMeansNoAnchor(): void {
		$this->assertSame(
			[],
			$this->recorder($this->aRun())->anchorFor(context: $this->context(), role: '')
		);
	}//end testNoAttachToMeansNoAnchor()

	/**
	 * 🔴 ATTACHING WITH AN UNREADABLE RUN REFUSES, unlike recording.
	 *
	 * The asymmetry is deliberate. Recording happens after the work landed;
	 * attaching happens before the task exists, and a task that silently lost
	 * its attachment is the defect this whole section is about.
	 *
	 * @return void
	 */
	public function testAttachingWithAnUnreadableRunRefuses(): void {
		$this->expectException(UnexpectedValueException::class);

		$this->recorder(null)->anchorFor(context: $this->context(), role: 'case');
	}//end testAttachingWithAnUnreadableRunRefuses()

	/**
	 * A subject with no numeric register or schema still attaches by uuid.
	 *
	 * @return void
	 */
	public function testASubjectWithoutNumericIdsStillAttaches(): void {
		$run = $this->aRun();
		(new FlowRunSubjects())->record(run: $run, role: 'case', uuid: 'obj-1', register: 'cases', schema: 'case');

		$anchor = $this->recorder($run)->anchorFor(context: $this->context(), role: 'case');

		$this->assertSame('obj-1', $anchor['objectUuid']);
		$this->assertArrayNotHasKey('registerId', $anchor);
		$this->assertArrayNotHasKey('schemaId', $anchor);
	}//end testASubjectWithoutNumericIdsStillAttaches()
}//end class
