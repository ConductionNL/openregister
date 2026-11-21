<?php

/**
 * A BPMN parallel gateway imports as the engine's own split and join: a
 * pass-through merge whose outgoing edges run side by side, marked
 * `join: true` where two or more paths arrive.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Bpmn
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/flow-designer-parallel-paths-and-waits/specs/flow-engine/spec.md#requirement-a-person-draws-parallel-paths-and-waits-in-the-flow-editor-and-the-flow-runs-as-drawn
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Bpmn;

use OCA\OpenRegister\Service\Flow\Bpmn\BpmnSchemaValidator;
use OCA\OpenRegister\Service\Flow\Bpmn\BpmnVocabulary;
use OCA\OpenRegister\Service\Flow\Bpmn\FlowBpmnExporter;
use OCA\OpenRegister\Service\Flow\Bpmn\FlowBpmnImporter;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Bpmn\FlowBpmnImporter
 * @uses   \OCA\OpenRegister\Service\Flow\Bpmn\BpmnVocabulary
 * @uses   \OCA\OpenRegister\Service\Flow\Bpmn\BpmnDiagramLayout
 * @uses   \OCA\OpenRegister\Service\Flow\Bpmn\BpmnImportPass
 * @uses   \OCA\OpenRegister\Service\Flow\Bpmn\BpmnMappingReport
 * @uses   \OCA\OpenRegister\Service\Flow\Bpmn\BpmnReading
 * @uses   \OCA\OpenRegister\Service\Flow\Bpmn\BpmnSchemaValidator
 */
class ParallelGatewayImportTest extends TestCase {

	/**
	 * A process: start, a split into two checks, a join, an await, an end.
	 *
	 * @param string $joinExtension Extension elements on the joining gateway.
	 *
	 * @return string The file.
	 */
	private static function process(string $joinExtension = ''): string {
		$prefix = BpmnVocabulary::EXTENSION_PREFIX;
		return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<bpmn:definitions xmlns:bpmn="' . FlowBpmnExporter::NS_BPMN . '"'
			. ' xmlns:' . $prefix . '="' . BpmnVocabulary::EXTENSION_NS . '" id="D1" targetNamespace="urn:x">'
			. '<bpmn:process id="P1" name="Vergunning" isExecutable="true">'
			. '<bpmn:startEvent id="start"/>'
			. '<bpmn:parallelGateway id="split"/>'
			. '<bpmn:intermediateCatchEvent id="budget" name="Budget toetsen"><bpmn:messageEventDefinition/></bpmn:intermediateCatchEvent>'
			. '<bpmn:intermediateCatchEvent id="permit" name="Vergunning toetsen"><bpmn:messageEventDefinition/></bpmn:intermediateCatchEvent>'
			. '<bpmn:parallelGateway id="join" name="Beslissen">' . $joinExtension . '</bpmn:parallelGateway>'
			. '<bpmn:endEvent id="end"/>'
			. '<bpmn:sequenceFlow id="f1" sourceRef="start" targetRef="split"/>'
			. '<bpmn:sequenceFlow id="f2" sourceRef="split" targetRef="budget"/>'
			. '<bpmn:sequenceFlow id="f3" sourceRef="split" targetRef="permit"/>'
			. '<bpmn:sequenceFlow id="f4" sourceRef="budget" targetRef="join"/>'
			. '<bpmn:sequenceFlow id="f5" sourceRef="permit" targetRef="join"/>'
			. '<bpmn:sequenceFlow id="f6" sourceRef="join" targetRef="end"/>'
			. '</bpmn:process></bpmn:definitions>';
	}//end process()

	/**
	 * The imported flow.
	 *
	 * @param string $xml The file.
	 *
	 * @return array<string, mixed>
	 */
	private static function imported(string $xml): array {
		return (new FlowBpmnImporter(vocabulary: new BpmnVocabulary(), validator: new BpmnSchemaValidator()))->import(xml: $xml);
	}//end imported()

	/**
	 * The nodes of a result, by id.
	 *
	 * @param array<string, mixed> $result The import result.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function nodesById(array $result): array {
		$nodes = [];
		foreach (($result['flow']['nodes'] ?? $result['nodes'] ?? []) as $node) {
			$nodes[$node['id']] = $node;
		}

		return $nodes;
	}//end nodesById()

	/**
	 * The split is a pass-through merge without a join; the converging gateway waits for both paths.
	 *
	 * @return void
	 */
	public function testASplitAndAJoinImportAsMergeNodesWithTheJoinMarked(): void {
		$nodes = self::nodesById(self::imported(self::process()));

		$this->assertSame('openregister.merge', $nodes['split']['type']);
		$this->assertArrayNotHasKey('join', $nodes['split']);
		$this->assertSame('openregister.merge', $nodes['join']['type']);
		$this->assertTrue($nodes['join']['join']);
		$this->assertSame('Beslissen', $nodes['join']['name']);
		$this->assertArrayNotHasKey('join', $nodes['end']);
	}//end testASplitAndAJoinImportAsMergeNodesWithTheJoinMarked()

	/**
	 * Every sequence flow arrives as an edge, so both paths run from the split and meet at the join.
	 *
	 * @return void
	 */
	public function testBothPathsSurviveTheImport(): void {
		$result = self::imported(self::process());
		$edges = array_map(
			static fn (array $edge): string => $edge['from'] . '>' . $edge['to'],
			($result['flow']['edges'] ?? $result['edges'] ?? [])
		);

		$this->assertSame(
			['start>split', 'split>budget', 'split>permit', 'budget>join', 'permit>join', 'join>end'],
			$edges
		);
	}//end testBothPathsSurviveTheImport()

	/**
	 * The gateways are reported as mapped, not dropped.
	 *
	 * @return void
	 */
	public function testTheGatewaysAreMappedNotDropped(): void {
		$entries = array_values(array_filter(
			self::imported(self::process())['report']->entries(),
			static fn (array $entry): bool => $entry['kind'] === 'parallelGateway'
		));

		$this->assertSame(['split', 'join'], array_column($entries, 'elementId'));
		$this->assertSame(['mapped', 'mapped'], array_column($entries, 'verdict'));
	}//end testTheGatewaysAreMappedNotDropped()

	/**
	 * A gateway our own export typed keeps its type, and is not marked a join by the importer.
	 *
	 * @return void
	 */
	public function testADeclaredTypeWinsOverTheGatewayReading(): void {
		$prefix = BpmnVocabulary::EXTENSION_PREFIX;
		$nodes = self::nodesById(self::imported(self::process('<bpmn:extensionElements><' . $prefix . ':type>openregister.end</' . $prefix . ':type></bpmn:extensionElements>')));

		$this->assertSame('openregister.end', $nodes['join']['type']);
		$this->assertArrayNotHasKey('join', $nodes['join']);
	}//end testADeclaredTypeWinsOverTheGatewayReading()
}//end class
