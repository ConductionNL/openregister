<?php

/**
 * The state one BPMN import threads through its reading of a file.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Bpmn
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/flow-bpmn-interchange/specs/flow-bpmn-interchange/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Bpmn;

use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * The xpath over one file and the report its reading writes to.
 *
 * Both are per import, so they travel together rather than living on the
 * importer, which is a shared service. The questions that need only the
 * xpath — an element's kind, its openregister extension elements, the
 * process's children — are answered here.
 *
 * @spec openspec/changes/flow-bpmn-interchange/specs/flow-bpmn-interchange/spec.md
 */
class BpmnImportPass {

	/**
	 * Constructor.
	 *
	 * @param DOMXPath          $xpath  The xpath, with the BPMN namespaces registered.
	 * @param BpmnMappingReport $report The report, written to as constructs are read.
	 */
	public function __construct(
		public readonly DOMXPath $xpath,
		public readonly BpmnMappingReport $report,
	) {

	}//end __construct()

	/**
	 * The process element's child elements, in document order.
	 *
	 * @param DOMNode|null $process The single bpmn:process element.
	 *
	 * @return array<int, DOMElement> The children.
	 *
	 * @spec openspec/changes/flow-bpmn-interchange/specs/flow-bpmn-interchange/spec.md
	 */
	public function childElements(?DOMNode $process): array {
		$children = $this->xpath->query('./*', $process);
		if ($children === false) {
			return [];
		}

		$elements = [];
		foreach ($children as $child) {
			if ($child instanceof DOMElement) {
				$elements[] = $child;
			}
		}

		return $elements;
	}//end childElements()

	/**
	 * The vocabulary kind for one element, event definition included.
	 *
	 * @param DOMElement $element The element.
	 *
	 * @return string The kind.
	 *
	 * @spec openspec/changes/flow-bpmn-interchange/specs/flow-bpmn-interchange/spec.md
	 */
	public function kindOf(DOMElement $element): string {
		$local = (string)$element->localName;
		$definition = $this->eventDefinitionOf(element: $element);
		if ($definition === '') {
			return $local;
		}

		if ($local === 'startEvent' || $local === 'endEvent') {
			return ($definition . ucfirst($local));
		}

		return ($local . ':' . $definition);
	}//end kindOf()

	/**
	 * The first event definition an element carries, or an empty string.
	 *
	 * @param DOMElement $element The element.
	 *
	 * @return string The definition: timer, message, conditional or terminate.
	 */
	private function eventDefinitionOf(DOMElement $element): string {
		foreach (['timer', 'message', 'conditional', 'terminate'] as $definition) {
			$found = $this->xpath->query('./bpmn:' . $definition . 'EventDefinition', $element);
			if ($found !== false && $found->length > 0) {
				return $definition;
			}
		}

		return '';
	}//end eventDefinitionOf()

	/**
	 * The openregister type an element declares, or an empty string.
	 *
	 * @param DOMElement $element The element.
	 *
	 * @return string The type.
	 *
	 * @spec openspec/changes/flow-bpmn-interchange/specs/flow-bpmn-interchange/spec.md
	 */
	public function extensionType(DOMElement $element): string {
		return trim($this->extensionTexts(element: $element)[BpmnVocabulary::ELEMENT_TYPE] ?? '');
	}//end extensionType()

	/**
	 * The openregister config an element declares, or null.
	 *
	 * @param DOMElement $element The element.
	 *
	 * @return array<string, mixed>|null The config.
	 *
	 * @spec openspec/changes/flow-bpmn-interchange/specs/flow-bpmn-interchange/spec.md
	 */
	public function extensionConfig(DOMElement $element): ?array {
		$text = ($this->extensionTexts(element: $element)[BpmnVocabulary::ELEMENT_CONFIG] ?? null);
		if ($text === null) {
			return null;
		}

		$decoded = json_decode($text, true);
		if (is_array($decoded) === true) {
			return $decoded;
		}

		return null;
	}//end extensionConfig()

	/**
	 * The text of each openregister extension element an element carries.
	 *
	 * @param DOMElement $element The element.
	 *
	 * @return array<string, string> The text, keyed by extension element name.
	 */
	private function extensionTexts(DOMElement $element): array {
		$texts = [];
		foreach ([BpmnVocabulary::ELEMENT_TYPE, BpmnVocabulary::ELEMENT_CONFIG] as $name) {
			$found = $this->xpath->query('./bpmn:extensionElements/' . BpmnVocabulary::EXTENSION_PREFIX . ':' . $name, $element);
			if ($found !== false && $found->length > 0) {
				$texts[$name] = (string)$found->item(0)->textContent;
			}
		}

		return $texts;
	}//end extensionTexts()
}//end class
