<?php

/**
 * Export, import, and the report in between — against our own fixtures.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Bpmn
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-bpmn-interchange/specs/flow-bpmn-interchange/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Bpmn;

use DOMDocument;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Exception\BpmnImportRefused;
use OCA\OpenRegister\Service\Flow\Bpmn\BpmnMappingReport;
use OCA\OpenRegister\Service\Flow\Bpmn\BpmnVocabulary;
use OCA\OpenRegister\Service\Flow\Bpmn\FlowBpmnExporter;
use OCA\OpenRegister\Service\Flow\Bpmn\FlowBpmnImporter;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the round trip and the import verdicts.
 */
class FlowBpmnRoundTripTest extends TestCase {

	/**
	 * The exporter.
	 *
	 * @return FlowBpmnExporter The exporter.
	 */
	private function exporter(): FlowBpmnExporter {
		return new FlowBpmnExporter(vocabulary: new BpmnVocabulary());
	}//end exporter()

	/**
	 * The importer.
	 *
	 * @return FlowBpmnImporter The importer.
	 */
	private function importer(): FlowBpmnImporter {
		return new FlowBpmnImporter(vocabulary: new BpmnVocabulary());
	}//end importer()

	/**
	 * A flow exercising every mapping row plus a fallback task.
	 *
	 * @return Flow The flow.
	 */
	private function flow(): Flow {
		$flow = new Flow();
		$flow->setUuid('7f1e2a10-0000-4000-8000-000000000001');
		$flow->setName('Bezwaar behandelen');
		$flow->setNodes(
			[
				['id' => 'start', 'name' => 'Start', 'type' => 'openregister.trigger-manual', 'position' => ['x' => 10, 'y' => 20]],
				['id' => 'sched', 'name' => 'Elke nacht', 'type' => 'openregister.trigger-schedule', 'config' => ['cron' => '0 2 * * *']],
				['id' => 'obj', 'name' => 'Op object', 'type' => 'openregister.trigger-object'],
				['id' => 'kies', 'name' => 'Kies', 'type' => 'openregister.switch'],
				['id' => 'route', 'name' => 'Route', 'type' => 'openregister.route'],
				['id' => 'wacht', 'name' => 'Wacht op signaal', 'type' => 'openregister.await-signal'],
				['id' => 'pauze', 'name' => 'Pauze', 'type' => 'openregister.wait'],
				['id' => 'deel', 'name' => 'Deelflow', 'type' => 'openregister.sub-flow'],
				['id' => 'mail', 'name' => 'Stuur mail', 'type' => 'openregister.send-email', 'config' => ['to' => 'a@b.nl']],
				['id' => 'klaar', 'name' => 'Klaar', 'type' => 'openregister.end'],
			]
		);
		$flow->setEdges(
			[
				['id' => 'e1', 'from' => 'start', 'to' => 'kies'],
				['id' => 'e2', 'from' => 'kies', 'to' => 'mail', 'condition' => 'bedrag > 100'],
				['id' => 'e3', 'from' => 'mail', 'to' => 'klaar'],
			]
		);

		return $flow;
	}//end flow()

	/**
	 * The export parses and declares the namespaces a reader needs.
	 *
	 * 🔑 THIS IS NOT "IT VALIDATES". The OMG XSD set is not vendored, so the
	 * requirement "every exported file validates against the BPMN 2.0 XSD" is
	 * NOT asserted anywhere. What is asserted is weaker and stated as such.
	 *
	 * @return void
	 */
	public function testTheExportParsesAndCarriesItsNamespaces(): void {
		$xml = $this->exporter()->export(flow: $this->flow());

		$document = new DOMDocument();
		$this->assertTrue($document->loadXML($xml), 'the export must at least be well-formed XML');
		$this->assertStringContainsString(FlowBpmnExporter::NS_BPMN, $xml);
		$this->assertStringContainsString(BpmnVocabulary::EXTENSION_NS, $xml);
		$this->assertStringContainsString('isExecutable="false"', $xml, 'BPMN here is interchange, never an execution semantic');
	}//end testTheExportParsesAndCarriesItsNamespaces()

	/**
	 * 🔴 Our own file round-trips: every node keeps its id, type and config,
	 * and the canvas position survives.
	 *
	 * @return void
	 */
	public function testOurOwnFileRoundTrips(): void {
		$original = $this->flow();
		$xml = $this->exporter()->export(flow: $original);
		$result = $this->importer()->import(xml: $xml);

		$byId = [];
		foreach ($result['flow']['nodes'] as $node) {
			$byId[$node['id']] = $node;
		}

		foreach ($original->getNodes() as $node) {
			$this->assertArrayHasKey($node['id'], $byId, sprintf('%s did not come back', $node['id']));
			$this->assertSame(
				$node['type'],
				$byId[$node['id']]['type'],
				sprintf('%s came back as a different type', $node['id'])
			);
		}

		$this->assertSame(
			['cron' => '0 2 * * *'],
			$byId['sched']['config'],
			'a node config must survive the trip, or a schedule comes back empty'
		);
		$this->assertSame(['x' => 10, 'y' => 20], $byId['start']['position'], 'and the canvas position with it');
		$this->assertSame('Bezwaar behandelen', $result['flow']['name']);
	}//end testOurOwnFileRoundTrips()

	/**
	 * 🔴 `switch` and `route` both export to an exclusive gateway, and BOTH
	 * come back as themselves.
	 *
	 * This is the collision the vocabulary's two tables exist for. Without the
	 * extension element one of the two would come back as the other, and the
	 * flow would still look right.
	 *
	 * @return void
	 */
	public function testSwitchAndRouteBothSurviveTheSharedGateway(): void {
		$xml = $this->exporter()->export(flow: $this->flow());
		$result = $this->importer()->import(xml: $xml);

		$types = [];
		foreach ($result['flow']['nodes'] as $node) {
			$types[$node['id']] = $node['type'];
		}

		$this->assertSame('openregister.switch', $types['kies']);
		$this->assertSame('openregister.route', $types['route'], 'the gateway alone cannot say which it was');
	}//end testSwitchAndRouteBothSurviveTheSharedGateway()

	/**
	 * Edges survive with their conditions.
	 *
	 * @return void
	 */
	public function testEdgesSurviveWithTheirConditions(): void {
		$result = $this->importer()->import(xml: $this->exporter()->export(flow: $this->flow()));

		$this->assertCount(3, $result['flow']['edges']);
		$conditions = array_column($result['flow']['edges'], 'condition', 'id');
		$this->assertSame('bedrag > 100', $conditions['e2']);
	}//end testEdgesSurviveWithTheirConditions()

	/**
	 * Our own file loses nothing, which is the control for every loss test.
	 *
	 * @return void
	 */
	public function testOurOwnFileLosesNothing(): void {
		$result = $this->importer()->import(xml: $this->exporter()->export(flow: $this->flow()));

		$this->assertFalse(
			$result['report']->lostSomething(),
			'the control: a file this product wrote must import with no approximation and no refusal'
		);
	}//end testOurOwnFileLosesNothing()

	/**
	 * A foreign file, with constructs from Camunda Modeler.
	 *
	 * @param string $extra Extra elements inside the process.
	 *
	 * @return string The XML.
	 */
	private function foreignFile(string $extra = ''): string {
		return '<?xml version="1.0" encoding="UTF-8"?>
<bpmn:definitions xmlns:bpmn="' . FlowBpmnExporter::NS_BPMN . '" id="D1" targetNamespace="urn:x">
  <bpmn:process id="Process_1" name="Ingekocht proces" isExecutable="true">
    <bpmn:startEvent id="StartEvent_1" name="Start"/>
    <bpmn:userTask id="Activity_1" name="Beoordeel"/>
    <bpmn:serviceTask id="Activity_2" name="Send email"/>
    ' . $extra . '
    <bpmn:endEvent id="EndEvent_1" name="Einde"/>
    <bpmn:sequenceFlow id="Flow_1" sourceRef="StartEvent_1" targetRef="Activity_1"/>
  </bpmn:process>
</bpmn:definitions>';
	}//end foreignFile()

	/**
	 * 🔴 A task with no openregister type imports TYPELESS and is listed.
	 *
	 * Never guessed from the name — the fixture's task is literally called
	 * "Send email".
	 *
	 * @return void
	 */
	public function testATaskCalledSendEmailDoesNotBecomeASendEmailNode(): void {
		$result = $this->importer()->import(xml: $this->foreignFile());

		$types = array_column($result['flow']['nodes'], 'type', 'id');
		$this->assertSame(
			'',
			$types['Activity_2'],
			'a flow that sends mail because a box was labelled "send email" is a flow nobody authorised'
		);

		$entries = array_column($result['report']->entries(), 'verdict', 'elementId');
		$this->assertSame(BpmnMappingReport::APPROXIMATED, $entries['Activity_2']);
	}//end testATaskCalledSendEmailDoesNotBecomeASendEmailNode()

	/**
	 * A user task is approximated to a signal, and the report says what was
	 * lost.
	 *
	 * @return void
	 */
	public function testAUserTaskIsApproximatedAndSaysWhatWasLost(): void {
		$result = $this->importer()->import(xml: $this->foreignFile());

		$types = array_column($result['flow']['nodes'], 'type', 'id');
		$this->assertSame('openregister.await-signal', $types['Activity_1']);

		$actions = array_column($result['report']->entries(), 'action', 'elementId');
		$this->assertStringContainsString('assignee', $actions['Activity_1']);
		$this->assertTrue($result['report']->lostSomething());
	}//end testAUserTaskIsApproximatedAndSaysWhatWasLost()

	/**
	 * 🔴 An unsupported construct is named, not silently dropped — and with
	 * strict it creates no flow.
	 *
	 * @return void
	 */
	public function testAnUnsupportedConstructIsNamedAndStrictCreatesNoFlow(): void {
		$xml = $this->foreignFile(extra: '<bpmn:transaction id="Transaction_1" name="Boeking"/>');

		$lenient = $this->importer()->import(xml: $xml);
		$entries = array_column($lenient['report']->entries(), 'verdict', 'elementId');
		$this->assertSame(BpmnMappingReport::REFUSED, $entries['Transaction_1'], 'the element is named as refused');

		$ids = array_column($lenient['flow']['nodes'], 'id');
		$this->assertNotContains('Transaction_1', $ids, 'and it is not in the flow');
		$this->assertNotSame([], $ids, 'while the rest of the file still imported');

		try {
			$this->importer()->import(xml: $xml, strict: true);
			$this->fail('strict must not create a flow when something was refused');
		} catch (BpmnImportRefused $refused) {
			$this->assertNotNull($refused->getReport(), 'a strict refusal still owes the author the list');
			$this->assertSame(
				['Transaction_1'],
				array_column($refused->getReport()->withVerdict(verdict: BpmnMappingReport::REFUSED), 'elementId')
			);
		}
	}//end testAnUnsupportedConstructIsNamedAndStrictCreatesNoFlow()

	/**
	 * 🔴 A file with no diagram interchange is laid out, not piled at the
	 * origin.
	 *
	 * @return void
	 */
	public function testAFileWithNoDiagramIsLaidOutRatherThanPiled(): void {
		$result = $this->importer()->import(xml: $this->foreignFile());

		$positions = [];
		foreach ($result['flow']['nodes'] as $node) {
			$positions[] = $node['position']['x'] . ',' . $node['position']['y'];
		}

		$this->assertSame(
			count($positions),
			count(array_unique($positions)),
			'a heap of overlapping boxes reads as "the import is broken", not as "this file had no layout"'
		);
	}//end testAFileWithNoDiagramIsLaidOutRatherThanPiled()

	/**
	 * A file declaring more than one process is refused rather than guessed at.
	 *
	 * @return void
	 */
	public function testAFileWithTwoProcessesIsRefused(): void {
		$xml = '<?xml version="1.0" encoding="UTF-8"?>
<bpmn:definitions xmlns:bpmn="' . FlowBpmnExporter::NS_BPMN . '" id="D1" targetNamespace="urn:x">
  <bpmn:process id="P1"><bpmn:startEvent id="S1"/></bpmn:process>
  <bpmn:process id="P2"><bpmn:startEvent id="S2"/></bpmn:process>
</bpmn:definitions>';

		$this->expectException(BpmnImportRefused::class);
		$this->importer()->import(xml: $xml);
	}//end testAFileWithTwoProcessesIsRefused()

	/**
	 * A file that is not XML is refused before any mapping happens.
	 *
	 * @return void
	 */
	public function testANonXmlFileIsRefusedBeforeMapping(): void {
		try {
			$this->importer()->import(xml: '<bpmn:definitions><oops');
			$this->fail('a malformed file must not produce a mapping report');
		} catch (BpmnImportRefused $refused) {
			$this->assertNull(
				$refused->getReport(),
				'a report over a malformed document would attribute XML problems to process constructs'
			);
		}
	}//end testANonXmlFileIsRefusedBeforeMapping()

	/**
	 * A flow with no nodes exports and imports without inventing anything.
	 *
	 * @return void
	 */
	public function testAnEmptyFlowSurvivesBothDirections(): void {
		$flow = new Flow();
		$flow->setUuid('empty');
		$flow->setName('Leeg');
		$flow->setNodes([]);
		$flow->setEdges([]);

		$result = $this->importer()->import(xml: $this->exporter()->export(flow: $flow));

		$this->assertSame([], $result['flow']['nodes']);
		$this->assertSame([], $result['flow']['edges']);
		$this->assertSame([], $result['report']->entries());
	}//end testAnEmptyFlowSurvivesBothDirections()

	/**
	 * 🔴 A uuid that begins with a digit is not a valid XML id.
	 *
	 * An invalid id makes the whole document unparseable by the tool the
	 * export exists to reach, and the failure arrives as "Camunda cannot open
	 * your file".
	 *
	 * @return void
	 */
	public function testANumericIdIsMadeValidXml(): void {
		$flow = new Flow();
		$flow->setUuid('9f1e2a10-0000-4000-8000-000000000001');
		$flow->setName('Cijfer');
		$flow->setNodes([['id' => '1-start', 'type' => 'openregister.trigger-manual']]);
		$flow->setEdges([]);

		$xml = $this->exporter()->export(flow: $flow);

		$document = new DOMDocument();
		$this->assertTrue($document->loadXML($xml), 'an id starting with a digit would make this unparseable');
	}//end testANumericIdIsMadeValidXml()

	/**
	 * 🔴 The endpoints call methods that exist.
	 *
	 * I wrote `FlowService::create()` first. There is no such method — it is
	 * `save()` — and nothing would have caught it: `php -l` cannot see it, and
	 * a mocked service invents whichever method it is asked for, so a
	 * controller test would have passed while the real call was a fatal. The
	 * same shape as a double that adds a method the real class lacks.
	 *
	 * @return void
	 */
	public function testTheEndpointsCallMethodsThatExist(): void {
		$service = \OCA\OpenRegister\Service\Flow\FlowService::class;

		foreach (['save', 'find'] as $method) {
			$this->assertTrue(
				method_exists($service, $method),
				sprintf('FlowController\'s BPMN endpoints call FlowService::%s(), which does not exist.', $method)
			);
		}

		$controller = (string)file_get_contents(dirname(__DIR__, 5) . '/lib/Controller/FlowController.php');
		$this->assertStringNotContainsString(
			'flows->create(',
			$controller,
			'FlowService has no create(); the import path must use save()'
		);
	}//end testTheEndpointsCallMethodsThatExist()
}//end class
