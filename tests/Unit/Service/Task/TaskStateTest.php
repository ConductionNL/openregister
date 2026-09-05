<?php

/**
 * The one status mapping, table-driven — including the live fleet defect.
 *
 * The table IS the published mapping: every legacy value the spec names,
 * the collapse-preserves-outcome rule (`done` and `approved` both land on
 * `completed` with different outcomes), and the refusal rule. The procest
 * defect is a first-class case: `CreateTaskHandler.php:76` writes
 * `status:'open'` into a schema whose enum has no `open`, so normalising
 * 'open' AGAINST THAT DECLARED VOCABULARY must refuse naming the value —
 * while the fleet mapping itself still covers `open` for sources that do
 * define it, as the spec's minimum-coverage list requires.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Task
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Task;

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Exception\TaskValidationException;
use OCA\OpenRegister\Service\Task\TaskState;
use PHPUnit\Framework\TestCase;

/**
 * The legacy-status mapping table, and its refusals.
 *
 * @covers \OCA\OpenRegister\Service\Task\TaskState
 * @covers \OCA\OpenRegister\Exception\TaskValidationException
 * @covers \OCA\OpenRegister\Db\Task
 */
class TaskStateTest extends TestCase {

	/**
	 * Every value the spec's minimum-coverage list names, mapped.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string|null}> value, state, outcome.
	 */
	public static function mappingProvider(): array {
		return [
			'open' => ['open', Task::STATE_ENABLED, null],
			'pending' => ['pending', Task::STATE_AVAILABLE, null],
			'todo' => ['todo', Task::STATE_ENABLED, null],
			'blocked' => ['blocked', Task::STATE_ACTIVE, 'blocked'],
			'in_progress' => ['in_progress', Task::STATE_ACTIVE, null],
			'in-progress' => ['in-progress', Task::STATE_ACTIVE, null],
			'in-execution' => ['in-execution', Task::STATE_ACTIVE, null],
			'done' => ['done', Task::STATE_COMPLETED, 'done'],
			'resolved' => ['resolved', Task::STATE_COMPLETED, 'resolved'],
			'approved' => ['approved', Task::STATE_COMPLETED, 'approved'],
			'rejected' => ['rejected', Task::STATE_COMPLETED, 'rejected'],
			'waived' => ['waived', Task::STATE_DISABLED, 'waived'],
			'skipped' => ['skipped', Task::STATE_DISABLED, 'skipped'],
			'cancelled' => ['cancelled', Task::STATE_TERMINATED, 'cancelled'],
			'expired' => ['expired', Task::STATE_TERMINATED, 'expired'],
			'error' => ['error', Task::STATE_TERMINATED, 'error'],
			'dead_letter' => ['dead_letter', Task::STATE_TERMINATED, 'dead_letter'],
			'reopen' => ['reopen', Task::STATE_ENABLED, 'reopened'],
			// The canonical six pass through untouched.
			'available' => [Task::STATE_AVAILABLE, Task::STATE_AVAILABLE, null],
			'active' => [Task::STATE_ACTIVE, Task::STATE_ACTIVE, null],
			'completed' => [Task::STATE_COMPLETED, Task::STATE_COMPLETED, null],
			'terminated' => [Task::STATE_TERMINATED, Task::STATE_TERMINATED, null],
			'disabled' => [Task::STATE_DISABLED, Task::STATE_DISABLED, null],
		];
	}//end mappingProvider()

	/**
	 * The table maps as published.
	 *
	 * @dataProvider mappingProvider
	 *
	 * @param string $value The incoming value.
	 * @param string $state The expected state.
	 * @param string|null $outcome The expected preserved outcome.
	 *
	 * @return void
	 */
	public function testMapping(string $value, string $state, ?string $outcome): void {
		$result = TaskState::normalise(value: $value);
		$this->assertSame($state, $result['state']);
		$this->assertSame($outcome, $result['outcome']);
	}//end testMapping()

	/**
	 * Two legacy spellings converge on one state without losing what they said.
	 *
	 * @return void
	 */
	public function testDoneAndApprovedCollapseButOutcomesDiffer(): void {
		$done = TaskState::normalise(value: 'done');
		$approved = TaskState::normalise(value: 'approved');
		$this->assertSame(Task::STATE_COMPLETED, $done['state']);
		$this->assertSame(Task::STATE_COMPLETED, $approved['state']);
		$this->assertTrue(TaskState::isTerminal(state: $done['state']));
		$this->assertNotSame($done['outcome'], $approved['outcome']);
	}//end testDoneAndApprovedCollapseButOutcomesDiffer()

	/**
	 * A value in no vocabulary is refused, naming itself.
	 *
	 * @return void
	 */
	public function testUnknownValueIsRefusedByName(): void {
		$this->expectException(TaskValidationException::class);
		$this->expectExceptionMessage("'openstaand'");
		TaskState::normalise(value: 'openstaand');
	}//end testUnknownValueIsRefusedByName()

	/**
	 * THE PROCEST DEFECT: `status:'open'` against a source vocabulary that
	 * does not define it MUST be refused naming the value — even though the
	 * fleet mapping knows the word. This is the write at
	 * `procest/lib/Service/Transitions/CreateTaskHandler.php:76` failing
	 * loudly at migration instead of laundering silently.
	 *
	 * @return void
	 */
	public function testOpenAgainstAVocabularyWithoutItIsRefused(): void {
		$procestEnum = ['pending', 'in_progress', 'done', 'cancelled'];
		$this->expectException(TaskValidationException::class);
		$this->expectExceptionMessage("'open'");
		TaskState::normalise(value: 'open', sourceVocabulary: $procestEnum);
	}//end testOpenAgainstAVocabularyWithoutItIsRefused()

	/**
	 * Nothing in the mapping produces a state outside the six, and
	 * terminality agrees with the entity's TERMINAL_STATES everywhere.
	 *
	 * @return void
	 */
	public function testEveryMappedStateIsCanonicalAndTerminalityAgrees(): void {
		foreach (TaskState::mapping() as $value => [$state]) {
			$this->assertContains($state, Task::STATES, sprintf("'%s' maps outside the six CMMN states", (string)$value));
			$this->assertSame(
				in_array($state, Task::TERMINAL_STATES, true),
				TaskState::isTerminal(state: $state)
			);
		}
	}//end testEveryMappedStateIsCanonicalAndTerminalityAgrees()

	/**
	 * No mapped value spells overdue: overdue is not a state, anywhere.
	 *
	 * @return void
	 */
	public function testOverdueIsNotAStateAnywhere(): void {
		$this->assertArrayNotHasKey('overdue', TaskState::mapping());
		$this->assertNotContains('overdue', Task::STATES);
	}//end testOverdueIsNotAStateAnywhere()

	/**
	 * Rejecting outcomes are recognised; approvals are not.
	 *
	 * @return void
	 */
	public function testRejectingOutcomes(): void {
		$this->assertTrue(TaskState::isRejectingOutcome(outcome: 'rejected'));
		$this->assertTrue(TaskState::isRejectingOutcome(outcome: 'Returned'));
		$this->assertFalse(TaskState::isRejectingOutcome(outcome: 'approved'));
		$this->assertFalse(TaskState::isRejectingOutcome(outcome: null));
	}//end testRejectingOutcomes()
}//end class
