<?php
/**
 * Unit tests for the RemoveRetiredCronJobs repair step.
 *
 * The step exists because a class rename orphans its `oc_jobs` row:
 * `info.xml`'s `<job>` entries ADD registrations on upgrade and never remove
 * one whose class disappeared. Measured on a live instance carrying the
 * equivalent opencatalogi move — `oc_jobs` held `Cron\DirectorySync` beside
 * its replacement.
 *
 * A repair step must fail loudly in tests and quietly in production: it runs
 * during `occ upgrade`, so an exception here aborts an upgrade. These tests
 * assert on WHICH strings are passed and on continue-after-failure, because
 * both are invisible at runtime — the step has no return value, and one that
 * removed the wrong names would look identical to one that worked.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Repair;

use OCA\OpenRegister\Repair\RemoveRetiredCronJobs;
use OCP\BackgroundJob\IJobList;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Repair\RemoveRetiredCronJobs
 *
 * @spec exclude No canonical spec covers the OCA\OpenRegister\Cron ->
 *  OCA\OpenRegister\BackgroundJob move; ADR-100 Decision 3 is an architecture record,
 *  not a capability spec. Pointing this at an existing spec would report
 *  conformance to a requirement that says nothing about it.
 */
final class RemoveRetiredCronJobsTest extends TestCase {

	/**
	 * Every removed name is an OLD one, in the retired Cron namespace.
	 *
	 * Asserts the ARGUMENTS, not a call count. The whole value of the step is
	 * which strings it passes: a version that removed the NEW class names would
	 * satisfy a count-only assertion while deleting the registrations the app
	 * depends on.
	 *
	 * @return void
	 */
	public function testRemovesOnlyRetiredCronClasses(): void {
		$removed = [];

		$jobList = $this->createMock(IJobList::class);
		$jobList->method('remove')->willReturnCallback(
			static function ($class) use (&$removed): void {
				$removed[] = $class;
			}
		);

		$step = new RemoveRetiredCronJobs($jobList, $this->createMock(LoggerInterface::class));
		$step->run($this->createMock(IOutput::class));

		$this->assertNotEmpty($removed, 'the step must remove at least one retired registration');
		foreach ($removed as $class) {
			$this->assertStringContainsString('\\Cron\\', $class, 'only retired Cron classes may be removed: ' . $class);
			$this->assertStringNotContainsString(
				'BackgroundJob',
				$class,
				'a live BackgroundJob registration must never be removed: ' . $class
			);
		}

	}//end testRemovesOnlyRetiredCronClasses()

	/**
	 * The retired class this app actually registered is among them.
	 *
	 * Without this, the anti-widening test above would pass on a step that
	 * removed nothing relevant at all.
	 *
	 * @return void
	 */
	public function testRemovesTheRegisteredRetiredClass(): void {
		$removed = [];

		$jobList = $this->createMock(IJobList::class);
		$jobList->method('remove')->willReturnCallback(
			static function ($class) use (&$removed): void {
				$removed[] = $class;
			}
		);

		$step = new RemoveRetiredCronJobs($jobList, $this->createMock(LoggerInterface::class));
		$step->run($this->createMock(IOutput::class));

		$this->assertContains('OCA\\OpenRegister\\Cron\\LogCleanUpTask', $removed);

	}//end testRemovesTheRegisteredRetiredClass()

	/**
	 * A failure on one class does not abort the step or the upgrade.
	 *
	 * A repair step that raises takes the whole `occ upgrade` with it. Trading
	 * a dormant orphaned row for an instance that will not start is the worse
	 * outcome, so the step reports and continues — and "continues" is exactly
	 * the behaviour a later refactor would quietly drop.
	 *
	 * @return void
	 */
	public function testAFailureOnOneClassDoesNotStopTheRest(): void {
		$attempted = [];

		$jobList = $this->createMock(IJobList::class);
		$jobList->method('remove')->willReturnCallback(
			static function ($class) use (&$attempted): void {
				$attempted[] = $class;
				throw new RuntimeException('database is gone');
			}
		);

		$step = new RemoveRetiredCronJobs($jobList, $this->createMock(LoggerInterface::class));
		$step->run($this->createMock(IOutput::class));

		$this->assertContains('OCA\\OpenRegister\\Cron\\WebhookRetryJob', $attempted, 'every class must be attempted even when each one throws');

	}//end testAFailureOnOneClassDoesNotStopTheRest()

	/**
	 * The step is a repair step and names itself.
	 *
	 * @return void
	 */
	public function testItIsARepairStepWithAName(): void {
		$step = new RemoveRetiredCronJobs(
			$this->createMock(IJobList::class),
			$this->createMock(LoggerInterface::class)
		);

		$this->assertInstanceOf(IRepairStep::class, $step);
		$this->assertNotSame('', trim($step->getName()));

	}//end testItIsARepairStepWithAName()
}//end class
