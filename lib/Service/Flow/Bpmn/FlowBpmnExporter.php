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
	 * Reads the flow into the graph that is serialised.
	 *
	 * @var BpmnExportGraphBuilder
	 */
	private readonly BpmnExportGraphBuilder $builder;

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
		$this->builder = new BpmnExportGraphBuilder();
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
		$graph = $this->builder->graphOf(flow: $flow);
		$document = new BpmnExportDocument(definitionsId: 'Definitions_' . $graph->id);
		$this->process(document: $document, graph: $graph);
		$document->definitions->appendChild($this->diagram(document: $document, graph: $graph));

		// 🔴 THE BOUNDARY CHECK RUNS ON OUR OWN OUTPUT TOO. An export that does
		// not validate is a serializer bug, and the user must not be the one
		// who discovers it: a file Camunda refuses is indistinguishable, to
		// them, from a product that cannot export.
		$this->validator->assertValid(document: $document->dom, subject: 'The exported flow');

		return (string)$document->dom->saveXML();
	}//end export()

	/**
	 * The process element, attached to the root, then filled.
	 *
	 * @param BpmnExportDocument $document The document.
	 * @param BpmnExportGraph    $graph    The graph.
	 *
	 * @return void
	 */
	private function process(BpmnExportDocument $document, BpmnExportGraph $graph): void {
		$process = $document->create(qualifiedName: 'bpmn:process');
		$process->setAttribute('id', $graph->processId());
		$process->setAttribute('name', $graph->name);
		// 🔑 `isExecutable="false"` is the honest value. A BPMN engine reading
		// this file must not believe it can execute it: the execution semantic
		// is symfony/workflow's, and this file is interchange, never a
		// deployable process.
		$process->setAttribute('isExecutable', 'false');
		$document->definitions->appendChild($process);

		foreach ($this->flowElements(document: $document, graph: $graph) as $element) {
			$process->appendChild($element);
		}
	}//end process()

	/**
	 * Every node, then every edge, as process elements.
	 *
	 * @param BpmnExportDocument $document The document.
	 * @param BpmnExportGraph    $graph    The graph.
	 *
	 * @return array<int, DOMElement> The elements, in document order.
	 */
	private function flowElements(BpmnExportDocument $document, BpmnExportGraph $graph): array {
		$elements = [];
		foreach ($graph->nodes as $node) {
			$elements[] = $this->nodeElement(document: $document, node: $node);
		}

		foreach ($graph->edges as $edge) {
			$elements[] = $this->edgeElement(document: $document, edge: $edge);
		}

		return $elements;
	}//end flowElements()

	/**
	 * One node, with its type and config in `extensionElements`.
	 *
	 * 🔴 `extensionElements` COMES FIRST, BEFORE THE EVENT DEFINITION.
	 * `tBaseElement` puts it at the head of the sequence every BPMN element
	 * inherits, and an event definition written before it fails the schema
	 * on a document that is otherwise perfectly readable — which is exactly
	 * the class of mistake a validated boundary is for.
	 *
	 * @param BpmnExportDocument   $document The document.
	 * @param array<string, mixed> $node     The node.
	 *
	 * @return DOMElement The element.
	 */
	private function nodeElement(BpmnExportDocument $document, array $node): DOMElement {
		[$elementName, $eventDefinition] = $this->kindOf(node: $node);
		$element = $document->create(qualifiedName: 'bpmn:' . $elementName);
		$element->setAttribute('id', $this->builder->ncName(value: (string)($node['id'] ?? '')));
		$element->setAttribute('name', (string)($node['name'] ?? $node['id'] ?? ''));

		$element->appendChild($this->extensions(document: $document, node: $node));
		if ($eventDefinition !== null) {
			$element->appendChild($this->eventDefinition(document: $document, node: $node));
		}

		return $element;
	}//end nodeElement()

	/**
	 * A node's `extensionElements`: its own type, and its config when it has one.
	 *
	 * @param BpmnExportDocument   $document The document.
	 * @param array<string, mixed> $node     The node.
	 *
	 * @return DOMElement The extension elements.
	 */
	private function extensions(BpmnExportDocument $document, array $node): DOMElement {
		$extensions = $document->create(qualifiedName: 'bpmn:extensionElements');
		$extensions->appendChild($document->extension(name: BpmnVocabulary::ELEMENT_TYPE, text: (string)($node['type'] ?? '')));

		$config = $this->configOf(node: $node);
		if ($config !== []) {
			$extensions->appendChild($document->extension(name: BpmnVocabulary::ELEMENT_CONFIG, text: (string)json_encode($config)));
		}

		return $extensions;
	}//end extensions()

	/**
	 * A node's config, or an empty one when it carries none.
	 *
	 * @param array<string, mixed> $node The node.
	 *
	 * @return array<mixed> The config.
	 */
	private function configOf(array $node): array {
		$config = ($node['config'] ?? null);
		if (is_array($config) === true) {
			return $config;
		}

		return [];
	}//end configOf()

	/**
	 * A node's vocabulary kind as an element name and an optional event definition.
	 *
	 * @param array<string, mixed> $node The node.
	 *
	 * @return array{0: string, 1: string|null} The element name and the definition.
	 */
	private function kindOf(array $node): array {
		return $this->resolve(kind: $this->vocabulary->elementFor(nodeType: (string)($node['type'] ?? '')));
	}//end kindOf()

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
	 * One node's event definition, with the content its type requires.
	 *
	 * @param BpmnExportDocument   $document The document.
	 * @param array<string, mixed> $node     The node, whose kind carries a definition.
	 *
	 * @return DOMElement The definition.
	 */
	private function eventDefinition(BpmnExportDocument $document, array $node): DOMElement {
		$definition = (string)$this->kindOf(node: $node)[1];
		$element = $document->create(qualifiedName: 'bpmn:' . $definition . 'EventDefinition');

		$content = $this->definitionContent(definition: $definition, config: $this->configOf(node: $node));
		if ($content !== null) {
			$element->appendChild($document->create(qualifiedName: $content[0], text: $content[1]));
		}

		return $element;
	}//end eventDefinition()

	/**
	 * The child an event definition carries, as an element name and its text.
	 *
	 * 🔑 A CONDITIONAL EVENT DEFINITION IS NOT ALLOWED TO BE EMPTY: the schema
	 * requires a `condition`, and the subject the trigger listens for is what
	 * belongs in it. A timer's cycle is optional to the schema and required by
	 * our own spec, which says the cron travels in the `timerEventDefinition`.
	 *
	 * @param string       $definition The definition kind.
	 * @param array<mixed> $config     The node's config.
	 *
	 * @return array{0: string, 1: string}|null The child, or null for none.
	 */
	private function definitionContent(string $definition, array $config): ?array {
		if ($definition === 'conditional') {
			return ['bpmn:condition', $this->subjectOf(config: $config)];
		}

		$cron = trim((string)($config['cron'] ?? ''));
		if ($definition !== 'timer' || $cron === '') {
			return null;
		}

		return ['bpmn:timeCycle', $cron];
	}//end definitionContent()

	/**
	 * The subject an object trigger listens for, as a condition sentence.
	 *
	 * 🔑 NEVER AN EMPTY CONDITION. The schema forbids one, and "any object
	 * event" is the honest reading of a trigger that names no subject — which
	 * is what the engine does with it.
	 *
	 * @param array<mixed> $config The node's config.
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
			return 'true';
		}

		return implode(' and ', $parts);
	}//end subjectOf()

	/**
	 * One edge as a sequence flow.
	 *
	 * @param BpmnExportDocument   $document The document.
	 * @param array<string, mixed> $edge     The edge, with its id resolved.
	 *
	 * @return DOMElement The element.
	 */
	private function edgeElement(BpmnExportDocument $document, array $edge): DOMElement {
		$element = $document->create(qualifiedName: 'bpmn:sequenceFlow');
		$element->setAttribute('id', $this->builder->ncName(value: (string)$edge['id']));
		$element->setAttribute('sourceRef', $this->builder->ncName(value: (string)($edge['from'] ?? '')));
		$element->setAttribute('targetRef', $this->builder->ncName(value: (string)($edge['to'] ?? '')));

		$condition = trim((string)($edge['condition'] ?? ''));
		if ($condition !== '') {
			$element->appendChild($document->create(qualifiedName: 'bpmn:conditionExpression', text: $condition));
		}

		return $element;
	}//end edgeElement()

	/**
	 * The diagram interchange block, from the resolved boxes and routes.
	 *
	 * @param BpmnExportDocument $document The document.
	 * @param BpmnExportGraph    $graph    The graph.
	 *
	 * @return DOMElement The diagram.
	 */
	private function diagram(BpmnExportDocument $document, BpmnExportGraph $graph): DOMElement {
		$diagram = $document->create(qualifiedName: 'bpmndi:BPMNDiagram');
		$diagram->setAttribute('id', 'Diagram_' . $graph->processId());

		$plane = $document->create(qualifiedName: 'bpmndi:BPMNPlane');
		$plane->setAttribute('id', 'Plane_' . $graph->processId());
		$plane->setAttribute('bpmnElement', $graph->processId());
		$diagram->appendChild($plane);

		foreach ($this->diagramElements(document: $document, graph: $graph) as $element) {
			$plane->appendChild($element);
		}

		return $diagram;
	}//end diagram()

	/**
	 * Every node's shape, then every edge's line.
	 *
	 * @param BpmnExportDocument $document The document.
	 * @param BpmnExportGraph    $graph    The graph.
	 *
	 * @return array<int, DOMElement> The elements, in document order.
	 */
	private function diagramElements(BpmnExportDocument $document, BpmnExportGraph $graph): array {
		$elements = [];
		foreach ($graph->boxes as $box) {
			$elements[] = $this->shape(document: $document, box: $box);
		}

		foreach ($graph->routes as $route) {
			$elements[] = $this->line(document: $document, route: $route);
		}

		return $elements;
	}//end diagramElements()

	/**
	 * One node's shape, with its bounds.
	 *
	 * @param BpmnExportDocument                   $document The document.
	 * @param array{id: string, x: int, y: int}    $box      The node's box.
	 *
	 * @return DOMElement The shape.
	 */
	private function shape(BpmnExportDocument $document, array $box): DOMElement {
		$shape = $document->create(qualifiedName: 'bpmndi:BPMNShape');
		$shape->setAttribute('id', 'Shape_' . $box['id']);
		$shape->setAttribute('bpmnElement', $box['id']);

		$bounds = $document->create(qualifiedName: 'dc:Bounds');
		foreach (['x' => $box['x'], 'y' => $box['y'], 'width' => self::WIDTH, 'height' => self::HEIGHT] as $name => $value) {
			$bounds->setAttribute($name, (string)$value);
		}

		$shape->appendChild($bounds);

		return $shape;
	}//end shape()

	/**
	 * One edge's line, from centre to centre.
	 *
	 * 🔴 TWO WAYPOINTS, ALWAYS. `di:Edge` requires `minOccurs="2"`, so an
	 * edge drawn without them is not a diagram with a missing line: it fails
	 * the schema and every modeller refuses the whole file.
	 *
	 * @param BpmnExportDocument                                        $document The document.
	 * @param array{id: string, from: array<int>, to: array<int>}       $route    The edge's route.
	 *
	 * @return DOMElement The edge.
	 */
	private function line(BpmnExportDocument $document, array $route): DOMElement {
		$element = $document->create(qualifiedName: 'bpmndi:BPMNEdge');
		$element->setAttribute('id', 'Edge_' . $route['id']);
		$element->setAttribute('bpmnElement', $route['id']);

		foreach ([$route['from'], $route['to']] as $point) {
			$waypoint = $document->create(qualifiedName: 'di:waypoint');
			$waypoint->setAttribute('x', (string)$point[0]);
			$waypoint->setAttribute('y', (string)$point[1]);
			$element->appendChild($waypoint);
		}

		return $element;
	}//end line()

}//end class
