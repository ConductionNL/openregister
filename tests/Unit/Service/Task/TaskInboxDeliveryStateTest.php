<?php

/**
 * The inbox row's delivery state for an external task: summarised from the
 * request records, `not-recorded` without them, and never a failure path.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Task
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Task;

use DateTime;
use OCA\OpenRegister\Db\PortalTaskDelivery;
use OCA\OpenRegister\Db\PortalTaskDeliveryMapper;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Service\Task\TaskInboxService;
use OCA\OpenRegister\Service\Task\TaskTemporalProjection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for the delivery half of {@see TaskInboxService}.
 *
 * @covers \OCA\OpenRegister\Service\Task\TaskInboxService
 * @covers \OCA\OpenRegister\Db\PortalTaskDelivery
 * @uses \OCA\OpenRegister\Db\Task
 * @uses \OCA\OpenRegister\Service\Task\TaskTemporalProjection
 */
class TaskInboxDeliveryStateTest extends TestCase {

	/**
	 * An external task.
	 *
	 * @return Task The task.
	 */
	private function externalTask(): Task {
		$task = new Task();
		$task->setId(1);
		$task->setUuid('t-1');
		$task->setState(Task::STATE_ACTIVE);
		$task->setPerformerType(Task::PERFORMER_EXTERNAL);
		$task->setAssignee('party:bsn-1');

		return $task;
	}//end externalTask()

	/**
	 * A service over a delivery mapper (or none).
	 *
	 * @param PortalTaskDeliveryMapper|null $deliveries The mapper.
	 *
	 * @return TaskInboxService The service.
	 */
	private function service(?PortalTaskDeliveryMapper $deliveries): TaskInboxService {
		return new TaskInboxService(
			tasks: $this->createMock(TaskMapper::class),
			temporal: new TaskTemporalProjection(),
			logger: new NullLogger(),
			objects: null,
			deliveries: $deliveries
		);
	}//end service()

	/**
	 * An external row carries the summarised delivery state; a user task's
	 * row carries none.
	 *
	 * @return void
	 */
	public function testTheExternalRowCarriesTheSummarisedDeliveryState(): void {
		$row = new PortalTaskDelivery();
		$row->setChannel(PortalTaskDelivery::CHANNEL_PORTAL_INBOX);
		$row->setState(PortalTaskDelivery::STATE_DELIVERED);
		$row->setKind(PortalTaskDelivery::KIND_ASK);
		$row->setRequestedAt(new DateTime('2026-09-01T10:00:00+00:00'));
		$row->setDeliveredAt(new DateTime('2026-09-01T10:05:00+00:00'));

		$deliveries = $this->createMock(PortalTaskDeliveryMapper::class);
		$deliveries->method('findForTask')->with('t-1')->willReturn([$row]);
		$service = $this->service($deliveries);

		$rendered = $service->row(task: $this->externalTask(), subjects: [], now: new DateTime());
		$this->assertSame('delivered', $rendered['delivery']['state']);
		$this->assertArrayHasKey('portal-inbox', $rendered['delivery']['channels']);

		$user = $this->externalTask();
		$user->setPerformerType(Task::PERFORMER_USER);
		$user->setAssignee('alice');
		$this->assertArrayNotHasKey('delivery', $service->row(task: $user, subjects: [], now: new DateTime()));
	}//end testTheExternalRowCarriesTheSummarisedDeliveryState()

	/**
	 * No mapper and a failing mapper both read `not-recorded`, never a throw.
	 *
	 * @return void
	 */
	public function testAnAbsentOrFailingLedgerReadsNotRecorded(): void {
		$this->assertSame(
			PortalTaskDelivery::STATE_NOT_RECORDED,
			$this->service(null)->deliveryState(task: $this->externalTask())['state']
		);

		$broken = $this->createMock(PortalTaskDeliveryMapper::class);
		$broken->method('findForTask')->willThrowException(new RuntimeException('db gone'));
		$this->assertSame(
			PortalTaskDelivery::STATE_NOT_RECORDED,
			$this->service($broken)->deliveryState(task: $this->externalTask())['state']
		);
	}//end testAnAbsentOrFailingLedgerReadsNotRecorded()

	/**
	 * The subject-context pass-through answers empty without an object store.
	 *
	 * @return void
	 */
	public function testSubjectContextsForAnswersEmptyWithoutAStore(): void {
		$this->assertSame([], $this->service(null)->subjectContextsFor(tasks: [$this->externalTask()]));
	}//end testSubjectContextsForAnswersEmptyWithoutAStore()
}//end class
