<?php

/**
 * Unit test for FlowConnectivity on malformed documents: nodes that are no
 * list, entries that are no map, edges that are no map, and types without an
 * owning app, for both the dead-end and the entry-and-exit checks.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowConnectivity;
use OCA\OpenRegister\Service\Flow\FlowNodeCatalogueInterface;
use OCA\OpenRegister\Service\Flow\FlowNodePreflight;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowConnectivity
 * @uses   \OCA\OpenRegister\Service\Flow\FlowGraph
 */
class FlowConnectivityEdgeCasesTest extends TestCase {

	/**
	 * Connectivity over a catalogue where `x.start` triggers and `x.end` ends.
	 *
	 * @return FlowConnectivity
	 */
	private function connectivity(): FlowConnectivity {
		$registry = $this->createMock(FlowNodeCatalogueInterface::class);
		$registry->method('isTrigger')->willReturnCallback(fn (string $type): bool => $type === 'x.start');
		$registry->method('isEnd')->willReturnCallback(fn (string $type): bool => $type === 'x.end');
		return new FlowConnectivity(registry: $registry);
	}//end connectivity()

	/**
	 * A document without a node list has no findings at all.
	 *
	 * @return void
	 */
	public function testNoNodeListMeansNoFindings(): void {
		foreach ([[], ['nodes' => 'a,b'], ['nodes' => []]] as $flow) {
			$this->assertSame([], $this->connectivity()->deadEnds(flow: $flow));
			$this->assertSame([], $this->connectivity()->entryAndExit(flow: $flow));
		}
	}//end testNoNodeListMeansNoFindings()

	/**
	 * Entries that are no map, nodes without id or type, exits and non-map edges are never dead ends.
	 *
	 * @return void
	 */
	public function testOnlyTypedNodesWithoutAnExitAreDeadEnds(): void {
		$flow = [
			'nodes' => [
				'junk',
				['type' => 'x.step'],
				['id' => 'untyped'],
				['id' => 'done', 'type' => 'x.end'],
				['id' => 'stop', 'type' => 'x.step', 'exit' => true],
				['id' => 'linked', 'type' => 'x.step'],
				['id' => 'dead', 'type' => 'step-without-app'],
			],
			'edges' => ['junk', ['from' => 'linked', 'to' => 'done']],
		];

		$this->assertSame(
			[[
				'type'   => 'step-without-app',
				'app'    => '',
				'step'   => 'dead',
				'reason' => FlowNodePreflight::REASON_DEAD_END,
				'detail' => 'Node "dead" has no outgoing edge and does not end the flow, so its token would arrive, run, and stop with the run reported as completed. Connect it, give it a terminal step type, or mark it "exit": true if stopping there is deliberate.',
			]],
			$this->connectivity()->deadEnds(flow: $flow)
		);
	}//end testOnlyTypedNodesWithoutAnExitAreDeadEnds()

	/**
	 * A dead end of an app's type names that app; one whose type starts with a dot names none.
	 *
	 * @return void
	 */
	public function testTheOwningAppIsTheTypePrefix(): void {
		$flow = ['nodes' => [['id' => 'a', 'type' => 'procest.close'], ['id' => 'b', 'type' => '.odd']], 'edges' => []];

		$this->assertSame(['procest', ''], array_column($this->connectivity()->deadEnds(flow: $flow), 'app'));
	}//end testTheOwningAppIsTheTypePrefix()

	/**
	 * A flow missing a trigger and an end gets both flow-level findings; entries that are no map are skipped.
	 *
	 * @return void
	 */
	public function testMissingTriggerAndEndAreBothFound(): void {
		$missing = $this->connectivity()->entryAndExit(flow: ['nodes' => ['junk', ['id' => 'a', 'type' => 'x.step']]]);
		$this->assertSame([FlowNodePreflight::REASON_NO_TRIGGER, FlowNodePreflight::REASON_NO_END], array_column($missing, 'reason'));
		$this->assertSame(['', ''], array_column($missing, 'step'));

		$this->assertSame([], $this->connectivity()->entryAndExit(flow: ['nodes' => [['id' => 's', 'type' => 'x.start'], ['id' => 'e', 'type' => 'x.end']]]));
	}//end testMissingTriggerAndEndAreBothFound()
}//end class
