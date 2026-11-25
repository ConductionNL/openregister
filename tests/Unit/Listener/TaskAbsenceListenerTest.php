<?php

/**
 * TaskAbsenceListener: an absence that starts or ends only queues the
 * substitution job.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/tasks-delegation-and-substitution/specs/flow-tasks/spec.md#requirement-a-task-goes-to-the-stand-in-of-an-absent-assignee
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\BackgroundJob\TaskSubstitutionJob;
use OCA\OpenRegister\Listener\TaskAbsenceListener;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\IUser;
use OCP\User\Events\OutOfOfficeEndedEvent;
use OCP\User\Events\OutOfOfficeStartedEvent;
use OCP\User\IOutOfOfficeData;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Design D-5: the event only queues.
 *
 * @covers \OCA\OpenRegister\Listener\TaskAbsenceListener
 */
class TaskAbsenceListenerTest extends TestCase {

	private IJobList&MockObject $jobs;

	private LoggerInterface&MockObject $logger;

	/**
	 * Anna's absence 42, naming Chris.
	 *
	 * @var IOutOfOfficeData&MockObject
	 */
	private IOutOfOfficeData&MockObject $absence;

	/**
	 * Fresh mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->jobs = $this->createMock(IJobList::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('anna');
		$this->absence = $this->createMock(IOutOfOfficeData::class);
		$this->absence->method('getUser')->willReturn($user);
		$this->absence->method('getId')->willReturn('42');
		$this->absence->method('getReplacementUserId')->willReturn('chris');
		$this->absence->expects($this->never())->method('getMessage');
	}//end setUp()

	/**
	 * A start queues the job in start mode.
	 *
	 * @return void
	 */
	public function testAStartQueuesTheJob(): void {
		$this->jobs->expects($this->once())->method('add')->with(
			TaskSubstitutionJob::class,
			['mode' => 'start', 'uid' => 'anna', 'absenceId' => '42', 'standIn' => 'chris', 'after' => 0]
		);

		(new TaskAbsenceListener(jobs: $this->jobs, logger: $this->logger))->handle(new OutOfOfficeStartedEvent($this->absence));
	}//end testAStartQueuesTheJob()

	/**
	 * An end queues the job in end mode.
	 *
	 * @return void
	 */
	public function testAnEndQueuesTheJob(): void {
		$this->jobs->expects($this->once())->method('add')->with(
			TaskSubstitutionJob::class,
			$this->callback(static fn (array $a): bool => $a['mode'] === 'end' && $a['uid'] === 'anna' && $a['standIn'] === 'chris')
		);

		(new TaskAbsenceListener(jobs: $this->jobs, logger: $this->logger))->handle(new OutOfOfficeEndedEvent($this->absence));
	}//end testAnEndQueuesTheJob()

	/**
	 * Any other event is ignored; a queue that fails is logged, not thrown.
	 *
	 * @return void
	 */
	public function testOtherEventsAndQueueFailures(): void {
		$listener = new TaskAbsenceListener(jobs: $this->jobs, logger: $this->logger);
		$this->jobs->expects($this->once())->method('add')->willThrowException(new RuntimeException('jobs table locked'));
		$this->logger->expects($this->once())->method('warning');

		$listener->handle(new Event());
		$listener->handle(new OutOfOfficeStartedEvent($this->absence));
	}//end testOtherEventsAndQueueFailures()
}//end class
