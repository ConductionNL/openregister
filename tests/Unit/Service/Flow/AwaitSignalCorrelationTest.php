<?php

/**
 * The await-signal correlation key: declared per step, resolved from the
 * item at suspension, stamped into the resume slot, and absent when
 * undeclared so every existing flow behaves exactly as before
 * (flow-approval-consolidation task 5.1).
 *
 * The four delivery cases (hit, ambiguous, unmatched-and-not-buffered,
 * cannot decide a user task) live in FlowRunSignalByKeyTest; the
 * cannot-decide boundary itself is UserTaskNodeTest's
 * testASignalWithADecisionDoesNotAnswerForThePerformer.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowNodeResumeState;
use OCA\OpenRegister\Service\Flow\FlowResumeState;
use OCA\OpenRegister\Service\Flow\FlowSuspension;
use OCA\OpenRegister\Service\Flow\Nodes\AwaitSignalNode;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\AwaitSignalNode
 * @uses \OCA\OpenRegister\Service\Flow\FlowItems
 * @uses \OCA\OpenRegister\Service\Flow\FlowNodeResumeState
 * @uses \OCA\OpenRegister\Service\Flow\FlowResumeState
 * @uses \OCA\OpenRegister\Service\Flow\FlowSuspension
 * @uses \OCA\OpenRegister\Service\Flow\FlowValueTemplate
 */
class AwaitSignalCorrelationTest extends TestCase {

	private AwaitSignalNode $node;

	protected function setUp(): void {
		parent::setUp();
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$this->node = new AwaitSignalNode($l10n, $this->createMock(IURLGenerator::class));
	}//end setUp()

	/**
	 * @param array<int, array<string, mixed>> $records The item payloads.
	 */
	private function items(array $records): array {
		return array_map(static fn (array $r): array => FlowItems::item(json: $r), $records);
	}//end items()

	public function testTheConfigVocabularyGainsTheCorrelationKey(): void {
		self::assertContains('correlationKey', $this->node->configKeys());
	}//end testTheConfigVocabularyGainsTheCorrelationKey()

	public function testADeclaredKeyIsResolvedFromTheItemAndStamped(): void {
		$state = new FlowResumeState();
		$context = [FlowNodeResumeState::CONTEXT_KEY => $state->forNode(nodeId: 'approval')];

		try {
			$this->node->execute(
				$this->items([['id' => 1, 'proposalId' => '42']]),
				['question' => 'Close the vote?', 'correlationKey' => 'vote:{{ proposalId }}'],
				$context
			);
			self::fail('the node must suspend');
		} catch (FlowSuspension $suspension) {
			// Expected: waiting for the signal.
		}

		self::assertSame('vote:42', $state->forNode(nodeId: 'approval')->get(key: 'correlationKey'));
	}//end testADeclaredKeyIsResolvedFromTheItemAndStamped()

	public function testAnUndeclaredKeyStampsTheEmptyString(): void {
		$state = new FlowResumeState();
		$context = [FlowNodeResumeState::CONTEXT_KEY => $state->forNode(nodeId: 'approval')];

		try {
			$this->node->execute($this->items([['id' => 1]]), ['question' => 'Publish it?'], $context);
			self::fail('the node must suspend');
		} catch (FlowSuspension $suspension) {
			// Expected.
		}

		self::assertSame(
			'',
			$state->forNode(nodeId: 'approval')->get(key: 'correlationKey'),
			'a flow that declares no key keeps run-uuid addressing only'
		);
	}//end testAnUndeclaredKeyStampsTheEmptyString()
}//end class
