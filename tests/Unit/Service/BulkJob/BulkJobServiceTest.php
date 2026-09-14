<?php

/**
 * Unit tests for BulkJobService — the lifecycle of a bulk act.
 *
 * Covers the refusals that happen before anything is written: a selection
 * above the instance ceiling, a commit with no reason where the action
 * requires one, and a selection that is neither a list of ids nor a query.
 * Then the two properties a long job depends on: the delta a query-backed
 * selection reports at commit, and a retry that does not act twice.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\BulkJob
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/bulk-action-jobs/specs/bulk-action-jobs/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\BulkJob;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use InvalidArgumentException;
use OCA\OpenRegister\BackgroundJob\BulkJobRunner;
use OCA\OpenRegister\BulkAction\BulkActionInterface;
use OCA\OpenRegister\Db\BulkJob;
use OCA\OpenRegister\Db\BulkJobMapper;
use OCA\OpenRegister\Db\BulkJobMember;
use OCA\OpenRegister\Db\BulkJobMemberMapper;
use OCA\OpenRegister\Exception\BulkJobRefusedException;
use OCA\OpenRegister\Service\BulkActionRegistry;
use OCA\OpenRegister\Service\BulkJob\BulkJobExecutor;
use OCA\OpenRegister\Service\BulkJob\BulkJobService;
use OCA\OpenRegister\Service\BulkJob\BulkSelectionResolver;
use OCA\OpenRegister\Service\ObjectService;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class BulkJobServiceTest extends TestCase {

	private BulkJobMapper $jobMapper;

	private BulkJobMemberMapper $memberMapper;

	private BulkActionRegistry $registry;

	private BulkSelectionResolver $resolver;

	private BulkJobExecutor $executor;

	private IJobList $jobList;

	private IAppConfig $appConfig;

	protected function setUp(): void {
		parent::setUp();

		$this->jobMapper = $this->createMock(BulkJobMapper::class);
		$this->memberMapper = $this->createMock(BulkJobMemberMapper::class);
		$this->registry = $this->createMock(BulkActionRegistry::class);
		$this->resolver = $this->createMock(BulkSelectionResolver::class);
		$this->executor = $this->createMock(BulkJobExecutor::class);
		$this->jobList = $this->createMock(IJobList::class);
		$this->appConfig = $this->createMock(IAppConfig::class);

		$this->jobMapper->method('save')->willReturnArgument(0);
	}

	private function service(int $ceiling = 1000): BulkJobService {
		$this->appConfig->method('getValueInt')->willReturnCallback(
			static function (string $app, string $key, int $default = 0) use ($ceiling): int {
				if ($key === BulkJobService::CEILING_KEY) {
					return $ceiling;
				}

				return $default;
			}
		);

		return new BulkJobService(
			$this->jobMapper,
			$this->memberMapper,
			$this->registry,
			$this->resolver,
			$this->executor,
			$this->createMock(ObjectService::class),
			$this->createMock(IUserManager::class),
			$this->jobList,
			$this->appConfig,
			new NullLogger()
		);
	}

	private function action(bool $requiresJustification = false): BulkActionInterface {
		$action = $this->createMock(BulkActionInterface::class);
		$action->method('getId')->willReturn('openregister:assign');
		$action->method('getGuards')->willReturn([]);
		$action->method('requiresJustification')->willReturn($requiresJustification);

		return $action;
	}

	private function job(string $state = BulkJob::STATE_PREVIEWED, string $selectionType = BulkJob::SELECTION_IDS): BulkJob {
		$job = new BulkJob();
		$job->setId(11);
		$job->setUuid('job-uuid');
		$job->setAction('openregister:assign');
		$job->setSelectionType($selectionType);
		$job->setSelection(['query' => ['status' => 'open']]);
		$job->setState($state);
		$job->setStartedBy('coordinator');
		$job->setTotal(400);

		return $job;
	}

	public function testASelectionAboveTheCeilingIsRefusedAtCreationNamingBoth(): void {
		$this->registry->method('get')->willReturn($this->action());
		$this->resolver->method('resolveUuids')->willReturn(array_map(static fn (int $i): string => 'o'.$i, range(1, 4000)));

		$this->jobMapper->expects($this->never())->method('createFromArray');

		try {
			$this->service(1000)->create('openregister:assign', [], ['ids' => ['x']], null, 'coordinator');
			$this->fail('The ceiling should have refused this selection.');
		} catch (BulkJobRefusedException $exception) {
			$this->assertSame('ceiling', $exception->getReason());
			$this->assertSame(['ceiling' => 1000, 'count' => 4000], $exception->getDetails());
			$this->assertStringContainsString('at most 1000', $exception->getMessage());
			$this->assertStringContainsString('holds 4000', $exception->getMessage());
		}
	}

	public function testASelectionAtTheCeilingIsAccepted(): void {
		$this->registry->method('get')->willReturn($this->action());
		$this->resolver->method('resolveUuids')->willReturn(array_map(static fn (int $i): string => 'o'.$i, range(1, 1000)));
		$this->resolver->method('hydrate')->willReturn([]);
		$this->jobMapper->method('createFromArray')->willReturnCallback(
			static function (array $data): BulkJob {
				$job = new BulkJob();
				$job->hydrate($data);
				$job->setId(11);

				return $job;
			}
		);

		$job = $this->service(1000)->create('openregister:assign', [], ['ids' => ['x']], null, 'coordinator');

		$this->assertSame(1000, $job->getTotal());
		$this->assertSame(BulkJob::STATE_PREVIEWED, $job->getState());
	}

	public function testACommitWithoutAReasonIsRefusedAndNothingIsQueued(): void {
		$this->registry->method('get')->willReturn($this->action(true));
		$this->jobList->expects($this->never())->method('add');

		try {
			$this->service()->commit($this->job(), null);
			$this->fail('The missing justification should have refused this commit.');
		} catch (BulkJobRefusedException $exception) {
			$this->assertSame('justification-required', $exception->getReason());
			$this->assertStringContainsString('openregister:assign', $exception->getMessage());
			$this->assertStringContainsString('Nothing was modified.', $exception->getMessage());
		}
	}

	public function testACommitWithAReasonQueuesTheWork(): void {
		$this->registry->method('get')->willReturn($this->action(true));

		$queued = [];
		$this->jobList->method('add')->willReturnCallback(
			static function (string $class, $argument) use (&$queued): void {
				$queued[] = [$class, $argument];
			}
		);

		$job = $this->service()->commit($this->job(), 'Hans left on the 30th.');

		$this->assertSame(BulkJob::STATE_RUNNING, $job->getState());
		$this->assertSame('Hans left on the 30th.', $job->getJustification());
		$this->assertSame([[BulkJobRunner::class, ['job_id' => 11]]], $queued);
	}

	public function testAQuerySelectionReportsItsDeltaAtCommit(): void {
		$this->registry->method('get')->willReturn($this->action());

		$known = array_map(static fn (int $i): string => 'o'.$i, range(1, 400));
		$current = array_map(static fn (int $i): string => 'o'.$i, range(1, 406));

		$this->memberMapper->method('findUuidsByJob')->willReturn($known);
		$this->resolver->method('resolveUuids')->willReturn($current);
		$this->resolver->method('hydrate')->willReturn([]);

		$added = null;
		$this->executor->method('writePreviewMembers')->willReturnCallback(
			static function (BulkJob $job, array $uuids) use (&$added): void {
				$added = $uuids;
			}
		);

		$job = $this->service()->commit($this->job(BulkJob::STATE_PREVIEWED, BulkJob::SELECTION_QUERY));

		$delta = $job->getReport()['delta'];
		$this->assertSame(400, $delta['countAtCreation']);
		$this->assertSame(406, $delta['countAtCommit']);
		$this->assertSame(6, $delta['added']);
		$this->assertSame(0, $delta['removed']);
		$this->assertCount(6, $added);
		$this->assertSame(406, $job->getTotal());
	}

	public function testARetryQueuesOnlyWhenMembersRemain(): void {
		$job = $this->job(BulkJob::STATE_FAILED);
		$job->setApplied(300);

		$member = new BulkJobMember();
		$member->setId(301);
		$member->setOutcome(BulkJobMember::OUTCOME_APPLIED);
		$this->memberMapper->method('findPendingBatch')->willReturn([$member]);

		$queued = [];
		$this->jobList->method('add')->willReturnCallback(
			static function (string $class, $argument) use (&$queued): void {
				$queued[] = $class;
			}
		);

		$retried = $this->service()->retry($job);

		$this->assertSame(BulkJob::STATE_RUNNING, $retried->getState());
		$this->assertSame(0, $retried->getCursor());
		$this->assertSame(1, $retried->getReport()['retries']);
		$this->assertSame(300, $retried->getReport()['skippedAsAlreadyApplied']);
		$this->assertSame([BulkJobRunner::class], $queued);
	}

	public function testARetryWithNothingLeftIsRefused(): void {
		$job = $this->job(BulkJob::STATE_COMPLETED);
		$job->setApplied(400);
		$this->memberMapper->method('findPendingBatch')->willReturn([]);
		$this->jobList->expects($this->never())->method('add');

		$this->expectException(BulkJobRefusedException::class);
		$this->expectExceptionMessage('nothing to retry');

		$this->service()->retry($job);
	}

	public function testARunningJobCannotBeRetried(): void {
		$this->expectException(BulkJobRefusedException::class);

		$this->service()->retry($this->job(BulkJob::STATE_RUNNING));
	}

	public function testCancellingARunningJobAsksItToStop(): void {
		$job = $this->service()->cancel($this->job(BulkJob::STATE_RUNNING));

		$this->assertSame(BulkJob::STATE_CANCELLING, $job->getState());
	}

	public function testCancellingAPreviewedJobEndsItOutright(): void {
		$job = $this->service()->cancel($this->job(BulkJob::STATE_PREVIEWED));

		$this->assertSame(BulkJob::STATE_CANCELLED, $job->getState());
	}

	public function testASelectionThatIsNeitherIdsNorAQueryIsRefused(): void {
		$this->registry->method('get')->willReturn($this->action());

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('either an ids array or a query object');

		$this->service()->create('openregister:assign', [], [], null, 'coordinator');
	}

	public function testASelectionThatIsBothIsRefused(): void {
		$this->registry->method('get')->willReturn($this->action());

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('never both');

		$this->service()->create('openregister:assign', [], ['ids' => ['a'], 'query' => []], null, 'coordinator');
	}
}
