<?php

/**
 * ExampleSetTaskClaim refuses an inconsistent claim on a fragment task and
 * writes the claim audit entry only for a claimed task.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 * @author   Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use DateTime;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskAudit;
use OCA\OpenRegister\Db\TaskAuditMapperInterface;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetTaskClaim;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetTaskClaim
 * @uses   \OCA\OpenRegister\Db\Task
 * @uses   \OCA\OpenRegister\Db\TaskAudit
 */
class ExampleSetTaskClaimTest extends TestCase {

	/**
	 * A claim object over a container holding the given audit mapper.
	 *
	 * @param TaskAuditMapperInterface $mapper The audit mapper.
	 *
	 * @return ExampleSetTaskClaim
	 */
	private function claim(TaskAuditMapperInterface $mapper): ExampleSetTaskClaim {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(TaskAuditMapperInterface::class)->willReturn($mapper);
		return new ExampleSetTaskClaim($container);
	}

	/**
	 * An unclaimed task without claimedAt is fine; with claimedAt it is refused.
	 *
	 * @return void
	 */
	public function testUnclaimedTask(): void {
		$claim = $this->claim($this->createMock(TaskAuditMapperInterface::class));
		$this->assertSame('', $claim->refusal([], null));
		$this->assertSame('claimedAt needs claimedBy', $claim->refusal(['claimedAt' => '2020-01-01'], null));
	}

	/**
	 * Only the assignee may have claimed the task.
	 *
	 * @return void
	 */
	public function testClaimerMustBeAssignee(): void {
		$claim = $this->claim($this->createMock(TaskAuditMapperInterface::class));
		$this->assertSame('claimedBy is the assignee: "bob" is not', $claim->refusal(['claimedBy' => 'bob', 'assignee' => 'alice'], null));
		$this->assertSame('claimedBy is the assignee: "bob" is not', $claim->refusal(['claimedBy' => 'bob'], null));
	}

	/**
	 * A claim moment in the future is refused; a past or absent one is accepted.
	 *
	 * @return void
	 */
	public function testClaimMoment(): void {
		$claim = $this->claim($this->createMock(TaskAuditMapperInterface::class));
		$data  = ['claimedBy' => 'alice', 'assignee' => 'alice'];
		$this->assertSame('claimedAt lies in the future', $claim->refusal($data, new DateTime('2999-01-01')));
		$this->assertSame('', $claim->refusal($data, new DateTime('2020-01-01')));
		$this->assertSame('', $claim->refusal($data, null));
	}

	/**
	 * An unclaimed task writes no audit entry.
	 *
	 * @return void
	 */
	public function testUnclaimedTaskWritesNothing(): void {
		$mapper = $this->createMock(TaskAuditMapperInterface::class);
		$mapper->expects($this->never())->method('insert');
		$this->claim($mapper)->write(new Task(), ['claimedBy' => '']);
	}

	/**
	 * A claimed task writes one authorised claim entry at the claim moment.
	 *
	 * @return void
	 */
	public function testClaimedTaskWritesAuditEntry(): void {
		$task = new Task();
		$task->setId(42);
		$task->setState('claimed');
		$task->setPerformerType('internal');

		$written = null;
		$mapper  = $this->createMock(TaskAuditMapperInterface::class);
		$mapper->expects($this->once())->method('insert')->willReturnCallback(
			static function (TaskAudit $entry) use (&$written) {
				$written = $entry;
				return $entry;
			}
		);

		$this->claim($mapper)->write($task, ['claimedBy' => 'alice', 'claimedAt' => '2020-01-02T03:04:05+00:00']);

		$this->assertSame(
			[42, 'claim', 'claimed', 'alice', 'internal', true, '2020-01-02T03:04:05+00:00'],
			[
				$written->getTaskId(),
				$written->getAction(),
				$written->getStateAfter(),
				$written->getActor(),
				$written->getPerformerType(),
				$written->getAuthorized(),
				$written->getCreated()->format('c'),
			]
		);
	}

	/**
	 * Without a claim moment the entry is stamped now.
	 *
	 * @return void
	 */
	public function testClaimWithoutMomentIsStampedNow(): void {
		$written = null;
		$mapper  = $this->createMock(TaskAuditMapperInterface::class);
		$mapper->method('insert')->willReturnCallback(
			static function (TaskAudit $entry) use (&$written) {
				$written = $entry;
				return $entry;
			}
		);
		$before = new DateTime();
		$this->claim($mapper)->write(new Task(), ['claimedBy' => 'alice']);
		$this->assertGreaterThanOrEqual($before->getTimestamp(), $written->getCreated()->getTimestamp());
	}
}
