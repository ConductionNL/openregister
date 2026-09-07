<?php

/**
 * Giving existing runs the one subject they can honestly be said to have declared.
 *
 * 🔴 A REPAIR STEP RUNS AT `occ upgrade` AND NOWHERE ELSE, and it warns without
 * failing. That combination is why it is tested here rather than trusted: a
 * repair that throws reports itself skipped and lets the upgrade go green
 * having done nothing, which is exactly how thirteen migrations once shipped
 * and never ran.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Repair
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

namespace Unit\Repair;

use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Repair\SeedFlowRunTriggerSubject;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for {@see SeedFlowRunTriggerSubject}.
 *
 * @covers \OCA\OpenRegister\Repair\SeedFlowRunTriggerSubject
 * @uses \OCA\OpenRegister\Service\Flow\FlowRunSubjects
 * @uses \OCA\OpenRegister\Db\FlowRun
 */
final class SeedFlowRunTriggerSubjectTest extends TestCase {

	/**
	 * Every run the mapper was asked to store.
	 *
	 * @var array<int, FlowRun>
	 */
	private array $stored = [];

	/**
	 * Everything the step printed to the upgrade output.
	 *
	 * @var array<int, string>
	 */
	private array $said = [];

	/**
	 * The (limit, offset) pairs the mapper was asked for.
	 *
	 * @var array<int, array<int, int>>
	 */
	private array $pages = [];

	/**
	 * A run that fired on an object.
	 *
	 * @param string $uuid     The run uuid.
	 * @param string $subject  What it fired on, or '' for nothing.
	 * @param string $register The subject's register.
	 * @param string $schema   The subject's schema.
	 *
	 * @return FlowRun The run.
	 */
	private function aRun(string $uuid, string $subject, string $register = '', string $schema = ''): FlowRun {
		$run = new FlowRun();
		$run->setUuid($uuid);
		$run->setSubjectUuid($subject);
		$run->setSubjectRegister($register);
		$run->setSubjectSchema($schema);

		return $run;
	}//end aRun()

	/**
	 * The step, over a mapper serving the given pages of runs.
	 *
	 * @param array<int, array<int, FlowRun>>|null $pages One entry per page the
	 *                                                    mapper should serve,
	 *                                                    or null when the
	 *                                                    container cannot
	 *                                                    resolve it at all.
	 *
	 * @return SeedFlowRunTriggerSubject The step.
	 */
	private function step(?array $pages): SeedFlowRunTriggerSubject {
		$container = $this->createMock(ContainerInterface::class);

		if ($pages === null) {
			$container->method('get')->willThrowException(new RuntimeException('no such table'));
		} else {
			$mapper = $this->createMock(FlowRunMapper::class);
			// ⚠️ THE REAL SIGNATURE IS (flowId, status, limit, offset, ...).
			// Taking the first two arguments as (limit, offset) reads null for
			// both, which pins the page index at 0 and loops forever — a hang,
			// not a failure. The step calls it with NAMED arguments, so only
			// the double can get this wrong.
			$mapper->method('findAllRuns')->willReturnCallback(
				function (
					?string $flowId = null,
					?string $status = null,
					int $limit = 50,
					int $offset = 0
				) use ($pages): array {
					$this->pages[] = [$limit, $offset];
					$index = 0;
					if ($limit > 0) {
						$index = intdiv($offset, $limit);
					}

					return ($pages[$index] ?? []);
				}
			);
			$mapper->method('update')->willReturnCallback(
				function (FlowRun $run): FlowRun {
					$this->stored[] = $run;

					return $run;
				}
			);

			$container->method('get')->willReturn($mapper);
		}

		return new SeedFlowRunTriggerSubject(
			$container,
			$this->createMock(LoggerInterface::class)
		);
	}//end step()

	/**
	 * An upgrade output that records what it was told.
	 *
	 * ⚠️ NOT named `output()`: that is FINAL on PHPUnit's TestCase, and
	 * overriding it is a fatal error rather than a failing test — the same trap
	 * `run()` sets, one method along.
	 *
	 * @return IOutput The output.
	 */
	private function upgradeOutput(): IOutput {
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(
			function (string $message): void {
				$this->said[] = $message;
			}
		);

		return $output;
	}//end upgradeOutput()

	/**
	 * The step has the name `occ upgrade` prints.
	 *
	 * @return void
	 */
	public function testTheStepIsNamed(): void {
		$this->assertNotSame('', $this->step([])->getName());
	}//end testTheStepIsNamed()

	/**
	 * 🔑 THE `trigger` ENTRY, FROM THE COLUMN THE RUN ALREADY CARRIED.
	 *
	 * That column records what the run actually fired on, so calling it the
	 * run's `trigger` subject states a fact rather than inventing one.
	 *
	 * @return void
	 */
	public function testARunThatFiredOnAnObjectGetsATriggerSubject(): void {
		$this->step([[$this->aRun('run-1', 'obj-1', '3', '7')]])->run($this->upgradeOutput());

		$this->assertCount(1, $this->stored);
		$subjects = $this->stored[0]->getSubjects();
		$this->assertSame('obj-1', $subjects['trigger']['uuid']);
		$this->assertSame('3', $subjects['trigger']['register']);
		$this->assertSame('7', $subjects['trigger']['schema']);
	}//end testARunThatFiredOnAnObjectGetsATriggerSubject()

	/**
	 * A run that fired on nothing is left alone.
	 *
	 * @return void
	 */
	public function testARunThatFiredOnNothingIsLeftAlone(): void {
		$this->step([[$this->aRun('run-1', '   ')]])->run($this->upgradeOutput());

		$this->assertSame([], $this->stored);
	}//end testARunThatFiredOnNothingIsLeftAlone()

	/**
	 * 🔴 A REPAIR MUST NOT OVERWRITE WHAT A FLOW AUTHOR SAID.
	 *
	 * A run that already declares a subject keeps it, even when the declared
	 * role is not `trigger` and the trigger column holds something else.
	 *
	 * @return void
	 */
	public function testARunThatAlreadyDeclaredSomethingIsNotTouched(): void {
		$run = $this->aRun('run-1', 'obj-1');
		$run->setSubjects(['case' => ['uuid' => 'obj-9']]);

		$this->step([[$run]])->run($this->upgradeOutput());

		$this->assertSame([], $this->stored);
	}//end testARunThatAlreadyDeclaredSomethingIsNotTouched()

	/**
	 * 🔴 EVERY PAGE, NOT THE FIRST ONE.
	 *
	 * A mapper's default limit would silently seed the first page and report
	 * success — the shape that left 119 of 219 flows unversioned once already.
	 *
	 * @return void
	 */
	public function testEveryPageIsSeededNotJustTheFirst(): void {
		$first = [];
		for ($i = 0; $i < 500; $i++) {
			$first[] = $this->aRun('run-' . $i, 'obj-' . $i);
		}

		$this->step([$first, [$this->aRun('run-500', 'obj-500')]])->run($this->upgradeOutput());

		$this->assertCount(501, $this->stored, 'the second page must be read too');
		$this->assertSame([[500, 0], [500, 500]], $this->pages);
	}//end testEveryPageIsSeededNotJustTheFirst()

	/**
	 * 🔴 A RUN THAT CANNOT BE SEEDED IS REPORTED AND SKIPPED, NOT FATAL.
	 *
	 * One unreadable row must not abandon every row after it, and must not fail
	 * the upgrade.
	 *
	 * @return void
	 */
	public function testOneBadRunDoesNotStopTheRest(): void {
		$bad = $this->aRun('run-bad', 'obj-bad');
		// An empty role is refused by FlowRunSubjects, which is the throw this
		// step has to survive. Recording under the trigger role with an empty
		// uuid is the same refusal from the other side.
		$bad->setSubjectUuid('obj-bad');

		$mapperThrows = $this->createMock(FlowRunMapper::class);
		$mapperThrows->method('findAllRuns')->willReturn([$bad, $this->aRun('run-ok', 'obj-ok')]);
		$mapperThrows->method('update')->willReturnCallback(
			function (FlowRun $run): FlowRun {
				if ((string)$run->getUuid() === 'run-bad') {
					throw new RuntimeException('row is locked');
				}

				$this->stored[] = $run;

				return $run;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($mapperThrows);

		(new SeedFlowRunTriggerSubject($container, $this->createMock(LoggerInterface::class)))
			->run($this->upgradeOutput());

		$this->assertCount(1, $this->stored, 'the run after the bad one is still seeded');
		$this->assertStringContainsString('1 could not be read', implode(' ', $this->said));
	}//end testOneBadRunDoesNotStopTheRest()

	/**
	 * 🔴 A MISSING TABLE SKIPS THE STEP, IT DOES NOT FAIL THE UPGRADE.
	 *
	 * This runs during install, when the tables may not exist yet.
	 *
	 * @return void
	 */
	public function testAnUnresolvableMapperSkipsInsteadOfFailing(): void {
		$this->step(null)->run($this->upgradeOutput());

		$this->assertSame([], $this->stored);
		$this->assertStringContainsString('skipped', implode(' ', $this->said));
	}//end testAnUnresolvableMapperSkipsInsteadOfFailing()

	/**
	 * The step reports what it did, so an upgrade log answers "did it run".
	 *
	 * @return void
	 */
	public function testItReportsHowManyItSeeded(): void {
		$this->step([[$this->aRun('run-1', 'obj-1'), $this->aRun('run-2', '')]])->run($this->upgradeOutput());

		$this->assertStringContainsString('1 run(s)', implode(' ', $this->said));
	}//end testItReportsHowManyItSeeded()
}//end class
