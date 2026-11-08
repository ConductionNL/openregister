<?php

/**
 * Golden test: the logic-category nodes' palette surface and validation.
 *
 * Recorded on the code before these nodes shared LogicNodeBase, so a green run
 * proves the palette and the preflight see exactly what they saw before.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-node-taxonomy/specs/flow-node-taxonomy/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes;

use OCA\OpenRegister\Db\MappingMapperInterface;
use OCA\OpenRegister\Service\Dmn\DecisionTableEvaluator;
use OCA\OpenRegister\Service\Dmn\DecisionTableStore;
use OCA\OpenRegister\Service\Dmn\DecisionTableValidator;
use OCA\OpenRegister\Service\Flow\FlowLocator;
use OCA\OpenRegister\Service\Flow\FlowRunServiceInterface;
use OCA\OpenRegister\Service\Flow\Nodes\ActionInputsNode;
use OCA\OpenRegister\Service\Flow\Nodes\DecisionTableNode;
use OCA\OpenRegister\Service\Flow\Nodes\EndNode;
use OCA\OpenRegister\Service\Flow\Nodes\ExplodeNode;
use OCA\OpenRegister\Service\Flow\Nodes\FilterNode;
use OCA\OpenRegister\Service\Flow\Nodes\FlowNodePresentation;
use OCA\OpenRegister\Service\Flow\Nodes\FlowStateNode;
use OCA\OpenRegister\Service\Flow\Nodes\IterateNode;
use OCA\OpenRegister\Service\Flow\Nodes\MapNode;
use OCA\OpenRegister\Service\Flow\Nodes\MergeNode;
use OCA\OpenRegister\Service\Flow\Nodes\RouterNode;
use OCA\OpenRegister\Service\Flow\Nodes\SetFieldsNode;
use OCA\OpenRegister\Service\Flow\Nodes\SubFlowNode;
use OCA\OpenRegister\Service\Flow\Nodes\SwitchNode;
use OCA\OpenRegister\Service\Flow\Nodes\WaitNode;
use OCA\OpenRegister\Service\MappingService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Pins the logic nodes' observable palette and validation behaviour.
 */
final class LogicNodesGoldenTest extends TestCase {
	use NodeSurfaceSnapshot;

	/**
	 * Configs every logic node is validated against.
	 *
	 * @return array<string, array> Label => config.
	 */
	private function configs(): array {
		return [
			'empty' => [],
			'path' => ['path' => 'findings', 'as' => 'finding'],
			'blank-path' => ['path' => '  '],
			'condition' => ['condition' => '{{ a }} == 1', 'conditions' => [['field' => 'a', 'operator' => 'equals', 'value' => 1]]],
			'bad-conditions' => ['conditions' => 'x'],
			'mode' => ['mode' => 'byKey', 'key' => 'id'],
			'bad-mode' => ['mode' => 'sideways'],
			'routes' => ['routes' => [['name' => 'a', 'condition' => 'true']]],
			'bad-routes' => ['routes' => 'a'],
			'fields' => ['fields' => ['a' => 1], 'keepOnlySet' => true],
			'bad-fields' => ['fields' => 'a'],
			'mapping' => ['mapping' => 'm1'],
			'body' => ['body' => [['type' => 'openregister.set-fields', 'config' => []]], 'path' => 'list'],
			'state' => ['operation' => 'set', 'slot' => 'count', 'value' => 1],
			'bad-state' => ['operation' => 'explode', 'slot' => ''],
			'wait' => ['duration' => 'PT1H'],
			'bad-wait' => ['duration' => 'soon', 'until' => 'later'],
			'flow' => ['flow' => 'child-flow', 'mode' => 'wait'],
			'table' => ['table' => ['hitPolicy' => 'FIRST', 'inputs' => [], 'outputs' => [], 'rules' => []]],
			'end' => ['status' => 'completed', 'outcome' => 'ok'],
			'bad-end' => ['status' => 'exploded'],
		];
	}//end configs()

	/**
	 * The palette surface and validation outcomes of every logic node match the recording.
	 *
	 * @return void
	 */
	public function testLogicNodesMatchTheirRecording(): void {
		$l10n = $this->snapshotL10n();
		$urls = $this->snapshotUrls();
		$nodes = [
			new ActionInputsNode(l10n: $l10n, urls: $urls),
			new DecisionTableNode(
				engine: $this->createMock(DecisionTableEvaluator::class),
				validator: $this->createMock(DecisionTableValidator::class),
				presentation: new FlowNodePresentation(l10n: $l10n, urls: $urls),
				store: $this->createMock(DecisionTableStore::class)
			),
			new EndNode(l10n: $l10n, urls: $urls),
			new ExplodeNode(l10n: $l10n, urls: $urls),
			new FilterNode(l10n: $l10n, urls: $urls),
			new FlowStateNode(l10n: $l10n, urls: $urls),
			new IterateNode($l10n, $urls, $this->createMock(ContainerInterface::class)),
			new MapNode(
				l10n: $l10n,
				urls: $urls,
				mappings: $this->createMock(MappingService::class),
				mapper: $this->createMock(MappingMapperInterface::class)
			),
			new MergeNode(l10n: $l10n, urls: $urls),
			new RouterNode(l10n: $l10n, urls: $urls),
			new SetFieldsNode(l10n: $l10n, urls: $urls),
			new SubFlowNode(
				resolvers: $this->createMock(FlowLocator::class),
				runs: $this->createMock(FlowRunServiceInterface::class),
				l10n: $l10n,
				urls: $urls
			),
			new SwitchNode(l10n: $l10n, urls: $urls),
			new WaitNode(l10n: $l10n, urls: $urls),
		];

		$actual = [];
		foreach ($nodes as $node) {
			$actual[] = $this->surface(node: $node, configs: $this->configs());
		}

		$this->assertGolden(name: 'LogicNodes', actual: $actual);
	}//end testLogicNodesMatchTheirRecording()
}//end class
