<?php

/**
 * The task seed step: off by default, idempotent when on.
 *
 * The review's concern was five Dutch demo tasks landing on every production
 * instance through a post-migration repair step. The first test is that
 * concern made permanent: with the flag unset the step writes NOTHING. The
 * others pin what a demo instance gets, and that a re-run adds nothing.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Repair;

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskAudit;
use OCA\OpenRegister\Db\TaskAuditMapper;
use OCA\OpenRegister\Db\TaskCandidateMapper;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Repair\SeedTaskFixtures;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Gate, content and idempotency of the seed step.
 *
 * @covers \OCA\OpenRegister\Repair\SeedTaskFixtures
 */
class SeedTaskFixturesTest extends TestCase {

	/**
	 * The task table, mocked.
	 *
	 * @var TaskMapper&MockObject
	 */
	private TaskMapper&MockObject $tasks;

	/**
	 * The candidate index, mocked.
	 *
	 * @var TaskCandidateMapper&MockObject
	 */
	private TaskCandidateMapper&MockObject $candidates;

	/**
	 * The audit, mocked.
	 *
	 * @var TaskAuditMapper&MockObject
	 */
	private TaskAuditMapper&MockObject $audits;

	/**
	 * Fresh mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->tasks = $this->createMock(originalClassName: TaskMapper::class);
		$this->candidates = $this->createMock(originalClassName: TaskCandidateMapper::class);
		$this->audits = $this->createMock(originalClassName: TaskAuditMapper::class);
		// Sequential ids in insertion order, so a test can address "the
		// second fixture" by id.
		$next = 0;
		$this->tasks->method('insert')->willReturnCallback(
			static function (Task $task) use (&$next): Task {
				$next++;
				$task->setId($next);

				return $task;
			}
		);
		$this->audits->method('insert')->willReturnArgument(0);
	}//end setUp()

	/**
	 * A step over a config reporting the flag as given.
	 *
	 * @param bool $enabled The flag value.
	 *
	 * @return SeedTaskFixtures The step.
	 */
	private function step(bool $enabled): SeedTaskFixtures {
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueBool')->with('openregister', SeedTaskFixtures::FLAG, false)->willReturn($enabled);

		return new SeedTaskFixtures(
			appConfig: $config,
			tasks: $this->tasks,
			candidates: $this->candidates,
			audits: $this->audits,
			logger: new NullLogger()
		);
	}//end step()

	/**
	 * OFF BY DEFAULT: nothing is read, nothing is written, and the log says so.
	 *
	 * @return void
	 */
	public function testWithTheFlagOffTheStepWritesNothing(): void {
		$this->tasks->expects($this->never())->method('findByUuid');
		$this->tasks->expects($this->never())->method('insert');
		$this->audits->expects($this->never())->method('insert');
		$output = $this->createMock(originalClassName: IOutput::class);
		$output->expects($this->once())->method('info')->with($this->stringContains('skipped'));

		$this->step(enabled: false)->run(output: $output);
	}//end testWithTheFlagOffTheStepWritesNothing()

	/**
	 * With the flag on, the five design.md groups land: one with no run,
	 * one delegated on a run with enforcing expiry, one agent task with a
	 * typed checklist, one completed 'approved', one terminated by
	 * propagation; six audit entries, exactly one of them a denial; and the
	 * candidate index is written for every task.
	 *
	 * @return void
	 */
	public function testWithTheFlagOnTheFiveGroupsAreSeeded(): void {
		$this->tasks->method('findByUuid')->willThrowException(new DoesNotExistException('absent'));
		$inserted = [];
		$this->candidates->method('replaceForTask')->willReturnCallback(
			function (int $taskId, array $candidates) use (&$inserted): void {
				// Recorded per replaceForTask so the assertions below can
				// read what was inserted without a second insert matcher.
				$inserted[$taskId] = $candidates;
			}
		);
		$audits = [];
		$seeded = [];
		$this->audits->method('insert')->willReturnCallback(
			static function (TaskAudit $entry) use (&$audits): TaskAudit {
				$audits[] = $entry;

				return $entry;
			}
		);
		$output = $this->createMock(originalClassName: IOutput::class);
		$output->expects($this->once())->method('info')->with($this->stringContains('5 seeded'));

		$step = $this->step(enabled: true);
		$step->run(output: $output);

		// Five candidate-index rewrites, one per fixture, ids 1..5.
		$this->assertSame([1, 2, 3, 4, 5], array_keys($inserted));

		// Read the fixtures back through the step's own table.
		$byUuid = [];
		$reflection = new \ReflectionMethod($step, 'fixtures');
		foreach ($reflection->invoke($step) as $fixture) {
			$task = new Task();
			$task->hydrate($fixture['task']);
			$task->setUuid((string)$fixture['uuid']);
			$byUuid[(string)$fixture['uuid']] = $task;
			$seeded[] = $task;
		}

		$this->assertCount(5, $seeded);

		$pooled = $byUuid['00000000-0000-0000-0000-000000000001'];
		$this->assertNull($pooled->getRunUuid());
		$this->assertNull($pooled->getAssignee());
		$this->assertSame(Task::PERFORMER_GROUP, $pooled->getPerformerType());

		$delegated = $byUuid['00000000-0000-0000-0000-000000000002'];
		$this->assertSame('00000000-0000-0000-0000-0000000000f1', $delegated->getRunUuid());
		$this->assertSame('EXAMPLE_DIRECTOR_USER', $delegated->getOnBehalfOf());
		$this->assertNotNull($delegated->getExpiresAt());

		$agent = $byUuid['00000000-0000-0000-0000-000000000003'];
		$this->assertSame(Task::PERFORMER_AGENT, $agent->getPerformerType());
		$this->assertCount(3, $agent->getChecklist());

		$this->assertTrue($byUuid['00000000-0000-0000-0000-000000000004']->getIsTerminal());
		$this->assertSame('approved', $byUuid['00000000-0000-0000-0000-000000000004']->getOutcome());
		$this->assertSame(Task::STATE_TERMINATED, $byUuid['00000000-0000-0000-0000-000000000005']->getState());

		$this->assertCount(6, $audits);
		$denials = array_filter($audits, static fn (TaskAudit $entry): bool => $entry->getAuthorized() === false);
		$this->assertCount(1, $denials);
	}//end testWithTheFlagOnTheFiveGroupsAreSeeded()

	/**
	 * IDEMPOTENT ON UUID: a fixture that exists is left exactly as it is.
	 *
	 * @return void
	 */
	public function testAnExistingFixtureIsLeftAlone(): void {
		$this->tasks->method('findByUuid')->willReturn(new Task());
		$this->tasks->expects($this->never())->method('insert');
		$this->audits->expects($this->never())->method('insert');
		$output = $this->createMock(originalClassName: IOutput::class);
		$output->expects($this->once())->method('info')->with($this->stringContains('0 seeded, 5 already present'));

		$this->step(enabled: true)->run(output: $output);
	}//end testAnExistingFixtureIsLeftAlone()

	/**
	 * One failing fixture is reported and does not abort the others.
	 *
	 * @return void
	 */
	public function testAFailingFixtureIsReportedAndTheRestContinue(): void {
		$this->tasks->method('findByUuid')->willThrowException(new DoesNotExistException('absent'));
		$this->candidates->method('replaceForTask')->willReturnCallback(
			static function (int $taskId): void {
				if ($taskId === 2) {
					throw new \RuntimeException('index down');
				}
			}
		);
		$output = $this->createMock(originalClassName: IOutput::class);
		$output->expects($this->once())->method('warning')->with($this->stringContains('index down'));
		$output->expects($this->once())->method('info')->with($this->stringContains('4 seeded'));

		$this->step(enabled: true)->run(output: $output);
		$this->assertSame('Seed the task fixtures (flow-task-entity)', $this->step(enabled: true)->getName());
	}//end testAFailingFixtureIsReportedAndTheRestContinue()
}//end class
