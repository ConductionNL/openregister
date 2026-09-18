<?php

/**
 * A flow, written out as BPMN 2.0 XML.
 *
 * 🔴 EXPORT IS TOTAL AND LOSSLESS FOR US, PARTIAL FOR EVERYONE ELSE, and the
 * file says which. Every flow exports, because a step the standard has no word
 * for becomes a `serviceTask` carrying its real type and configuration in
 * `extensionElements`. A conformant tool must preserve those and may ignore
 * them, so the file round-trips exactly through another modeller and still
 * reads as a diagram there — just as a diagram whose task bodies it does not
 * understand.
 *
 * 🔴 THE MAPPING IS NOT THIS CLASS'S. `BpmnVocabulary` owns both directions,
 * including the fact that the import table is not the export table flipped,
 * and this exporter asks it rather than keeping a second copy. Two tables agree
 * until somebody adds a node type to one of them, and the disagreement shows up
 * as a file that does not round-trip through its own product.
 *
 * 🔴 IT VALIDATES NOTHING AGAINST THE OMG XSD, because the XSD is not vendored
 * here. The output is well-formed XML in the standard's namespaces and shape;
 * whether it is schema-VALID is unverified, and saying "BPMN 2.0 export" while
 * meaning "XML that looks like it" is exactly the claim this comment exists to
 * stop. The XSD, and validation on both sides of the boundary, is the next
 * piece of this change.
 *
 * 🔑 NOTHING IN THE RUN PATH MAY REACH IN HERE. Interchange is a boundary, not
 * an execution semantic: this namespace depends on the flow document, and
 * nothing depends on this namespace. An architecture test holds that line.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Bpmn
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-bpmn-interchange/specs/flow-bpmn-interchange/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Bpmn;

use DOMDocument;
use DOMElement;
use OCA\OpenRegister\Db\Flow;

/**
 * Serialises a flow into the declared BPMN subset.
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
	 * The diagram-common namespace the DI bounds live in.
	 *
	 * @var string
	 */
	public const NS_DC = 'http://www.omg.org/spec/DD/20100524/DC';

	/**
	 * Constructor.
	 *
	 * @param BpmnVocabulary $vocabulary The mapping both directions read.
	 */
	public function __construct(
		private readonly BpmnVocabulary $vocabulary = new BpmnVocabulary(),
	) {
	}//end __construct()

	/**
	 * Default node box, in diagram units.
	 *
	 * @var int
	 */
	private const NODE_WIDTH = 100;

	/**
	 * Default node height, in diagram units.
	 *
	 * @var int
	 */
	private const NODE_HEIGHT = 80;

	/**
	 * Horizontal step used when a node carries no canvas position.
	 *
	 * @var int
	 */
	private const AUTO_STEP = 180;

	/**
	 * Write a flow as BPMN 2.0 XML.
	 *
	 * @param Flow $flow The flow.
	 *
	 * @return string The XML.
	 */
	public function export(Flow $flow): string {
		$document = new DOMDocument('1.0', 'UTF-8');
		$document->formatOutput = true;

		$definitions = $document->createElementNS(self::NS_BPMN, 'bpmn:definitions');
		$definitions->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:bpmndi', self::NS_BPMNDI);
		$definitions->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:dc', self::NS_DC);
		$definitions->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:openregister', BpmnVocabulary::EXTENSION_NS);
		$definitions->setAttribute('id', 'definitions_' . $this->identifier(value: (string)$flow->getUuid()));
		$definitions->setAttribute('targetNamespace', BpmnVocabulary::EXTENSION_NS);
		$definitions->setAttribute('exporter', 'OpenRegister');
		$document->appendChild($definitions);

		$processId = 'process_' . $this->identifier(value: (string)$flow->getUuid());
		$process = $document->createElementNS(self::NS_BPMN, 'bpmn:process');
		$process->setAttribute('id', $processId);
		$process->setAttribute('name', (string)$flow->getName());
		// A flow is executable HERE and nowhere else: the attribute says the
		// process is meant to run, not that another engine may run it.
		$process->setAttribute('isExecutable', 'true');
		$definitions->appendChild($process);

		$nodes = $this->nodesOf(flow: $flow);
		$edges = $this->edgesOf(flow: $flow, nodes: $nodes);

		foreach ($nodes as $node) {
			$process->appendChild($this->nodeElement(document: $document, node: $node));
		}

		foreach ($edges as $edge) {
			$flowElement = $document->createElementNS(self::NS_BPMN, 'bpmn:sequenceFlow');
			$flowElement->setAttribute('id', $this->identifier(value: $edge['id']));
			$flowElement->setAttribute('sourceRef', $this->identifier(value: $edge['from']));
			$flowElement->setAttribute('targetRef', $this->identifier(value: $edge['to']));
			$process->appendChild($flowElement);
		}

		$definitions->appendChild(
			$this->diagram(document: $document, processId: $processId, nodes: $nodes, edges: $edges)
		);

		return (string)$document->saveXML();
	}//end export()

	/**
	 * One node, as its BPMN element, with its real type beside it.
	 *
	 * @param DOMDocument          $document The document.
	 * @param array<string, mixed> $node     The flow node.
	 *
	 * @return DOMElement The element.
	 */
	private function nodeElement(DOMDocument $document, array $node): DOMElement {
		$type = (string)($node['type'] ?? '');
		$mapped = $this->vocabulary->elementFor(nodeType: $type);

		// The catch and start variants are the same BPMN element with a child
		// event definition; keeping that detail here rather than in the map
		// leaves the map readable as a table.
		$eventDefinition = null;
		$tag = $mapped;
		foreach (
			[
				'timerStartEvent' => ['startEvent', 'timerEventDefinition'],
				'conditionalStartEvent' => ['startEvent', 'conditionalEventDefinition'],
				'intermediateCatchEvent:message' => ['intermediateCatchEvent', 'messageEventDefinition'],
				'intermediateCatchEvent:timer' => ['intermediateCatchEvent', 'timerEventDefinition'],
			] as $key => $pair
		) {
			if ($mapped === $key) {
				[$tag, $eventDefinition] = $pair;
			}
		}

		$element = $document->createElementNS(self::NS_BPMN, 'bpmn:' . $tag);
		$element->setAttribute('id', $this->identifier(value: (string)($node['id'] ?? '')));
		$element->setAttribute('name', (string)($node['name'] ?? ($node['id'] ?? '')));

		// 🔑 THE EXTENSION GOES ON EVERY NODE, including the ones BPMN has a
		// word for. A `switch` exported as a bare exclusiveGateway comes back
		// as an exclusiveGateway and loses which of our two gateway types it
		// was; the extension is what makes our own round-trip exact rather
		// than merely close.
		$extensions = $document->createElementNS(self::NS_BPMN, 'bpmn:extensionElements');
		$typeElement = $document->createElementNS(BpmnVocabulary::EXTENSION_NS, 'openregister:' . BpmnVocabulary::ELEMENT_TYPE);
		$typeElement->appendChild($document->createTextNode($type));
		$extensions->appendChild($typeElement);

		$config = ($node['config'] ?? []);
		if (is_array($config) === true && $config !== []) {
			$configElement = $document->createElementNS(
				BpmnVocabulary::EXTENSION_NS,
				'openregister:' . BpmnVocabulary::ELEMENT_CONFIG
			);
			// CDATA because a config value may hold anything a person typed,
			// and an unescaped `<` in a condition would make the file
			// unreadable rather than merely wrong.
			$configElement->appendChild(
				$document->createCDATASection((string)json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
			);
			$extensions->appendChild($configElement);
		}

		$element->appendChild($extensions);

		if ($eventDefinition !== null) {
			$element->appendChild($document->createElementNS(self::NS_BPMN, 'bpmn:' . $eventDefinition));
		}

		return $element;
	}//end nodeElement()

	/**
	 * The diagram interchange, from the canvas positions when there are any.
	 *
	 * A node with no stored position is laid out left to right in document
	 * order. That is not the diagram the author drew, but it is a diagram
	 * somebody can open; omitting DI entirely leaves most modellers stacking
	 * every shape on the origin.
	 *
	 * @param DOMDocument                    $document  The document.
	 * @param string                         $processId The process id.
	 * @param array<int, array<string, mixed>> $nodes   The nodes.
	 * @param array<int, array<string, string>> $edges  The edges.
	 *
	 * @return DOMElement The diagram element.
	 */
	private function diagram(DOMDocument $document, string $processId, array $nodes, array $edges): DOMElement {
		$diagram = $document->createElementNS(self::NS_BPMNDI, 'bpmndi:BPMNDiagram');
		$diagram->setAttribute('id', 'diagram_' . $processId);
		$plane = $document->createElementNS(self::NS_BPMNDI, 'bpmndi:BPMNPlane');
		$plane->setAttribute('id', 'plane_' . $processId);
		$plane->setAttribute('bpmnElement', $processId);
		$diagram->appendChild($plane);

		$index = 0;
		foreach ($nodes as $node) {
			$id = $this->identifier(value: (string)($node['id'] ?? ''));
			$position = ($node['position'] ?? []);
			$x = (int)($position['x'] ?? ($index * self::AUTO_STEP));
			$y = (int)($position['y'] ?? 0);

			$shape = $document->createElementNS(self::NS_BPMNDI, 'bpmndi:BPMNShape');
			$shape->setAttribute('id', 'shape_' . $id);
			$shape->setAttribute('bpmnElement', $id);
			$bounds = $document->createElementNS(self::NS_DC, 'dc:Bounds');
			$bounds->setAttribute('x', (string)$x);
			$bounds->setAttribute('y', (string)$y);
			$bounds->setAttribute('width', (string)self::NODE_WIDTH);
			$bounds->setAttribute('height', (string)self::NODE_HEIGHT);
			$shape->appendChild($bounds);
			$plane->appendChild($shape);
			$index++;
		}//end foreach

		foreach ($edges as $edge) {
			$edgeElement = $document->createElementNS(self::NS_BPMNDI, 'bpmndi:BPMNEdge');
			$edgeElement->setAttribute('id', 'edge_' . $this->identifier(value: $edge['id']));
			$edgeElement->setAttribute('bpmnElement', $this->identifier(value: $edge['id']));
			$plane->appendChild($edgeElement);
		}

		return $diagram;
	}//end diagram()

	/**
	 * The flow's nodes, as a list.
	 *
	 * @param Flow $flow The flow.
	 *
	 * @return array<int, array<string, mixed>> The nodes.
	 */
	private function nodesOf(Flow $flow): array {
		$nodes = [];
		foreach ((array)($flow->getNodes() ?? []) as $key => $node) {
			if (is_array($node) === false) {
				continue;
			}

			// A node map keyed by id and a node list are both in the wild.
			if (isset($node['id']) === false && is_string($key) === true) {
				$node['id'] = $key;
			}

			if (trim((string)($node['id'] ?? '')) === '') {
				continue;
			}

			$nodes[] = $node;
		}

		return $nodes;
	}//end nodesOf()

	/**
	 * The flow's edges, flattened to one source and one target each.
	 *
	 * A flow edge may name several endpoints on either side; BPMN's
	 * `sequenceFlow` names exactly one of each, so a fan-out becomes several
	 * sequence flows. That is the same graph, drawn the way BPMN draws it.
	 *
	 * @param Flow                             $flow  The flow.
	 * @param array<int, array<string, mixed>> $nodes The nodes, for dangling-edge checks.
	 *
	 * @return array<int, array{id: string, from: string, to: string}> The edges.
	 */
	private function edgesOf(Flow $flow, array $nodes): array {
		$known = array_column($nodes, 'id');
		$edges = [];
		foreach ((array)($flow->getEdges() ?? []) as $index => $edge) {
			if (is_array($edge) === false) {
				continue;
			}

			$id = (string)($edge['id'] ?? sprintf('edge-%d', $index));
			$from = (array)($edge['from'] ?? []);
			$to = (array)($edge['to'] ?? []);
			$pair = 0;
			foreach ($from as $source) {
				foreach ($to as $target) {
					// A dangling edge is dropped rather than exported. A
					// sequenceFlow pointing at nothing makes the file
					// unopenable in every modeller, which turns one broken
					// edge into an export nobody can use.
					if (in_array((string)$source, $known, true) === false
						|| in_array((string)$target, $known, true) === false
					) {
						continue;
					}

					// The first pair keeps the edge's own id so a simple
					// one-to-one edge round-trips under the name it had.
					$edgeId = $id;
					if ($pair > 0) {
						$edgeId = $id . '-' . $pair;
					}

					$edges[] = [
						'id' => $edgeId,
						'from' => (string)$source,
						'to' => (string)$target,
					];
					$pair++;
				}
			}
		}//end foreach

		return $edges;
	}//end edgesOf()

	/**
	 * An xsd:ID-safe identifier.
	 *
	 * BPMN ids are XML ids: they may not start with a digit and may not hold
	 * arbitrary punctuation. A uuid does both, so every id is prefixed and
	 * scrubbed rather than hoping the modeller is forgiving.
	 *
	 * @param string $value The raw id.
	 *
	 * @return string The identifier.
	 */
	private function identifier(string $value): string {
		$safe = preg_replace('/[^A-Za-z0-9_.-]/', '_', $value);
		if ($safe === null || $safe === '') {
			$safe = 'anonymous';
		}

		if (preg_match('/^[A-Za-z_]/', $safe) !== 1) {
			$safe = 'or_' . $safe;
		}

		return $safe;
	}//end identifier()
}//end class
