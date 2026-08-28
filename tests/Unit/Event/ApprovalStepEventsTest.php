<?php

namespace Unit\Event;

use OCA\OpenRegister\Db\ApprovalChain;
use OCA\OpenRegister\Db\ApprovalStep;
use OCA\OpenRegister\Event\ApprovalStepApprovedEvent;
use OCA\OpenRegister\Event\ApprovalStepCompletedEvent;
use OCA\OpenRegister\Event\ApprovalStepInitiatedEvent;
use OCA\OpenRegister\Event\ApprovalStepRejectedEvent;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for the four approval-step events introduced by
 * the `add-approval-step-events` change. Each event MUST round-trip
 * its constructor arguments through its public accessors so downstream
 * listeners (docudesk signing, decidesk decisions) can rely on them.
 */
class ApprovalStepEventsTest extends TestCase {
	public function testInitiatedEventExposesChainStepAndObjectUuid(): void {
		$chain = new ApprovalChain();
		$chain->hydrate(['name' => 'doc-signing-chain']);

		$step = new ApprovalStep();
		$step->hydrate(['stepOrder' => 1, 'role' => 'signer', 'status' => 'pending']);

		$event = new ApprovalStepInitiatedEvent($chain, $step, 'object-uuid-abc');

		$this->assertSame($chain, $event->getChain());
		$this->assertSame($step, $event->getStep());
		$this->assertSame('object-uuid-abc', $event->getObjectUuid());
	}

	public function testApprovedEventNonFinalStepExposesNextStep(): void {
		$chain = new ApprovalChain();
		$chain->hydrate(['name' => 'two-step']);

		$step = new ApprovalStep();
		$step->hydrate(['stepOrder' => 1, 'objectUuid' => 'obj-xyz']);

		$next = new ApprovalStep();
		$next->hydrate(['stepOrder' => 2, 'objectUuid' => 'obj-xyz', 'status' => 'pending']);

		$event = new ApprovalStepApprovedEvent($chain, $step, 'alice', 'in-review', $next);

		$this->assertSame($chain, $event->getChain());
		$this->assertSame($step, $event->getStep());
		$this->assertSame('alice', $event->getUserId());
		$this->assertSame('in-review', $event->getStatusOnApprove());
		$this->assertSame($next, $event->getNextStep());
		$this->assertFalse($event->isFinalStep());
		$this->assertSame('obj-xyz', $event->getObjectUuid());
	}

	public function testApprovedEventFinalStepReportsNoNext(): void {
		$chain = new ApprovalChain();
		$step = new ApprovalStep();
		$step->hydrate(['objectUuid' => 'final-obj']);

		$event = new ApprovalStepApprovedEvent($chain, $step, 'bob', 'approved', null);

		$this->assertTrue($event->isFinalStep());
		$this->assertNull($event->getNextStep());
		$this->assertSame('final-obj', $event->getObjectUuid());
	}

	public function testRejectedEventExposesStatusOnRejectAndUser(): void {
		$chain = new ApprovalChain();
		$step = new ApprovalStep();
		$step->hydrate(['objectUuid' => 'reject-obj']);

		$event = new ApprovalStepRejectedEvent($chain, $step, 'carol', 'rejected-by-legal');

		$this->assertSame($chain, $event->getChain());
		$this->assertSame($step, $event->getStep());
		$this->assertSame('carol', $event->getUserId());
		$this->assertSame('rejected-by-legal', $event->getStatusOnReject());
		$this->assertSame('reject-obj', $event->getObjectUuid());
	}

	public function testCompletedEventReportsTerminalStatus(): void {
		$chain = new ApprovalChain();
		$chain->hydrate(['name' => 'final-chain']);

		$final = new ApprovalStep();
		$final->hydrate(['objectUuid' => 'done-obj', 'stepOrder' => 3]);

		$event = new ApprovalStepCompletedEvent($chain, $final, 'dave', 'goedgekeurd');

		$this->assertSame($chain, $event->getChain());
		$this->assertSame($final, $event->getFinalStep());
		$this->assertSame('dave', $event->getUserId());
		$this->assertSame('goedgekeurd', $event->getStatusOnApprove());
		$this->assertSame('done-obj', $event->getObjectUuid());
	}
}
