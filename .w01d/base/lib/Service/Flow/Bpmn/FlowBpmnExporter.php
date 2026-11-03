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
 * 🔴 EVERY EXPORT IS VALIDATED AGAINST THE VENDORED OMG XSD BEFORE IT LEAVES,
 * and a document that does not validate is never handed to a caller. A
 * serializer regression is then a failing export here rather than "Camunda
 * cannot open your file" a week later, and the schema is the unmodified one:
 * when our output and the standard disagree, the output is what changes.
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
use OCA\OpenRegister\Exception\BpmnSchemaInvalid;

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
	 * The shared DI namespace an edge's waypoints live in.
	 *
	 * 🔑 NOT `NS_BPMNDI`. `bpmndi:BPMNEdge` extends `di:LabeledEdge`, so its
	 * waypoints are `di:waypoint` in the DD namespace, and the schema requires
	 * at least two of them: an edge drawn with none is a file every modeller
	 * refuses.
	 *
	 * @var string
	 */
	public const NS_DI = 'http://www.omg.org/spec/DD/20100524/DI';

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
	 * @param BpmnVocabulary      $vocabulary The one mapping table.
	 * @param BpmnSchemaValidator $validator  The vendored OMG schema set.
	 */
	public function __construct(
		private readonly BpmnVocabulary $vocabulary,
		private readonly BpmnSchemaValidator $validator,
	) {
	}//end __construct()

	/**
	 * A flow as BPMN 2.0 XML, validated before it is returned.
	 *
	 * @param Flow $flow The flow.
	 *
	 * @return string The XML.
	 *
	 * @throws BpmnSchemaInvalid When our own output does not validate, which is a bug here.
	 *
	 * @spec openspec/changes/flow-bpmn-interchange/specs/flow-bpmn-interchange/spec.md
	 */
	public function export(Flow $flow): string {
		$document = new DOMDocument('1.0', 'UTF-8');
		$document->formatOutput = true;

		$definitions = $document->createElementNS(self::NS_BPMN, 'bpmn:definitions');
		$definitions->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:bpmndi', self::NS_BPMNDI);
		$definitions->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:dc', self::NS_DC);
		$definitions->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:di', self::NS_DI);
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

		// 🔴 THE BOUNDARY CHECK RUNS ON OUR OWN OUTPUT TOO. An export that does
		// not validate is a serializer bug, and the user must not be the one
		// who discovers it: a file Camunda refuses is indistinguishable, to
		// them, from a product that cannot export.
		$this->validator->assertValid(document: $document, subject: 'The exported flow');

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

		[$elementName, $eventDefinition] = $this->resolve(kind: $kind);

		$element = $document->createElementNS(self::NS_BPMN, 'bpmn:' . $elementName);
		$element->setAttribute('id', $this->idOf(value: (string)($node['id'] ?? '')));
		$element->setAttribute('name', (string)($node['name'] ?? $node['id'] ?? ''));

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

		// 🔴 `extensionElements` COMES FIRST, BEFORE THE EVENT DEFINITION.
		// `tBaseElement` puts it at the head of the sequence every BPMN element
		// inherits, and an event definition written before it fails the schema
		// on a document that is otherwise perfectly readable — which is exactly
		// the class of mistake a validated boundary is for.
		$element->appendChild($extensions);

		if ($eventDefinition !== null) {
			$definitionConfig = [];
			if (is_array($config) === true) {
				$definitionConfig = $config;
			}

			$element->appendChild(
				$this->eventDefinition(
					document: $document,
					definition: $eventDefinition,
					config: $definitionConfig
				)
			);
		}

		return $element;
	}//end nodeElement()

	/**
	 * A vocabulary kind as an element name and an optional event definition.
	 *
	 * The vocabulary names an event and its definition as one kind, in two
	 * spellings: `intermediateCatchEvent:message` for the catch events and
	 * `timerStartEvent` for the start and end events, because the second is
	 * what the importer reads off a parsed file and the two tables have to
	 * meet on the same word. Neither is a BPMN element: both are an event
	 * element with a definition child, and this is the one place that knows it.
	 *
	 * @param string $kind The vocabulary kind.
	 *
	 * @return array{0: string, 1: string|null} The element name and the definition.
	 */
	private function resolve(string $kind): array {
		if (str_contains($kind, ':') === true) {
			[$name, $definition] = explode(':', $kind, 2);
			return [$name, $definition];
		}

		$matched = [];
		if (preg_match('/^(timer|message|conditional|terminate|error|signal)(StartEvent|EndEvent)$/', $kind, $matched) === 1) {
			return [lcfirst($matched[2]), $matched[1]];
		}

		return [$kind, null];
	}//end resolve()

	/**
	 * One event definition, with the content its type requires.
	 *
	 * 🔑 A CONDITIONAL EVENT DEFINITION IS NOT ALLOWED TO BE EMPTY: the schema
	 * requires a `condition`, and the subject the trigger listens for is what
	 * belongs in it. A timer's cycle is optional to the schema and required by
	 * our own spec, which says the cron travels in the `timerEventDefinition`.
	 *
	 * @param DOMDocument          $document   The document.
	 * @param string               $definition The definition kind.
	 * @param array<string, mixed> $config     The node's config.
	 *
	 * @return DOMElement The definition.
	 */
	private function eventDefinition(DOMDocument $document, string $definition, array $config): DOMElement {
		$element = $document->createElementNS(self::NS_BPMN, 'bpmn:' . $definition . 'EventDefinition');

		if ($definition === 'timer') {
			$cron = trim((string)($config['cron'] ?? ''));
			if ($cron !== '') {
				$element->appendChild($document->createElementNS(self::NS_BPMN, 'bpmn:timeCycle', $cron));
			}

			return $element;
		}

		if ($definition === 'conditional') {
			$element->appendChild(
				$document->createElementNS(self::NS_BPMN, 'bpmn:condition', $this->subjectOf(config: $config))
			);
		}

		return $element;
	}//end eventDefinition()

	/**
	 * The subject an object trigger listens for, as a condition sentence.
	 *
	 * @param array<string, mixed> $config The node's config.
	 *
	 * @return string The condition.
	 */
	private function subjectOf(array $config): string {
		$parts = [];
		foreach (['event', 'register', 'schema'] as $key) {
			$value = trim((string)($config[$key] ?? ''));
			if ($value !== '') {
				$parts[] = sprintf('%s == "%s"', $key, $value);
			}
		}

		if ($parts === []) {
			// 🔑 NOT AN EMPTY CONDITION. The schema forbids one, and "any
			// object event" is the honest reading of a trigger that names no
			// subject — which is what the engine does with it.
			return 'true';
		}

		return implode(' and ', $parts);
	}//end subjectOf()

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

		$centres = [];
		foreach ($nodes as $index => $node) {
			[$left, $top] = $this->positionOf(node: $node, index: (int)$index);
			$centres[$this->idOf(value: (string)($node['id'] ?? ''))] = [
				(int)($left + intdiv(self::WIDTH, 2)),
				(int)($top + intdiv(self::HEIGHT, 2)),
			];
		}

		foreach ($nodes as $index => $node) {
			$id = $this->idOf(value: (string)($node['id'] ?? ''));
			$shape = $document->createElementNS(self::NS_BPMNDI, 'bpmndi:BPMNShape');
			$shape->setAttribute('id', 'Shape_' . $id);
			$shape->setAttribute('bpmnElement', $id);

			$bounds = $document->createElementNS(self::NS_DC, 'dc:Bounds');
			[$left, $top] = $this->positionOf(node: $node, index: (int)$index);
			$bounds->setAttribute('x', (string)$left);
			$bounds->setAttribute('y', (string)$top);
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

			// 🔴 TWO WAYPOINTS, ALWAYS. `di:Edge` requires `minOccurs="2"`, so
			// an edge drawn without them is not a diagram with a missing line:
			// it fails the schema and every modeller refuses the whole file.
			$from = ($centres[$this->idOf(value: (string)($edge['from'] ?? ''))] ?? [0, 0]);
			$to = ($centres[$this->idOf(value: (string)($edge['to'] ?? ''))] ?? [0, 0]);
			foreach ([$from, $to] as $point) {
				$waypoint = $document->createElementNS(self::NS_DI, 'di:waypoint');
				$waypoint->setAttribute('x', (string)$point[0]);
				$waypoint->setAttribute('y', (string)$point[1]);
				$element->appendChild($waypoint);
			}

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
		$known = [];
		foreach ($this->nodesOf(flow: $flow) as $node) {
			$known[] = (string)($node['id'] ?? '');
		}

		$edges = [];
		foreach ((array)($flow->getEdges() ?? []) as $edge) {
			if (is_array($edge) === false) {
				continue;
			}

			// 🔴 A DANGLING EDGE IS DROPPED, NOT EXPORTED. A `sequenceFlow`
			// whose sourceRef or targetRef names nothing in the process is not
			// a slightly wrong diagram: every modeller refuses the whole file,
			// so one edge left behind by a deleted node turns the export into
			// something nobody can open. The flow itself is not wrong — the
			// engine refuses a dangling edge at build time — but a document
			// assembled from a stored node list can still carry one, and the
			// export is the surface where it becomes fatal.
			$from = (string)($edge['from'] ?? '');
			$to = (string)($edge['to'] ?? '');
			if (in_array($from, $known, true) === false || in_array($to, $known, true) === false) {
				continue;
			}

			$edges[] = $edge;
		}//end foreach

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
