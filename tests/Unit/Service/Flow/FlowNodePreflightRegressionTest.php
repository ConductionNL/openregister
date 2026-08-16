<?php

/**
 * The or#2247 regression, replayed against real collaborators.
 *
 * Everything here is the production wiring except the app manager and the event
 * dispatcher: a real `FlowNodeRegistry` populated through the real
 * `RegisterFlowNodesEvent`, the real `FlowNodePreflight`, the real
 * `FlowNodePreflightListener`, and the real `ObjectCreatingEvent` the mapper
 * dispatches. The document is the graph of `hydra/flows/hydra-file-findings.flow.json`
 * verbatim, which is the flow that actually shipped naming a node the instance
 * did not have.
 *
 * The point of using real objects is that a mocked registry would prove only
 * that the test's own stub throws. This proves the refusal happens through the
 * same `FlowNodeRegistry::get()` that the engine calls at dispatch — just at
 * save time instead of mid-run.
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Listener\FlowNodePreflightListener;
use OCA\OpenRegister\Service\Flow\FlowNodePreflight;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistry;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent;
use OCP\App\IAppManager;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowNodePreflight
 * @covers \OCA\OpenRegister\Listener\FlowNodePreflightListener
 */
class FlowNodePreflightRegressionTest extends TestCase {
	use FiltersFlowLevelFindings;

	/**
	 * The graph of hydra/flows/hydra-file-findings.flow.json, verbatim.
	 *
	 * @return array The flow document.
	 */
	private function hydraFileFindings(): array {
		return [
			'name' => 'hydra-file-findings',
			// The real hydra-file-findings flow, in the action-node shape: the
			// three STEPS are the nodes, and the places they met at are the
			// edges between them. Their names carry over onto the lines.
			'nodes' => [
				['id' => 'explode-findings', 'type' => 'openregister.explode'],
				['id' => 'actionable-only', 'type' => 'openregister.filter'],
				// Filing the issue is where this flow ends. Saying so keeps the
				// fixture a complete document, so the assertions below count
				// only the registry findings they are about.
				['id' => 'file-issue', 'type' => 'openconnector.source-call', 'exit' => true],
			],
			'edges' => [
				[
					'id' => 'exploded',
					'from' => 'explode-findings',
					'to' => 'actionable-only',
					'title' => 'One item per finding',
				],
				[
					'id' => 'actionable',
					'from' => 'actionable-only',
					'to' => 'file-issue',
					'title' => 'Open WARNING / SUGGESTION only',
				],
			],
		];
	}

	/**
	 * A node type contributed the way an app contributes one.
	 *
	 * @param string $id The type id.
	 *
	 * @return IFlowNode
	 */
	private function node(string $id): IFlowNode {
		$node = $this->createMock(IFlowNode::class);
		$node->method('getId')->willReturn($id);

		return $node;
	}

	/**
	 * A REAL registry populated through the REAL contribution event.
	 *
	 * @param array<int, string> $types The types apps contribute.
	 *
	 * @return FlowNodeRegistry
	 */
	private function registry(array $types): FlowNodeRegistry {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (Event $event) use ($types): void {
				if (($event instanceof RegisterFlowNodesEvent) === false) {
					return;
				}

				foreach ($types as $type) {
					$event->registerNode($this->node($type));
				}
			}
		);

		return new FlowNodeRegistry($dispatcher, $this->createMock(LoggerInterface::class));
	}

	/**
	 * A REAL preflight over a REAL registry.
	 *
	 * @param array<int, string> $types Contributed types.
	 * @param array<int, string> $enabled Enabled apps.
	 *
	 * @return FlowNodePreflight
	 */
	private function preflight(array $types, array $enabled): FlowNodePreflight {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForUser')->willReturnCallback(
			static fn (string $appId): bool => in_array($appId, $enabled, true)
		);

		return new FlowNodePreflight(
			$this->registry($types),
			$appManager,
			$this->createMock(LoggerInterface::class)
		);
	}

	/**
	 * Every node type openregister ships as of or#2247.
	 *
	 * @return array<int, string>
	 */
	private function or2247Nodes(): array {
		return [
			'openregister.route',
			'openregister.switch',
			'openregister.filter',
			'openregister.merge',
			'openregister.loop',
			'openregister.wait',
			'openregister.end',
			'openregister.set-fields',
			'openregister.sub-flow',
			'openregister.flow-state',
			'openregister.object-read',
			'openregister.object-write',
			'openregister.explode',
		];
	}

	/**
	 * THE REGRESSION.
	 *
	 * An instance at or#2244 has every node except `openregister.explode`, which
	 * shipped in or#2247. Saving the hydra flow there used to succeed, run, file
	 * real issues, and only then die on the explode step with nothing rolled
	 * back. It must now be refused at the save.
	 *
	 * @return void
	 */
	public function testTheHydraFlowIsRefusedOnAnInstanceThatPredatesExplode(): void {
		$or2244 = array_values(
			array_filter(
				$this->or2247Nodes(),
				static fn (string $type): bool => $type !== 'openregister.explode'
			)
		);

		$listener = new FlowNodePreflightListener(
			$this->preflight(types: $or2244, enabled: ['openregister', 'openconnector']),
			$this->createMock(LoggerInterface::class)
		);

		$entity = new ObjectEntity();
		$entity->setUuid('11111111-1111-1111-1111-111111111111');
		$entity->setObject($this->hydraFileFindings());

		$event = new ObjectCreatingEvent($entity);
		$listener->handle($event);

		$this->assertTrue($event->isPropagationStopped(), 'The save must be refused.');
		$errors = $event->getErrors();
		$this->assertSame('flow-node-type-unavailable', $errors['code']);
		$this->assertStringContainsString('openregister.explode', $errors['message']);
		$this->assertStringContainsString('explode-findings', $errors['message']);
		$this->assertStringContainsString('openregister', $errors['message']);
	}

	/**
	 * POSITIVE CONTROL — the same flow on an instance at or#2247 saves.
	 *
	 * Without this the test above proves only that the listener refuses things,
	 * which a listener that refused everything would also satisfy.
	 *
	 * @return void
	 */
	public function testTheSameFlowSavesOnceExplodeExists(): void {
		$complete = array_merge($this->or2247Nodes(), ['openconnector.source-call']);

		$listener = new FlowNodePreflightListener(
			$this->preflight(types: $complete, enabled: ['openregister', 'openconnector']),
			$this->createMock(LoggerInterface::class)
		);

		$entity = new ObjectEntity();
		$entity->setUuid('11111111-1111-1111-1111-111111111111');
		$entity->setObject($this->hydraFileFindings());

		$event = new ObjectCreatingEvent($entity);
		$listener->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame([], $event->getErrors());
	}

	/**
	 * The same flow on an instance WITHOUT openconnector still saves.
	 *
	 * `openconnector.source-call` is unresolvable there too, but installing
	 * openconnector is the fix and the document is not wrong. Refusing here
	 * would break every configuration import onto a partial instance.
	 *
	 * @return void
	 */
	public function testAnInstanceWithoutOpenconnectorStillAcceptsTheFlow(): void {
		$listener = new FlowNodePreflightListener(
			$this->preflight(types: $this->or2247Nodes(), enabled: ['openregister']),
			$this->createMock(LoggerInterface::class)
		);

		$entity = new ObjectEntity();
		$entity->setUuid('11111111-1111-1111-1111-111111111111');
		$entity->setObject($this->hydraFileFindings());

		$event = new ObjectCreatingEvent($entity);
		$listener->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}

	/**
	 * The registry really is the authority — no second list to drift from it.
	 *
	 * @return void
	 */
	public function testTheRegistryIsWhatDecides(): void {
		$preflight = $this->preflight(types: ['openregister.explode'], enabled: ['openregister']);

		$report = $preflight->inspect(flow: $this->hydraFileFindings());

		// explode resolves; filter does not and openregister is enabled; and
		// source-call is owned by an app that is not enabled.
		$this->assertCount(1, $report['blocking']);
		$this->assertSame('openregister.filter', $report['blocking'][0]['type']);
		$this->assertCount(1, $this->nodeWarnings($report));
		$this->assertSame('openconnector.source-call', $this->nodeWarnings($report)[0]['type']);
	}
}
