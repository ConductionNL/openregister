<?php

/**
 * Unit tests for the inverse of a bulk job.
 *
 * Covers the five refusals and the one acceptance. A reversal is an ordinary
 * job that names its cause, over the members the original actually WROTE,
 * and it is refused, naming which reason it is, for a job still running, a
 * job whose action was never reversible, a job whose window has closed, a
 * job already being undone, and a job that wrote nothing.
 *
 * The window refusal is the one the e2e suite cannot reach, because it needs
 * a clock the HTTP surface does not expose. It is asserted here.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\BulkJob
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/undo-a-bulk-action/specs/bulk-action-jobs/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\BulkJob;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTime;
use OCA\OpenRegister\BulkAction\RestorePriorValuesAction;
use OCA\OpenRegister\BulkAction\ReversibleBulkActionInterface;
use OCA\OpenRegister\Db\BulkJob;
use OCA\OpenRegister\Db\BulkJobMapper;
use OCA\OpenRegister\Db\BulkJobMemberMapper;
use OCA\OpenRegister\Exception\BulkJobRefusedException;
use OCA\OpenRegister\Service\BulkActionRegistry;
use OCA\OpenRegister\Service\BulkJob\BulkJobExecutor;
use OCA\OpenRegister\Service\BulkJob\BulkJobReversal;
use OCA\OpenRegister\Service\BulkJob\BulkJobService;
use OCA\OpenRegister\Service\BulkJob\BulkSelectionResolver;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class BulkJobReversalTest extends TestCase {

	/**
	 * Job persistence.
	 *
	 * @var BulkJobMapper
	 */
	private BulkJobMapper $jobMapper;

	/**
	 * Member persistence.
	 *
	 * @var BulkJobMemberMapper
	 */
	private BulkJobMemberMapper $memberMapper;

	/**
	 * The action catalogue.
	 *
	 * @var BulkActionRegistry
	 */
	private BulkActionRegistry $registry;

	/**
	 * Selection resolution.
	 *
	 * @var BulkSelectionResolver
	 */
	private BulkSelectionResolver $resolver;

	/**
	 * The one executor.
	 *
	 * @var BulkJobExecutor
	 */
	private BulkJobExecutor $executor;

	/**
	 * Every job row the service asked the mapper to create.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $created = [];

	protected function setUp(): void {
		parent::setUp();

		$this->created = [];
		$this->jobMapper = $this->createMock(BulkJobMapper::class);
		$this->memberMapper = $this->createMock(BulkJobMemberMapper::class);
		$this->registry = $this->createMock(BulkActionRegistry::class);
		$this->resolver = $this->createMock(BulkSelectionResolver::class);
		$this->executor = $this->createMock(BulkJobExecutor::class);

		$this->jobMapper->method('save')->willReturnArgument(0);
		$this->jobMapper->method('createFromArray')->willReturnCallback(
			function (array $data): BulkJob {
				$this->created[] = $data;
				$job = new BulkJob();
				$job->hydrate($data);
				$job->setId(99);
				$job->setUuid('reversal-uuid');

				return $job;
			}
		);

		$restore = $this->createMock(ReversibleBulkActionInterface::class);
		$restore->method('getId')->willReturn(RestorePriorValuesAction::ID);
		$restore->method('getGuards')->willReturn([]);
		$restore->method('requiresJustification')->willReturn(true);
		$restore->method('getReversalWindow')->willReturn(604800);
		$this->registry->method('get')->willReturn($restore);
	}

	private function reversal(): BulkJobReversal {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnCallback(
			static fn (string $app, string $key, int $default = 0): int => $default
		);

		$service = new BulkJobService(
			$this->jobMapper,
			$this->memberMapper,
			$this->registry,
			$this->resolver,
			$this->executor,
			$this->createMock(ObjectService::class),
			$this->createMock(IUserManager::class),
			$this->createMock(IJobList::class),
			$appConfig,
			new NullLogger()
		);

		return new BulkJobReversal($service, $this->jobMapper, $this->memberMapper);
	}

	private function original(
		string $state = BulkJob::STATE_COMPLETED,
		?int $window = 604800,
		string $until = '+6 days'
	): BulkJob {
		$job = new BulkJob();
		$job->setId(7);
		$job->setUuid('original-uuid');
		$job->setAction('openregister:set-properties');
		$job->setSelectionType(BulkJob::SELECTION_IDS);
		$job->setRegisterId(1);
		$job->setSchemaId(2);
		$job->setState($state);
		$job->setStartedBy('coordinator');
		$job->setTotal(100);
		$job->setApplied(100);
		$job->setReversalWindow($window);

		if ($window !== null) {
			$job->setReversibleUntil(new DateTime($until));
		}

		return $job;
	}

	private function refusal(BulkJob $original, string $actor = 'fatima'): BulkJobRefusedException {
		try {
			$this->reversal()->reverse($original, $actor, 'Verkeerde filter.');
		} catch (BulkJobRefusedException $exception) {
			return $exception;
		}

		$this->fail('The reversal should have been refused.');
	}

	public function testAHundredCasesGoBackAsOneJobNamingTheOriginal(): void {
		$uuids = array_map(static fn (int $i): string => 'case-'.$i, range(1, 100));
		$this->memberMapper->method('findWrittenUuidsByJob')->willReturn($uuids);
		$this->resolver->method('resolveUuids')->willReturn($uuids);
		$this->resolver->method('hydrate')->willReturn([]);

		$original = $this->original();
		$reversal = $this->reversal()->reverse($original, 'fatima', 'De filter stond verkeerd.');

		$this->assertSame(RestorePriorValuesAction::ID, $this->created[0]['action']);
		$this->assertSame(
			[RestorePriorValuesAction::PARAM_JOB => 7],
			$this->created[0]['parameters'],
			'the reversal does not name the job it undoes'
		);
		$this->assertSame($uuids, $this->created[0]['selection']['ids']);
		$this->assertSame('fatima', $this->created[0]['startedBy'], 'the reversal runs as the person doing it');
		$this->assertSame(7, $reversal->getReversesJobId());
		$this->assertSame(99, $original->getReversedByJobId(), 'the original does not name its reversal');

		$report = ($reversal->getReport() ?? []);
		$this->assertSame('original-uuid', $report['reverses']['jobUuid']);
		$this->assertSame(100, $report['reverses']['written']);
	}

	public function testTheSelectionIsWhatTheOriginalWroteNotWhatItHeld(): void {
		// The original held a hundred members and wrote twelve. A reversal
		// over all hundred would report eighty-eight skips that were never
		// this job's business.
		$written = ['case-3', 'case-9'];
		$this->memberMapper->method('findWrittenUuidsByJob')->willReturn($written);
		$this->resolver->method('resolveUuids')->willReturn($written);
		$this->resolver->method('hydrate')->willReturn([]);

		$this->reversal()->reverse($this->original(), 'fatima', 'x');

		$this->assertSame($written, $this->created[0]['selection']['ids']);
	}

	public function testAJobStillRunningIsRefusedRatherThanUndoneHalfWay(): void {
		$exception = $this->refusal($this->original(BulkJob::STATE_RUNNING));

		$this->assertSame('not-finished', $exception->getReason());
		$this->assertStringContainsString('running', $exception->getMessage());
	}

	public function testAJobWhoseActionWasNeverReversibleIsRefusedNamingTheAction(): void {
		$exception = $this->refusal($this->original(BulkJob::STATE_COMPLETED, null));

		$this->assertSame('not-reversible', $exception->getReason());
		$this->assertStringContainsString('openregister:set-properties', $exception->getMessage());
	}

	public function testAReversalOutsideTheWindowIsRefusedNamingTheWindow(): void {
		$exception = $this->refusal($this->original(BulkJob::STATE_COMPLETED, 604800, '-1 hour'));

		$this->assertSame('window-expired', $exception->getReason());
		$this->assertStringContainsString('604800 seconds', $exception->getMessage());
		$this->assertArrayHasKey('reversibleUntil', $exception->getDetails());
	}

	public function testAJobThatWroteNothingHasNothingToUndo(): void {
		$this->memberMapper->method('findWrittenUuidsByJob')->willReturn([]);

		$exception = $this->refusal($this->original());

		$this->assertSame('nothing-to-reverse', $exception->getReason());
	}

	public function testASecondReversalOfTheSameJobIsRefused(): void {
		$existing = new BulkJob();
		$existing->setId(42);
		$existing->setState(BulkJob::STATE_RUNNING);
		$this->jobMapper->method('find')->willReturn($existing);

		$original = $this->original();
		$original->setReversedByJobId(42);

		$exception = $this->refusal($original);

		$this->assertSame('already-reversed', $exception->getReason());
		$this->assertStringContainsString('42', $exception->getMessage());
	}

	public function testACancelledReversalDoesNotStrandTheJobItWasMeantToUndo(): void {
		$existing = new BulkJob();
		$existing->setId(42);
		$existing->setState(BulkJob::STATE_CANCELLED);
		$this->jobMapper->method('find')->willReturn($existing);
		$this->memberMapper->method('findWrittenUuidsByJob')->willReturn(['case-1']);
		$this->resolver->method('resolveUuids')->willReturn(['case-1']);
		$this->resolver->method('hydrate')->willReturn([]);

		$original = $this->original();
		$original->setReversedByJobId(42);

		$reversal = $this->reversal()->reverse($original, 'fatima', 'Tweede poging.');

		$this->assertSame(7, $reversal->getReversesJobId());
	}

	public function testAReversalNamingAJobThatIsGoneIsNotBlockedByIt(): void {
		$this->jobMapper->method('find')->willThrowException(new DoesNotExistException('gone'));
		$this->memberMapper->method('findWrittenUuidsByJob')->willReturn(['case-1']);
		$this->resolver->method('resolveUuids')->willReturn(['case-1']);
		$this->resolver->method('hydrate')->willReturn([]);

		$original = $this->original();
		$original->setReversedByJobId(42);

		$this->assertSame(7, $this->reversal()->reverse($original, 'fatima', 'x')->getReversesJobId());
	}
}//end class
