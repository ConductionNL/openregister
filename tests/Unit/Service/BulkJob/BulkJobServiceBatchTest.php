<?php

/**
 * Unit tests for walking one batch of a bulk job and for the instance limits.
 *
 * A batch runs as the account that created the job; when that account is gone
 * the job fails with the reason in its report instead of running as nobody.
 * A limit configured below one falls back to its default.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\BulkJob
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/bulk-action-jobs/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\BulkJob;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\BulkJob;
use OCA\OpenRegister\Db\BulkJobMapper;
use OCA\OpenRegister\Db\BulkJobMemberMapper;
use OCA\OpenRegister\Service\BulkActionRegistry;
use OCA\OpenRegister\Service\BulkJob\BulkJobActionCall;
use OCA\OpenRegister\Service\BulkJob\BulkJobActions;
use OCA\OpenRegister\Service\BulkJob\BulkJobEnvironment;
use OCA\OpenRegister\Service\BulkJob\BulkJobExecutor;
use OCA\OpenRegister\Service\BulkJob\BulkJobSelection;
use OCA\OpenRegister\Service\BulkJob\BulkJobService;
use OCA\OpenRegister\Service\BulkJob\BulkJobStore;
use OCA\OpenRegister\Service\BulkJob\BulkSelectionResolver;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(BulkJobService::class)]
#[UsesClass(BulkJob::class)]
#[UsesClass(BulkJobStore::class)]
#[UsesClass(BulkJobActions::class)]
#[UsesClass(BulkJobEnvironment::class)]
#[UsesClass(BulkJobSelection::class)]
#[UsesClass(BulkJobActionCall::class)]
#[UsesClass(\OCA\OpenRegister\Kernel\Contracts\Value\BulkBatchDefaults::class)]
#[UsesClass(\OCA\OpenRegister\Kernel\Contracts\Value\BulkSaveOptions::class)]
#[UsesClass(\OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget::class)]
#[UsesClass(\OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions::class)]
#[UsesClass(\OCA\OpenRegister\Kernel\Contracts\Value\SaveActor::class)]
#[UsesClass(\OCA\OpenRegister\Kernel\Contracts\Value\SavePolicy::class)]
final class BulkJobServiceBatchTest extends TestCase {

	private BulkJobMapper&MockObject $jobMapper;

	private BulkJobExecutor&MockObject $executor;

	private ObjectOperationsInterface&MockObject $objects;

	private IUserManager&MockObject $users;

	private IAppConfig&MockObject $appConfig;

	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->jobMapper = $this->createMock(BulkJobMapper::class);
		$this->executor = $this->createMock(BulkJobExecutor::class);
		$this->objects = $this->createMock(ObjectOperationsInterface::class);
		$this->users = $this->createMock(IUserManager::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function service(): BulkJobService {
		return new BulkJobService(
			new BulkJobStore($this->jobMapper, $this->createMock(BulkJobMemberMapper::class), $this->createMock(IJobList::class), $this->logger),
			new BulkJobActions($this->createMock(BulkActionRegistry::class), $this->createMock(BulkSelectionResolver::class), $this->executor),
			new BulkJobEnvironment($this->objects, $this->users, $this->appConfig)
		);
	}

	private function job(): BulkJob {
		$job = new BulkJob();
		$job->setId(11);
		$job->setUuid('job-uuid');
		$job->setState(BulkJob::STATE_RUNNING);
		$job->setStartedBy('coordinator');
		$job->setReport(['succeeded' => 3]);

		return $job;
	}

	public function testABatchRunsAsTheAccountThatCreatedTheJob(): void {
		$job = $this->job();
		$actor = $this->createMock(IUser::class);
		$this->users->method('get')->with('coordinator')->willReturn($actor);
		$this->objects->expects($this->once())->method('runAs')->with($actor, $this->isType('callable'))
			->willReturnCallback(static fn (IUser $user, callable $operation) => $operation());
		$this->executor->expects($this->once())->method('processBatch')->with($job, 40)->willReturn(true);
		$this->jobMapper->expects($this->never())->method('save');

		$this->assertTrue($this->service()->processBatch($job, 40));
	}

	public function testABatchAnswersWhetherMembersRemain(): void {
		$this->users->method('get')->willReturn($this->createMock(IUser::class));
		$this->objects->method('runAs')->willReturnCallback(static fn (IUser $user, callable $operation) => $operation());
		$this->executor->method('processBatch')->willReturn(false);

		$this->assertFalse($this->service()->processBatch($this->job(), 25));
	}

	public function testAJobWhoseAccountIsGoneFailsWithTheReasonAndRunsNothing(): void {
		$job = $this->job();
		$this->users->method('get')->with('coordinator')->willReturn(null);
		$this->objects->expects($this->never())->method('runAs');
		$this->executor->expects($this->never())->method('processBatch');
		$this->jobMapper->expects($this->once())->method('save')->with($job)->willReturnArgument(0);
		$this->logger->expects($this->once())->method('error')->with(
			"[BulkJobService] The job's actor no longer exists",
			['job' => 'job-uuid', 'actor' => 'coordinator']
		);

		$this->assertFalse($this->service()->processBatch($job, 25));
		$this->assertSame(BulkJob::STATE_FAILED, $job->getState());
		$this->assertSame(
			['succeeded' => 3, 'fatal' => 'the account that created this job no longer exists'],
			$job->getReport()
		);
	}

	/**
	 * @return array<string, array{string, string, int, int}>
	 */
	public static function limits(): array {
		return [
			'ceiling configured' => ['getCeiling', BulkJobService::CEILING_KEY, 250, 250],
			'ceiling zero' => ['getCeiling', BulkJobService::CEILING_KEY, 0, BulkJobService::CEILING_DEFAULT],
			'batch configured' => ['getBatchSize', BulkJobService::BATCH_SIZE_KEY, 50, 50],
			'batch negative' => ['getBatchSize', BulkJobService::BATCH_SIZE_KEY, -5, BulkJobService::BATCH_SIZE_DEFAULT],
			'undo configured' => ['getUndoCeiling', BulkJobService::UNDO_CEILING_KEY, 2048, 2048],
			'undo zero' => ['getUndoCeiling', BulkJobService::UNDO_CEILING_KEY, 0, BulkJobService::UNDO_CEILING_DEFAULT],
		];
	}

	#[DataProvider('limits')]
	public function testALimitBelowOneFallsBackToItsDefault(string $method, string $key, int $configured, int $expected): void {
		$this->appConfig->method('getValueInt')->willReturnCallback(
			static fn (string $app, string $asked, int $default) => ($app === 'openregister' && $asked === $key) ? $configured : $default
		);

		$this->assertSame($expected, $this->service()->{$method}());
	}

	public function testSelectionAndActionCallCarryTheirArguments(): void {
		$service = $this->service();

		$selection = $service->selection(['status' => 'open'], 3, 7);
		$call = $service->actionCall('openregister:assign', ['to' => 'bob']);

		$this->assertSame(['status' => 'open'], $selection->criteria);
		$this->assertSame(3, $selection->registerId);
		$this->assertSame(7, $selection->schemaId);
		$this->assertSame('openregister:assign', $call->actionId);
		$this->assertSame(['to' => 'bob'], $call->parameters);
	}
}
