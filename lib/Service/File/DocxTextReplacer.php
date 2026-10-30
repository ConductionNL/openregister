<?php

/**
 * OpenRegister DocxTextReplacer
 *
 * Replaces words inside a DOCX package by editing its XML parts in place.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/file-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;
use ZipArchive;

/**
 * Replaces words in the text of a DOCX package with ZipArchive and DOM.
 *
 * Every text-bearing part is edited: the body, headers, footers, footnotes,
 * endnotes and comments. Matching is case-insensitive and runs over the
 * whole text of a paragraph, so a value split over several runs is found;
 * the replacement takes the formatting of the run the match starts in.
 * Inserted and deleted tracked-change text are matched separately. All other
 * package content is left byte for byte as it was.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
class DocxTextReplacer {

	/**
	 * WordprocessingML main namespace.
	 */
	private const NS_W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

	/**
	 * Package parts that carry document text.
	 */
	private const TEXT_PARTS = '#^word/(document|header\d*|footer\d*|footnotes|endnotes|comments)\.xml$#';

	/**
	 * The replacements of the current run, applied in the order given.
	 *
	 * @var array<string, string>
	 */
	private array $replacements = [];

	/**
	 * XPath context of the part being edited.
	 *
	 * @var DOMXPath
	 */
	private DOMXPath $xpath;

	/**
	 * Apply the replacements to the package at a path, rewriting it in place.
	 *
	 * @param string                $path         Path of the DOCX file.
	 * @param array<string, string> $replacements Search text => replacement.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the package cannot be opened or a part is not well-formed XML.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function replaceInFile(string $path, array $replacements): void {
		$this->replacements = array_filter(
			$replacements,
			static fn ($search): bool => (string) $search !== '',
			ARRAY_FILTER_USE_KEY
		);

		$zip = new ZipArchive();
		if ($zip->open($path) !== true) {
			throw new RuntimeException('The document is not a readable DOCX package');
		}

		try {
			foreach ($this->textParts(zip: $zip) as $part) {
				$this->replaceInPart(zip: $zip, part: $part);
			}
		} finally {
			$zip->close();
		}
	}//end replaceInFile()

	/**
	 * Names of the text-bearing parts in the package.
	 *
	 * @param ZipArchive $zip The open package.
	 *
	 * @return list<string>
	 */
	private function textParts(ZipArchive $zip): array {
		$parts = [];
		for ($index = 0; $index < $zip->numFiles; $index++) {
			$name = (string) $zip->getNameIndex($index);
			if (preg_match(self::TEXT_PARTS, $name) === 1) {
				$parts[] = $name;
			}
		}

		return $parts;
	}//end textParts()

	/**
	 * Replace in one part and write it back when anything changed.
	 *
	 * @param ZipArchive $zip  The open package.
	 * @param string     $part The part name.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the part is not well-formed XML.
	 */
	private function replaceInPart(ZipArchive $zip, string $part): void {
		$xml = $zip->getFromName($part);
		if ($xml === false || $xml === '') {
			return;
		}

		$dom = $this->loadPart(xml: $xml);
		$this->xpath = new DOMXPath($dom);
		$this->xpath->registerNamespace('w', self::NS_W);

		if ($this->replaceInParagraphs() === true) {
			$zip->addFromString($part, (string) $dom->saveXML());
		}
	}//end replaceInPart()

	/**
	 * Parse one package part.
	 *
	 * @param string $xml The part's XML.
	 *
	 * @return DOMDocument
	 *
	 * @throws RuntimeException When the part is not well-formed XML or declares a DOCTYPE.
	 */
	private function loadPart(string $xml): DOMDocument {
		$dom = new DOMDocument();
		$dom->preserveWhiteSpace = true;
		$previous = libxml_use_internal_errors(true);
		$loaded = $dom->loadXML($xml, LIBXML_NONET);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		if ($loaded === false || $dom->doctype !== null) {
			throw new RuntimeException('A document part is not well-formed XML');
		}

		return $dom;
	}//end loadPart()

	/**
	 * Apply the replacements to every paragraph of the part in {@see $xpath}.
	 *
	 * @return bool Whether any text changed.
	 */
	private function replaceInParagraphs(): bool {
		$changed = false;
		foreach ($this->xpath->query('//w:p') as $paragraph) {
			if (($paragraph instanceof DOMElement) === false) {
				continue;
			}

			foreach (['w:t', 'w:delText'] as $textElement) {
				$nodes = $this->paragraphTextNodes(paragraph: $paragraph, query: './/' . $textElement);
				$changed = $this->replaceInNodes(nodes: $nodes) || $changed;
			}
		}

		return $changed;
	}//end replaceInParagraphs()

	/**
	 * Text elements of one paragraph, without those of paragraphs nested inside it.
	 *
	 * @param DOMElement $paragraph The `w:p` element.
	 * @param string     $query     Relative XPath for the text elements.
	 *
	 * @return list<DOMElement>
	 */
	private function paragraphTextNodes(DOMElement $paragraph, string $query): array {
		$nodes = [];
		foreach ($this->xpath->query($query, $paragraph) as $node) {
			if ($node instanceof DOMElement && $this->closestParagraph(node: $node) === $paragraph) {
				$nodes[] = $node;
			}
		}

		return $nodes;
	}//end paragraphTextNodes()

	/**
	 * The nearest `w:p` ancestor of a node.
	 *
	 * @param DOMElement $node The text element.
	 *
	 * @return DOMElement|null
	 */
	private function closestParagraph(DOMElement $node): ?DOMElement {
		$parent = $node->parentNode;
		while ($parent instanceof DOMElement) {
			if ($parent->namespaceURI === self::NS_W && $parent->localName === 'p') {
				return $parent;
			}

			$parent = $parent->parentNode;
		}

		return null;
	}//end closestParagraph()

	/**
	 * Apply every replacement across the joined text of a node list.
	 *
	 * @param list<DOMElement> $nodes Text elements in document order.
	 *
	 * @return bool Whether any text changed.
	 */
	private function replaceInNodes(array $nodes): bool {
		if ($nodes === []) {
			return false;
		}

		$changed = false;
		foreach ($this->replacements as $search => $replacement) {
			$match = new DocxTextMatch(search: (string) $search, replacement: $replacement);
			while ($match->findIn(nodes: $nodes) === true) {
				$match->applyTo(nodes: $nodes);
				$changed = true;
			}
		}

		return $changed;
	}//end replaceInNodes()
}//end class
