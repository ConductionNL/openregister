<?php

/**
 * What a run says it is working with.
 *
 * 🔴 IDEMPOTENCY IS NOT OPTIONAL HERE. A user-task node is re-entered on a
 * heartbeat, by design, with its task still open — so any node that records a
 * subject is re-entered too. A set that grew on each wake would report a run as
 * working on the same case forty times.
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
use OCA\OpenRegister\Service\Flow\FlowRunSubjects;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use UnexpectedValueException;

/**
 * Tests for {@see FlowRunSubjects}.
 *
 * @covers \OCA\OpenRegister\Service\Flow\FlowRunSubjects
 * @uses \OCA\OpenRegister\Db\FlowRun
 */
final class FlowRunSubjectsTest extends TestCase {

	/**
	 * Everything the logger was told.
	 *
	 * @var array<int, string>
	 */
	private array $logged = [];

	/**
	 * The subject rules, watching the log.
	 *
	 * @return FlowRunSubjects The service.
	 */
	private function subjects(): FlowRunSubjects {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			function (string $message): void {
				$this->logged[] = $message;
			}
		);

		return new FlowRunSubjects($logger);
	}//end subjects()

	/**
	 * A run with nothing declared.
	 *
	 * ⚠️ NOT named `run()`: that is FINAL on PHPUnit's TestCase, and overriding
	 * it is a fatal error rather than a failing test.
	 *
	 * @return FlowRun The run.
	 */
	private function newRun(): FlowRun {
		$run = new FlowRun();
		$run->setUuid('run-1');

		return $run;
	}//end newRun()

	/**
	 * A run that has declared nothing holds no roles.
	 *
	 * @return void
	 */
	public function testARunDeclaresNothingUntilAStepSaysSo(): void {
		$run = $this->newRun();

		$this->assertSame([], $this->subjects()->all(run: $run));
		$this->assertSame([], $this->subjects()->roles(run: $run));
	}//end testARunDeclaresNothingUntilAStepSaysSo()

	/**
	 * Recording puts the object under its role, with where it lives.
	 *
	 * @return void
	 */
	public function testRecordingPutsTheObjectUnderItsRole(): void {
		$run = $this->newRun();
		$this->subjects()->record(run: $run, role: 'case', uuid: 'obj-1', register: 'cases', schema: 'case');

		$recorded = $this->subjects()->all(run: $run)['case'];
		$this->assertSame('obj-1', $recorded['uuid']);
		$this->assertSame('cases', $recorded['register']);
		$this->assertSame('case', $recorded['schema']);
		$this->assertNotSame('', $recorded['recordedAt']);
	}//end testRecordingPutsTheObjectUnderItsRole()

	/**
	 * 🔴 RE-RECORDING THE SAME OBJECT UNDER THE SAME ROLE CHANGES NOTHING.
	 *
	 * The heartbeat case. Forty wake-ups must leave one subject, and must not
	 * log forty replacements either.
	 *
	 * @return void
	 */
	public function testARefireDoesNotDuplicateOrLog(): void {
		$run = $this->newRun();
		$subjects = $this->subjects();

		for ($i = 0; $i < 40; $i++) {
			$subjects->record(run: $run, role: 'case', uuid: 'obj-1', register: 'cases', schema: 'case');
		}

		$this->assertSame(['case'], $subjects->roles(run: $run));
		$this->assertSame([], $this->logged, 're-recording the same object is not a replacement');
	}//end testARefireDoesNotDuplicateOrLog()

	/**
	 * 🔴 RE-POINTING A ROLE IS ALLOWED, AND LOGGED.
	 *
	 * Superseding a draft decision with a final one genuinely means `decision`
	 * to be the new object, and refusing would force authors into `decision2`.
	 * But it is also exactly how a later step silently attaches to the wrong
	 * record, so the log entry is what makes "why is this task on the old
	 * decision" answerable in under a minute.
	 *
	 * @return void
	 */
	public function testAReplacementIsAllowedAndLoggedWithBothObjects(): void {
		$run = $this->newRun();
		$subjects = $this->subjects();

		$subjects->record(run: $run, role: 'decision', uuid: 'draft-1');
		$subjects->record(run: $run, role: 'decision', uuid: 'final-1');

		$this->assertSame('final-1', $subjects->all(run: $run)['decision']['uuid']);

		$said = implode(' ', $this->logged);
		$this->assertStringContainsString('draft-1', $said, 'the log names what it was');
		$this->assertStringContainsString('final-1', $said, 'and what it became');
		$this->assertStringContainsString('decision', $said);
	}//end testAReplacementIsAllowedAndLoggedWithBothObjects()

	/**
	 * A role addresses its object.
	 *
	 * @return void
	 */
	public function testARoleAddressesItsObject(): void {
		$run = $this->newRun();
		$subjects = $this->subjects();
		$subjects->record(run: $run, role: 'case', uuid: 'obj-1', register: 'cases', schema: 'case');

		$this->assertSame('obj-1', $subjects->addressed(run: $run, role: 'case')['uuid']);
	}//end testARoleAddressesItsObject()

	/**
	 * 🔴 AN UNHELD ROLE FAILS, NAMING THE ROLES THAT ARE HELD.
	 *
	 * A typo in `attachTo` would otherwise attach the task to nothing and leave
	 * the author looking for a record that was never made. Listing what exists
	 * is the whole reason no role vocabulary has to be registered anywhere.
	 *
	 * @return void
	 */
	public function testAnUnheldRoleFailsNamingWhatIsHeld(): void {
		$run = $this->newRun();
		$subjects = $this->subjects();
		$subjects->record(run: $run, role: 'case', uuid: 'obj-1');
		$subjects->record(run: $run, role: 'besluit', uuid: 'obj-2');

		try {
			$subjects->addressed(run: $run, role: 'csae');
			$this->fail('an unheld role should fail');
		} catch (UnexpectedValueException $e) {
			$this->assertStringContainsString('csae', $e->getMessage());
			$this->assertStringContainsString('besluit', $e->getMessage());
			$this->assertStringContainsString('case', $e->getMessage());
		}
	}//end testAnUnheldRoleFailsNamingWhatIsHeld()

	/**
	 * A run holding nothing says so, rather than listing an empty set.
	 *
	 * @return void
	 */
	public function testARunHoldingNothingSaysSo(): void {
		try {
			$this->subjects()->addressed(run: $this->newRun(), role: 'case');
			$this->fail('an unheld role should fail');
		} catch (UnexpectedValueException $e) {
			$this->assertStringContainsString('declared none', $e->getMessage());
		}
	}//end testARunHoldingNothingSaysSo()

	/**
	 * A role or an object that is empty is refused, not stored.
	 *
	 * A subject with no object would be addressable and would attach a task to
	 * nothing.
	 *
	 * @return void
	 */
	public function testAnEmptyRoleOrObjectIsRefused(): void {
		$subjects = $this->subjects();

		$this->expectException(UnexpectedValueException::class);
		$subjects->record(run: $this->newRun(), role: 'case', uuid: '   ');
	}//end testAnEmptyRoleOrObjectIsRefused()

	/**
	 * Several roles coexist, and each addresses its own object.
	 *
	 * @return void
	 */
	public function testSeveralRolesCoexist(): void {
		$run = $this->newRun();
		$subjects = $this->subjects();

		$subjects->record(run: $run, role: 'case', uuid: 'obj-1');
		$subjects->record(run: $run, role: 'besluit', uuid: 'obj-2');
		$subjects->record(run: $run, role: 'aanvraag', uuid: 'obj-3');

		$this->assertSame(['aanvraag', 'besluit', 'case'], $subjects->roles(run: $run));
		$this->assertSame('obj-2', $subjects->addressed(run: $run, role: 'besluit')['uuid']);
	}//end testSeveralRolesCoexist()
}//end class
