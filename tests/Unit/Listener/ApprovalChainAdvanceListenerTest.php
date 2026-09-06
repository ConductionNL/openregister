<?php

/**
 * ApprovalChainAdvanceListener tests (flow-approval-consolidation).
 *
 * Exercises the `TaskSequenceCompletedEvent` → `TransitionEngine::transition()`
 * auto-advance wiring:
 *  - a sequence whose schema declares `onApprove: advanceTransition` invokes
 *    the declared transition on completion;
 *  - a sequence with no matching declarative entry does NOT invoke it;
 *  - a sequence declaring a different `onApprove` value does NOT invoke it;
 *  - a transition that throws is logged and does NOT undo the completion.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/flow-approval-consolidation/specs/approval-workflow/spec.md#req-010
 */

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskSequence;
use OCA\OpenRegister\Event\TaskSequenceCompletedEvent;
use OCA\OpenRegister\Listener\ApprovalChainAdvanceListener;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Listener\ApprovalChainAdvanceListener
 * @covers \OCA\OpenRegister\Event\TaskSequenceCompletedEvent
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Db\Task
 * @uses \OCA\OpenRegister\Db\TaskSequence
 */
class ApprovalChainAdvanceListenerTest extends TestCase {
	private SchemaMapper&MockObject $schemaMapper;
	private TransitionEngine&MockObject $transitionEngine;
	private LoggerInterface&MockObject $logger;
	private ApprovalChainAdvanceListener $listener;

	protected function setUp(): void {
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->transitionEngine = $this->createMock(TransitionEngine::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->listener = new ApprovalChainAdvanceListener(
			$this->schemaMapper,
			$this->transitionEngine,
			$this->logger
		);
	}//end setUp()

	private function schemaDeclaring(?array $entry): void {
		$schema = new Schema();
		$schema->setId(5);
		$chains = [];
		if ($entry !== null) {
			$chains['submit-approval'] = $entry;
		}

		$schema->setConfiguration(['x-openregister-approval-chains' => $chains]);
		$this->schemaMapper->method('find')->willReturn($schema);
	}//end schemaDeclaring()

	private function completedEvent(string $chainKey = 'submit-approval'): TaskSequenceCompletedEvent {
		$sequence = new TaskSequence();
		$sequence->setUuid('seq-1');
		$sequence->setSchemaId(5);
		$sequence->setChainKey($chainKey);
		$sequence->setAnchorObjectUuid('obj-1');
		$sequence->setStatus(TaskSequence::STATUS_COMPLETED);

		$task = new Task();
		$task->setUuid('task-9');

		return new TaskSequenceCompletedEvent(
			sequence: $sequence,
			finalTask: $task,
			decider: 'director1',
			statusOnApprove: 'approved'
		);
	}//end completedEvent()

	public function testAdvanceTransitionInvokesTransitionEngine(): void {
		$this->schemaDeclaring(['transition' => 'submit', 'onApprove' => 'advanceTransition']);

		$this->transitionEngine->expects($this->once())
			->method('transition')
			->with('obj-1', 'submit');

		$this->listener->handle($this->completedEvent());
	}//end testAdvanceTransitionInvokesTransitionEngine()

	public function testNoMatchingDeclarativeEntryDoesNotAdvance(): void {
		$this->schemaDeclaring(null);

		$this->transitionEngine->expects($this->never())->method('transition');

		$this->listener->handle($this->completedEvent());
	}//end testNoMatchingDeclarativeEntryDoesNotAdvance()

	public function testDifferentOnApproveValueDoesNotAdvance(): void {
		$this->schemaDeclaring(['transition' => 'submit', 'onApprove' => 'notify']);

		$this->transitionEngine->expects($this->never())->method('transition');

		$this->listener->handle($this->completedEvent());
	}//end testDifferentOnApproveValueDoesNotAdvance()

	public function testAFailingAdvanceIsLoggedAndSwallowed(): void {
		$this->schemaDeclaring(['transition' => 'submit', 'onApprove' => 'advanceTransition']);

		$this->transitionEngine->method('transition')
			->willThrowException(new RuntimeException('guard refused'));
		$this->logger->expects($this->once())->method('warning')
			->with($this->stringContains('auto-advance "submit" for object obj-1 failed'));

		$this->listener->handle($this->completedEvent());
	}//end testAFailingAdvanceIsLoggedAndSwallowed()
}//end class
