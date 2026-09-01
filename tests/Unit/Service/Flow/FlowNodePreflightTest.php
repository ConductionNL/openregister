<?php

/**
 * The registry refused an unknown step type in the right way and at the wrong time.
 *
 * `FlowNodeRegistry::get()` throws at dispatch — mid-run, after earlier steps
 * have already moved labels, pushed commits and filed issues, none of which roll
 * back. These tests pin the same refusal to save/import time, and pin the one
 * distinction that makes refusing safe: an owning app that is ENABLED and still
 * has no such type is a defect no install can fix, while an app that is simply
 * not enabled here is a legitimate absence that must not block a save.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

require_once __DIR__ . '/FiltersFlowLevelFindings.php';

use OCA\OpenRegister\Service\Flow\FlowNodePreflight;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistry;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use UnexpectedValueException;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowNodePreflight
 */
class FlowNodePreflightTest extends TestCase {
	use FiltersFlowLevelFindings;

	/**
	 * Build a preflight over a fixed set of known types and enabled apps.
	 *
	 * @param array<int, string> $known Types the registry provides.
	 * @param array<int, string> $enabled Apps that are enabled.
	 *
	 * @return FlowNodePreflight
	 */
	private function preflight(array $known, array $enabled): FlowNodePreflight {
		$registry = $this->createMock(FlowNodeRegistry::class);
		$registry->method('get')->willReturnCallback(
			function (string $type) use ($known): IFlowNode {
				if (in_array($type, $known, true) === false) {
					throw new UnexpectedValueException(
						sprintf('No app provides the flow node type "%s".', $type)
					);
				}

				return $this->createMock(IFlowNode::class);
			}
		);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForUser')->willReturnCallback(
			static fn (string $appId): bool => in_array($appId, $enabled, true)
		);

		return new FlowNodePreflight($registry, $appManager, $this->createMock(LoggerInterface::class));
	}

	/**
	 * A minimal two-node graph whose single edge carries the given type.
	 *
	 * @param string $type The step type.
	 *
	 * @return array The flow document.
	 */
	private function flowWith(string $type): array {
		return [
			'name' => 'test-flow',
			// The step is on the NODE (or-flow-action-nodes). The preflight
			// walks nodes for the same reason: left on edges it would inspect a
			// list where nothing carries a type, find nothing, and report the
			// document valid without having looked at it.
			// Only ONE typed node, so a fixture built for "is this one type
			// resolvable" yields exactly one finding. A second typed node would
			// add a second finding and make every count assertion below wrong
			// for a reason that has nothing to do with what is being tested.
			'nodes' => [['id' => 'a', 'type' => $type], ['id' => 'b']],
			'edges' => [['id' => 'e1', 'from' => 'a', 'to' => 'b']],
		];
	}

	/**
	 * The measured case: openregister is right here and does not have the type.
	 *
	 * `openregister.explode` shipped in or#2247 while the instance sat at
	 * or#2244. No install fixes that, so it must not be storable.
	 */
	public function testATypeMissingFromAnEnabledAppIsRefused(): void {
		$preflight = $this->preflight(known: ['openregister.route'], enabled: ['openregister']);

		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessageMatches('/openregister\.explode/');
		$preflight->assertRunnable(flow: $this->flowWith('openregister.explode'));
	}

	/**
	 * A type owned by an app that is not enabled here is NOT refused.
	 *
	 * A shared configuration export names openconnector and hermiq steps. An
	 * instance that has not enabled them yet is incomplete, not wrong, and the
	 * import must land — this is the case a blanket refusal would break.
	 */
	public function testATypeFromAnAbsentAppIsWarnedNotRefused(): void {
		$preflight = $this->preflight(known: ['openregister.route'], enabled: ['openregister']);

		$preflight->assertRunnable(flow: $this->flowWith('openconnector.source-call'));

		$report = $preflight->inspect(flow: $this->flowWith('openconnector.source-call'));
		$this->assertSame([], $report['blocking']);
		$this->assertCount(1, $this->nodeWarnings($report));
		$this->assertSame(FlowNodePreflight::REASON_OWNER_NOT_ENABLED, $this->nodeWarnings($report)[0]['reason']);
		$this->assertSame('openconnector', $this->nodeWarnings($report)[0]['app']);
	}

	/**
	 * An unnamespaced type can never resolve, so it is refused too.
	 */
	public function testAnUnnamespacedTypeIsRefused(): void {
		$preflight = $this->preflight(known: ['openregister.route'], enabled: ['openregister']);

		$report = $preflight->inspect(flow: $this->flowWith('explode'));
		$this->assertCount(1, $report['blocking']);
		$this->assertSame(FlowNodePreflight::REASON_NOT_NAMESPACED, $report['blocking'][0]['reason']);
	}

	/**
	 * Every missing type is reported at once, not one per save attempt.
	 */
	public function testAllMissingTypesAreNamedInOneMessage(): void {
		$preflight = $this->preflight(known: [], enabled: ['openregister']);
		$flow = [
			'name' => 'multi',
			'nodes' => [
				['id' => 'a', 'type' => 'openregister.explode'],
				['id' => 'b', 'type' => 'openregister.teleport'],
				['id' => 'c', 'type' => 'openregister.end'],
			],
			'edges' => [
				['id' => 'e1', 'from' => 'a', 'to' => 'b'],
				['id' => 'e2', 'from' => 'b', 'to' => 'c'],
			],
		];

		try {
			$preflight->assertRunnable(flow: $flow);
			$this->fail('Expected the flow to be refused.');
		} catch (UnexpectedValueException $e) {
			$this->assertStringContainsString('openregister.explode', $e->getMessage());
			$this->assertStringContainsString('openregister.teleport', $e->getMessage());
			$this->assertStringContainsString('openregister', $e->getMessage());
		}
	}

	/**
	 * A registered type passes, and a typeless node is left to the builder.
	 *
	 * The preflight's job is "can this instance run these step types", not "is
	 * this document well-formed" — `FlowDefinitionBuilder` refuses a typeless
	 * node, and duplicating that here would give one rule two owners.
	 */
	public function testKnownTypesPassAndATypelessNodeIsNotThePreflightsToJudge(): void {
		$preflight = $this->preflight(known: ['openregister.route'], enabled: ['openregister']);
		$flow = [
			'name' => 'ok',
			'nodes' => [
				['id' => 'a', 'type' => 'openregister.route'],
				['id' => 'b'],
			],
			'edges' => [['id' => 'e1', 'from' => 'a', 'to' => 'b']],
		];

		$preflight->assertRunnable(flow: $flow);
		$report = $preflight->inspect(flow: $flow);
		$this->assertSame([], $report['blocking']);
		$this->assertSame([], $this->nodeWarnings($report));
	}

	/**
	 * Recognition is structural, so it must not claim ordinary objects.
	 *
	 * @dataProvider nonFlowProvider
	 *
	 * @param array $data The object data.
	 *
	 * @return void
	 */
	public function testOnlyGraphShapedDataIsTreatedAsAFlow(array $data): void {
		$preflight = $this->preflight(known: [], enabled: []);
		$this->assertFalse($preflight->looksLikeFlow(data: $data));
	}

	/**
	 * Data that must NOT be mistaken for a flow.
	 *
	 * @return array<string, array{0: array}>
	 */
	public static function nonFlowProvider(): array {
		return [
			'no graph keys at all' => [['title' => 'a lead', 'status' => 'open']],
			'nodes but no edges' => [['nodes' => [['id' => 'a']]]],
			'edges but no nodes' => [['edges' => [['from' => 'a', 'to' => 'b']]]],
			'empty lists' => [['nodes' => [], 'edges' => []]],
			'nodes without ids' => [['nodes' => [['label' => 'a']], 'edges' => [['from' => 'a', 'to' => 'b']]]],
			'edges without endpoints' => [['nodes' => [['id' => 'a']], 'edges' => [['type' => 'x']]]],
			'scalar nodes' => [['nodes' => 'a,b', 'edges' => 'c']],
		];
	}

	/**
	 * A real graph IS recognised — the positive control for the above.
	 */
	public function testAGraphIsRecognised(): void {
		$preflight = $this->preflight(known: [], enabled: []);
		$this->assertTrue($preflight->looksLikeFlow(data: $this->flowWith('openregister.route')));
		// `source`/`target` is the other endpoint spelling the builder accepts.
		$this->assertTrue(
			$preflight->looksLikeFlow(
				data: [
					'nodes' => [['id' => 'a'], ['id' => 'b']],
					'edges' => [['source' => 'a', 'target' => 'b', 'type' => 'openregister.route']],
				]
			)
		);
	}

	/**
	 * A pre-inversion document is REFUSED, not reported valid.
	 *
	 * Measured on the live Hydra sequencer: after the preflight moved to walking
	 * nodes, an un-migrated flow — whose steps are all on edges — produced zero
	 * findings and validated clean, while being exactly the shape the builder
	 * refuses. Walking nodes closed one hole by opening another, and the author
	 * reading "the flow engine accepts this graph" in the editor is precisely
	 * who needed telling otherwise.
	 *
	 * @return void
	 */
	public function testAPreInversionDocumentIsRefusedRatherThanReportedValid(): void {
		$preflight = $this->preflight(known: ['openregister.route'], enabled: ['openregister']);
		$report = $preflight->inspect(
			flow: [
				'name' => 'un-migrated',
				'nodes' => [['id' => 'a'], ['id' => 'b']],
				'edges' => [['id' => 'scope', 'from' => 'a', 'to' => 'b', 'type' => 'openregister.route']],
			]
		);

		$this->assertCount(1, $report['blocking']);
		$this->assertSame(FlowNodePreflight::REASON_PRE_INVERSION_SHAPE, $report['blocking'][0]['reason']);
		$this->assertSame('scope', $report['blocking'][0]['step']);
	}

	/**
	 * Positive control: the same flow validates once the step is on the node.
	 *
	 * Without this, the refusal above is satisfied by a preflight that refuses
	 * everything it is shown.
	 *
	 * @return void
	 */
	public function testTheSameFlowValidatesOnceTheStepIsOnTheNode(): void {
		$preflight = $this->preflight(known: ['openregister.route'], enabled: ['openregister']);
		$report = $preflight->inspect(
			flow: [
				'name' => 'migrated',
				// `exit` so the one-node fixture is a COMPLETE document. This
				// asserts an EXACTLY empty report, and a lone node with no
				// outgoing edge is a dead end however correct its step is —
				// the connectivity warning would be right, and would make this
				// positive control fail for a reason it is not about.
				'nodes' => [['id' => 'scope', 'type' => 'openregister.route', 'exit' => true]],
				'edges' => [],
			]
		);

		$this->assertSame([], $report['blocking']);
		$this->assertSame([], $this->nodeWarnings($report));
	}
}
