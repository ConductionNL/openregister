<?php

/**
 * Unit tests for what a reversible job remembers.
 *
 * Four properties. The rehearsal records the prior and applied values on
 * every member, so the preview can already say what going back would mean.
 * The commit re-reads them immediately before the write, because minutes or
 * hours may have passed since the rehearsal (D-2). An action that did not
 * declare itself reversible records nothing, which is what makes the
 * reversal route's refusal honest rather than a silent no-op (D-4). And the
 * reversal deadline is fixed when the job stops writing, not when somebody
 * opened the dialog.
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

use OCA\OpenRegister\BulkAction\BulkActionInterface;
use OCA\OpenRegister\BulkAction\BulkActionResult;
use OCA\OpenRegister\BulkAction\ReversibleBulkActionInterface;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\BulkJob;
use OCA\OpenRegister\Db\BulkJobMapper;
use OCA\OpenRegister\Db\BulkJobMember;
use OCA\OpenRegister\Db\BulkJobMemberMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\BulkJobRefusedException;
use OCA\OpenRegister\Service\BulkActionRegistry;
use OCA\OpenRegister\Service\BulkJob\BulkJobExecutor;
use OCA\OpenRegister\Service\BulkJob\BulkJobService;
use OCA\OpenRegister\Service\BulkJob\BulkSelectionResolver;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class BulkJobPriorValueCaptureTest extends TestCase {

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
	 * Selection resolution.
	 *
	 * @var BulkSelectionResolver
	 */
	private BulkSelectionResolver $resolver;

	/**
	 * The action catalogue.
	 *
	 * @var BulkActionRegistry
	 */
	private BulkActionRegistry $registry;

	/**
	 * Per-object access checks.
	 *
	 * @var PermissionHandler
	 */
	private PermissionHandler $permissionHandler;

	/**
	 * Every member row written during the test, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $written = [];

	protected function setUp(): void {
		parent::setUp();

		$this->written = [];
		$this->jobMapper = $this->createMock(BulkJobMapper::class);
		$this->memberMapper = $this->createMock(BulkJobMemberMapper::class);
		$this->resolver = $this->createMock(BulkSelectionResolver::class);
		$this->registry = $this->createMock(BulkActionRegistry::class);
		$this->permissionHandler = $this->createMock(PermissionHandler::class);

		$this->memberMapper->method('createFromArray')->willReturnCallback(
			function (array $data): BulkJobMember {
				$this->written[] = $data;
				$member = new BulkJobMember();
				$member->hydrate($data);

				return $member;
			}
		);
		$this->memberMapper->method('save')->willReturnArgument(0);
		$this->jobMapper->method('save')->willReturnArgument(0);
		$this->permissionHandler->method('hasPermission')->willReturn(true);
	}

	private function executor(): BulkJobExecutor {
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schema = new Schema();
		$schema->setTitle('Zaak');
		$schemaMapper->method('find')->willReturn($schema);

		return new BulkJobExecutor(
			$this->jobMapper,
			$this->memberMapper,
			$this->resolver,
			$this->registry,
			$this->permissionHandler,
			$schemaMapper,
			$this->createMock(AuditTrailMapper::class),
			$this->createMock(IUserManager::class),
			new NullLogger()
		);
	}

	private function object(string $uuid, array $data = ['status' => 'in behandeling']): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setRegister('1');
		$object->setSchema('2');
		$object->setOwner('coordinator');
		$object->setSchemaVersion('1.0.0');
		$object->setObject($data);

		return $object;
	}

	private function job(string $state = BulkJob::STATE_PREVIEWED, ?int $window = 604800): BulkJob {
		$job = new BulkJob();
		$job->setId(7);
		$job->setUuid('job-uuid');
		$job->setAction('openregister:set-properties');
		$job->setParameters(['properties' => ['status' => 'afgehandeld']]);
		$job->setStartedBy('coordinator');
		$job->setState($state);
		$job->setReversalWindow($window);

		return $job;
	}

	/**
	 * A reversible action whose plan is read off the object it is handed.
	 *
	 * @return ReversibleBulkActionInterface The action double.
	 */
	private function reversibleAction(): ReversibleBulkActionInterface {
		$action = $this->createMock(ReversibleBulkActionInterface::class);
		$action->method('getId')->willReturn('openregister:set-properties');
		$action->method('getGuards')->willReturn([]);
		$action->method('requiresJustification')->willReturn(false);
		$action->method('getReversalWindow')->willReturn(604800);
		$action->method('apply')->willReturn(BulkActionResult::applied());
		$action->method('reversalPlanFor')->willReturnCallback(
			static function (ObjectEntity $object, array $parameters): array {
				return [
					ReversibleBulkActionInterface::PLAN_PRIOR => ['status' => ($object->getObject()['status'] ?? null)],
					ReversibleBulkActionInterface::PLAN_APPLIED => ['status' => 'afgehandeld'],
				];
			}
		);

		return $action;
	}

	private function plainAction(): BulkActionInterface {
		$action = $this->createMock(BulkActionInterface::class);
		$action->method('getId')->willReturn('openregister:apply-rule');
		$action->method('getGuards')->willReturn([]);
		$action->method('requiresJustification')->willReturn(false);
		$action->method('apply')->willReturn(BulkActionResult::applied());

		return $action;
	}

	public function testThePriorStatusIsOnEveryMemberOutcomeAfterTheRehearsal(): void {
		$this->executor()->writePreviewMembers(
			$this->job(),
			['case-1', 'case-2'],
			['case-1' => $this->object('case-1'), 'case-2' => $this->object('case-2')],
			$this->reversibleAction(),
			null
		);

		$this->assertCount(2, $this->written);
		$this->assertSame(['status' => 'in behandeling'], $this->written[0]['priorValues']);
		$this->assertSame(['status' => 'afgehandeld'], $this->written[0]['appliedValues']);
		$this->assertSame(['status' => 'in behandeling'], $this->written[1]['priorValues']);
	}

	public function testAnActionThatIsNotReversibleRecordsNothingToGoBackTo(): void {
		$this->executor()->writePreviewMembers(
			$this->job(BulkJob::STATE_PREVIEWED, null),
			['case-1'],
			['case-1' => $this->object('case-1')],
			$this->plainAction(),
			null
		);

		$this->assertNull($this->written[0]['priorValues']);
		$this->assertNull($this->written[0]['appliedValues']);
	}

	public function testTheCommitRecordsWhatTheObjectHoldsNowNotWhatTheRehearsalSaw(): void {
		$job = $this->job(BulkJob::STATE_RUNNING);

		$member = new BulkJobMember();
		$member->setId(1);
		$member->setJobId(7);
		$member->setObjectUuid('case-1');
		$member->setOutcome(BulkJobMember::OUTCOME_APPLIED);
		// What the rehearsal recorded, hours ago.
		$member->setPriorValues(['status' => 'nieuw']);

		$this->memberMapper->method('findPendingBatch')->willReturn([$member]);
		$this->memberMapper->method('countByOutcome')->willReturn([BulkJobMember::OUTCOME_APPLIED => 1]);
		$this->memberMapper->method('countWalked')->willReturn(1);
		$this->jobMapper->method('readState')->willReturn(BulkJob::STATE_RUNNING);
		$this->registry->method('get')->willReturn($this->reversibleAction());
		// Somebody moved the case on between the rehearsal and the commit.
		$this->resolver->method('hydrate')->willReturn(
			['case-1' => $this->object('case-1', ['status' => 'in behandeling'])]
		);

		$this->executor()->processBatch($job, 25);

		$this->assertSame(
			['status' => 'in behandeling'],
			$member->getPriorValues(),
			'the commit kept the rehearsal\'s copy, so a reversal would restore a state this job never wrote over'
		);
		$this->assertSame(['status' => 'afgehandeld'], $member->getAppliedValues());
	}

	public function testTheReversalDeadlineIsFixedWhenTheJobStopsWriting(): void {
		$job = $this->job(BulkJob::STATE_RUNNING);
		$this->assertNull($job->getReversibleUntil(), 'the fixture must start without a deadline');

		$this->memberMapper->method('findPendingBatch')->willReturn([]);
		$this->memberMapper->method('countByOutcome')->willReturn([]);
		$this->memberMapper->method('countWalked')->willReturn(0);

		$this->executor()->processBatch($job, 25);

		$this->assertSame(BulkJob::STATE_COMPLETED, $job->getState());
		$this->assertNotNull($job->getReversibleUntil());
		$this->assertGreaterThan(time(), (int)$job->getReversibleUntil()->format('U'));
	}

	public function testAJobThatIsNotReversibleGetsNoDeadline(): void {
		$job = $this->job(BulkJob::STATE_RUNNING, null);

		$this->memberMapper->method('findPendingBatch')->willReturn([]);
		$this->memberMapper->method('countByOutcome')->willReturn([]);
		$this->memberMapper->method('countWalked')->willReturn(0);

		$this->executor()->processBatch($job, 25);

		$this->assertNull($job->getReversibleUntil());
		$this->assertFalse($job->isReversible());
	}

	public function testAJobAboveTheUndoCeilingIsRefusedAtCreationNamingTheCeiling(): void {
		$uuids = array_map(static fn (int $i): string => 'case-'.$i, range(1, 50));
		$objects = [];
		foreach ($uuids as $uuid) {
			// A long value, so fifty members outgrow a small ceiling.
			$objects[$uuid] = $this->object($uuid, ['status' => str_repeat('x', 200)]);
		}

		$this->resolver->method('resolveUuids')->willReturn($uuids);
		$this->resolver->method('hydrate')->willReturn($objects);
		$this->registry->method('get')->willReturn($this->reversibleAction());
		$this->jobMapper->expects($this->never())->method('createFromArray');

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnCallback(
			static function (string $app, string $key, int $default = 0): int {
				if ($key === BulkJobService::UNDO_CEILING_KEY) {
					return 512;
				}

				return $default;
			}
		);

		$service = new BulkJobService(
			$this->jobMapper,
			$this->memberMapper,
			$this->registry,
			$this->resolver,
			$this->createMock(BulkJobExecutor::class),
			$this->createMock(ObjectService::class),
			$this->createMock(IUserManager::class),
			$this->createMock(IJobList::class),
			$appConfig,
			new NullLogger()
		);

		try {
			$service->create('openregister:set-properties', [], ['ids' => $uuids], null, 'coordinator', 1, 2);
			$this->fail('The undo ceiling should have refused this job.');
		} catch (BulkJobRefusedException $exception) {
			$this->assertSame('undo-ceiling', $exception->getReason());
			$this->assertSame(512, $exception->getDetails()['ceiling']);
			$this->assertStringContainsString('512 bytes', $exception->getMessage());
		}
	}

	public function testAnActorIsHandedToTheRehearsalUnchanged(): void {
		// A guard on the seam the capture sits in: adding the plan must not
		// change who the rehearsal runs as.
		$actor = $this->createMock(IUser::class);
		$actor->method('getUID')->willReturn('coordinator');

		$seen = [];
		$action = $this->createMock(ReversibleBulkActionInterface::class);
		$action->method('getGuards')->willReturn([]);
		$action->method('reversalPlanFor')->willReturn(
			[
				ReversibleBulkActionInterface::PLAN_PRIOR => ['status' => 'in behandeling'],
				ReversibleBulkActionInterface::PLAN_APPLIED => ['status' => 'afgehandeld'],
			]
		);
		$action->method('apply')->willReturnCallback(
			static function (ObjectEntity $object, array $parameters, bool $commit, ?IUser $user = null) use (&$seen): BulkActionResult {
				$seen[] = $user?->getUID();

				return BulkActionResult::applied();
			}
		);

		$this->executor()->writePreviewMembers(
			$this->job(),
			['case-1'],
			['case-1' => $this->object('case-1')],
			$action,
			$actor
		);

		$this->assertSame(['coordinator'], $seen);
	}
}//end class
