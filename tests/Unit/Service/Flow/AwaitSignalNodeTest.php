<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowNodeResumeState;
use OCA\OpenRegister\Service\Flow\FlowResumeState;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\Flow\FlowStop;
use OCA\OpenRegister\Service\Flow\FlowSuspension;
use OCA\OpenRegister\Service\Flow\Nodes\AwaitSignalNode;
use OCA\OpenRegister\Service\Flow\Nodes\SetFieldsNode;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

class AwaitSignalNodeTest extends TestCase {
	private AwaitSignalNode $node;

	protected function setUp(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$this->node = new AwaitSignalNode($l10n, $this->createMock(IURLGenerator::class));
	}

	/** @param array<int, array<string, mixed>> $records */
	private function items(array $records): array {
		return array_map(static fn (array $r): array => FlowItems::item(json: $r), $records);
	}

	/**
	 * The base case: with no answer, the node suspends rather than passing
	 * through. A node that fell through would approve everything by default,
	 * which is the failure mode worth being certain about.
	 */
	public function testItSuspendsWhenNothingHasAnswered(): void {
		$this->expectException(FlowSuspension::class);

		$this->node->execute($this->items([['id' => 1]]), ['question' => 'Publish it?'], []);
	}

	/**
	 * The positive control for the suspension itself: it must carry a heartbeat,
	 * because a suspension with no `resumeAt` is one nothing can ever wake — no
	 * query returns it, and `hasActiveRun()` counts it, so it holds its flow's
	 * schedule shut. Losing this assertion is losing the safety net.
	 */
	public function testTheSuspensionCarriesAHeartbeat(): void {
		try {
			$this->node->execute($this->items([['id' => 1]]), ['question' => 'Publish it?'], []);
			$this->fail('Expected the node to suspend.');
		} catch (FlowSuspension $suspension) {
			$this->assertNotNull(
				$suspension->getResumeAt(),
				'A signal-only suspension is unreachable; the node must also heartbeat.'
			);
			$this->assertGreaterThan(new \DateTime(), $suspension->getResumeAt());
		}
	}

	/**
	 * A heartbeat below the cron period cannot happen, so asking for one gets
	 * the floor rather than a promise the scheduler cannot keep.
	 */
	public function testAHeartbeatBelowTheCronPeriodIsClamped(): void {
		try {
			$this->node->execute(
				$this->items([['id' => 1]]),
				['question' => 'Publish it?', 'heartbeatMinutes' => 1],
				[]
			);
			$this->fail('Expected the node to suspend.');
		} catch (FlowSuspension $suspension) {
			$this->assertGreaterThan(
				(new \DateTime())->modify('+4 minutes'),
				$suspension->getResumeAt()
			);
		}
	}

	/**
	 * The reason a paused run can explain itself: the question travels with the
	 * suspension into the run log.
	 */
	public function testTheSuspensionReasonNamesTheQuestion(): void {
		try {
			$this->node->execute($this->items([['id' => 1]]), ['question' => 'Publish it?'], []);
			$this->fail('Expected the node to suspend.');
		} catch (FlowSuspension $suspension) {
			$this->assertStringContainsString('Publish it?', $suspension->getMessage());
		}
	}

	/**
	 * An answer lets the items through, carrying what was decided so the steps
	 * after it can route on it.
	 */
	public function testAnAnswerPassesTheItemsThroughCarryingTheDecision(): void {
		$out = $this->node->execute(
			$this->items([['id' => 1], ['id' => 2]]),
			['question' => 'Publish it?'],
			[FlowRunService::SIGNAL_CONTEXT_KEY => ['decision' => 'approve', 'by' => 'ruben']]
		);

		$this->assertCount(2, $out);
		foreach ($out as $item) {
			$this->assertSame('approve', $item[FlowItems::JSON]['signal']['decision']);
			$this->assertSame('ruben', $item[FlowItems::JSON]['signal']['by']);
		}
	}

	/**
	 * WHERE the answer lands is part of the contract: inside the item's record
	 * (`json.<key>`), never beside it at the envelope level. The engine's
	 * expression data (FlowExpression::dataFor) exposes `json.*` only, and a
	 * rebuilding node keeps only `[json, binary]` — an envelope-level key is
	 * invisible to a Switch and silently dropped by the next rebuild.
	 */
	public function testTheAnswerLandsInsideTheItemsRecordNotBesideIt(): void {
		$out = $this->node->execute(
			$this->items([['id' => 1]]),
			['question' => 'Publish it?'],
			[FlowRunService::SIGNAL_CONTEXT_KEY => ['decision' => 'approve']]
		);

		$this->assertArrayNotHasKey('signal', $out[0], 'the answer must not sit at the envelope level');
		$this->assertSame('approve', $out[0][FlowItems::JSON]['signal']['decision']);
		$this->assertSame(1, $out[0][FlowItems::JSON]['id'], 'the record itself is kept, not replaced');
	}

	/**
	 * Where the answer lands is configurable, because a flow with two await
	 * steps needs to keep both answers rather than have the second overwrite
	 * the first.
	 */
	public function testTheAnswerFieldIsConfigurable(): void {
		$out = $this->node->execute(
			$this->items([['id' => 1]]),
			['question' => 'Publish it?', 'signalKey' => 'legalReview'],
			[FlowRunService::SIGNAL_CONTEXT_KEY => ['decision' => 'approve']]
		);

		$this->assertSame('approve', $out[0][FlowItems::JSON]['legalReview']['decision']);
		$this->assertArrayNotHasKey('signal', $out[0][FlowItems::JSON]);
	}

	/**
	 * A resume with no decision is a NUDGE, not an answer. Without this a stray
	 * or duplicated POST to the resume endpoint would carry the flow past an
	 * approval nobody gave.
	 *
	 * @param mixed $signal The payload posted to the resume endpoint.
	 */
	#[DataProvider('nonAnswers')]
	public function testAResumeWithoutADecisionKeepsWaiting(mixed $signal): void {
		$this->expectException(FlowSuspension::class);

		$this->node->execute(
			$this->items([['id' => 1]]),
			['question' => 'Publish it?'],
			[FlowRunService::SIGNAL_CONTEXT_KEY => $signal]
		);
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function nonAnswers(): array {
		return [
			'empty body' => [[]],
			'a comment but no decision' => [['reason' => 'looking into it']],
			'a blank decision' => [['decision' => '   ']],
			'not a bag at all' => ['approve'],
			'nothing delivered' => [null],
		];
	}

	/**
	 * Being told "no" is the flow working, not breaking. The default carries on
	 * so the author can route on the decision; only an explicit opt-in turns a
	 * rejection into a failure.
	 */
	public function testARejectionIsNotAFailureByDefault(): void {
		$out = $this->node->execute(
			$this->items([['id' => 1]]),
			['question' => 'Publish it?'],
			[FlowRunService::SIGNAL_CONTEXT_KEY => ['decision' => 'reject', 'reason' => 'not ready']]
		);

		$this->assertSame('reject', $out[0][FlowItems::JSON]['signal']['decision']);
	}

	/**
	 * ...and the opt-in works, carrying the rejection's reason into the error so
	 * the run says WHY it stopped rather than only that it did.
	 */
	public function testFailOnRejectStopsTheRunWithTheReason(): void {
		try {
			$this->node->execute(
				$this->items([['id' => 1]]),
				['question' => 'Publish it?', 'failOnReject' => true],
				[FlowRunService::SIGNAL_CONTEXT_KEY => ['decision' => 'reject', 'reason' => 'not ready']]
			);
			$this->fail('Expected the node to stop the run.');
		} catch (FlowStop $stop) {
			$this->assertTrue($stop->isError());
			$this->assertStringContainsString('not ready', $stop->getMessage());
		}
	}

	/**
	 * The node writes what it is waiting for into its OWN resume slot, which is
	 * what lets a paused run be listed as "waiting on X since Y".
	 */
	public function testItRecordsWhatItIsWaitingForInItsResumeSlot(): void {
		$state = new FlowResumeState();
		$context = [FlowNodeResumeState::CONTEXT_KEY => $state->forNode(nodeId: 'approval')];

		try {
			$this->node->execute(
				$this->items([['id' => 1]]),
				['question' => 'Publish it?', 'assignee' => 'legal'],
				$context
			);
		} catch (FlowSuspension $suspension) {
			// Expected.
		}

		$slot = $state->forNode(nodeId: 'approval')->all();
		$this->assertSame('Publish it?', $slot['question']);
		$this->assertSame('legal', $slot['assignee']);
		$this->assertArrayHasKey('askedAt', $slot);
	}

	/**
	 * The heartbeat must not restamp `askedAt`. It fires every quarter of an
	 * hour, so restamping would make a fortnight-old request read as fifteen
	 * minutes old — the reading that stops anyone chasing it.
	 */
	public function testAHeartbeatDoesNotRestampWhenItWasAsked(): void {
		$state = new FlowResumeState();
		$state->forNode(nodeId: 'approval')->set(key: 'askedAt', value: '2026-08-01T09:00:00+00:00');
		$context = [FlowNodeResumeState::CONTEXT_KEY => $state->forNode(nodeId: 'approval')];

		try {
			$this->node->execute($this->items([['id' => 1]]), ['question' => 'Publish it?'], $context);
		} catch (FlowSuspension $suspension) {
			// Expected.
		}

		$this->assertSame(
			'2026-08-01T09:00:00+00:00',
			$state->forNode(nodeId: 'approval')->get(key: 'askedAt')
		);
	}

	/**
	 * A step that asks nothing cannot be answered, so the preflight rejects it
	 * rather than letting it suspend forever at run time.
	 */
	public function testAnAwaitThatAsksNothingIsRejected(): void {
		$this->expectException(UnexpectedValueException::class);

		$this->node->validateConfig(['question' => '  ']);
	}

	/**
	 * Every field the form offers must be a key the node actually reads. A
	 * field over an ignored key looks like it works and changes nothing.
	 */
	public function testEveryFormFieldWritesAKeyTheNodeReads(): void {
		$keys = $this->node->configKeys();

		foreach ($this->node->configForm() as $field) {
			$this->assertContains($field['key'], $keys);
		}
	}

	/**
	 * The regression that motivated json-level placement: set-fields rebuilds
	 * every item as `[json, binary]`, so an answer written at the envelope
	 * level did not survive the very next step. The flow in
	 * flow-user-task.spec.ts (two gates, then set-fields) read
	 * `item.firstGate` as undefined for exactly this reason.
	 */
	public function testTheAnswerSurvivesASetFieldsRebuild(): void {
		$answered = $this->node->execute(
			$this->items([['id' => 1]]),
			['question' => 'First gate?', 'signalKey' => 'firstGate'],
			[FlowRunService::SIGNAL_CONTEXT_KEY => ['decision' => 'approved', 'mark' => 'first']]
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$setFields = new SetFieldsNode($l10n, $this->createMock(IURLGenerator::class));

		$out = $setFields->execute($answered, ['set' => ['finished' => true]], []);

		$this->assertTrue($out[0][FlowItems::JSON]['finished']);
		$this->assertSame(
			'first',
			$out[0][FlowItems::JSON]['firstGate']['mark'] ?? null,
			'the gate answer must survive the set-fields rebuild'
		);
	}
}
