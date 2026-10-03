<?php

/**
 * Unit tests for the `next` hint.
 *
 * The hint is a word, not a route: the list host owns its own notion of "the
 * next item" — its sort, its filter, its page — so a run result carrying a URL
 * would be a server deciding a client's navigation from a different sort order.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowNextHint;
use PHPUnit\Framework\TestCase;

class FlowNextHintTest extends TestCase {

	/**
	 * Nodes with a manual trigger carrying the given config.
	 *
	 * @param array $config The trigger config.
	 *
	 * @return array The nodes.
	 */
	private function nodes(array $config): array {
		return [
			['type' => FlowNextHint::MANUAL_TRIGGER, 'config' => $config],
			['type' => 'openregister.object-write', 'config' => []],
		];
	}//end nodes()

	/**
	 * A manual trigger with no `next` means stay: the page refreshes and the
	 * person is still looking at the record, which is what a macro did before
	 * this key existed.
	 *
	 * @return void
	 */
	public function testTheDefaultIsStay(): void {
		$this->assertSame(FlowNextHint::STAY, FlowNextHint::declared($this->nodes([])));
		$this->assertSame(FlowNextHint::STAY, FlowNextHint::declared([]));
	}//end testTheDefaultIsStay()

	/**
	 * The declared hint reaches the caller.
	 *
	 * @return void
	 */
	public function testTheTriggersHintIsRead(): void {
		$this->assertSame(FlowNextHint::NEXT, FlowNextHint::declared($this->nodes(['next' => 'next'])));
		$this->assertSame(FlowNextHint::LIST, FlowNextHint::declared($this->nodes(['next' => 'list'])));
	}//end testTheTriggersHintIsRead()

	/**
	 * A word outside the vocabulary is not quietly read as the default.
	 *
	 * @return void
	 */
	public function testAnUnknownWordIsNotAHint(): void {
		$this->assertNull(FlowNextHint::read('nextItem'));
		$this->assertNull(FlowNextHint::read(['next']));
		$this->assertSame(FlowNextHint::STAY, FlowNextHint::declared($this->nodes(['next' => 'nextItem'])));
	}//end testAnUnknownWordIsNotAHint()

	/**
	 * An end node overrides the trigger for the run that reached it: "close and
	 * notify" and "close and move on" can be one flow with two endings.
	 *
	 * @return void
	 */
	public function testAnEndNodeOverridesTheTrigger(): void {
		$effective = FlowNextHint::effective(
			$this->nodes(['next' => 'stay']),
			['type' => FlowNextHint::END_NODE, 'config' => ['next' => 'next']]
		);

		$this->assertSame(FlowNextHint::NEXT, $effective);
	}//end testAnEndNodeOverridesTheTrigger()

	/**
	 * An end node that declares nothing is SILENCE, not an override to stay.
	 * Reading it as an override would make every flow with a plain ending
	 * ignore its own trigger.
	 *
	 * @return void
	 */
	public function testASilentEndNodeLeavesTheTriggersAnswerStanding(): void {
		$effective = FlowNextHint::effective(
			$this->nodes(['next' => 'list']),
			['type' => FlowNextHint::END_NODE, 'config' => ['message' => 'Done']]
		);

		$this->assertSame(FlowNextHint::LIST, $effective);
	}//end testASilentEndNodeLeavesTheTriggersAnswerStanding()

	/**
	 * An end node's unknown word is not an override either.
	 *
	 * @return void
	 */
	public function testAnEndNodesUnknownWordIsNotAnOverride(): void {
		$effective = FlowNextHint::effective(
			$this->nodes(['next' => 'list']),
			['type' => FlowNextHint::END_NODE, 'config' => ['next' => 'elsewhere']]
		);

		$this->assertSame(FlowNextHint::LIST, $effective);
	}//end testAnEndNodesUnknownWordIsNotAnOverride()
}//end class
