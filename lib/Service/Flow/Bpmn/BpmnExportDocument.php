<?php

/**
 * The BPMN document an export is written into.
 *
 * One `bpmn:definitions` root with every namespace the exporter writes
 * declared on it, and one way to create an element: by its prefixed name, so
 * the namespace an element lands in is never typed twice.
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

/**
 * A DOM document rooted at `bpmn:definitions`.
 *
 * @spec openspec/changes/flow-bpmn-interchange/specs/flow-bpmn-interchange/spec.md
 */
final class BpmnExportDocument {

	/**
	 * The namespaces declared on the root, by prefix, in declaration order.
	 *
	 * @var array<string, string>
	 */
	public const DECLARED = [
		'bpmndi'                           => FlowBpmnExporter::NS_BPMNDI,
		'dc'                               => FlowBpmnExporter::NS_DC,
		'di'                               => FlowBpmnExporter::NS_DI,
		BpmnVocabulary::EXTENSION_PREFIX   => BpmnVocabulary::EXTENSION_NS,
	];

	/**
	 * The namespace of every prefix an element may be created with.
	 *
	 * @var array<string, string>
	 */
	public const NAMESPACES = (['bpmn' => FlowBpmnExporter::NS_BPMN] + self::DECLARED);

	/**
	 * The XML document.
	 *
	 * @var DOMDocument
	 */
	public readonly DOMDocument $dom;

	/**
	 * The `bpmn:definitions` root, already attached to the document.
	 *
	 * @var DOMElement
	 */
	public readonly DOMElement $definitions;

	/**
	 * Constructor.
	 *
	 * @param string $definitionsId The id of the definitions root.
	 */
	public function __construct(string $definitionsId) {
		$dom = new DOMDocument('1.0', 'UTF-8');
		$dom->formatOutput = true;
		$this->dom = $dom;
		$this->definitions = $this->root(definitionsId: $definitionsId);
	}//end __construct()

	/**
	 * A new, detached element in the namespace its prefix names.
	 *
	 * @param string      $qualifiedName The prefixed element name.
	 * @param string|null $text          Its text content, if any.
	 *
	 * @return DOMElement The element.
	 */
	public function create(string $qualifiedName, ?string $text=null): DOMElement {
		$namespace = self::NAMESPACES[(string)strstr($qualifiedName, ':', true)];
		if ($text === null) {
			return $this->dom->createElementNS($namespace, $qualifiedName);
		}

		return $this->dom->createElementNS($namespace, $qualifiedName, $text);
	}//end create()

	/**
	 * A new, detached element in our own extension namespace.
	 *
	 * @param string $name The unprefixed element name.
	 * @param string $text Its text content.
	 *
	 * @return DOMElement The element.
	 */
	public function extension(string $name, string $text): DOMElement {
		return $this->create(qualifiedName: BpmnVocabulary::EXTENSION_PREFIX . ':' . $name, text: $text);
	}//end extension()

	/**
	 * The definitions root, with its namespaces declared, appended to the document.
	 *
	 * @param string $definitionsId The id of the definitions root.
	 *
	 * @return DOMElement The root.
	 */
	private function root(string $definitionsId): DOMElement {
		$definitions = $this->create(qualifiedName: 'bpmn:definitions');
		foreach (self::DECLARED as $prefix => $namespace) {
			$definitions->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:' . $prefix, $namespace);
		}

		$definitions->setAttribute('id', $definitionsId);
		$definitions->setAttribute('targetNamespace', BpmnVocabulary::EXTENSION_NS);
		$this->dom->appendChild($definitions);

		return $definitions;
	}//end root()
}//end class
