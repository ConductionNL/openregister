<?php

/**
 * Golden test pinning the per-object access verdicts of BulkJobExecutor.
 *
 * Written against the executor before its constructor collaborators were
 * bundled, so the refactor is proven not to move an allowed or a refused
 * outcome: an object the actor may update is rehearsed, one they may not
 * update is refused with the rule that refused it and never reaches the
 * action, and one they cannot read is refused as not visible.
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

use OCA\OpenRegister\BulkAction\BulkActionInterface;
use OCA\OpenRegister\BulkAction\BulkActionResult;
use OCA\OpenRegister\BulkAction\BulkStep;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\BulkJob;
use OCA\OpenRegister\Db\BulkJobMapper;
use OCA\OpenRegister\Db\BulkJobMember;
use OCA\OpenRegister\Db\BulkJobMemberMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\BulkActionRegistry;
use OCA\OpenRegister\Service\BulkJob\BulkJobExecutor;
use OCA\OpenRegister\Service\BulkJob\BulkJobPass;
use OCA\OpenRegister\Service\BulkJob\BulkJobExecutorServices;
use OCA\OpenRegister\Service\BulkJob\BulkJobExecutorStores;
use OCA\OpenRegister\Service\BulkJob\BulkSelectionResolver;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class BulkJobExecutorAccessGoldenTest extends TestCase {

	/**
	 * Every member row written during the test, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $written = [];

	/**
	 * Every permission question asked, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $asked = [];

	/**
	 * The uuids the action was applied to.
	 *
	 * @var array<int, string>
	 */
	private array $applied = [];

	public function testAnObjectTheActorMayUpdateIsRehearsed(): void {
		$this->preview(['a'], ['a' => true]);

		$this->assertSame(BulkJobMember::OUTCOME_APPLIED, $this->written[0]['outcome']);
		$this->assertSame(['a'], $this->applied);
		$this->assertSame(
			[['action' => 'update', 'userId' => 'coordinator', 'owner' => 'owner-a', 'uuid' => 'a']],
			$this->asked
		);
	}

	public function testAnObjectTheActorMayNotUpdateIsRefusedBeforeTheAction(): void {
		$this->preview(['b'], ['b' => false]);

		$this->assertSame(BulkJobMember::OUTCOME_REFUSED, $this->written[0]['outcome']);
		$this->assertSame("the update rule on schema 'Zaak' does not include coordinator", $this->written[0]['reason']);
		$this->assertSame([], $this->applied);
	}

	public function testAnObjectTheActorCannotReadIsRefusedAsNotVisible(): void {
		$this->preview(['gone'], []);

		$this->assertSame(BulkJobMember::OUTCOME_REFUSED, $this->written[0]['outcome']);
		$this->assertSame(BulkJobExecutor::RULE_NOT_VISIBLE, $this->written[0]['reason']);
		$this->assertSame([], $this->applied);
		$this->assertSame([], $this->asked);
	}

	public function testAMixedSelectionKeepsEachVerdict(): void {
		$this->preview(['a', 'b', 'gone'], ['a' => true, 'b' => false]);

		$this->assertSame(
			[BulkJobMember::OUTCOME_APPLIED, BulkJobMember::OUTCOME_REFUSED, BulkJobMember::OUTCOME_REFUSED],
			array_column($this->written, 'outcome')
		);
		$this->assertSame(['a'], $this->applied);
	}

	/**
	 * Rehearse a selection where $verdicts maps each readable uuid to whether
	 * the actor may update it; a uuid missing from $verdicts is unreadable.
	 *
	 * @param array<int, string>   $uuids    The selection.
	 * @param array<string, bool>  $verdicts The update verdict per readable uuid.
	 *
	 * @return void
	 */
	private function preview(array $uuids, array $verdicts): void {
		$objects = [];
		foreach (array_keys($verdicts) as $uuid) {
			$object = new ObjectEntity();
			$object->setUuid($uuid);
			$object->setSchema('1');
			$object->setRegister('1');
			$object->setOwner('owner-' . $uuid);
			$object->setSchemaVersion('1.0.0');
			$object->setObject(['status' => 'open']);
			$objects[$uuid] = $object;
		}

		$actor = $this->createMock(IUser::class);
		$actor->method('getUID')->willReturn('coordinator');

		$job = new BulkJob();
		$job->setId(7);
		$job->setUuid('job-uuid');
		$job->setAction('openregister:set-properties');
		$job->setParameters(['properties' => ['status' => 'closed']]);
		$job->setStartedBy('coordinator');
		$job->setState(BulkJob::STATE_PREVIEWED);

		$this->executor($verdicts)->writePreviewMembers(new BulkJobPass($job, $this->action(), $actor, $objects), $uuids);
	}

	private function action(): BulkActionInterface {
		$action = $this->createMock(BulkActionInterface::class);
		$action->method('getId')->willReturn('openregister:set-properties');
		$action->method('getGuards')->willReturn([]);
		$action->method('requiresJustification')->willReturn(false);
		$action->method('apply')->willReturnCallback(
			function (BulkStep $step): BulkActionResult {
				$this->applied[] = (string)$step->object->getUuid();

				return BulkActionResult::applied();
			}
		);

		return $action;
	}

	/**
	 * The executor, with the update verdicts answered by a permission double.
	 *
	 * @param array<string, bool> $verdicts The update verdict per uuid.
	 *
	 * @return BulkJobExecutor
	 */
	private function executor(array $verdicts): BulkJobExecutor {
		$memberMapper = $this->createMock(BulkJobMemberMapper::class);
		$memberMapper->method('createFromArray')->willReturnCallback(
			function (array $data): BulkJobMember {
				$this->written[] = $data;
				$member = new BulkJobMember();
				$member->hydrate($data);

				return $member;
			}
		);

		$schema = new Schema();
		$schema->setTitle('Zaak');
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn($schema);

		$permissions = $this->createMock(PermissionHandlerInterface::class);
		$permissions->method('hasPermission')->willReturnCallback(
			function (
				Schema $schema,
				string $action,
				?string $userId = null,
				?string $objectOwner = null,
				bool $rbac = true,
				?ObjectEntity $object = null
			) use ($verdicts): bool {
				$this->asked[] = ['action' => $action, 'userId' => $userId, 'owner' => $objectOwner, 'uuid' => $object?->getUuid()];

				return ($verdicts[(string)$object?->getUuid()] ?? false);
			}
		);

		return new BulkJobExecutor(
			new BulkJobExecutorStores(
				$this->createMock(BulkJobMapper::class),
				$memberMapper,
				$schemaMapper,
				$this->createMock(AuditTrailMapper::class)
			),
			new BulkJobExecutorServices(
				$this->createMock(BulkSelectionResolver::class),
				$this->createMock(BulkActionRegistry::class),
				$permissions,
				$this->createMock(IUserManager::class)
			),
			new NullLogger()
		);
	}
}
