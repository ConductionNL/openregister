<?php

/**
 * A flow as BPMN 2.0 XML, so it can be opened somewhere else.
 *
 * 🔑 THE NODE'S OWN TYPE AND CONFIG TRAVEL IN `extensionElements`, on every
 * node, including the ones that map to a first-class BPMN element. That is what
 * makes a round trip through our own product lossless: an `exclusiveGateway`
 * alone cannot say whether it was a `switch` or a `route`, and reading it back
 * from the gateway kind would pick one of the two by table order. The extension
 * element says which, so the importer never has to guess.
 *
 * 🔴 IT VALIDATES NOTHING AGAINST THE OMG XSD, and says so rather than
 * implying otherwise. The schema set is a licence-checked third-party artefact
 * this lane did not vendor, so "every exported file validates against the BPMN
 * 2.0 XSD" is NOT asserted here. What is asserted is that the output parses,
 * carries the declared namespaces, and round-trips through our own importer.
 * That is a weaker claim, and it is the one the tests make.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Bpmn
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/flow-bpmn-interchange/specs/flow-bpmn-interchange/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Bpmn;

use DOMDocument;
use DOMElement;
use OCA\OpenRegister\Db\Flow;

/**
 * Serialises a flow's node/edge graph to a `bpmn:process` with diagram interchange.
 *
 * @spec openspec/changes/flow-bpmn-interchange/specs/flow-bpmn-interchange/spec.md
 */
class FlowBpmnExporter {

	/**
	 * The BPMN 2.0 model namespace.
	 *
	 * @var string
	 */
	public const NS_BPMN = 'http://www.omg.org/spec/BPMN/20100524/MODEL';

	/**
	 * The BPMN diagram interchange namespace.
	 *
	 * @var string
	 */
	public const NS_BPMNDI = 'http://www.omg.org/spec/BPMN/20100524/DI';

	/**
	 * The DC namespace DI bounds live in.
	 *
	 * @var string
	 */
	public const NS_DC = 'http://www.omg.org/spec/DD/20100524/DC';

	/**
	 * Default node width, when the canvas gave none.
	 *
	 * @var int
	 */
	public const WIDTH = 100;

	/**
	 * Default node height.
	 *
	 * @var int
	 */
	public const HEIGHT = 80;

	/**
	 * Horizontal spacing for a node with no stored position.
	 *
	 * @var int
	 */
	public const SPACING = 180;

	/**
	 * Constructor.
	 *
	 * @param BpmnVocabulary $vocabulary The one mapping table.
	 */
	public function __construct(
		private readonly BpmnVocabulary $vocabulary,
	) {
	}//end __construct()

	/**
	 * A flow as BPMN 2.0 XML.
	 *
	 * @param Flow $flow The flow.
	 *
	 * @return string The XML.
	 *
	 * @spec openspec/changes/flow-bpmn-interchange/specs/flow-bpmn-interchange/spec.md
	 */
	public function export(Flow $flow): string {
		$document = new DOMDocument('1.0', 'UTF-8');
		$document->formatOutput = true;

		$definitions = $document->createElementNS(self::NS_BPMN, 'bpmn:definitions');
		$definitions->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:bpmndi', self::NS_BPMNDI);
		$definitions->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:dc', self::NS_DC);
		$definitions->setAttributeNS(
			'http://www.w3.org/2000/xmlns/',
			'xmlns:' . BpmnVocabulary::EXTENSION_PREFIX,
			BpmnVocabulary::EXTENSION_NS
		);
		$definitions->setAttribute('id', 'Definitions_' . $this->idOf(value: (string)$flow->getUuid()));
		$definitions->setAttribute('targetNamespace', BpmnVocabulary::EXTENSION_NS);
		$document->appendChild($definitions);

		$processId = 'Process_' . $this->idOf(value: (string)$flow->getUuid());
		$process = $document->createElementNS(self::NS_BPMN, 'bpmn:process');
		$process->setAttribute('id', $processId);
		$process->setAttribute('name', (string)($flow->getName() ?? ''));
		// 🔑 `isExecutable="false"` is the honest value. A BPMN engine reading
		// this file must not believe it can execute it: the execution semantic
		// is symfony/workflow's, and this file is interchange, never a
		// deployable process.
		$process->setAttribute('isExecutable', 'false');
		$definitions->appendChild($process);

		$nodes = $this->nodesOf(flow: $flow);
		foreach ($nodes as $node) {
			$process->appendChild($this->nodeElement(document: $document, node: $node));
		}

		$edges = $this->edgesOf(flow: $flow);
		foreach ($edges as $index => $edge) {
			$process->appendChild($this->edgeElement(document: $document, edge: $edge, index: $index));
		}

		$definitions->appendChild(
			$this->diagram(document: $document, processId: $processId, nodes: $nodes, edges: $edges)
		);

		return (string)$document->saveXML();
	}//end export()

	/**
	 * One node, with its type and config in `extensionElements`.
	 *
	 * @param DOMDocument          $document The document.
	 * @param array<string, mixed> $node     The node.
	 *
	 * @return DOMElement The element.
	 */
	private function nodeElement(DOMDocument $document, array $node): DOMElement {
		$type = (string)($node['type'] ?? '');
		$kind = $this->vocabulary->elementFor(nodeType: $type);

		// `intermediateCatchEvent:message` is one element with a child event
		// definition, not an element called that. The colon is the
		// vocabulary's way of naming the pair; it is resolved here, in the one
		// place that writes XML.
		$eventDefinition = null;
		$elementName = $kind;
		if (str_contains($kind, ':') === true) {
			[$elementName, $eventDefinition] = explode(':', $kind, 2);
		}

		$element = $document->createElementNS(self::NS_BPMN, 'bpmn:' . $elementName);
		$element->setAttribute('id', $this->idOf(value: (string)($node['id'] ?? '')));
		$element->setAttribute('name', (string)($node['name'] ?? $node['id'] ?? ''));

		if ($eventDefinition !== null) {
			$element->appendChild(
				$document->createElementNS(self::NS_BPMN, 'bpmn:' . $eventDefinition . 'EventDefinition')
			);
		}

		$extensions = $document->createElementNS(self::NS_BPMN, 'bpmn:extensionElements');
		$typeElement = $document->createElementNS(
			BpmnVocabulary::EXTENSION_NS,
			BpmnVocabulary::EXTENSION_PREFIX . ':' . BpmnVocabulary::ELEMENT_TYPE,
			$type
		);
		$extensions->appendChild($typeElement);

		$config = ($node['config'] ?? null);
		if (is_array($config) === true && $config !== []) {
			$configElement = $document->createElementNS(
				BpmnVocabulary::EXTENSION_NS,
				BpmnVocabulary::EXTENSION_PREFIX . ':' . BpmnVocabulary::ELEMENT_CONFIG,
				(string)json_encode($config)
			);
			$extensions->appendChild($configElement);
		}

		$element->appendChild($extensions);

		return $element;
	}//end nodeElement()

	/**
	 * One edge as a sequence flow.
	 *
	 * @param DOMDocument          $document The document.
	 * @param array<string, mixed> $edge     The edge.
	 * @param int                  $index    Its index, for an edge with no id.
	 *
	 * @return DOMElement The element.
	 */
	private function edgeElement(DOMDocument $document, array $edge, int $index): DOMElement {
		$element = $document->createElementNS(self::NS_BPMN, 'bpmn:sequenceFlow');
		$element->setAttribute('id', $this->idOf(value: (string)($edge['id'] ?? ('edge-' . $index))));
		$element->setAttribute('sourceRef', $this->idOf(value: (string)($edge['from'] ?? '')));
		$element->setAttribute('targetRef', $this->idOf(value: (string)($edge['to'] ?? '')));

		$condition = trim((string)($edge['condition'] ?? ''));
		if ($condition !== '') {
			$expression = $document->createElementNS(self::NS_BPMN, 'bpmn:conditionExpression', $condition);
			$element->appendChild($expression);
		}

		return $element;
	}//end edgeElement()

	/**
	 * The diagram interchange block, from the canvas positions.
	 *
	 * 🔑 BOTH SPELLINGS OF A POSITION ARE READ. PHP does not own the canvas
	 * shape — the editor writes it — and the stored graphs carry `position:
	 * {x, y}` and bare `x`/`y` alike. Reading only one would silently lay out a
	 * perfectly positioned flow as a diagonal line, which reads as "the export
	 * lost my layout".
	 *
	 * @param DOMDocument              $document  The document.
	 * @param string                   $processId The process id.
	 * @param array<int, mixed>        $nodes     The nodes.
	 * @param array<int, mixed>        $edges     The edges.
	 *
	 * @return DOMElement The diagram.
	 */
	private function diagram(DOMDocument $document, string $processId, array $nodes, array $edges): DOMElement {
		$diagram = $document->createElementNS(self::NS_BPMNDI, 'bpmndi:BPMNDiagram');
		$diagram->setAttribute('id', 'Diagram_' . $processId);

		$plane = $document->createElementNS(self::NS_BPMNDI, 'bpmndi:BPMNPlane');
		$plane->setAttribute('id', 'Plane_' . $processId);
		$plane->setAttribute('bpmnElement', $processId);
		$diagram->appendChild($plane);

		foreach ($nodes as $index => $node) {
			$id = $this->idOf(value: (string)($node['id'] ?? ''));
			$shape = $document->createElementNS(self::NS_BPMNDI, 'bpmndi:BPMNShape');
			$shape->setAttribute('id', 'Shape_' . $id);
			$shape->setAttribute('bpmnElement', $id);

			$bounds = $document->createElementNS(self::NS_DC, 'dc:Bounds');
			[$x, $y] = $this->positionOf(node: $node, index: (int)$index);
			$bounds->setAttribute('x', (string)$x);
			$bounds->setAttribute('y', (string)$y);
			$bounds->setAttribute('width', (string)self::WIDTH);
			$bounds->setAttribute('height', (string)self::HEIGHT);
			$shape->appendChild($bounds);

			$plane->appendChild($shape);
		}

		foreach ($edges as $index => $edge) {
			$id = $this->idOf(value: (string)($edge['id'] ?? ('edge-' . $index)));
			$element = $document->createElementNS(self::NS_BPMNDI, 'bpmndi:BPMNEdge');
			$element->setAttribute('id', 'Edge_' . $id);
			$element->setAttribute('bpmnElement', $id);
			$plane->appendChild($element);
		}

		return $diagram;
	}//end diagram()

	/**
	 * A node's canvas position, or a laid-out one.
	 *
	 * @param array<string, mixed> $node  The node.
	 * @param int                  $index Its index.
	 *
	 * @return array{0: int, 1: int} The x and y.
	 */
	private function positionOf(array $node, int $index): array {
		$position = ($node['position'] ?? null);
		if (is_array($position) === true && isset($position['x']) === true && isset($position['y']) === true) {
			return [(int)$position['x'], (int)$position['y']];
		}

		if (isset($node['x']) === true && isset($node['y']) === true) {
			return [(int)$node['x'], (int)$node['y']];
		}

		return [($index * self::SPACING), 100];
	}//end positionOf()

	/**
	 * The flow's nodes as a list.
	 *
	 * @param Flow $flow The flow.
	 *
	 * @return array<int, array<string, mixed>> The nodes.
	 */
	private function nodesOf(Flow $flow): array {
		$nodes = [];
		foreach ((array)($flow->getNodes() ?? []) as $node) {
			if (is_array($node) === true) {
				$nodes[] = $node;
			}
		}

		return $nodes;
	}//end nodesOf()

	/**
	 * The flow's edges as a list.
	 *
	 * @param Flow $flow The flow.
	 *
	 * @return array<int, array<string, mixed>> The edges.
	 */
	private function edgesOf(Flow $flow): array {
		$edges = [];
		foreach ((array)($flow->getEdges() ?? []) as $edge) {
			if (is_array($edge) === true) {
				$edges[] = $edge;
			}
		}

		return $edges;
	}//end edgesOf()

	/**
	 * A value as an XML NCName, which is what a BPMN id must be.
	 *
	 * 🔴 A UUID STARTS WITH A DIGIT ABOUT HALF THE TIME, and an NCName may not.
	 * An id that is invalid XML makes the whole document unparseable by the
	 * tool the export exists to reach, and the failure arrives as "Camunda
	 * cannot open your file" rather than as anything about ids.
	 *
	 * @param string $value The value.
	 *
	 * @return string The NCName.
	 */
	private function idOf(string $value): string {
		$clean = (string)preg_replace('/[^A-Za-z0-9_.-]/', '_', trim($value));
		if ($clean === '') {
			$clean = 'unnamed';
		}

		if (preg_match('/^[A-Za-z_]/', $clean) !== 1) {
			$clean = ('id_' . $clean);
		}

		return $clean;
	}//end idOf()
}//end class
