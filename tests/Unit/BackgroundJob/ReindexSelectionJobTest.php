<?php

/**
 * Tests for ReindexSelectionJob: the run writes its progress onto its own run row.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use InvalidArgumentException;
use OCA\OpenRegister\BackgroundJob\RecordedJobRuntime;
use OCA\OpenRegister\BackgroundJob\RecordedQueuedJob;
use OCA\OpenRegister\BackgroundJob\ReindexSelectionJob;
use OCA\OpenRegister\Db\JobRun;
use OCA\OpenRegister\Db\JobRunMapper;
use OCA\OpenRegister\Service\Operations\JobAlertService;
use OCA\OpenRegister\Service\Operations\JobRunContext;
use OCA\OpenRegister\Service\Operations\JobRunRecorder;
use OCA\OpenRegister\Service\Operations\JobRunStart;
use OCA\OpenRegister\Service\Operations\RecordingRun;
use OCA\OpenRegister\Service\Operations\ReindexProgress;
use OCA\OpenRegister\Service\Operations\ReindexSelectionServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

#[CoversClass(ReindexSelectionJob::class)]
#[CoversClass(JobRunRecorder::class)]
#[UsesClass(RecordedQueuedJob::class)]
#[UsesClass(RecordedJobRuntime::class)]
#[UsesClass(JobRun::class)]
#[UsesClass(JobRunStart::class)]
#[UsesClass(JobRunContext::class)]
#[UsesClass(RecordingRun::class)]
#[UsesClass(ReindexProgress::class)]
final class ReindexSelectionJobTest extends TestCase {

	/** @var list<string|null> The details each update wrote, in order. */
	private array $writtenDetails = [];

	private function recorder(): JobRunRecorder {
		$runs = $this->createMock(JobRunMapper::class);
		$runs->method('insert')->willReturnCallback(static function (JobRun $run): JobRun {
			$run->setId(1);
			return $run;
		});
		$runs->method('update')->willReturnCallback(function (JobRun $run): JobRun {
			$this->writtenDetails[] = $run->getDetails();
			return $run;
		});

		return new JobRunRecorder($runs, $this->createMock(LoggerInterface::class), $this->createMock(JobAlertService::class));
	}

	private function job(JobRunRecorder $recorder, ReindexSelectionServiceInterface $reindex): ReindexSelectionJob {
		return new ReindexSelectionJob(new RecordedJobRuntime($this->createMock(ITimeFactory::class), $recorder), $reindex);
	}

	private function runJob(ReindexSelectionJob $job, mixed $argument): void {
		(new ReflectionMethod($job, 'run'))->invoke($job, $argument);
	}

	public function testTheRunWritesEachProgressOntoItsRowAndCompletes(): void {
		$reindex = $this->createMock(ReindexSelectionServiceInterface::class);
		$reindex->expects($this->once())->method('run')
			->willReturnCallback(function (string $runKey, string $actor, callable $onProgress): ReindexProgress {
				$this->assertSame('k1', $runKey);
				$this->assertSame('admin', $actor);
				$progress = new ReindexProgress(total: 2);
				$onProgress($progress->toArray());
				$progress->succeeded();
				$progress->failedOn(id: 'b', reason: 'gone');
				$onProgress($progress->toArray());
				return $progress;
			});

		$this->runJob(job: $this->job(recorder: $this->recorder(), reindex: $reindex), argument: ['run' => 'k1', 'actor' => 'admin']);

		// Two progress writes, then the close, which keeps the last progress.
		$this->assertCount(3, $this->writtenDetails);
		$first = json_decode((string)$this->writtenDetails[0], true);
		$this->assertSame(['total' => 2, 'processed' => 0, 'failed' => 0, 'failures' => [], 'failuresTruncated' => false], $first);
		$last = json_decode((string)$this->writtenDetails[2], true);
		$this->assertSame(2, $last['processed']);
		$this->assertSame([['id' => 'b', 'reason' => 'gone']], $last['failures']);
	}

	public function testAnArgumentWithoutTheRunAndItsAdministratorFailsTheRun(): void {
		$reindex = $this->createMock(ReindexSelectionServiceInterface::class);
		$reindex->expects($this->never())->method('run');

		$this->expectException(InvalidArgumentException::class);
		$this->runJob(job: $this->job(recorder: $this->recorder(), reindex: $reindex), argument: ['run' => 'k1']);
	}

	public function testProgressWithoutAnOpenRunWritesNothing(): void {
		$recorder = $this->recorder();

		$this->assertFalse($recorder->progress(jobClass: ReindexSelectionJob::class, details: ['processed' => 1]));
		$this->assertSame([], $this->writtenDetails);
	}

	public function testProgressAfterTheRunEndedWritesNothing(): void {
		$recorder = $this->recorder();
		$recorder->around(new JobRunStart(ReindexSelectionJob::class), static fn (): null => null);
		$closes = count($this->writtenDetails);

		$this->assertFalse($recorder->progress(jobClass: ReindexSelectionJob::class, details: ['processed' => 1]));
		$this->assertCount($closes, $this->writtenDetails);
	}

	public function testAProgressWriteThatFailsIsReportedAsNotWritten(): void {
		$runs = $this->createMock(JobRunMapper::class);
		$runs->method('insert')->willReturnArgument(0);
		$runs->method('update')->willThrowException(new RuntimeException('database gone'));
		$recorder = new JobRunRecorder($runs, $this->createMock(LoggerInterface::class), $this->createMock(JobAlertService::class));

		$written = $recorder->around(
			new JobRunStart(ReindexSelectionJob::class),
			static fn (): bool => $recorder->progress(jobClass: ReindexSelectionJob::class, details: ['processed' => 1])
		);

		$this->assertFalse($written);
	}
}
