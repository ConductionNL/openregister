<?php

/**
 * The trigger index follows the PUBLISHED version, never the head.
 *
 * 🔴 THE FAILURE THIS PREVENTS IS SILENT IN BOTH DIRECTIONS. Deriving from the
 * head while a draft is open would subscribe the draft's trigger nodes — a
 * half-authored flow firing on real object writes — and unsubscribe the
 * published version's, so the process that IS live quietly stops running. Both
 * look like nothing happening.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowTriggerMapper;
use OCA\OpenRegister\Service\Flow\FlowPublishedGraph;
use OCA\OpenRegister\Service\Flow\FlowTriggerDerivation;
use OCA\OpenRegister\Service\Flow\FlowTriggerIndex;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class FlowTriggerIndexPublishedTest extends TestCase {

	/**
	 * @var array<string, mixed> What replaceFor() was last called with.
	 */
	private array $written = [];

	/**
	 * An index whose resolver answers $publishedGraph for any flow.
	 *
	 * @param array|null $publishedGraph The published graph, or null for none.
	 *
	 * @return FlowTriggerIndex The index.
	 */
	private function index(?array $publishedGraph): FlowTriggerIndex {
		$mapper = $this->createMock(FlowTriggerMapper::class);
		$mapper->method('replaceFor')->willReturnCallback(
			function (string $flowUuid, array $triggers, bool $enabled): int {
				$this->written = ['flow' => $flowUuid, 'triggers' => $triggers, 'enabled' => $enabled];

				return count($triggers);
			}
		);
		$published = $this->createMock(FlowPublishedGraph::class);
		$published->method('graphOf')->willReturn($publishedGraph);

		return new FlowTriggerIndex(
			$mapper,
			new FlowTriggerDerivation(),
			new NullLogger(),
			$published
		);
	}//end index()

	/**
	 * A flow whose HEAD holds $nodes.
	 *
	 * @param array $nodes The head's nodes.
	 *
	 * @return Flow The flow.
	 */
	private function flowWithHead(array $nodes): Flow {
		$flow = new Flow();
		$flow->setUuid('flow-1');
		$flow->setEnabled(true);
		$flow->setNodes($nodes);
		$flow->setEdges([]);

		return $flow;
	}//end flowWithHead()

	/**
	 * One object trigger node.
	 *
	 * @param string $event The event it subscribes to.
	 *
	 * @return array The node.
	 */
	private function triggerNode(string $event): array {
		return [
			'id' => 'trigger-' . $event,
			'type' => 'openregister.trigger-object',
			'config' => ['event' => $event, 'register' => 'reg', 'schema' => 'sch'],
		];
	}//end triggerNode()

	/**
	 * 🔴 THE HEAD'S DRAFT TRIGGER MUST NOT BE SUBSCRIBED, and the published
	 * one must not be dropped. The head here says `object.updated`; the
	 * published version says `object.created`. Only the published one may
	 * reach the index.
	 *
	 * @return void
	 */
	public function testTheIndexFollowsThePublishedVersionNotTheHead(): void {
		$index = $this->index(['nodes' => [$this->triggerNode('object.created')], 'edges' => []]);

		$index->reindex(flow: $this->flowWithHead([$this->triggerNode('object.updated')]));

		$events = array_column($this->written['triggers'], 'event');

		$this->assertSame(['object.created'], $events, 'the draft head must not be subscribed');
	}//end testTheIndexFollowsThePublishedVersionNotTheHead()

	/**
	 * A flow with no published version subscribes to NOTHING — that is what
	 * makes "a draft's trigger nodes match nothing" true by construction
	 * rather than by a filter on the read path.
	 *
	 * @return void
	 */
	public function testAFlowWithNoPublishedVersionSubscribesToNothing(): void {
		$index = $this->index(null);

		$index->reindex(flow: $this->flowWithHead([$this->triggerNode('object.created')]));

		$this->assertSame([], $this->written['triggers']);
	}//end testAFlowWithNoPublishedVersionSubscribesToNothing()

	/**
	 * `enabled` comes from the LIVE flow, not from the published version.
	 * Switching a flow off must take effect at once and has nothing to do with
	 * which version is published.
	 *
	 * @return void
	 */
	public function testEnabledComesFromTheLiveFlow(): void {
		$index = $this->index(['nodes' => [$this->triggerNode('object.created')], 'edges' => []]);

		$flow = $this->flowWithHead([]);
		$flow->setEnabled(false);
		$index->reindex(flow: $flow);

		$this->assertFalse($this->written['enabled']);
	}//end testEnabledComesFromTheLiveFlow()

	/**
	 * The rows are written against the flow's own uuid, not the carrier's — a
	 * detached carrier that lost the id would silently index nothing.
	 *
	 * @return void
	 */
	public function testTheRowsAreKeyedOnTheFlowsOwnUuid(): void {
		$index = $this->index(['nodes' => [$this->triggerNode('object.created')], 'edges' => []]);

		$index->reindex(flow: $this->flowWithHead([]));

		$this->assertSame('flow-1', $this->written['flow']);
	}//end testTheRowsAreKeyedOnTheFlowsOwnUuid()
}//end class
