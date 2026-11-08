<?php

/**
 * Characterisation of BpmnDiagramLayout::positions().
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Bpmn;

use DOMDocument;
use DOMXPath;
use OCA\OpenRegister\Service\Flow\Bpmn\BpmnDiagramLayout;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Bpmn\BpmnDiagramLayout
 */
final class BpmnDiagramPositionsCharacterisationTest extends TestCase {

	/**
	 * Shapes with bounds are read, shapes without are skipped, ids are
	 * trimmed, coordinates are cast to int, and a repeated id keeps the last.
	 *
	 * @return void
	 */
	public function testWhichShapesArePositioned(): void {
		$xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<definitions xmlns="http://www.omg.org/spec/BPMN/20100524/MODEL"
             xmlns:bpmndi="http://www.omg.org/spec/BPMN/20100524/DI"
             xmlns:dc="http://www.omg.org/spec/DD/20100524/DC">
  <bpmndi:BPMNDiagram>
    <bpmndi:BPMNPlane>
      <bpmndi:BPMNShape bpmnElement=" start "><dc:Bounds x="10.7" y="20" width="36" height="36"/></bpmndi:BPMNShape>
      <bpmndi:BPMNShape bpmnElement="noBounds"/>
      <bpmndi:BPMNShape bpmnElement="task"><dc:Bounds x="100" y="-5"/><dc:Bounds x="999" y="999"/></bpmndi:BPMNShape>
      <bpmndi:BPMNShape bpmnElement="task"><dc:Bounds x="300" y="40"/></bpmndi:BPMNShape>
      <bpmndi:BPMNShape><dc:Bounds x="1" y="2"/></bpmndi:BPMNShape>
      <bpmndi:BPMNShape bpmnElement="blank"><dc:Bounds/></bpmndi:BPMNShape>
    </bpmndi:BPMNPlane>
  </bpmndi:BPMNDiagram>
</definitions>
XML;

		$document = new DOMDocument();
		$document->loadXML($xml);
		$xpath = new DOMXPath($document);
		$xpath->registerNamespace('bpmndi', 'http://www.omg.org/spec/BPMN/20100524/DI');
		$xpath->registerNamespace('dc', 'http://www.omg.org/spec/DD/20100524/DC');

		$this->assertSame(
			[
				'start' => ['x' => 10, 'y' => 20],
				'task' => ['x' => 300, 'y' => 40],
				'' => ['x' => 1, 'y' => 2],
				'blank' => ['x' => 0, 'y' => 0],
			],
			(new BpmnDiagramLayout())->positions($xpath)
		);
	}//end testWhichShapesArePositioned()

	/**
	 * A document with no diagram has no positions.
	 *
	 * @return void
	 */
	public function testNoDiagramMeansNoPositions(): void {
		$document = new DOMDocument();
		$document->loadXML('<definitions xmlns="http://www.omg.org/spec/BPMN/20100524/MODEL"/>');
		$xpath = new DOMXPath($document);
		$xpath->registerNamespace('bpmndi', 'http://www.omg.org/spec/BPMN/20100524/DI');
		$xpath->registerNamespace('dc', 'http://www.omg.org/spec/DD/20100524/DC');

		$this->assertSame([], (new BpmnDiagramLayout())->positions($xpath));
	}//end testNoDiagramMeansNoPositions()
}//end class
