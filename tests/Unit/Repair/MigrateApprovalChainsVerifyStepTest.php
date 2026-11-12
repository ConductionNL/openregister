<?php

/**
 * Characterisation: the per-step reconciliation check and the derived
 * template of the approval-chain migration.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Repair;

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskAuditMapper;
use OCA\OpenRegister\Db\TaskCandidateMapper;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Db\TaskSequenceMapper;
use OCA\OpenRegister\Repair\MigrateApprovalChainsToTasks;
use OCA\OpenRegister\Repair\Support\ApprovalTaskStores;
use OCA\OpenRegister\Repair\Support\RepairPlatform;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;
use RuntimeException;

/**
 * Pins verifyStep() outcome by outcome, and templateFor() for declared and undeclared chains.
 *
 * @covers \OCA\OpenRegister\Repair\MigrateApprovalChainsToTasks
 */
class MigrateApprovalChainsVerifyStepTest extends TestCase {

	/**
	 * The step over a task table holding one task, or none.
	 *
	 * @param Task|null $task The task the lookup finds.
	 *
	 * @return MigrateApprovalChainsToTasks The step.
	 */
	private function step(?Task $task): MigrateApprovalChainsToTasks {
		$tasks = $this->createMock(TaskMapper::class);
		if ($task === null) {
			$tasks->method('findByUuid')->willThrowException(new DoesNotExistException('gone'));
		} else {
			$tasks->method('findByUuid')->willReturn($task);
		}

		return new MigrateApprovalChainsToTasks(
			platform: new RepairPlatform(db: $this->createMock(IDBConnection::class), config: $this->createMock(IAppConfig::class), logger: new NullLogger()),
			stores: new ApprovalTaskStores(tasks: $tasks, candidates: $this->createMock(TaskCandidateMapper::class), audits: $this->createMock(TaskAuditMapper::class), sequences: $this->createMock(TaskSequenceMapper::class))
		);
	}//end step()

	/**
	 * A task at an ordinal in a state.
	 *
	 * @param int    $position The sequence position.
	 * @param string $state    The task state.
	 *
	 * @return Task The task.
	 */
	private function task(int $position, string $state): Task {
		$task = new Task();
		$task->setSequencePosition($position);
		$task->setState($state);

		return $task;
	}//end task()

	/**
	 * Step status, recorded task uuid, the task found, and the expected count or message.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string|null, 3: int|string}>
	 */
	public static function cases(): array {
		$none = 'Approval migration failed: no migrated task recorded for chain 7, object obj-1, step 11. The migration did NOT succeed.';
		$gone = 'Approval migration failed: migrated task t-1 for chain 7, object obj-1, step 11 does not exist. The migration did NOT succeed.';
		$ordinal = 'Approval migration failed: task t-1 sits at ordinal 3 while its step holds 2 (chain 7, object obj-1, step 11). The migration did NOT succeed.';
		$terminal = 'Approval migration failed: non-terminal chain 7, object obj-1, step 11 maps to terminal task t-1. The migration did NOT succeed.';
		$notEnabled = 'Approval migration failed: pending chain 7, object obj-1, step 11 maps to a task that is not enabled. The migration did NOT succeed.';

		return [
			'no task recorded' => ['pending', '  ', 'present', $none],
			'task gone' => ['pending', 't-1', null, $gone],
			'wrong ordinal' => ['approved', 't-1', 'ordinal', $ordinal],
			'pending on terminal task' => ['pending', 't-1', Task::STATE_COMPLETED, $terminal],
			'waiting on terminal task' => ['waiting', 't-1', Task::STATE_COMPLETED, $terminal],
			'pending, not enabled' => ['pending', 't-1', Task::STATE_ACTIVE, $notEnabled],
			'pending, enabled' => ['pending', 't-1', Task::STATE_ENABLED, 1],
			'waiting, not terminal' => ['waiting', 't-1', Task::STATE_ACTIVE, 0],
			'approved on terminal task' => ['approved', 't-1', Task::STATE_COMPLETED, 0],
		];
	}//end cases()

	/**
	 * Each outcome of verifyStep().
	 *
	 * @param string          $status   The step status.
	 * @param string          $taskUuid The recorded task uuid.
	 * @param string|null     $found    The task state found, 'ordinal' for a misplaced task, null for none.
	 * @param int|string      $expected The count returned, or the failure message.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testVerifyStep(string $status, string $taskUuid, ?string $found, int|string $expected): void {
		$task = null;
		if ($found === 'ordinal') {
			$task = $this->task(3, Task::STATE_COMPLETED);
		} else if ($found !== null) {
			$task = $this->task(2, ($found === 'present' ? Task::STATE_ENABLED : $found));
		}

		$step = ['id' => 11, 'chain_id' => 7, 'object_uuid' => 'obj-1', 'status' => $status, 'step_order' => 2, 'migrated_task_uuid' => $taskUuid];
		$verify = new ReflectionMethod(MigrateApprovalChainsToTasks::class, 'verifyStep');

		if (is_string($expected) === true) {
			$this->expectException(RuntimeException::class);
			$this->expectExceptionMessage($expected);
		}

		$this->assertSame($expected, $verify->invoke($this->step($task), $step));
	}//end testVerifyStep()

	/**
	 * The template of a chain on a schema, skipping blank-role entries and numbering missing orders.
	 *
	 * @return void
	 */
	public function testTemplateForADeclaredChain(): void {
		$chain = [
			'id' => 5,
			'name' => 'Inkoop',
			'schema_id' => '12',
			'steps' => json_encode([
				['order' => 4, 'role' => ' manager ', 'statusOnApprove' => 'ok'],
				['role' => '  '],
				'not-an-entry',
				['role' => 'director', 'statusOnReject' => 'no'],
			]),
		];
		$template = (new ReflectionMethod(MigrateApprovalChainsToTasks::class, 'templateFor'))->invoke($this->step(null), $chain);

		$hash = md5('or-approval-template:12:Inkoop');
		$this->assertSame(
			[
				'templateId' => substr($hash, 0, 8) . '-' . substr($hash, 8, 4) . '-' . substr($hash, 12, 4) . '-' . substr($hash, 16, 4) . '-' . substr($hash, 20, 12),
				'templateVersion' => 1,
				'name' => 'Inkoop',
				'schemaId' => 12,
				'separationOfDuties' => false,
				'migratedFromChainId' => 5,
				'positions' => [
					['order' => 4, 'role' => 'manager', 'statusOnApprove' => 'ok', 'statusOnReject' => null],
					['order' => 2, 'role' => 'director', 'statusOnApprove' => null, 'statusOnReject' => 'no'],
				],
			],
			$template
		);
	}//end testTemplateForADeclaredChain()

	/**
	 * A chain without a schema keys its template on the legacy chain id; unreadable steps give no positions.
	 *
	 * @return void
	 */
	public function testTemplateForAChainWithoutSchema(): void {
		$template = (new ReflectionMethod(MigrateApprovalChainsToTasks::class, 'templateFor'))->invoke(
			$this->step(null),
			['id' => 9, 'name' => 'Losse keten', 'steps' => '{not json']
		);

		$hash = md5('or-approval-template:legacy:9:Losse keten');
		$this->assertSame(substr($hash, 0, 8) . '-' . substr($hash, 8, 4) . '-' . substr($hash, 12, 4) . '-' . substr($hash, 16, 4) . '-' . substr($hash, 20, 12), $template['templateId']);
		$this->assertNull($template['schemaId']);
		$this->assertSame([], $template['positions']);
	}//end testTemplateForAChainWithoutSchema()
}//end class
