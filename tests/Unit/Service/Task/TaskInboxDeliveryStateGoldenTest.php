<?php

/**
 * Characterisation test pinning the whole not-recorded answer and the log a failing delivery read leaves.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Task;

use OCA\OpenRegister\Db\PortalTaskDelivery;
use OCA\OpenRegister\Db\PortalTaskDeliveryMapper;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Service\Task\TaskInboxService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * No store and a failing store both answer the full not-recorded shape; only the failure logs.
 */
class TaskInboxDeliveryStateGoldenTest extends TestCase {

	/**
	 * The not-recorded answer, key for key.
	 *
	 * @var array<string, mixed>
	 */
	private const NOT_RECORDED = [
		'state' => PortalTaskDelivery::STATE_NOT_RECORDED,
		'channels' => [],
		'requestedAt' => null,
		'deliveredAt' => null,
	];

	/**
	 * A task with a uuid.
	 *
	 * @return Task
	 */
	private function task(): Task {
		$task = new Task();
		$task->setUuid('t-9');

		return $task;
	}//end task()

	/**
	 * Without a delivery store the answer is not-recorded and nothing is logged.
	 *
	 * @return void
	 */
	public function testNoStoreAnswersNotRecordedSilently(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('debug');
		$service = new TaskInboxService(tasks: $this->createMock(TaskMapper::class), logger: $logger, objects: null, deliveries: null);

		$this->assertSame(self::NOT_RECORDED, $service->deliveryState(task: $this->task()));
	}//end testNoStoreAnswersNotRecordedSilently()

	/**
	 * A failing store answers not-recorded and logs one debug line naming the task.
	 *
	 * @return void
	 */
	public function testAFailingStoreAnswersNotRecordedAndLogs(): void {
		$calls = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('debug')->willReturnCallback(
			function (string $message, array $context = []) use (&$calls): void {
				$calls[] = [$message, $context];
			}
		);
		$broken = $this->createMock(PortalTaskDeliveryMapper::class);
		$broken->method('findForTask')->willThrowException(new RuntimeException('db gone'));
		$service = new TaskInboxService(tasks: $this->createMock(TaskMapper::class), logger: $logger, objects: null, deliveries: $broken);

		$this->assertSame(self::NOT_RECORDED, $service->deliveryState(task: $this->task()));
		$this->assertSame([['[TaskInboxService] Could not read delivery state: db gone', ['task' => 't-9']]], $calls);
	}//end testAFailingStoreAnswersNotRecordedAndLogs()
}//end class
