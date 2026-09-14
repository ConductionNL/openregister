<?php

/**
 * Unit tests for BulkJobExecutor — the one executor behind the rehearsal
 * and the commit.
 *
 * Covers the four properties the design turns on. The preview and the commit
 * disagree about nothing except whether the write is made (D-1). A refusal is
 * never reported as a skip (D-3). The homogeneity guard names both versions
 * and their counts before a single object is touched (D-6). A cancel stops
 * before the next object and the report says which one (D-4).
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

use OCA\OpenRegister\BulkAction\BulkActionInterface;
use OCA\OpenRegister\BulkAction\BulkActionResult;
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
use OCA\OpenRegister\Service\BulkJob\BulkSelectionResolver;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class BulkJobExecutorTest extends TestCase {

	private BulkJobMapper $jobMapper;

	private BulkJobMemberMapper $memberMapper;

	private BulkSelectionResolver $resolver;

	private BulkActionRegistry $registry;

	private PermissionHandler $permissionHandler;

	private SchemaMapper $schemaMapper;

	private AuditTrailMapper $auditTrailMapper;

	private IUserManager $userManager;

	/**
	 * Every member row written during the test, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $written = [];

	/**
	 * Every member row saved back during the test, in order.
	 *
	 * @var array<int, BulkJobMember>
	 */
	private array $saved = [];

	protected function setUp(): void {
		parent::setUp();

		$this->written = [];
		$this->saved = [];

		$this->jobMapper = $this->createMock(BulkJobMapper::class);
		$this->memberMapper = $this->createMock(BulkJobMemberMapper::class);
		$this->resolver = $this->createMock(BulkSelectionResolver::class);
		$this->registry = $this->createMock(BulkActionRegistry::class);
		$this->permissionHandler = $this->createMock(PermissionHandler::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
		$this->userManager = $this->createMock(IUserManager::class);

		$this->memberMapper->method('createFromArray')->willReturnCallback(
			function (array $data): BulkJobMember {
				$this->written[] = $data;
				$member = new BulkJobMember();
				$member->hydrate($data);

				return $member;
			}
		);

		$this->memberMapper->method('save')->willReturnCallback(
			function (BulkJobMember $member): BulkJobMember {
				$this->saved[] = $member;

				return $member;
			}
		);

		$schema = new Schema();
		$schema->setTitle('Zaak');
		$this->schemaMapper->method('find')->willReturn($schema);
	}

	private function executor(): BulkJobExecutor {
		return new BulkJobExecutor(
			$this->jobMapper,
			$this->memberMapper,
			$this->resolver,
			$this->registry,
			$this->permissionHandler,
			$this->schemaMapper,
			$this->auditTrailMapper,
			$this->userManager,
			new NullLogger()
		);
	}

	private function object(string $uuid, string $version = '1.0.0', string $owner = 'coordinator'): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setSchema('1');
		$object->setRegister('1');
		$object->setOwner($owner);
		$object->setSchemaVersion($version);
		$object->setObject(['status' => 'open']);

		return $object;
	}

	private function job(string $state = BulkJob::STATE_PREVIEWED): BulkJob {
		$job = new BulkJob();
		$job->setId(7);
		$job->setUuid('job-uuid');
		$job->setAction('openregister:set-properties');
		$job->setParameters(['properties' => ['status' => 'closed']]);
		$job->setStartedBy('coordinator');
		$job->setState($state);
		$job->setTotal(2);

		return $job;
	}

	private function action(array $guards = [], ?BulkActionResult $result = null, ?array &$calls = null): BulkActionInterface {
		$action = $this->createMock(BulkActionInterface::class);
		$action->method('getId')->willReturn('openregister:set-properties');
		$action->method('getGuards')->willReturn($guards);
		$action->method('requiresJustification')->willReturn(false);
		$action->method('apply')->willReturnCallback(
			function (ObjectEntity $object, array $parameters, bool $commit, ?IUser $actor = null) use ($result, &$calls): BulkActionResult {
				if ($calls !== null) {
					$calls[] = ['uuid' => $object->getUuid(), 'commit' => $commit];
				}

				return ($result ?? BulkActionResult::applied());
			}
		);

		return $action;
	}

	public function testTheHomogeneityGuardNamesBothVersionsAndTheirCounts(): void {
		$objects = [
			'a' => $this->object('a', '1.0.0'),
			'b' => $this->object('b', '1.0.0'),
			'c' => $this->object('c', '2.0.0'),
		];

		$this->expectException(BulkJobRefusedException::class);
		$this->expectExceptionMessage('1.0.0 (2), 2.0.0 (1)');

		$this->executor()->assertGuards($this->action([BulkActionInterface::GUARD_HOMOGENEITY]), $objects);
	}

	public function testAnActionWithoutTheGuardAcceptsMixedVersions(): void {
		$objects = [
			'a' => $this->object('a', '1.0.0'),
			'c' => $this->object('c', '2.0.0'),
		];

		$this->executor()->assertGuards($this->action([]), $objects);

		$this->addToAssertionCount(1);
	}

	public function testThePreviewRehearsesWithoutCommitting(): void {
		$calls = [];
		$this->permissionHandler->method('hasPermission')->willReturn(true);

		$this->executor()->writePreviewMembers(
			$this->job(),
			['a', 'b'],
			['a' => $this->object('a'), 'b' => $this->object('b')],
			$this->action([], BulkActionResult::applied(), $calls),
			null
		);

		$this->assertSame([['uuid' => 'a', 'commit' => false], ['uuid' => 'b', 'commit' => false]], $calls);
		$this->assertCount(2, $this->written);
		$this->assertSame(BulkJobMember::OUTCOME_APPLIED, $this->written[0]['outcome']);
		$this->assertSame('1.0.0', $this->written[0]['schemaVersion']);
	}

	public function testAnObjectTheActorMayNotWriteIsRefusedAndNotSkipped(): void {
		$this->permissionHandler->method('hasPermission')->willReturnCallback(
			static fn (Schema $schema, string $action, ?string $userId = null, ?string $objectOwner = null, bool $rbac = true, ?ObjectEntity $object = null): bool => $object?->getUuid() !== 'b'
		);

		$this->executor()->writePreviewMembers(
			$this->job(),
			['a', 'b'],
			['a' => $this->object('a'), 'b' => $this->object('b')],
			$this->action([], BulkActionResult::skipped('the object is closed')),
			null
		);

		$this->assertSame(BulkJobMember::OUTCOME_SKIPPED, $this->written[0]['outcome']);
		$this->assertSame('the object is closed', $this->written[0]['reason']);

		$this->assertSame(BulkJobMember::OUTCOME_REFUSED, $this->written[1]['outcome']);
		$this->assertStringContainsString("update rule on schema 'Zaak'", (string)$this->written[1]['reason']);
	}

	public function testAnObjectTheActorCannotEvenReadIsRefused(): void {
		$this->permissionHandler->method('hasPermission')->willReturn(true);

		$this->executor()->writePreviewMembers(
			$this->job(),
			['gone'],
			[],
			$this->action(),
			null
		);

		$this->assertSame(BulkJobMember::OUTCOME_REFUSED, $this->written[0]['outcome']);
		$this->assertSame(BulkJobExecutor::RULE_NOT_VISIBLE, $this->written[0]['reason']);
		$this->assertNull($this->written[0]['schemaVersion']);
	}

	public function testACancelStopsBeforeTheNextObjectAndSaysWhere(): void {
		$job = $this->job(BulkJob::STATE_RUNNING);
		$job->setTotal(3);

		$pending = [];
		foreach (['a', 'b', 'c'] as $index => $uuid) {
			$member = new BulkJobMember();
			$member->setId(($index + 1));
			$member->setJobId(7);
			$member->setObjectUuid($uuid);
			// What the preview said, with no write behind it yet. This is
			// exactly the member a commit must still walk.
			$member->setOutcome(BulkJobMember::OUTCOME_APPLIED);
			$pending[] = $member;
		}

		$this->memberMapper->method('findPendingBatch')->willReturn($pending);
		$this->memberMapper->method('countByOutcome')->willReturn([BulkJobMember::OUTCOME_APPLIED => 2]);
		$this->memberMapper->method('countWalked')->willReturn(2);

		// The job is running for the first two members and cancelling for the third.
		$states = [BulkJob::STATE_RUNNING, BulkJob::STATE_RUNNING, BulkJob::STATE_CANCELLING];
		$this->jobMapper->method('readState')->willReturnCallback(
			static function () use (&$states): string {
				return (string)array_shift($states);
			}
		);
		$this->jobMapper->method('save')->willReturnArgument(0);

		$this->registry->method('get')->willReturn($this->action());
		$this->permissionHandler->method('hasPermission')->willReturn(true);
		$this->resolver->method('hydrate')->willReturn(
			['a' => $this->object('a'), 'b' => $this->object('b'), 'c' => $this->object('c')]
		);

		$more = $this->executor()->processBatch($job, 25);

		$this->assertFalse($more);
		$this->assertSame(BulkJob::STATE_CANCELLED, $job->getState());
		$this->assertSame('c', $job->getReport()['cancelledBefore']['objectUuid']);
		$this->assertSame(2, $job->getReport()['cancelledBefore']['applied']);
		$this->assertCount(2, $this->saved, 'only the members before the boundary were written');
	}

	public function testAnEmptyBatchCompletesTheJob(): void {
		$job = $this->job(BulkJob::STATE_RUNNING);

		$this->memberMapper->method('findPendingBatch')->willReturn([]);
		$this->memberMapper->method('countByOutcome')->willReturn([BulkJobMember::OUTCOME_APPLIED => 2]);
		$this->memberMapper->method('countWalked')->willReturn(2);
		$this->jobMapper->method('save')->willReturnArgument(0);

		$this->assertFalse($this->executor()->processBatch($job, 25));
		$this->assertSame(BulkJob::STATE_COMPLETED, $job->getState());
		$this->assertSame(2, $job->getApplied());
		$this->assertSame(2, $job->getProcessed());
	}

	public function testAMemberThePreviewCalledAppliedIsStillWrittenAtCommit(): void {
		$job = $this->job(BulkJob::STATE_RUNNING);

		$member = new BulkJobMember();
		$member->setId(1);
		$member->setJobId(7);
		$member->setObjectUuid('a');
		// The rehearsal said this one would apply. Nothing was written.
		$member->setOutcome(BulkJobMember::OUTCOME_APPLIED);
		$this->assertNull($member->getAppliedAt(), 'the fixture must start unwritten');

		$this->memberMapper->method('findPendingBatch')->willReturn([$member]);
		$this->memberMapper->method('countByOutcome')->willReturn([BulkJobMember::OUTCOME_APPLIED => 1]);
		$this->memberMapper->method('countWalked')->willReturn(1);
		$this->jobMapper->method('readState')->willReturn(BulkJob::STATE_RUNNING);
		$this->jobMapper->method('save')->willReturnArgument(0);
		$this->permissionHandler->method('hasPermission')->willReturn(true);
		$this->resolver->method('hydrate')->willReturn(['a' => $this->object('a')]);

		$calls = [];
		$this->registry->method('get')->willReturn($this->action([], BulkActionResult::applied(), $calls));

		$this->executor()->processBatch($job, 25);

		$this->assertSame([['uuid' => 'a', 'commit' => true]], $calls, 'the commit never reached the member');
		$this->assertNotNull($member->getAppliedAt(), 'a written member must carry its write stamp');
		$this->assertSame(1, $job->getCursor());
	}

	public function testTheCommitWritesOneAuditEntryPerAppliedMember(): void {
		$job = $this->job(BulkJob::STATE_RUNNING);
		$job->setJustification('Hans left on the 30th.');

		$member = new BulkJobMember();
		$member->setId(1);
		$member->setJobId(7);
		$member->setObjectUuid('a');
		$member->setOutcome(BulkJobMember::OUTCOME_APPLIED);

		$this->memberMapper->method('findPendingBatch')->willReturn([$member]);
		$this->memberMapper->method('countByOutcome')->willReturn([BulkJobMember::OUTCOME_APPLIED => 1]);
		$this->memberMapper->method('countWalked')->willReturn(1);
		$this->jobMapper->method('readState')->willReturn(BulkJob::STATE_RUNNING);
		$this->jobMapper->method('save')->willReturnArgument(0);
		$this->registry->method('get')->willReturn($this->action());
		$this->permissionHandler->method('hasPermission')->willReturn(true);
		$this->resolver->method('hydrate')->willReturn(['a' => $this->object('a')]);

		$context = null;
		$this->auditTrailMapper->expects($this->once())
			->method('createAuditTrailEntry')
			->willReturnCallback(
				function (ObjectEntity $object, string $action, array $ctx = [], ?string $actorId = null, ?string $actorName = null) use (&$context) {
					$context = ['action' => $action, 'context' => $ctx, 'actor' => $actorId];

					return $this->createMock(\OCA\OpenRegister\Db\AuditTrail::class);
				}
			);

		$this->executor()->processBatch($job, 25);

		$this->assertSame('bulk.applied', $context['action']);
		$this->assertSame('job-uuid', $context['context']['bulkJob']);
		$this->assertSame('Hans left on the 30th.', $context['context']['reason']);
		$this->assertSame('coordinator', $context['actor']);
	}
}
