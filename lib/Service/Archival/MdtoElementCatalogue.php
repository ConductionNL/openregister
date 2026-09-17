<?php

/**
 * Which MDTO elements exist, and which of them the standard demands.
 *
 * 🔴 READ OUT OF THE XSD, NOT RETYPED BESIDE IT. The vendored
 * `MDTO-XML1.0.1.xsd` is what the generated document is validated against, so
 * it is the authority on which elements an `informatieobject` has and which
 * carry `minOccurs="1"`. A hand-kept list here would be a second copy of that
 * answer, and a second copy drifts: the day the XSD is bumped, a mandatory
 * element added by the standard would still be optional to this gate, and the
 * first anyone heard of it would be the e-Depot refusing a package.
 *
 * 🔴 A PARSE THAT FINDS NOTHING THROWS. An empty catalogue would make every
 * mapping valid and every transfer pass, which is the same green a correct
 * mapping gets. That failure mode is the whole reason this class exists, so it
 * refuses rather than reporting an empty set.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

/**
 * The MDTO informatieobject elements, read from the vendored XSD.
 *
 * @psalm-suppress UnusedClass
 */
class MdtoElementCatalogue {

	/**
	 * The vendored schema the generated documents are validated against.
	 */
	public const XSD_PATH = __DIR__ . '/../../Resources/mdto/MDTO-XML1.0.1.xsd';

	/**
	 * The XSD namespace the type definitions live in.
	 */
	private const XSD_NS = 'http://www.w3.org/2001/XMLSchema';

	/**
	 * The complex types an `informatieobject` is built from, base first.
	 *
	 * `informatieobjectType` extends `objectType`, and `identificatie` and
	 * `naam` are declared on the base. Reading only the extension would report
	 * two of the five mandatory elements as not existing at all.
	 *
	 * @var string[]
	 */
	private const TYPES = ['objectType', 'informatieobjectType'];

	/**
	 * The parsed catalogue, so several questions cost one parse.
	 *
	 * @var array<string, bool>|null
	 */
	private ?array $catalogue = null;

	/**
	 * Every MDTO informatieobject element, mapped to whether it is mandatory.
	 *
	 * @return array<string, bool> Element name mapped to true when minOccurs is at least one.
	 *
	 * @throws RuntimeException When the XSD is missing, unreadable or yields no elements.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	public function elements(): array {
		if ($this->catalogue !== null) {
			return $this->catalogue;
		}

		$this->catalogue = $this->parse();

		return $this->catalogue;
	}//end elements()

	/**
	 * The elements MDTO demands, in the order the XSD declares them.
	 *
	 * @return string[] The mandatory element names.
	 *
	 * @throws RuntimeException When the XSD cannot be read.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	public function mandatory(): array {
		return array_keys(array_filter($this->elements()));
	}//end mandatory()

	/**
	 * Is this a name MDTO knows?
	 *
	 * @param string $element The element name.
	 *
	 * @return bool True when the XSD declares it on an informatieobject.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	public function knows(string $element): bool {
		return array_key_exists($element, $this->elements());
	}//end knows()

	/**
	 * The element declarations of one complex type.
	 *
	 * @param DOMXPath $xpath The document.
	 * @param string   $type  The complex type name.
	 *
	 * @return array<string, bool> Element name mapped to whether it is mandatory.
	 */
	private function elementsOfType(DOMXPath $xpath, string $type): array {
		$elements = [];
		$query = sprintf('//xsd:complexType[@name="%s"]//xsd:element[@name]', $type);

		foreach ($xpath->query($query) as $node) {
			if (($node instanceof DOMElement) === false) {
				continue;
			}

			$name = $node->getAttribute('name');
			if ($name === '') {
				continue;
			}

			// An absent minOccurs means 1 in XSD, which is the direction that
			// matters: treating it as optional would let a mandatory element go
			// unmapped and the refusal never fire.
			$minOccurs = $node->getAttribute('minOccurs');
			$elements[$name] = ($minOccurs === '' || (int)$minOccurs >= 1);
		}

		return $elements;
	}//end elementsOfType()

	/**
	 * Read the element declarations out of the vendored XSD.
	 *
	 * @return array<string, bool> Element name mapped to whether it is mandatory.
	 *
	 * @throws RuntimeException When the XSD is missing, unreadable or yields no elements.
	 */
	private function parse(): array {
		$path = realpath(self::XSD_PATH);
		if ($path === false || is_readable($path) === false) {
			throw new RuntimeException(
				'The vendored MDTO schema is missing at ' . self::XSD_PATH
				. ', so which elements MDTO demands cannot be established'
			);
		}

		// Read the bytes first, then parse the string.
		//
		// `DOMDocument::load()` goes through libxml's external entity loader,
		// and Nextcloud's `lib/base.php` replaces that loader with one that
		// returns null. The replacement does not distinguish the primary
		// document from an entity it references, so `load()` returns false for
		// a perfectly readable local file the moment the Nextcloud bootstrap is
		// in the process, reporting "Failed to load external entity because the
		// resolver function returned null". `loadXML()` on a string never asks
		// the resolver. Same reason `MdtoXmlGenerator` uses
		// `schemaValidateSource()` rather than `schemaValidate()`.
		$source = file_get_contents($path);
		if ($source === false) {
			throw new RuntimeException('The vendored MDTO schema at ' . $path . ' could not be read');
		}

		$document = new DOMDocument();
		$previous = libxml_use_internal_errors(true);
		$loaded = $document->loadXML((string)$source);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		if ($loaded === false) {
			throw new RuntimeException('The vendored MDTO schema at ' . $path . ' could not be parsed');
		}

		$xpath = new DOMXPath($document);
		$xpath->registerNamespace('xsd', self::XSD_NS);

		$elements = [];
		foreach (self::TYPES as $type) {
			$elements = array_merge($elements, $this->elementsOfType(xpath: $xpath, type: $type));
		}

		if ($elements === []) {
			throw new RuntimeException(
				'The vendored MDTO schema at ' . $path . ' yielded no element declarations. '
				. 'An empty catalogue would make every mapping valid, so this refuses instead.'
			);
		}

		return $elements;
	}//end parse()
}//end class
