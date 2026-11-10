<?php

/**
 * A BPMN 2.0 file as a flow document, plus everything it could not take.
 *
 * 🔴 IT NEVER IMPORTS SILENTLY LESS THAN THE FILE SAID. Every construct lands
 * in exactly one of the three declared verdicts, in the report, by element id.
 * A file arriving from Camunda Modeler carries things this engine has no
 * equivalent for; there are two honest answers — refuse the file, or import
 * less AND say so element by element — and the third, which is what happens by
 * default, leaves an author with a flow that looks complete and is not.
 *
 * 🔴 IT VALIDATES AGAINST THE VENDORED OMG XSD BEFORE IT MAPS ANYTHING, and
 * that is a DIFFERENT ANSWER from the mapping report. "Your file is malformed,
 * at this element, on this line" is `BpmnSchemaInvalid`; "we cannot express
 * this construct" is a refusal in the report. Before the schema step there was
 * only the second answer, so a malformed file was walked into a flow with
 * missing nodes and read as a successful import. A mapping report over a
 * malformed document attributes XML problems to process constructs, which is
 * precisely what validating first prevents.
 *
 * 🔑 AND IT NEVER GUESSES A TYPE FROM A NAME. A `serviceTask` with no
 * openregister extension imports typeless and is listed as needing one. A flow
 * that sends mail because a box was labelled "send email" is a flow nobody
 * authorised.
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
use DOMNode;
use DOMXPath;
use OCA\OpenRegister\Exception\BpmnImportRefused;
use OCA\OpenRegister\Exception\BpmnSchemaInvalid;

/**
 * Reads a documented BPMN subset into a flow document, reporting every loss.
 *
 * @spec openspec/changes/flow-bpmn-interchange/specs/flow-bpmn-interchange/spec.md
 */
class FlowBpmnImporter {

	/**
	 * Horizontal spacing of the automatic layout.
	 *
	 * @var int
	 */
	public const LAYOUT_X = 180;

	/**
	 * Vertical spacing of the automatic layout, used when a row fills up.
	 *
	 * @var int
	 */
	public const LAYOUT_Y = 140;

	/**
	 * How many nodes the automatic layout puts in one row.
	 *
	 * @var int
	 */
	public const LAYOUT_COLUMNS = 6;

	/**
	 * Reads the file's diagram interchange, and lays a graph out without one.
	 *
	 * @var BpmnDiagramLayout
	 */
	private BpmnDiagramLayout $layout;

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
		$this->layout = new BpmnDiagramLayout();
	}//end __construct()

	/**
	 * Read a BPMN file into a flow document and a mapping report.
	 *
	 * Constructs this importer does not map are REPORTED and the flow is
	 * still created. A caller that wants the opposite asks
	 * {@see self::importStrictly()} rather than passing a flag: "import it
	 * and tell me what was lost" and "refuse unless everything maps" are two
	 * requests, and a flag dropped between the endpoint and here silently
	 * turns the second into the first.
	 *
	 * @param string $xml The file.
	 *
	 * @return array{flow: array<string, mixed>, report: BpmnMappingReport} The result.
	 *
	 * @throws BpmnImportRefused When the file cannot be read.
	 * @throws BpmnSchemaInvalid When the document is not valid BPMN 2.0, which is a different answer.
	 *
	 * @spec openspec/changes/flow-bpmn-interchange/specs/flow-bpmn-interchange/spec.md
	 */
	public function import(string $xml): array {
		return $this->read(xml: $xml, strict: false);
	}//end import()

	/**
	 * Read a BPMN file, refusing it outright when anything does not map.
	 *
	 * @param string $xml The file.
	 *
	 * @return array{flow: array<string, mixed>, report: BpmnMappingReport} The result.
	 *
	 * @throws BpmnImportRefused When the file cannot be read, or when anything in it is refused.
	 * @throws BpmnSchemaInvalid When the document is not valid BPMN 2.0, which is a different answer.
	 *
	 * @spec openspec/changes/flow-bpmn-interchange/specs/flow-bpmn-interchange/spec.md
	 */
	public function importStrictly(string $xml): array {
		return $this->read(xml: $xml, strict: true);
	}//end importStrictly()

	/**
	 * The shared body of {@see self::import()} and {@see self::importStrictly()}.
	 *
	 * 🔴 THE SCHEMA STEP COMES BEFORE THE MAPPING, NOT BESIDE IT. Every verdict
	 * the mapping gives reads a construct and says what became of it; run that
	 * over a document that is not BPMN and the author is told their process is
	 * unsupported when what is wrong is their XML.
	 *
	 * @param string  $xml    The file.
	 * @param boolean $strict Whether a refusal fails the whole import.
	 *
	 * @return array{flow: array<string, mixed>, report: BpmnMappingReport} The result.
	 *
	 * @throws BpmnImportRefused When the file cannot be read, or when strict meets a refusal.
	 * @throws BpmnSchemaInvalid When the document is not valid BPMN 2.0, which is a different answer.
	 *
	 * @spec openspec/changes/flow-bpmn-interchange/specs/flow-bpmn-interchange/spec.md
	 */
	private function read(string $xml, bool $strict): array {
		$document = $this->parse(xml: $xml);
		$this->validator->assertValid(document: $document);

		$xpath = $this->xpathOf(document: $document);
		$process = $this->singleProcess(xpath: $xpath);

		$pass = new BpmnImportPass(xpath: $xpath, report: new BpmnMappingReport());
		$flow = $this->flowOf(pass: $pass, process: $process);
		$this->refuseWhenStrict(report: $pass->report, strict: $strict);

		return ['flow' => $flow, 'report' => $pass->report];
	}//end read()

	/**
	 * An xpath over the document with every namespace the importer reads.
	 *
	 * @param DOMDocument $document The validated document.
	 *
	 * @return DOMXPath The xpath.
	 *
	 * @spec openspec/changes/flow-bpmn-interchange/specs/flow-bpmn-interchange/spec.md
	 */
	private function xpathOf(DOMDocument $document): DOMXPath {
		$xpath = new DOMXPath($document);
		$xpath->registerNamespace('bpmn', FlowBpmnExporter::NS_BPMN);
		$xpath->registerNamespace('bpmndi', FlowBpmnExporter::NS_BPMNDI);
		$xpath->registerNamespace('dc', FlowBpmnExporter::NS_DC);
		$xpath->registerNamespace(BpmnVocabulary::EXTENSION_PREFIX, BpmnVocabulary::EXTENSION_NS);

		return $xpath;
	}//end xpathOf()

	/**
	 * The file's one bpmn:process element.
	 *
	 * More than one is declared as a refusal in the vocabulary, and raised
	 * here rather than reported, because there is no single flow to attach a
	 * report to: importing the first would silently pick one.
	 *
	 * @param DOMXPath $xpath The xpath.
	 *
	 * @return DOMNode|null The process element.
	 *
	 * @throws BpmnImportRefused When the file declares no process, or several.
	 *
	 * @spec openspec/changes/flow-bpmn-interchange/specs/flow-bpmn-interchange/spec.md
	 */
	private function singleProcess(DOMXPath $xpath): ?DOMNode {
		$processes = $xpath->query('//bpmn:process');
		if ($processes === false || $processes->length === 0) {
			throw new BpmnImportRefused(message: 'The file declares no bpmn:process, so there is no flow in it.');
		}

		if ($processes->length > 1) {
			throw new BpmnImportRefused(message: BpmnVocabulary::REFUSED['process:multiple']);
		}

		return $processes->item(0);
	}//end singleProcess()

	/**
	 * The flow document one process element describes, laid out.
	 *
	 * @param BpmnImportPass $pass    The xpath and the report.
	 * @param DOMNode|null   $process The single bpmn:process element.
	 *
	 * @return array<string, mixed> The flow document.
	 *
	 * @spec openspec/changes/flow-bpmn-interchange/specs/flow-bpmn-interchange/spec.md
	 */
	private function flowOf(BpmnImportPass $pass, ?DOMNode $process): array {
		$positions = $this->layout->positions(xpath: $pass->xpath);
		$graph = $this->graphOf(pass: $pass, process: $process);

		return [
			'name' => $this->nameOf(process: $process),
			'nodes' => $this->layout->laidOut(nodes: $graph['nodes'], positions: $positions),
			'edges' => $graph['edges'],
		];
	}//end flowOf()

	/**
	 * Refuse the import when strict was asked for and anything was refused.
	 *
	 * @param BpmnMappingReport $report The finished report.
	 * @param boolean           $strict Whether a refusal fails the whole import.
	 *
	 * @return void
	 *
	 * @throws BpmnImportRefused When strict meets a refusal.
	 *
	 * @spec openspec/changes/flow-bpmn-interchange/specs/flow-bpmn-interchange/spec.md
	 */
	private function refuseWhenStrict(BpmnMappingReport $report, bool $strict): void {
		if ($strict === true && $report->failsStrict() === true) {
			throw new BpmnImportRefused(
				message: 'The file contains constructs this importer refuses, and strict was requested, so no flow was created.',
				report: $report
			);
		}

	}//end refuseWhenStrict()

	/**
	 * The nodes and edges one process element declares.
	 *
	 * A child that is neither a sequence flow nor a mappable construct is
	 * DROPPED, and the report is where it says so. This walk records; it never
	 * refuses, because a refusal without a report tells an author nothing
	 * about the rest of their file.
	 *
	 * @param BpmnImportPass $pass    The xpath and the report, written to as constructs are read.
	 * @param DOMNode|null   $process The single bpmn:process element.
	 *
	 * @return array{nodes: array<int, array<string, mixed>>, edges: array<int, array<string, mixed>>} The graph.
	 *
	 * @spec openspec/changes/flow-bpmn-interchange/specs/flow-bpmn-interchange/spec.md
	 */
	private function graphOf(BpmnImportPass $pass, ?DOMNode $process): array {
		$nodes = [];
		$edges = [];
		foreach ($pass->childElements(process: $process) as $child) {
			if ($child->localName === 'sequenceFlow') {
				$edges[] = $this->edgeFrom(element: $child);
				continue;
			}

			$nodes[] = $this->nodeFrom(element: $child, pass: $pass);
		}

		return ['nodes' => array_values(array_filter($nodes)), 'edges' => $edges];
	}//end graphOf()

	/**
	 * One node, and its entry in the report.
	 *
	 * @param DOMElement     $element The element.
	 * @param BpmnImportPass $pass    The xpath and the report.
	 *
	 * @return array<string, mixed>|null The node, or null when it is dropped.
	 */
	private function nodeFrom(DOMElement $element, BpmnImportPass $pass): ?array {
		$id = trim($element->getAttribute('id'));
		$mapping = $this->mappingFor(element: $element, pass: $pass);
		$pass->report->record(elementId: $id, kind: $mapping['kind'], verdict: $mapping['verdict'], action: $mapping['action']);
		if ($mapping['verdict'] === BpmnMappingReport::REFUSED) {
			return null;
		}

		$node = ['id' => $id, 'name' => trim($element->getAttribute('name')), 'type' => $mapping['type']];

		return $this->withConfig(node: $node, config: $pass->extensionConfig(element: $element));
	}//end nodeFrom()

	/**
	 * The node with its declared config, when the element declares one.
	 *
	 * @param array<string, mixed>      $node   The node.
	 * @param array<string, mixed>|null $config The declared config, or null.
	 *
	 * @return array<string, mixed> The node.
	 */
	private function withConfig(array $node, ?array $config): array {
		if ($config !== null) {
			$node['config'] = $config;
		}

		return $node;
	}//end withConfig()

	/**
	 * What one element maps to: its kind, type, verdict and the action it needs.
	 *
	 * 🔑 THE EXTENSION ELEMENT WINS over the element kind. An
	 * `exclusiveGateway` cannot say whether it was a `switch` or a `route`, and
	 * the vocabulary's reverse table has to pick one — so our own files carry
	 * the answer and the mapping is only consulted for files that do not.
	 *
	 * @param DOMElement     $element The element.
	 * @param BpmnImportPass $pass    The xpath and the report.
	 *
	 * @return array{kind: string, type: string, verdict: string, action: string} The mapping.
	 */
	private function mappingFor(DOMElement $element, BpmnImportPass $pass): array {
		$kind = $pass->kindOf(element: $element);
		$reading = $this->vocabulary->readingFor(element: $kind);

		$declared = $pass->extensionType(element: $element);
		if ($declared !== '') {
			return ['kind' => $kind, 'type' => $declared, 'verdict' => BpmnMappingReport::MAPPED, 'action' => ''];
		}

		return ['kind' => $kind, 'type' => $reading['type'], 'verdict' => $reading['verdict'], 'action' => $reading['note']];
	}//end mappingFor()

	/**
	 * One sequence flow as an edge.
	 *
	 * @param DOMElement $element The element.
	 *
	 * @return array<string, mixed> The edge.
	 */
	private function edgeFrom(DOMElement $element): array {
		$edge = [
			'id' => trim($element->getAttribute('id')),
			'from' => trim($element->getAttribute('sourceRef')),
			'to' => trim($element->getAttribute('targetRef')),
		];

		$condition = trim((string)$element->textContent);
		if ($condition !== '') {
			$edge['condition'] = $condition;
		}

		return $edge;
	}//end edgeFrom()

	/**
	 * The process name, or an empty string.
	 *
	 * @param DOMNode|null $process The process element.
	 *
	 * @return string The name.
	 */
	private function nameOf(?DOMNode $process): string {
		if (($process instanceof DOMElement) === false) {
			return '';
		}

		return trim($process->getAttribute('name'));
	}//end nameOf()

	/**
	 * Parse the file, refusing one that is not XML.
	 *
	 * @param string $xml The file.
	 *
	 * @return DOMDocument The document.
	 *
	 * @throws BpmnImportRefused When it does not parse.
	 */
	private function parse(string $xml): DOMDocument {
		if (trim($xml) === '') {
			throw new BpmnImportRefused(message: 'The file is empty.');
		}

		$document = new DOMDocument();
		$errors = $this->loadQuietly(document: $document, xml: $xml);
		if ($errors !== null) {
			throw new BpmnImportRefused(message: sprintf('The file could not be read: %s', $this->firstError(errors: $errors)));
		}

		return $document;
	}//end parse()

	/**
	 * Load the XML into the document with libxml's errors captured, not raised.
	 *
	 * 🔴 EXTERNAL ENTITIES STAY OFF. A BPMN file is somebody else's document,
	 * uploaded; parsing one with entity substitution on is an XXE read of the
	 * server's filesystem dressed as a process import.
	 *
	 * @param DOMDocument $document The empty document to load into.
	 * @param string      $xml      The file.
	 *
	 * @return array<int, \LibXMLError>|null The libxml errors when it did not load, null when it did.
	 */
	private function loadQuietly(DOMDocument $document, string $xml): ?array {
		$previous = libxml_use_internal_errors(true);
		libxml_clear_errors();
		$loaded = $document->loadXML($xml, LIBXML_NONET);
		$errors = libxml_get_errors();
		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		if ($loaded === false) {
			return $errors;
		}

		return null;
	}//end loadQuietly()

	/**
	 * The first libxml error's message, or a generic one when there is none.
	 *
	 * @param array<int, \LibXMLError> $errors The errors.
	 *
	 * @return string The message.
	 */
	private function firstError(array $errors): string {
		if ($errors === []) {
			return 'the document is not well-formed XML';
		}

		return trim((string)$errors[0]->message);
	}//end firstError()
}//end class
