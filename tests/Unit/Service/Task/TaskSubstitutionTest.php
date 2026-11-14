<?php

/**
 * TaskSubstitution: a task assigned to an absent person goes to the stand-in
 * their Nextcloud absence names, when the stand-in holds the mandate.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Task
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

namespace OCA\OpenRegister\Tests\Unit\Service\Task;

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Service\Task\TaskMandateGuard;
use OCA\OpenRegister\Service\Task\TaskSubstitution;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\User\IAvailabilityCoordinator;
use OCP\User\IOutOfOfficeData;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Design D-4 and D-6.
 *
 * @covers \OCA\OpenRegister\Service\Task\TaskSubstitution
 * @uses \OCA\OpenRegister\Service\Task\TaskAct
 * @uses \OCA\OpenRegister\Db\Task
 */
class TaskSubstitutionTest extends TestCase {

	private IAvailabilityCoordinator&MockObject $availability;

	private IUserManager&MockObject $users;

	private TaskMandateGuard&MockObject $mandates;

	private IOutOfOfficeData&MockObject $absence;

	private LoggerInterface&MockObject $logger;

	/**
	 * Which users exist, and whether each is enabled.
	 *
	 * @var array<string, bool>
	 */
	private array $known = ['anna' => true, 'chris' => true, 'dora' => false];

	/**
	 * Anna is away 2026-10-05 to 2026-10-16 (Amsterdam), naming Chris.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->availability = $this->createMock(IAvailabilityCoordinator::class);
		$this->users = $this->createMock(IUserManager::class);
		$this->mandates = $this->createMock(TaskMandateGuard::class);
		$this->absence = $this->createMock(IOutOfOfficeData::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->users->method('get')->willReturnCallback(fn (string $uid): ?IUser => $this->user(uid: $uid));
		$this->absence->method('getId')->willReturn('42');
		// Midnight and end of day in Europe/Amsterdam (CEST, UTC+2).
		$this->absence->method('getStartDate')->willReturn(strtotime('2026-10-04T22:00:00Z'));
		$this->absence->method('getEndDate')->willReturn(strtotime('2026-10-16T21:59:59Z'));
		$this->absence->expects($this->never())->method('getMessage');
		$this->absence->expects($this->never())->method('getShortMessage');
		$this->mandates->method('verbFor')->willReturn('decide');
	}//end setUp()

	/**
	 * A user mock, or null for a uid nobody has.
	 *
	 * @param string $uid The uid.
	 *
	 * @return IUser|null The user.
	 */
	private function user(string $uid): ?IUser {
		if (isset($this->known[$uid]) === false) {
			return null;
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('isEnabled')->willReturn($this->known[$uid]);

		return $user;
	}//end user()

	/**
	 * The service with Anna's absence as given.
	 *
	 * @param bool        $enabled     Whether the absence feature is on.
	 * @param bool        $inEffect    Whether the absence is in effect.
	 * @param string|null $replacement Who it names.
	 *
	 * @return TaskSubstitution The service.
	 */
	private function substitution(bool $enabled = true, bool $inEffect = true, ?string $replacement = 'chris'): TaskSubstitution {
		$this->availability->method('isEnabled')->willReturn($enabled);
		$this->availability->method('getCurrentOutOfOfficeData')->willReturnCallback(
			fn (IUser $user): ?IOutOfOfficeData => ($user->getUID() === 'anna') ? $this->absence : null
		);
		$this->availability->method('isInEffect')->willReturn($inEffect);
		$this->absence->method('getReplacementUserId')->willReturn($replacement);

		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturn('Europe/Amsterdam');

		return new TaskSubstitution(
			availability: $this->availability,
			users: $this->users,
			mandates: $this->mandates,
			config: $config,
			logger: $this->logger
		);
	}//end substitution()

	/**
	 * A user task assigned to Anna.
	 *
	 * @return Task The task.
	 */
	private function task(): Task {
		$task = new Task();
		$task->setUuid('t-1');
		$task->setPerformerType(Task::PERFORMER_USER);
		$task->setAssignee('anna');

		return $task;
	}//end task()

	/**
	 * The task goes to Chris for Anna, audited with the absence and its period.
	 *
	 * @return void
	 */
	public function testAnAbsentAssigneeWithAReplacementIsSubstituted(): void {
		$evidence = ['verb' => 'read', 'source' => 'schema', 'rule' => 'chris', 'until' => null];
		$task = $this->task();
		$this->mandates->expects($this->once())->method('evidenceFor')->with($task, 'chris')->willReturn($evidence);

		$act = $this->substitution()->routeIfAbsent(task: $task);

		$this->assertSame('chris', $task->getAssignee());
		$this->assertSame('anna', $task->getOnBehalfOf());
		$this->assertSame($evidence, $task->getMandateEvidence());
		$this->assertSame('substitute', $act?->verb);
		$this->assertSame('absence:42', $act?->actor);
		$this->assertSame("'anna' is absent 2026-10-05 to 2026-10-16; 'chris' stands in.", $act?->reason);
	}//end testAnAbsentAssigneeWithAReplacementIsSubstituted()

	/**
	 * Nothing to check stores no evidence.
	 *
	 * @return void
	 */
	public function testASubstitutionWithNothingToCheckStoresNoEvidence(): void {
		$task = $this->task();
		$this->mandates->method('evidenceFor')->willReturn([]);

		$this->assertSame('substitute', $this->substitution()->routeIfAbsent(task: $task)?->verb);
		$this->assertNull($task->getMandateEvidence());
	}//end testASubstitutionWithNothingToCheckStoresNoEvidence()

	/**
	 * An absence naming nobody leaves the task where it is.
	 *
	 * @return void
	 */
	public function testAnAbsenceWithoutAReplacementChangesNothing(): void {
		$task = $this->task();
		$this->mandates->expects($this->never())->method('evidenceFor');

		$this->assertNull($this->substitution(replacement: null)->routeIfAbsent(task: $task));
		$this->assertSame('anna', $task->getAssignee());
		$this->assertTrue($this->substitution(replacement: null)->isAbsent(uid: 'anna'));
	}//end testAnAbsenceWithoutAReplacementChangesNothing()

	/**
	 * An absence that is not in effect changes nothing.
	 *
	 * @return void
	 */
	public function testAnAbsenceNotInEffectChangesNothing(): void {
		$task = $this->task();

		$this->assertNull($this->substitution(inEffect: false)->routeIfAbsent(task: $task));
		$this->assertSame('anna', $task->getAssignee());
		$this->assertFalse($this->substitution(inEffect: false)->isAbsent(uid: 'anna'));
	}//end testAnAbsenceNotInEffectChangesNothing()

	/**
	 * With the absence feature disabled nobody is absent.
	 *
	 * @return void
	 */
	public function testTheFeatureDisabledChangesNothing(): void {
		$this->availability->expects($this->never())->method('getCurrentOutOfOfficeData');
		$task = $this->task();

		$this->assertNull($this->substitution(enabled: false)->routeIfAbsent(task: $task));
		$this->assertSame('anna', $task->getAssignee());
	}//end testTheFeatureDisabledChangesNothing()

	/**
	 * A stand-in without the mandate is not used, and the refusal names the verb.
	 *
	 * @return void
	 */
	public function testAStandInWithoutTheMandateIsNotUsed(): void {
		$task = $this->task();
		$task->setRequiredMandate('decide');
		$this->mandates->method('evidenceFor')->willReturn(null);

		$act = $this->substitution()->routeIfAbsent(task: $task);

		$this->assertSame('anna', $task->getAssignee());
		$this->assertNull($task->getOnBehalfOf());
		$this->assertSame('substitute-refused', $act?->verb);
		$this->assertSame('absence:42', $act?->actor);
		$this->assertStringContainsString("mandate 'decide'", (string)$act?->reason);
		$this->assertStringContainsString("'chris'", (string)$act?->reason);
	}//end testAStandInWithoutTheMandateIsNotUsed()

	/**
	 * A disabled or self-naming stand-in is no stand-in.
	 *
	 * @return void
	 */
	public function testADisabledOrSelfStandInIsIgnored(): void {
		$this->assertNull($this->substitution(replacement: 'dora')->routeIfAbsent(task: $this->task()));
		$this->assertNull($this->substitution(replacement: 'anna')->absenceOf(uid: 'anna')['replacement']);
	}//end testADisabledOrSelfStandInIsIgnored()

	/**
	 * Only a user performer is ever absent.
	 *
	 * @return void
	 */
	public function testAGroupPerformerIsNeverSubstituted(): void {
		$task = $this->task();
		$task->setPerformerType('group');
		$this->availability->expects($this->never())->method('isEnabled');

		$this->assertNull($this->substitution()->routeIfAbsent(task: $task));
	}//end testAGroupPerformerIsNeverSubstituted()

	/**
	 * An absence that cannot be read leaves the task alone, and is logged.
	 *
	 * @return void
	 */
	public function testABrokenAbsenceReadChangesNothing(): void {
		$this->availability->method('isEnabled')->willReturn(true);
		$this->availability->method('getCurrentOutOfOfficeData')->willThrowException(new RuntimeException('calendar down'));
		$this->logger->expects($this->once())->method('warning');
		$substitution = new TaskSubstitution(availability: $this->availability, users: $this->users, mandates: $this->mandates, logger: $this->logger);

		$this->assertNull($substitution->routeIfAbsent(task: $this->task()));
	}//end testABrokenAbsenceReadChangesNothing()

	/**
	 * Without the coordinator, nobody is absent.
	 *
	 * @return void
	 */
	public function testWithoutACoordinatorNobodyIsAbsent(): void {
		$this->assertFalse((new TaskSubstitution())->isAbsent(uid: 'anna'));
	}//end testWithoutACoordinatorNobodyIsAbsent()
}//end class
