<?php

/**
 * Unit tests for the BPMN export.
 *
 * The export is a CLAIM about a standard, and the claim has to be exactly as
 * wide as what the code does. These tests pin both halves: the mapping rows the
 * subset names, and the fact that a step the standard has no word for still
 * leaves with its real type attached.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Bpmn;

use DOMDocument;
use DOMXPath;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Service\Flow\Bpmn\BpmnVocabulary;
use OCA\OpenRegister\Service\Flow\Bpmn\FlowBpmnExporter;
use PHPUnit\Framework\TestCase;

class FlowBpmnExporterTest extends TestCase {

	private FlowBpmnExporter $exporter;

	protected function setUp(): void {
		parent::setUp();
		$this->exporter = new FlowBpmnExporter();
	}//end setUp()

	/**
	 * A flow exercising the mapping rows.
	 *
	 * @param array $nodes The nodes.
	 * @param array $edges The edges.
	 *
	 * @return Flow
	 */
	private function flow(array $nodes, array $edges = []): Flow {
		$flow = new Flow();
		$flow->setUuid('9f1c2d3e-0000-4000-8000-000000000001');
		$flow->setName('Close and notify');
		$flow->setNodes($nodes);
		$flow->setEdges($edges);
		return $flow;
	}//end flow()

	/**
	 * Parse the export and give back an xpath bound to the namespaces.
	 *
	 * @param Flow $flow The flow.
	 *
	 * @return DOMXPath
	 */
	private function xpath(Flow $flow): DOMXPath {
		$xml = $this->exporter->export($flow);
		$document = new DOMDocument();
		$this->assertTrue($document->loadXML($xml), 'the export is well-formed XML');

		$xpath = new DOMXPath($document);
		$xpath->registerNamespace('bpmn', FlowBpmnExporter::NS_BPMN);
		$xpath->registerNamespace('bpmndi', FlowBpmnExporter::NS_BPMNDI);
		$xpath->registerNamespace('dc', FlowBpmnExporter::NS_DC);
		$xpath->registerNamespace('or', BpmnVocabulary::EXTENSION_NS);
		return $xpath;
	}//end xpath()

	/**
	 * Every mapping row lands on the element the subset names.
	 *
	 * @return void
	 */
	public function testEveryMappingRowExportsAsItsDeclaredElement(): void {
		$xpath = $this->xpath(
			$this->flow(
				[
					['id' => 'start', 'type' => 'openregister.trigger-manual'],
					['id' => 'cron', 'type' => 'openregister.trigger-schedule'],
					['id' => 'onwrite', 'type' => 'openregister.trigger-object'],
					['id' => 'choice', 'type' => 'openregister.switch'],
					['id' => 'signal', 'type' => 'openregister.await-signal'],
					['id' => 'pause', 'type' => 'openregister.wait'],
					['id' => 'child', 'type' => 'openregister.sub-flow'],
					['id' => 'stop', 'type' => 'openregister.end'],
				]
			)
		);

		$this->assertSame(3, $xpath->query('//bpmn:startEvent')->length, 'three triggers, three start events');
		$this->assertSame(1, $xpath->query('//bpmn:startEvent/bpmn:timerEventDefinition')->length);
		$this->assertSame(1, $xpath->query('//bpmn:startEvent/bpmn:conditionalEventDefinition')->length);
		$this->assertSame(1, $xpath->query('//bpmn:exclusiveGateway')->length);
		$this->assertSame(2, $xpath->query('//bpmn:intermediateCatchEvent')->length);
		$this->assertSame(1, $xpath->query('//bpmn:intermediateCatchEvent/bpmn:messageEventDefinition')->length);
		$this->assertSame(1, $xpath->query('//bpmn:intermediateCatchEvent/bpmn:timerEventDefinition')->length);
		$this->assertSame(1, $xpath->query('//bpmn:callActivity')->length);
		$this->assertSame(1, $xpath->query('//bpmn:endEvent')->length);
	}//end testEveryMappingRowExportsAsItsDeclaredElement()

	/**
	 * A step the standard has no word for still leaves, as a serviceTask
	 * carrying what it really is.
	 *
	 * @return void
	 */
	public function testAnUnnamedStepExportsAsAServiceTaskCarryingItsRealType(): void {
		$xpath = $this->xpath(
			$this->flow([['id' => 'write', 'type' => 'openregister.object-write', 'config' => ['register' => 'zaken']]])
		);

		$this->assertSame(1, $xpath->query('//bpmn:serviceTask')->length);
		$this->assertSame(
			'openregister.object-write',
			$xpath->query('//bpmn:serviceTask/bpmn:extensionElements/or:type')->item(0)->textContent
		);
		$this->assertSame(
			['register' => 'zaken'],
			json_decode(
				$xpath->query('//bpmn:serviceTask/bpmn:extensionElements/or:config')->item(0)->textContent,
				true
			)
		);
	}//end testAnUnnamedStepExportsAsAServiceTaskCarryingItsRealType()

	/**
	 * The extension goes on EVERY node, including the ones BPMN can name.
	 *
	 * A `switch` and a `route` are both exclusive gateways; without the
	 * extension the file cannot say which it was.
	 *
	 * @return void
	 */
	public function testTheExtensionIsOnNamedNodesToo(): void {
		$xpath = $this->xpath($this->flow([['id' => 'choice', 'type' => 'openregister.route']]));

		$this->assertSame(
			'openregister.route',
			$xpath->query('//bpmn:exclusiveGateway/bpmn:extensionElements/or:type')->item(0)->textContent
		);
	}//end testTheExtensionIsOnNamedNodesToo()

	/**
	 * An edge naming several endpoints becomes several sequence flows.
	 *
	 * @return void
	 */
	public function testAFanOutEdgeBecomesSeveralSequenceFlows(): void {
		$xpath = $this->xpath(
			$this->flow(
				[
					['id' => 'start', 'type' => 'openregister.trigger-manual'],
					['id' => 'a', 'type' => 'openregister.object-write'],
					['id' => 'b', 'type' => 'openregister.object-write'],
				],
				[['id' => 'fan', 'from' => ['start'], 'to' => ['a', 'b']]]
			)
		);

		$this->assertSame(2, $xpath->query('//bpmn:sequenceFlow')->length);
		$this->assertSame('fan', $xpath->query('//bpmn:sequenceFlow')->item(0)->getAttribute('id'));
		$this->assertSame('fan-1', $xpath->query('//bpmn:sequenceFlow')->item(1)->getAttribute('id'));
	}//end testAFanOutEdgeBecomesSeveralSequenceFlows()

	/**
	 * A dangling edge is dropped rather than exported.
	 *
	 * A sequenceFlow pointing at nothing makes the file unopenable in every
	 * modeller, which turns one broken edge into an export nobody can use.
	 *
	 * @return void
	 */
	public function testADanglingEdgeIsDroppedRatherThanExported(): void {
		$xpath = $this->xpath(
			$this->flow(
				[['id' => 'start', 'type' => 'openregister.trigger-manual']],
				[['id' => 'nowhere', 'from' => ['start'], 'to' => ['deleted-node']]]
			)
		);

		$this->assertSame(0, $xpath->query('//bpmn:sequenceFlow')->length);
	}//end testADanglingEdgeIsDroppedRatherThanExported()

	/**
	 * Canvas positions become diagram interchange; a node without one is laid
	 * out in document order rather than stacked on the origin.
	 *
	 * @return void
	 */
	public function testCanvasPositionsBecomeDiagramInterchange(): void {
		$xpath = $this->xpath(
			$this->flow(
				[
					['id' => 'start', 'type' => 'openregister.trigger-manual', 'position' => ['x' => 340, 'y' => 120]],
					['id' => 'next', 'type' => 'openregister.object-write'],
				]
			)
		);

		$bounds = $xpath->query('//bpmndi:BPMNShape/dc:Bounds');
		$this->assertSame('340', $bounds->item(0)->getAttribute('x'));
		$this->assertSame('120', $bounds->item(0)->getAttribute('y'));
		$this->assertSame('180', $bounds->item(1)->getAttribute('x'), 'laid out, not stacked on the origin');
	}//end testCanvasPositionsBecomeDiagramInterchange()

	/**
	 * A uuid is not a valid XML id, and every id in the file has to be one.
	 *
	 * @return void
	 */
	public function testIdsAreMadeValidXmlIds(): void {
		$xpath = $this->xpath($this->flow([['id' => '9f1c-2d3e/step one', 'type' => 'openregister.end']]));

		$id = $xpath->query('//bpmn:endEvent')->item(0)->getAttribute('id');
		$this->assertSame('or_9f1c-2d3e_step_one', $id);
		$this->assertMatchesRegularExpression('/^[A-Za-z_][A-Za-z0-9_.-]*$/', $id);
	}//end testIdsAreMadeValidXmlIds()

	/**
	 * The exporter does not keep a second mapping table.
	 *
	 * `BpmnVocabulary` owns both directions, including the fact that the import
	 * table is not the export table flipped. A copy here would agree with it
	 * until somebody added a node type to one of them, and the disagreement
	 * would show up as a file that does not round-trip through its own product.
	 *
	 * @return void
	 */
	public function testTheExporterReadsTheSharedVocabulary(): void {
		$xpath = $this->xpath($this->flow([['id' => 'child', 'type' => 'openregister.sub-flow']]));

		$this->assertSame('callActivity', BpmnVocabulary::EXPORT['openregister.sub-flow']);
		$this->assertSame(1, $xpath->query('//bpmn:callActivity')->length);

		$source = (string)file_get_contents(__DIR__ . '/../../../../../lib/Service/Flow/Bpmn/FlowBpmnExporter.php');
		$this->assertStringNotContainsString(
			"'openregister.trigger-manual' =>",
			$source,
			'the exporter must not hold its own copy of the mapping'
		);
	}//end testTheExporterReadsTheSharedVocabulary()
}//end class
