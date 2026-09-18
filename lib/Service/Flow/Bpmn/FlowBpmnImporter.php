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
 * 🔴 IT DOES NOT VALIDATE AGAINST THE OMG XSD, and this is the one requirement
 * that genuinely waits on somebody else's decision. The spec says "The importer
 * SHALL validate the file against the BPMN 2.0 XSD before mapping, and refuse a
 * non-validating file naming the first violation". The schema set is a
 * licence-checked third-party artefact that a build lane should not vendor on
 * its own judgement, so this importer checks that the document PARSES and that
 * it contains exactly one `bpmn:process`, and refuses otherwise. That is a
 * strictly weaker check, it is stated here rather than implied, and the
 * mapping report is not a substitute for it: a malformed document would have
 * its XML problems attributed to process constructs, which is exactly what the
 * XSD step exists to prevent.
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
use DOMXPath;
use OCA\OpenRegister\Exception\BpmnImportRefused;

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
	 * Constructor.
	 *
	 * @param BpmnVocabulary $vocabulary The one mapping table.
	 */
	public function __construct(
		private readonly BpmnVocabulary $vocabulary,
	) {
	}//end __construct()

	/**
	 * Read a BPMN file into a flow document and a mapping report.
	 *
	 * @param string $xml    The file.
	 * @param bool   $strict Whether a refusal fails the whole import.
	 *
	 * @return array{flow: array<string, mixed>, report: BpmnMappingReport} The result.
	 *
	 * @throws BpmnImportRefused When the file cannot be read, or when strict meets a refusal.
	 *
	 * @spec openspec/changes/flow-bpmn-interchange/specs/flow-bpmn-interchange/spec.md
	 */
	public function import(string $xml, bool $strict = false): array {
		$document = $this->parse(xml: $xml);
		$xpath = new DOMXPath($document);
		$xpath->registerNamespace('bpmn', FlowBpmnExporter::NS_BPMN);
		$xpath->registerNamespace('bpmndi', FlowBpmnExporter::NS_BPMNDI);
		$xpath->registerNamespace('dc', FlowBpmnExporter::NS_DC);
		$xpath->registerNamespace(BpmnVocabulary::EXTENSION_PREFIX, BpmnVocabulary::EXTENSION_NS);

		$processes = $xpath->query('//bpmn:process');
		if ($processes === false || $processes->length === 0) {
			throw new BpmnImportRefused(message: 'The file declares no bpmn:process, so there is no flow in it.');
		}

		if ($processes->length > 1) {
			// Declared as a refusal in the vocabulary, and raised here rather
			// than reported, because there is no single flow to attach a
			// report to: importing the first would silently pick one.
			throw new BpmnImportRefused(message: BpmnVocabulary::REFUSED['process:multiple']);
		}

		$report = new BpmnMappingReport();
		$positions = $this->positions(xpath: $xpath);

		$nodes = [];
		$edges = [];

		foreach ($xpath->query('./*', $processes->item(0)) ?: [] as $child) {
			if (($child instanceof DOMElement) === false) {
				continue;
			}

			if ($child->localName === 'sequenceFlow') {
				$edges[] = $this->edgeFrom(element: $child);
				continue;
			}

			$node = $this->nodeFrom(element: $child, xpath: $xpath, report: $report);
			if ($node !== null) {
				$nodes[] = $node;
			}
		}

		if ($strict === true && $report->failsStrict() === true) {
			throw new BpmnImportRefused(
				message: 'The file contains constructs this importer refuses, and strict was requested, so no flow was created.',
				report: $report
			);
		}

		return [
			'flow' => [
				'name' => $this->nameOf(process: $processes->item(0)),
				'nodes' => $this->laidOut(nodes: $nodes, positions: $positions),
				'edges' => $edges,
			],
			'report' => $report,
		];
	}//end import()

	/**
	 * One node, and its entry in the report.
	 *
	 * @param DOMElement          $element The element.
	 * @param DOMXPath            $xpath   The xpath.
	 * @param BpmnMappingReport   $report  The report.
	 *
	 * @return array<string, mixed>|null The node, or null when it is dropped.
	 */
	private function nodeFrom(DOMElement $element, DOMXPath $xpath, BpmnMappingReport $report): ?array {
		$id = trim($element->getAttribute('id'));
		$kind = $this->kindOf(element: $element, xpath: $xpath);
		$reading = $this->vocabulary->readingFor(element: $kind);

		// 🔑 THE EXTENSION ELEMENT WINS over the element kind. An
		// `exclusiveGateway` cannot say whether it was a `switch` or a `route`,
		// and the vocabulary's reverse table has to pick one — so our own files
		// carry the answer and the mapping is only consulted for files that do
		// not.
		$declared = $this->extensionType(element: $element, xpath: $xpath);
		$type = ($declared !== '' ? $declared : $reading['type']);

		$verdict = $reading['verdict'];
		$action = $reading['note'];
		if ($declared !== '') {
			$verdict = BpmnMappingReport::MAPPED;
			$action = '';
		}

		$report->record(elementId: $id, kind: $kind, verdict: $verdict, action: $action);

		if ($verdict === BpmnMappingReport::REFUSED) {
			return null;
		}

		$node = [
			'id' => $id,
			'name' => trim($element->getAttribute('name')),
			'type' => $type,
		];

		$config = $this->extensionConfig(element: $element, xpath: $xpath);
		if ($config !== null) {
			$node['config'] = $config;
		}

		return $node;
	}//end nodeFrom()

	/**
	 * The vocabulary kind for one element, event definition included.
	 *
	 * @param DOMElement $element The element.
	 * @param DOMXPath   $xpath   The xpath.
	 *
	 * @return string The kind.
	 */
	private function kindOf(DOMElement $element, DOMXPath $xpath): string {
		$local = (string)$element->localName;

		foreach (['timer', 'message', 'conditional', 'terminate'] as $definition) {
			$found = $xpath->query('./bpmn:' . $definition . 'EventDefinition', $element);
			if ($found !== false && $found->length > 0) {
				if ($local === 'startEvent') {
					return ($definition . 'StartEvent');
				}

				if ($local === 'endEvent') {
					return ($definition . 'EndEvent');
				}

				return ($local . ':' . $definition);
			}
		}

		return $local;
	}//end kindOf()

	/**
	 * The openregister type an element declares, or an empty string.
	 *
	 * @param DOMElement $element The element.
	 * @param DOMXPath   $xpath   The xpath.
	 *
	 * @return string The type.
	 */
	private function extensionType(DOMElement $element, DOMXPath $xpath): string {
		$found = $xpath->query(
			'./bpmn:extensionElements/' . BpmnVocabulary::EXTENSION_PREFIX . ':' . BpmnVocabulary::ELEMENT_TYPE,
			$element
		);

		if ($found === false || $found->length === 0) {
			return '';
		}

		return trim((string)$found->item(0)->textContent);
	}//end extensionType()

	/**
	 * The openregister config an element declares, or null.
	 *
	 * @param DOMElement $element The element.
	 * @param DOMXPath   $xpath   The xpath.
	 *
	 * @return array<string, mixed>|null The config.
	 */
	private function extensionConfig(DOMElement $element, DOMXPath $xpath): ?array {
		$found = $xpath->query(
			'./bpmn:extensionElements/' . BpmnVocabulary::EXTENSION_PREFIX . ':' . BpmnVocabulary::ELEMENT_CONFIG,
			$element
		);

		if ($found === false || $found->length === 0) {
			return null;
		}

		$decoded = json_decode((string)$found->item(0)->textContent, true);

		return (is_array($decoded) === true ? $decoded : null);
	}//end extensionConfig()

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
	 * The diagram positions the file carries, keyed by element id.
	 *
	 * @param DOMXPath $xpath The xpath.
	 *
	 * @return array<string, array{x: int, y: int}> The positions.
	 */
	private function positions(DOMXPath $xpath): array {
		$positions = [];
		foreach ($xpath->query('//bpmndi:BPMNShape') ?: [] as $shape) {
			if (($shape instanceof DOMElement) === false) {
				continue;
			}

			$bounds = $xpath->query('./dc:Bounds', $shape);
			if ($bounds === false || $bounds->length === 0) {
				continue;
			}

			$bound = $bounds->item(0);
			if (($bound instanceof DOMElement) === false) {
				continue;
			}

			$positions[trim($shape->getAttribute('bpmnElement'))] = [
				'x' => (int)$bound->getAttribute('x'),
				'y' => (int)$bound->getAttribute('y'),
			];
		}

		return $positions;
	}//end positions()

	/**
	 * The nodes with their positions, laid out when the file carried none.
	 *
	 * 🔴 NOT A PILE AT THE ORIGIN. A file with no diagram interchange is the
	 * common case for a hand-written or generated BPMN, and importing one into
	 * a heap of overlapping boxes reads as "the import is broken" rather than
	 * as "this file had no layout".
	 *
	 * @param array<int, array<string, mixed>>      $nodes     The nodes.
	 * @param array<string, array{x: int, y: int}>  $positions The file's positions.
	 *
	 * @return array<int, array<string, mixed>> The nodes.
	 */
	private function laidOut(array $nodes, array $positions): array {
		foreach ($nodes as $index => $node) {
			$id = (string)($node['id'] ?? '');
			if (array_key_exists($id, $positions) === true) {
				$nodes[$index]['position'] = $positions[$id];
				continue;
			}

			$nodes[$index]['position'] = [
				'x' => (($index % self::LAYOUT_COLUMNS) * self::LAYOUT_X),
				'y' => ((int)floor($index / self::LAYOUT_COLUMNS) * self::LAYOUT_Y),
			];
		}

		return $nodes;
	}//end laidOut()

	/**
	 * The process name, or an empty string.
	 *
	 * @param mixed $process The process element.
	 *
	 * @return string The name.
	 */
	private function nameOf(mixed $process): string {
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

		$previous = libxml_use_internal_errors(true);
		libxml_clear_errors();

		$document = new DOMDocument();
		// 🔴 EXTERNAL ENTITIES STAY OFF. A BPMN file is somebody else's
		// document, uploaded; parsing one with entity substitution on is an
		// XXE read of the server's filesystem dressed as a process import.
		$loaded = $document->loadXML($xml, LIBXML_NONET);

		$errors = libxml_get_errors();
		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		if ($loaded === false) {
			$first = 'the document is not well-formed XML';
			if ($errors !== []) {
				$first = trim((string)$errors[0]->message);
			}

			throw new BpmnImportRefused(
				message: sprintf('The file could not be read: %s', $first)
			);
		}

		return $document;
	}//end parse()
}//end class
