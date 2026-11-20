<?php

/**
 * JobRunRecordingInterface is the run recorder's port, bound to the run history.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Kernel
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Kernel;

use OCA\OpenRegister\BackgroundJob\RecordedJobRuntime;
use OCA\OpenRegister\BackgroundJob\RecordedQueuedJob;
use OCA\OpenRegister\Db\JobRun;
use OCA\OpenRegister\Kernel\Contracts\Core\JobRunRecordingInterface;
use OCA\OpenRegister\Service\ContractBindings;
use OCA\OpenRegister\Service\Operations\JobRunContext;
use OCA\OpenRegister\Service\Operations\JobRunRecorder;
use OCA\OpenRegister\Service\Operations\JobRunRecorderInterface;
use OCA\OpenRegister\Service\Operations\JobRunStart;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * The recorded job base asks the Core port; the run recorder answers both names.
 */
final class JobRunRecordingBindingTest extends TestCase {

	public function testThePortAndTheRecorderInterfaceResolveToTheSameRecorder(): void {
		$this->assertSame(JobRunRecorder::class, ContractBindings::BINDINGS[JobRunRecordingInterface::class]);
		$this->assertSame(JobRunRecorder::class, ContractBindings::BINDINGS[JobRunRecorderInterface::class]);
		$this->assertTrue(is_subclass_of(JobRunRecorderInterface::class, JobRunRecordingInterface::class));
	}

	public function testTheRunCausesKeepTheirValues(): void {
		$this->assertSame('schedule', JobRunContext::CAUSE_SCHEDULE);
		$this->assertSame('manual', JobRunContext::CAUSE_MANUAL);
		$this->assertSame(JobRunContext::CAUSE_SCHEDULE, JobRun::CAUSE_SCHEDULE);
		$this->assertSame(JobRunContext::CAUSE_MANUAL, JobRun::CAUSE_MANUAL);
		$this->assertSame(JobRun::CAUSE_SCHEDULE, (new JobRunContext())->cause);
	}

	public function testAQueuedJobRecordsThroughThePortWithItsCauseAndActor(): void {
		$starts   = [];
		$recorder = $this->createMock(JobRunRecordingInterface::class);
		$recorder->expects($this->exactly(2))->method('around')->willReturnCallback(
			static function (JobRunStart $start, callable $work) use (&$starts): mixed {
				$starts[] = $start;
				return $work();
			}
		);
		$ran = [];
		$job = new class(new RecordedJobRuntime($this->createMock(ITimeFactory::class), $recorder), $ran) extends RecordedQueuedJob {
			private array $ran;

			public function __construct(RecordedJobRuntime $runtime, array &$ran) {
				parent::__construct(time: $runtime->time, recorder: $runtime->recorder);
				$this->ran = &$ran;
			}

			protected function runRecorded(mixed $argument): void {
				$this->ran[] = $argument;
			}
		};

		$runner = new \ReflectionMethod($job, 'run');
		$runner->invoke($job, ['actor' => 'admin']);
		$runner->invoke($job, ['registerId' => 4]);

		$this->assertSame([['actor' => 'admin'], ['registerId' => 4]], $ran);
		$this->assertSame(JobRun::CAUSE_MANUAL, $starts[0]->context->cause);
		$this->assertSame('admin', $starts[0]->context->actor);
		$this->assertSame(JobRun::CAUSE_SCHEDULE, $starts[1]->context->cause);
		$this->assertNull($starts[1]->context->actor);
		$this->assertSame($job::class, $starts[0]->jobClass);
	}
}//end class
