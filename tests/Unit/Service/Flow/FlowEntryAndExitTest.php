<?php

/**
 * A flow needs a way IN and a way OUT.
 *
 * Two failures this makes visible, both of which look like a working flow:
 *
 *   NO TRIGGER  nothing can ever start it. The flow sits there fully authored
 *               and never runs, and no run record appears to say why — the
 *               quietest possible failure.
 *   NO END      no path finishes deliberately, so every path stops somewhere
 *               the author did not mark and the run is still reported
 *               completed.
 *
 * The role is read from the node's TYPE, never from its position in the graph.
 * These tests pin that distinction directly: a lone unconnected step is not a
 * trigger merely because nothing points at it.
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
 *
 * @spec openspec/specs/flow-engine/spec.md#requirement-a-flow-must-have-a-trigger-and-an-end
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowConnectivity;
use OCA\OpenRegister\Service\Flow\FlowNodePreflight;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Covers the missing-trigger and missing-end findings.
 */
class FlowEntryAndExitTest extends TestCase {

	/**
	 * Connectivity over a registry that answers from two fixed lists.
	 *
	 * @param array<int, string> $triggers Types that can start a run.
	 * @param array<int, string> $ends Types that end one.
	 *
	 * @return FlowConnectivity
	 */
	private function connectivity(array $triggers = [], array $ends = []): FlowConnectivity {
		$registry = $this->createMock(FlowNodeRegistry::class);
		$registry->method('isTrigger')->willReturnCallback(
			static fn (string $type): bool => in_array($type, $triggers, true)
		);
		$registry->method('isEnd')->willReturnCallback(
			static fn (string $type): bool => in_array($type, $ends, true)
		);

		return new FlowConnectivity($registry);
	}//end connectivity()

	/**
	 * The reasons reported for a document.
	 *
	 * @param array $findings The findings.
	 *
	 * @return array<int, string> The reasons.
	 */
	private function reasons(array $findings): array {
		return array_column($findings, 'reason');
	}//end reasons()

	/**
	 * A flow with both says nothing.
	 *
	 * The positive control. Without it the tests below prove only that the
	 * check returns findings, which a check hardcoded to complain also does.
	 *
	 * @return void
	 */
	public function testAFlowWithBothIsSilent(): void {
		$findings = $this->connectivity(
			triggers: ['openregister.trigger-object'],
			ends: ['openregister.end']
		)->entryAndExit(
			flow: [
				'nodes' => [
					['id' => 't', 'type' => 'openregister.trigger-object'],
					['id' => 'a', 'type' => 'openregister.set-fields'],
					['id' => 'e', 'type' => 'openregister.end'],
				],
			]
		);

		$this->assertSame([], $this->reasons($findings));

	}//end testAFlowWithBothIsSilent()

	/**
	 * No trigger is reported, by reason.
	 *
	 * @return void
	 */
	public function testAFlowWithNoTriggerIsReported(): void {
		$findings = $this->connectivity(ends: ['openregister.end'])->entryAndExit(
			flow: [
				'nodes' => [
					['id' => 'a', 'type' => 'openregister.set-fields'],
					['id' => 'e', 'type' => 'openregister.end'],
				],
			]
		);

		$this->assertSame([FlowNodePreflight::REASON_NO_TRIGGER], $this->reasons($findings));
		$this->assertStringContainsString('nothing can ever start it', $findings[0]['detail']);

	}//end testAFlowWithNoTriggerIsReported()

	/**
	 * No end is reported, by reason.
	 *
	 * @return void
	 */
	public function testAFlowWithNoEndIsReported(): void {
		$findings = $this->connectivity(triggers: ['openregister.trigger-manual'])->entryAndExit(
			flow: [
				'nodes' => [
					['id' => 't', 'type' => 'openregister.trigger-manual'],
					['id' => 'a', 'type' => 'openregister.set-fields'],
				],
			]
		);

		$this->assertSame([FlowNodePreflight::REASON_NO_END], $this->reasons($findings));

	}//end testAFlowWithNoEndIsReported()

	/**
	 * Missing BOTH reports both, not one.
	 *
	 * An author who is told about one, fixes it and is then told about the
	 * other has been made to do the work twice.
	 *
	 * @return void
	 */
	public function testAFlowMissingBothIsToldAboutBoth(): void {
		$findings = $this->connectivity()->entryAndExit(
			flow: ['nodes' => [['id' => 'a', 'type' => 'openregister.set-fields']]]
		);

		$this->assertSame(
			[FlowNodePreflight::REASON_NO_TRIGGER, FlowNodePreflight::REASON_NO_END],
			$this->reasons($findings)
		);

	}//end testAFlowMissingBothIsToldAboutBoth()

	/**
	 * A flow that ends in ERROR has still ended deliberately.
	 *
	 * `openregister.end` carries an `error` flag. Failing is an outcome, not
	 * the absence of one, and a flow whose only exit is a failure exit must not
	 * be told it has no end.
	 *
	 * @return void
	 */
	public function testAnEndThatFailsStillCounts(): void {
		$findings = $this->connectivity(
			triggers: ['openregister.trigger-manual'],
			ends: ['openregister.end']
		)->entryAndExit(
			flow: [
				'nodes' => [
					['id' => 't', 'type' => 'openregister.trigger-manual'],
					['id' => 'e', 'type' => 'openregister.end', 'config' => ['error' => true]],
				],
			]
		);

		$this->assertSame([], $this->reasons($findings));

	}//end testAnEndThatFailsStillCounts()

	/**
	 * THE TYPOLOGY RULE: role comes from the TYPE, never from the graph.
	 *
	 * This document is topologically perfect — `a` has nothing pointing at it
	 * so it "looks like" a start, `b` has no outgoing edge so it "looks like"
	 * an end. Both are ordinary steps, and the flow has neither a trigger nor
	 * an end. A check reading the drawing would report nothing here.
	 *
	 * @return void
	 */
	public function testGraphPositionIsNotARole(): void {
		$findings = $this->connectivity()->entryAndExit(
			flow: [
				'nodes' => [
					['id' => 'a', 'type' => 'openregister.set-fields'],
					['id' => 'b', 'type' => 'openregister.set-fields'],
				],
				'edges' => [['id' => 'e1', 'from' => 'a', 'to' => 'b']],
			]
		);

		$this->assertSame(
			[FlowNodePreflight::REASON_NO_TRIGGER, FlowNodePreflight::REASON_NO_END],
			$this->reasons($findings),
			'the check read the graph shape instead of the node types'
		);

	}//end testGraphPositionIsNotARole()

	/**
	 * `exit: true` does not substitute for an end NODE.
	 *
	 * The flag is a per-instance escape for migrated documents — it stops the
	 * dead-end warning for one node. It does not make the flow's exit
	 * deliberate, so a flow whose only exit is a flag is still told it has no
	 * end.
	 *
	 * @return void
	 */
	public function testTheExitFlagIsNotAnEndNode(): void {
		$findings = $this->connectivity(triggers: ['openregister.trigger-manual'])->entryAndExit(
			flow: [
				'nodes' => [
					['id' => 't', 'type' => 'openregister.trigger-manual'],
					['id' => 'a', 'type' => 'openregister.set-fields', 'exit' => true],
				],
			]
		);

		$this->assertSame([FlowNodePreflight::REASON_NO_END], $this->reasons($findings));

	}//end testTheExitFlagIsNotAnEndNode()

	/**
	 * An EMPTY document is not nagged.
	 *
	 * A flow with nothing in it is missing both by definition, and the author
	 * can see that. Two findings on a blank canvas is how a warning list
	 * becomes noise.
	 *
	 * @return void
	 */
	public function testAnEmptyDocumentIsNotReported(): void {
		$this->assertSame([], $this->connectivity()->entryAndExit(flow: ['nodes' => []]));
		$this->assertSame([], $this->connectivity()->entryAndExit(flow: []));

	}//end testAnEmptyDocumentIsNotReported()

}//end class
