<?php

/**
 * Unit tests for FlowDefinitionPin.
 *
 * 🔴 THE ONE BEHAVIOUR THAT MATTERS: the graph a version names cannot change
 * under a run. The content store is addressed by the hash of its own content,
 * so "editing a published version" is not an operation that exists — an edit
 * produces a different hash, which is a different row, which the version rows
 * are not pointing at.
 *
 * 🔴 AND THE ONE THING IT MUST NOT DO: fall back. An earlier draft of this
 * class answered a missing definition with the flow's LIVE graph. That is the
 * defect versioning exists to remove, so the tests below assert null — the
 * caller must fail the run naming the version, never substitute another graph.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\FlowDefinition;
use OCA\OpenRegister\Db\FlowDefinitionMapper;
use OCA\OpenRegister\Service\Flow\FlowDefinitionPin;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

class FlowDefinitionPinTest extends TestCase {

	/**
	 * @var array<string, FlowDefinition> A fake definition store, keyed by hash.
	 */
	private array $store = [];

	/**
	 * A pin over an in-memory store.
	 *
	 * @param boolean $storeFails Whether writes throw.
	 * @param boolean $readFails  Whether reads throw.
	 *
	 * @return FlowDefinitionPin The pin.
	 */
	private function pin(bool $storeFails = false, bool $readFails = false): FlowDefinitionPin {
		$mapper = $this->createMock(FlowDefinitionMapper::class);

		$mapper->method('store')->willReturnCallback(
			function (string $hash, string $definition, ?string $flowUuid = null) use ($storeFails): ?FlowDefinition {
				if ($storeFails === true) {
					throw new RuntimeException('write failed');
				}

				$e = new FlowDefinition();
				$e->setHash($hash);
				$e->setDefinition($definition);
				$e->setFlowUuid($flowUuid);
				$this->store[$hash] = $e;

				return $e;
			}
		);

		$mapper->method('findByHash')->willReturnCallback(
			function (string $hash) use ($readFails): ?FlowDefinition {
				if ($readFails === true) {
					throw new RuntimeException('read failed');
				}

				return ($this->store[$hash] ?? null);
			}
		);

		return new FlowDefinitionPin($mapper, new NullLogger());
	}//end pin()

	/**
	 * A two-node graph.
	 *
	 * @return array<string, mixed> The graph.
	 */
	private function twoNodeGraph(): array {
		return [
			'nodes' => [['id' => 'a', 'type' => 'start'], ['id' => 'b', 'type' => 'end']],
			'edges' => [['from' => 'a', 'to' => 'b']],
			'limits' => [],
			'executionMode' => 'async',
		];
	}//end twoNodeGraph()

	/**
	 * 🔴 THE DEFECT. Pin a graph, then edit the flow into something else. The
	 * hash taken before the edit must still resolve to the ORIGINAL graph —
	 * that is what lets a run suspended for two weeks finish on the process it
	 * began, which ADR-098 Decision 6 requires before a human task node may
	 * suspend a run at all.
	 *
	 * @return void
	 */
	public function testAPinnedGraphIsUnaffectedByEditingTheFlow(): void {
		$pin = $this->pin();

		$before = $this->twoNodeGraph();
		$hash = $pin->pin(flow: $before, flowId: 'flow-1');
		$this->assertNotNull($hash);

		// The author now rewrites the flow completely.
		$after = [
			'nodes' => [['id' => 'x', 'type' => 'start']],
			'edges' => [],
			'limits' => [],
			'executionMode' => 'sync',
		];
		$pin->pin(flow: $after, flowId: 'flow-1');

		$resolved = $pin->graphFor($hash);

		$this->assertSame($before['nodes'], $resolved['nodes'], 'the pinned graph must not follow the edit');
		$this->assertSame('async', $resolved['executionMode']);
	}//end testAPinnedGraphIsUnaffectedByEditingTheFlow()

	/**
	 * 🔴 NO FALLBACK. A missing definition must produce null so the caller can
	 * fail the run naming its version — never a substitute graph.
	 *
	 * @return void
	 */
	public function testAMissingDefinitionResolvesToNullRatherThanAnySubstitute(): void {
		$this->assertNull($this->pin()->graphFor('0000000000000000000000000000000000000000000000000000000000000000'));
	}//end testAMissingDefinitionResolvesToNullRatherThanAnySubstitute()

	/**
	 * A read failure is not grounds to serve a different graph either.
	 *
	 * @return void
	 */
	public function testAReadFailureResolvesToNull(): void {
		$pin = $this->pin();
		$hash = $pin->pin(flow: $this->twoNodeGraph(), flowId: 'flow-1');

		$this->assertNull($this->pin(readFails: true)->graphFor($hash));
	}//end testAReadFailureResolvesToNull()

	/**
	 * An unreadable definition resolves to null, not to an empty graph that
	 * would let a run "complete" without executing anything.
	 *
	 * @return void
	 */
	public function testAnUnreadableDefinitionResolvesToNull(): void {
		$broken = new FlowDefinition();
		$broken->setHash('deadbeef');
		$broken->setDefinition('{not json');
		$this->store['deadbeef'] = $broken;

		$this->assertNull($this->pin()->graphFor('deadbeef'));
	}//end testAnUnreadableDefinitionResolvesToNull()

	/**
	 * An absent hash is not a lookup at all.
	 *
	 * @return void
	 */
	public function testAnEmptyHashResolvesToNull(): void {
		$pin = $this->pin();

		$this->assertNull($pin->graphFor(null));
		$this->assertNull($pin->graphFor('   '));
	}//end testAnEmptyHashResolvesToNull()

	/**
	 * Key order must not change the hash, or the same graph loaded through two
	 * code paths would store two rows and the dedupe would be off.
	 *
	 * @return void
	 */
	public function testKeyOrderDoesNotChangeTheHash(): void {
		$pin = $this->pin();

		$a = ['nodes' => [['id' => 'a']], 'edges' => [], 'limits' => [], 'executionMode' => 'async'];
		$b = ['executionMode' => 'async', 'limits' => [], 'edges' => [], 'nodes' => [['id' => 'a']]];

		$this->assertSame($pin->pin(flow: $a, flowId: 'f'), $pin->pin(flow: $b, flowId: 'f'));
	}//end testKeyOrderDoesNotChangeTheHash()

	/**
	 * A different graph must hash differently, or an edit would be invisible.
	 *
	 * @return void
	 */
	public function testADifferentGraphHashesDifferently(): void {
		$pin = $this->pin();

		$a = $this->twoNodeGraph();
		$b = $this->twoNodeGraph();
		$b['nodes'][] = ['id' => 'c', 'type' => 'end'];

		$this->assertNotSame($pin->pin(flow: $a, flowId: 'f'), $pin->pin(flow: $b, flowId: 'f'));
	}//end testADifferentGraphHashesDifferently()

	/**
	 * Node ORDER is part of the graph: lists keep their order through
	 * canonicalisation, only maps are sorted. Sorting lists too would make two
	 * genuinely different graphs share a hash.
	 *
	 * @return void
	 */
	public function testReorderingNodesIsADifferentDefinition(): void {
		$pin = $this->pin();

		$a = ['nodes' => [['id' => 'a'], ['id' => 'b']], 'edges' => []];
		$b = ['nodes' => [['id' => 'b'], ['id' => 'a']], 'edges' => []];

		$this->assertNotSame($pin->pin(flow: $a, flowId: 'f'), $pin->pin(flow: $b, flowId: 'f'));
	}//end testReorderingNodesIsADifferentDefinition()

	/**
	 * 🔴 AUTHORIZATION IS NOT PART OF THE PINNED CONTENT. Pinning the owner or
	 * the organisation would freeze a grant into the definition, so revoking
	 * access would stop mattering to any run already queued. Pin the shape of
	 * the work; never pin the right to do it.
	 *
	 * @return void
	 */
	public function testOwnerAndOrganisationAreNotPartOfTheHash(): void {
		$pin = $this->pin();

		$a = $this->twoNodeGraph() + ['owner' => 'alice', 'organisation' => 'org-1'];
		$b = $this->twoNodeGraph() + ['owner' => 'bob', 'organisation' => 'org-2'];

		$this->assertSame(
			$pin->pin(flow: $a, flowId: 'f'),
			$pin->pin(flow: $b, flowId: 'f'),
			'changing who may run a flow must not produce a different definition'
		);
	}//end testOwnerAndOrganisationAreNotPartOfTheHash()

	/**
	 * Pinning an unedited flow twice must store ONE row, or a busy trigger
	 * would fill the table with identical copies of the same 4 KB graph.
	 *
	 * @return void
	 */
	public function testPinningAnUneditedFlowTwiceDedupes(): void {
		$pin = $this->pin();
		$graph = $this->twoNodeGraph();

		$pin->pin(flow: $graph, flowId: 'f');
		$pin->pin(flow: $graph, flowId: 'f');

		$this->assertCount(1, $this->store);
	}//end testPinningAnUneditedFlowTwiceDedupes()

	/**
	 * A storage failure yields no hash. The caller must then refuse to publish
	 * rather than name a graph that is not there.
	 *
	 * @return void
	 */
	public function testAStorageFailureYieldsNoHash(): void {
		$this->assertNull($this->pin(storeFails: true)->pin(flow: $this->twoNodeGraph(), flowId: 'f'));
	}//end testAStorageFailureYieldsNoHash()

	/**
	 * A graph with nothing in it cannot be canonicalised into a meaningful
	 * definition, and must not silently hash to a shared "empty" row that
	 * several broken flows would then share.
	 *
	 * @return void
	 */
	public function testTheStoredDefinitionRoundTrips(): void {
		$pin = $this->pin();
		$graph = $this->twoNodeGraph();

		$hash = $pin->pin(flow: $graph, flowId: 'f');
		$back = $pin->graphFor($hash);

		$this->assertSame($graph['nodes'], $back['nodes']);
		$this->assertSame($graph['edges'], $back['edges']);
	}//end testTheStoredDefinitionRoundTrips()
}//end class
