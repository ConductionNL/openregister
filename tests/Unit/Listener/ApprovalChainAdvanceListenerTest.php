<?php

/**
 * ApprovalChainAdvanceListener tests (approval-chains-declarative).
 *
 * Exercises the `ApprovalStepCompletedEvent` → `TransitionEngine::transition()`
 * auto-advance wiring:
 *  - a chain whose schema declares `onApprove: advanceTransition` invokes the
 *    declared transition on completion;
 *  - a chain with no matching declarative entry does NOT invoke it;
 *  - a chain declaring a different `onApprove` value does NOT invoke it.
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
 * @spec openspec/changes/approval-chains-declarative/specs/approval-workflow/spec.md
 */

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenRegister\Db\ApprovalChain;
use OCA\OpenRegister\Db\ApprovalStep;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ApprovalStepCompletedEvent;
use OCA\OpenRegister\Listener\ApprovalChainAdvanceListener;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @coversDefaultClass \OCA\OpenRegister\Listener\ApprovalChainAdvanceListener
 */
class ApprovalChainAdvanceListenerTest extends TestCase {
	private SchemaMapper&MockObject $schemaMapper;
	private TransitionEngine&MockObject $transitionEngine;
	private ApprovalChainAdvanceListener $listener;

	protected function setUp(): void {
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->transitionEngine = $this->createMock(TransitionEngine::class);
		$logger = $this->createMock(LoggerInterface::class);

		$this->listener = new ApprovalChainAdvanceListener(
			$this->schemaMapper,
			$this->transitionEngine,
			$logger
		);
	}//end setUp()

	private function completedEvent(int $schemaId, string $chainName): ApprovalStepCompletedEvent {
		$chain = new ApprovalChain();
		$chain->setId(10);
		$chain->hydrate(['name' => $chainName, 'schemaId' => $schemaId]);

		$step = new ApprovalStep();
		$step->hydrate(['objectUuid' => 'obj-1', 'stepOrder' => 1]);

		return new ApprovalStepCompletedEvent(
			chain: $chain,
			finalStep: $step,
			userId: 'director-1',
			statusOnApprove: 'approved'
		);
	}//end completedEvent()

	public function testAdvanceTransitionInvokesTransitionEngine(): void {
		$schema = new Schema();
		$schema->setId(5);
		$schema->setConfiguration(
			[
				'x-openregister-approval-chains' => [
					'submit-approval' => [
						'transition' => 'submit',
						'approvers' => [['role' => 'finance-clerks', 'min' => 1]],
						'onApprove' => 'advanceTransition',
					],
				],
			]
		);
		$this->schemaMapper->method('find')->willReturn($schema);

		$this->transitionEngine->expects($this->once())
			->method('transition')
			->with(objectId: 'obj-1', action: 'submit');

		$this->listener->handle($this->completedEvent(5, 'submit-approval'));
	}//end testAdvanceTransitionInvokesTransitionEngine()

	public function testNoMatchingDeclarativeEntryDoesNotAdvance(): void {
		$schema = new Schema();
		$schema->setId(5);
		$schema->setConfiguration([]);
		$this->schemaMapper->method('find')->willReturn($schema);

		$this->transitionEngine->expects($this->never())->method('transition');

		$this->listener->handle($this->completedEvent(5, 'legacy-crud-chain'));
	}//end testNoMatchingDeclarativeEntryDoesNotAdvance()

	public function testDifferentOnApproveValueDoesNotAdvance(): void {
		$schema = new Schema();
		$schema->setId(5);
		$schema->setConfiguration(
			[
				'x-openregister-approval-chains' => [
					'submit-approval' => [
						'transition' => 'submit',
						'approvers' => [['role' => 'finance-clerks', 'min' => 1]],
						'onApprove' => 'notifyOnly',
					],
				],
			]
		);
		$this->schemaMapper->method('find')->willReturn($schema);

		$this->transitionEngine->expects($this->never())->method('transition');

		$this->listener->handle($this->completedEvent(5, 'submit-approval'));
	}//end testDifferentOnApproveValueDoesNotAdvance()
}//end class
