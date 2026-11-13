<?php

/**
 * Unit test for FlowBpmnInterchange: the bound facade exports a flow, imports
 * the file back leniently, and imports strictly only what nothing refuses.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Bpmn;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Exception\BpmnImportRefused;
use OCA\OpenRegister\Service\Flow\Bpmn\FlowBpmnInterchange;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Bpmn\FlowBpmnInterchange
 * @uses   \OCA\OpenRegister\Db\Flow
 * @uses   \OCA\OpenRegister\Exception\BpmnImportRefused
 * @uses   \OCA\OpenRegister\Service\Flow\Bpmn\BpmnDiagramLayout
 * @uses   \OCA\OpenRegister\Service\Flow\Bpmn\BpmnExportDiagram
 * @uses   \OCA\OpenRegister\Service\Flow\Bpmn\BpmnExportDocument
 * @uses   \OCA\OpenRegister\Service\Flow\Bpmn\BpmnExportElements
 * @uses   \OCA\OpenRegister\Service\Flow\Bpmn\BpmnExportGraph
 * @uses   \OCA\OpenRegister\Service\Flow\Bpmn\BpmnExportGraphBuilder
 * @uses   \OCA\OpenRegister\Service\Flow\Bpmn\BpmnImportPass
 * @uses   \OCA\OpenRegister\Service\Flow\Bpmn\BpmnMappingReport
 * @uses   \OCA\OpenRegister\Service\Flow\Bpmn\BpmnSchemaValidator
 * @uses   \OCA\OpenRegister\Service\Flow\Bpmn\BpmnVocabulary
 * @uses   \OCA\OpenRegister\Service\Flow\Bpmn\FlowBpmnExporter
 * @uses   \OCA\OpenRegister\Service\Flow\Bpmn\FlowBpmnImporter
 * @uses \OCA\OpenRegister\Service\Flow\Bpmn\BpmnReading
 */
class FlowBpmnInterchangeTest extends TestCase {

	/**
	 * A small flow: start, mail, end.
	 *
	 * @return Flow
	 */
	private function flow(): Flow {
		$flow = new Flow();
		$flow->setUuid('7f1e2a10-0000-4000-8000-000000000002');
		$flow->setName('Melding afhandelen');
		$flow->setNodes(
			[
				['id' => 'start', 'name' => 'Start', 'type' => 'openregister.trigger-manual'],
				['id' => 'mail', 'name' => 'Stuur mail', 'type' => 'openregister.send-email', 'config' => ['to' => 'a@b.nl']],
				['id' => 'klaar', 'name' => 'Klaar', 'type' => 'openregister.end'],
			]
		);
		$flow->setEdges(
			[
				['id' => 'e1', 'from' => 'start', 'to' => 'mail'],
				['id' => 'e2', 'from' => 'mail', 'to' => 'klaar'],
			]
		);
		return $flow;
	}//end flow()

	/**
	 * The exported file imports back with the same nodes and edges, leniently and strictly.
	 *
	 * @return void
	 */
	public function testAnExportedFlowImportsBack(): void {
		$interchange = new FlowBpmnInterchange();
		$xml         = $interchange->export(flow: $this->flow());

		$this->assertStringContainsString('Melding afhandelen', $xml);
		foreach ([$interchange->import(xml: $xml), $interchange->importStrictly(xml: $xml)] as $result) {
			$this->assertSame(['start', 'mail', 'klaar'], array_column($result['flow']['nodes'], 'id'));
			$this->assertSame(['e1', 'e2'], array_column($result['flow']['edges'], 'id'));
			$this->assertFalse($result['report']->lostSomething());
		}
	}//end testAnExportedFlowImportsBack()

	/**
	 * A file with a refused construct imports leniently without it, and strictly not at all.
	 *
	 * @return void
	 */
	public function testARefusedConstructBlocksOnlyTheStrictImport(): void {
		$interchange = new FlowBpmnInterchange();
		$xml         = $interchange->export(flow: $this->flow());
		$foreign     = preg_replace('#(</[a-z0-9]+:process>)#i', '<bpmn:transaction id="Transaction_1" name="Boeking"/>$1', $xml, 1);
		$this->assertNotSame($xml, $foreign);

		$lenient = $interchange->import(xml: (string) $foreign);
		$this->assertNotContains('Transaction_1', array_column($lenient['flow']['nodes'], 'id'));
		$this->assertTrue($lenient['report']->lostSomething());

		$this->expectException(BpmnImportRefused::class);
		$interchange->importStrictly(xml: (string) $foreign);
	}//end testARefusedConstructBlocksOnlyTheStrictImport()
}//end class
