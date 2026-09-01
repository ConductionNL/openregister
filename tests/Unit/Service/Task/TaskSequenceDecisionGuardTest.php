<?php

/**
 * Separation of duties on a sequence position: the requester may not decide,
 * directly or through a delegate, unless the frozen declaration opts out
 * (flow-approval-consolidation task 2.2, REQ-009).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Task
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Task;

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskSequence;
use OCA\OpenRegister\Db\TaskSequenceMapper;
use OCA\OpenRegister\Exception\TaskSeparationOfDutiesException;
use OCA\OpenRegister\Service\Task\TaskSequenceDecisionGuard;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Task\TaskSequenceDecisionGuard
 * @covers \OCA\OpenRegister\Exception\TaskSeparationOfDutiesException
 */
class TaskSequenceDecisionGuardTest extends TestCase {

	private TaskSequenceMapper&MockObject $sequences;
	private TaskSequenceDecisionGuard $guard;

	protected function setUp(): void {
		parent::setUp();
		$this->sequences = $this->createMock(TaskSequenceMapper::class);
		$this->guard = new TaskSequenceDecisionGuard(sequences: $this->sequences);
	}//end setUp()

	private function sequenceWith(?string $requester, mixed $separationOfDuties = null): void {
		$sequence = new TaskSequence();
		$sequence->setUuid('seq-1');
		$sequence->setRequesterId($requester);
		$snapshot = ['name' => 'submit-approval'];
		if ($separationOfDuties !== null) {
			$snapshot['separationOfDuties'] = $separationOfDuties;
		}

		$sequence->setTemplateSnapshot($snapshot);
		$this->sequences->method('findByUuid')->willReturn($sequence);
	}//end sequenceWith()

	private function task(?string $onBehalfOf = null): Task {
		$task = new Task();
		$task->setUuid('task-1');
		$task->setSequenceUuid('seq-1');
		$task->setOnBehalfOf($onBehalfOf);

		return $task;
	}//end task()

	public function testTheRequesterMayNotDecideTheirOwnSequence(): void {
		$this->sequenceWith(requester: 'alice');

		$this->expectException(TaskSeparationOfDutiesException::class);
		$this->expectExceptionMessage('Separation of duties');
		$this->guard->assertDecidable(task: $this->task(), actor: 'alice');
	}//end testTheRequesterMayNotDecideTheirOwnSequence()

	public function testTheRefusalNamesTheRule_NotAuthorization(): void {
		$this->sequenceWith(requester: 'alice');

		try {
			$this->guard->assertDecidable(task: $this->task(), actor: 'alice');
			self::fail('the self-decision must be refused');
		} catch (TaskSeparationOfDutiesException $refusal) {
			self::assertStringContainsString('not an authorization failure', $refusal->getMessage());
		}
	}//end testTheRefusalNamesTheRule_NotAuthorization()

	public function testADelegatedSelfDecisionIsRefusedOnTheSameGrounds(): void {
		$this->sequenceWith(requester: 'alice');

		$this->expectException(TaskSeparationOfDutiesException::class);
		$this->expectExceptionMessage('delegated self-decision');
		$this->guard->assertDecidable(task: $this->task(onBehalfOf: 'alice'), actor: 'deputy');
	}//end testADelegatedSelfDecisionIsRefusedOnTheSameGrounds()

	public function testAnExplicitOptOutLetsTheRequesterDecide(): void {
		$this->sequenceWith(requester: 'alice', separationOfDuties: false);

		$this->guard->assertDecidable(task: $this->task(), actor: 'alice');
		self::assertTrue(true, 'no refusal');
	}//end testAnExplicitOptOutLetsTheRequesterDecide()

	public function testUnstatedPolicyDefaultsToOn(): void {
		// No separationOfDuties key in the frozen snapshot at all.
		$this->sequenceWith(requester: 'alice', separationOfDuties: null);

		$this->expectException(TaskSeparationOfDutiesException::class);
		$this->guard->assertDecidable(task: $this->task(), actor: 'alice');
	}//end testUnstatedPolicyDefaultsToOn()

	public function testADifferentDeciderPasses(): void {
		$this->sequenceWith(requester: 'alice');

		$this->guard->assertDecidable(task: $this->task(), actor: 'bob');
		self::assertTrue(true, 'no refusal');
	}//end testADifferentDeciderPasses()

	public function testASequenceWithoutARequesterIsNotRefused(): void {
		$this->sequenceWith(requester: null);

		$this->guard->assertDecidable(task: $this->task(), actor: 'alice');
		self::assertTrue(true, 'no refusal');
	}//end testASequenceWithoutARequesterIsNotRefused()

	public function testATaskOutsideAnySequencePasses(): void {
		$task = new Task();
		$task->setUuid('lone');

		$this->sequences->expects(self::never())->method('findByUuid');
		$this->guard->assertDecidable(task: $task, actor: 'alice');
		self::assertTrue(true, 'no refusal');
	}//end testATaskOutsideAnySequencePasses()

	public function testADanglingSequenceReferencePasses(): void {
		$this->sequences->method('findByUuid')->willThrowException(new DoesNotExistException('gone'));

		$this->guard->assertDecidable(task: $this->task(), actor: 'alice');
		self::assertTrue(true, 'no refusal; the performer check still applies');
	}//end testADanglingSequenceReferencePasses()
}//end class
