<?php

/**
 * ApprovalChainGateListener enforcement tests (flow-approval-consolidation).
 *
 * Exercises the ObjectUpdatingEvent gate against the SEQUENCE store:
 *  - an ungated transition (no matching declared chain) passes untouched;
 *  - the first gated attempt provisions a sequence and BLOCKS
 *    (`approval-chain-pending`);
 *  - a running sequence blocks a repeat attempt WITHOUT provisioning again;
 *  - amount-threshold routing freezes the correct single tier;
 *  - a completed sequence RELEASES the transition;
 *  - a rejected sequence is KEPT and a new one opened on the next attempt;
 *  - an uncompilable declaration fails CLOSED (`approval-chain-misconfigured`).
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
 * @spec openspec/changes/flow-approval-consolidation/specs/approval-workflow/spec.md#req-007
 */

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\TaskSequence;
use OCA\OpenRegister\Db\TaskSequenceMapper;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Listener\ApprovalChainGateListener;
use OCA\OpenRegister\Service\ApprovalChainAnnotationInstaller;
use OCA\OpenRegister\Service\Task\TaskSequenceService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Listener\ApprovalChainGateListener
 * @covers \OCA\OpenRegister\Service\ApprovalChainAnnotationInstaller
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Db\TaskSequence
 * @uses \OCA\OpenRegister\Event\ObjectUpdatingEvent
 */
class ApprovalChainGateListenerTest extends TestCase {
	private SchemaMapper&MockObject $schemaMapper;
	private TaskSequenceMapper&MockObject $sequenceMapper;
	private TaskSequenceService&MockObject $sequenceService;
	private IUserSession&MockObject $userSession;
	private ApprovalChainGateListener $listener;

	protected function setUp(): void {
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->sequenceMapper = $this->createMock(TaskSequenceMapper::class);
		$this->sequenceService = $this->createMock(TaskSequenceService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$logger = $this->createMock(LoggerInterface::class);

		$this->loginAs('requester1');

		// The REAL compiler on purpose: it is a pure function of the schema,
		// and the gate's contract includes what the compiler derives.
		$this->listener = new ApprovalChainGateListener(
			$this->schemaMapper,
			$this->sequenceMapper,
			$this->sequenceService,
			new ApprovalChainAnnotationInstaller(logger: $logger),
			$this->userSession,
			$logger
		);
	}//end setUp()

	private function loginAs(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}//end loginAs()

	/**
	 * Schema with a `submit` lifecycle transition and a declared
	 * `submit-approval` chain gating it, amount-routed between two tiers.
	 */
	private function gatedSchema(array $approvers = null): Schema {
		$schema = new Schema();
		$schema->setId(5);
		$schema->setSlug('test-commitment');
		$schema->setConfiguration(
			[
				'x-openregister-lifecycle' => [
					'field' => 'status',
					'transitions' => [
						'submit' => ['from' => 'draft', 'to' => 'submitted'],
					],
				],
				'x-openregister-approval-chains' => [
					'submit-approval' => [
						'transition' => 'submit',
						'amountField' => 'amount',
						'separationOfDuties' => true,
						'onApprove' => 'advanceTransition',
						'approvers' => ($approvers ?? [
							['role' => 'finance-clerks', 'min' => 1, 'minAmount' => 0],
							['role' => 'finance-directors', 'min' => 1, 'minAmount' => 100000],
						]),
					],
				],
			]
		);

		$this->schemaMapper->method('find')->willReturn($schema);
		return $schema;
	}//end gatedSchema()

	/**
	 * Ungated schema — lifecycle transition declared, no
	 * x-openregister-approval-chains at all.
	 */
	private function ungatedSchema(): Schema {
		$schema = new Schema();
		$schema->setId(6);
		$schema->setSlug('test-plain');
		$schema->setConfiguration(
			[
				'x-openregister-lifecycle' => [
					'field' => 'status',
					'transitions' => [
						'submit' => ['from' => 'draft', 'to' => 'submitted'],
					],
				],
			]
		);

		$this->schemaMapper->method('find')->willReturn($schema);
		return $schema;
	}//end ungatedSchema()

	private function event(string $schemaSlug, string $oldStatus, string $newStatus, int $amount = 5000): ObjectUpdatingEvent {
		$old = new ObjectEntity();
		$old->setSchema($schemaSlug);
		$old->setUuid('obj-1');
		$old->setObject(['status' => $oldStatus, 'amount' => $amount]);

		$new = new ObjectEntity();
		$new->setSchema($schemaSlug);
		$new->setUuid('obj-1');
		$new->setObject(['status' => $newStatus, 'amount' => $amount]);

		return new ObjectUpdatingEvent(newObject: $new, oldObject: $old);
	}//end event()

	private function sequence(string $status): TaskSequence {
		$sequence = new TaskSequence();
		$sequence->setUuid('seq-1');
		$sequence->setStatus($status);
		return $sequence;
	}//end sequence()

	public function testUngatedTransitionPassesUntouched(): void {
		$this->ungatedSchema();
		$event = $this->event('test-plain', 'draft', 'submitted');

		$this->sequenceService->expects($this->never())->method('provision');

		$this->listener->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testUngatedTransitionPassesUntouched()

	public function testFirstGatedAttemptProvisionsASequenceAndIsBlocked(): void {
		$this->gatedSchema();
		$event = $this->event('test-commitment', 'draft', 'submitted', amount: 5000);

		$this->sequenceMapper->method('findNewestForAnchor')->willReturn(null);
		$this->sequenceService->expects($this->once())
			->method('provision')
			->with(
				$this->callback(
					static fn (array $template): bool => ($template['name'] === 'submit-approval')
						&& (count($template['positions']) === 2)
				),
				'obj-1',
				'requester1',
				[['order' => 1, 'role' => 'finance-clerks', 'min' => 1, 'minAmount' => 0]]
			);

		$this->listener->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame('approval-chain-pending', $event->getErrors()['code']);
	}//end testFirstGatedAttemptProvisionsASequenceAndIsBlocked()

	public function testARunningSequenceBlocksWithoutProvisioningAgain(): void {
		$this->gatedSchema();
		$event = $this->event('test-commitment', 'draft', 'submitted');

		$this->sequenceMapper->method('findNewestForAnchor')->willReturn($this->sequence(TaskSequence::STATUS_RUNNING));
		$this->sequenceService->expects($this->never())->method('provision');

		$this->listener->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame('approval-chain-pending', $event->getErrors()['code']);
	}//end testARunningSequenceBlocksWithoutProvisioningAgain()

	public function testACompletedSequenceReleasesTheTransition(): void {
		$this->gatedSchema();
		$event = $this->event('test-commitment', 'draft', 'submitted');

		$this->sequenceMapper->method('findNewestForAnchor')->willReturn($this->sequence(TaskSequence::STATUS_COMPLETED));
		$this->sequenceService->expects($this->never())->method('provision');

		$this->listener->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testACompletedSequenceReleasesTheTransition()

	public function testARejectedSequenceIsKeptAndANewOneOpens(): void {
		$this->gatedSchema();
		$event = $this->event('test-commitment', 'draft', 'submitted');

		// The rejected sequence is the NEWEST one — it was not deleted.
		$this->sequenceMapper->method('findNewestForAnchor')->willReturn($this->sequence(TaskSequence::STATUS_REJECTED));
		$this->sequenceService->expects($this->once())->method('provision');

		$this->listener->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame('approval-chain-pending', $event->getErrors()['code']);
	}//end testARejectedSequenceIsKeptAndANewOneOpens()

	public function testHighAmountObjectFreezesTheHigherTier(): void {
		$this->gatedSchema();
		$event = $this->event('test-commitment', 'draft', 'submitted', amount: 250000);

		$this->sequenceMapper->method('findNewestForAnchor')->willReturn(null);
		$this->sequenceService->expects($this->once())
			->method('provision')
			->with(
				$this->anything(),
				'obj-1',
				'requester1',
				[['order' => 1, 'role' => 'finance-directors', 'min' => 1, 'minAmount' => 100000]]
			);

		$this->listener->handle($event);

		$this->assertTrue($event->isPropagationStopped());
	}//end testHighAmountObjectFreezesTheHigherTier()

	public function testAnUncompilableChainFailsClosed(): void {
		// Declared, but with no usable approver at all.
		$this->gatedSchema(approvers: [['min' => 1]]);
		$event = $this->event('test-commitment', 'draft', 'submitted');

		$this->sequenceService->expects($this->never())->method('provision');

		$this->listener->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame('approval-chain-misconfigured', $event->getErrors()['code']);
	}//end testAnUncompilableChainFailsClosed()
}//end class
