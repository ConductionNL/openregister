<?php

/**
 * Characterisation of AwaitSignalNode's answer handling.
 *
 * Pins the rejection spellings, the reason fallbacks, the opt-in check and
 * the answer placement exactly as they behave today, so the node can be
 * restructured without changing what a run sees.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\Flow\FlowStop;
use OCA\OpenRegister\Service\Flow\Nodes\AwaitSignalNode;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\AwaitSignalNode
 */
final class AwaitSignalAnswerCharacterisationTest extends TestCase {

	/**
	 * The node under test.
	 *
	 * @var AwaitSignalNode
	 */
	private AwaitSignalNode $node;

	/**
	 * Build the node.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$this->node = new AwaitSignalNode($l10n, $this->createMock(IURLGenerator::class));
	}//end setUp()

	/**
	 * Run the node with a signal and return the items or the stop reason.
	 *
	 * @param array<string, mixed> $config The step configuration.
	 * @param array<string, mixed> $signal The decision payload.
	 * @param array                $items  The input items.
	 *
	 * @return array|string The items, or 'STOP:' plus the stop reason.
	 */
	private function answer(array $config, array $signal, array $items = []): array|string {
		if ($items === []) {
			$items = [FlowItems::item(json: ['id' => 1])];
		}

		try {
			return $this->node->execute(new FlowNodeCall(items: $items, config: $config, context: [FlowRunService::SIGNAL_CONTEXT_KEY => $signal]));
		} catch (FlowStop $stop) {
			$this->assertTrue($stop->isError());

			return 'STOP:' . $stop->getMessage();
		}
	}//end answer()

	/**
	 * Both spellings, in any case and padding, stop an opted-in step.
	 *
	 * @return void
	 */
	public function testBothRejectionSpellingsStopAnOptedInStep(): void {
		$config = ['question' => 'Publish it?', 'failOnReject' => true];

		$this->assertSame('STOP:Rejected: late', $this->answer($config, ['decision' => ' Reject ', 'reason' => 'late']));
		$this->assertSame('STOP:Rejected: late', $this->answer($config, ['decision' => 'REJECTED', 'reason' => 'late']));
	}//end testBothRejectionSpellingsStopAnOptedInStep()

	/**
	 * The reason falls back to the question, then to a fixed phrase.
	 *
	 * @return void
	 */
	public function testTheStopReasonFallsBackToTheQuestionThenAFixedPhrase(): void {
		$this->assertSame(
			'STOP:Rejected: Publish it?',
			$this->answer(['question' => 'Publish it?', 'failOnReject' => true], ['decision' => 'rejected'])
		);
		$this->assertSame(
			'STOP:Rejected: no reason given',
			$this->answer(['failOnReject' => true], ['decision' => 'reject'])
		);
	}//end testTheStopReasonFallsBackToTheQuestionThenAFixedPhrase()

	/**
	 * Only a literal `true` opts in, and only a rejection stops.
	 *
	 * @return void
	 */
	public function testOnlyALiteralTrueOptsInAndOnlyARejectionStops(): void {
		$loose = $this->answer(['question' => 'Q', 'failOnReject' => 1], ['decision' => 'reject']);
		$this->assertIsArray($loose);
		$this->assertSame('reject', $loose[0][FlowItems::JSON]['signal']['decision']);

		$approved = $this->answer(['question' => 'Q', 'failOnReject' => true], ['decision' => 'approve']);
		$this->assertIsArray($approved);
		$this->assertSame('approve', $approved[0][FlowItems::JSON]['signal']['decision']);

		$near = $this->answer(['question' => 'Q', 'failOnReject' => true], ['decision' => 'rejecting']);
		$this->assertIsArray($near);
	}//end testOnlyALiteralTrueOptsInAndOnlyARejectionStops()

	/**
	 * A blank key falls back to `signal`; a non-array item passes untouched;
	 * keys are preserved.
	 *
	 * @return void
	 */
	public function testABlankKeyFallsBackAndNonArrayItemsPassUntouched(): void {
		$items = [
			'a' => FlowItems::item(json: ['id' => 1]),
			'b' => 'not-an-item',
			'c' => ['binary' => []],
		];

		$out = $this->answer(['question' => 'Q', 'signalKey' => '   '], ['decision' => 'approve'], $items);

		$this->assertSame(['a', 'b', 'c'], array_keys($out));
		$this->assertSame(['decision' => 'approve'], $out['a'][FlowItems::JSON]['signal']);
		$this->assertSame(1, $out['a'][FlowItems::JSON]['id']);
		$this->assertSame('not-an-item', $out['b']);
		$this->assertSame(['decision' => 'approve'], $out['c'][FlowItems::JSON]['signal']);
		$this->assertSame([], $out['c']['binary']);
	}//end testABlankKeyFallsBackAndNonArrayItemsPassUntouched()
}//end class
