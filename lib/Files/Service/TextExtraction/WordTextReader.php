<?php

/**
 * OpenRegister WordTextReader
 *
 * Text reader for DOCX and ODT that needs only the zip and dom extensions.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\TextExtraction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\TextExtraction;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use ZipArchive;

/**
 * Reads the text of a DOCX or ODT package with ZipArchive and DOM.
 *
 * DOCX: headers, body, footers, footnotes and endnotes. ODT: master-page
 * headers and footers, then the body. One line per paragraph, and a line
 * break after every table row. Deleted tracked changes and comments are not
 * read. Legacy binary .doc is not supported.
 *
 * @spec openspec/specs/text-extraction-word/spec.md
 */
class WordTextReader {

	/**
	 * WordprocessingML main namespace.
	 */
	private const NS_W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

	/**
	 * OpenDocument text namespace.
	 */
	private const NS_TEXT = 'urn:oasis:names:tc:opendocument:xmlns:text:1.0';

	/**
	 * OpenDocument table namespace.
	 */
	private const NS_TABLE = 'urn:oasis:names:tc:opendocument:xmlns:table:1.0';

	/**
	 * The `w:type` values of footnote and endnote separators, which carry no text.
	 */
	private const DOCX_SEPARATORS = ['separator', 'continuationSeparator', 'continuationNotice'];

	/**
	 * ODT paragraph and heading elements, in Clark notation.
	 */
	private const ODT_PARAGRAPHS = ['{' . self::NS_TEXT . '}p', '{' . self::NS_TEXT . '}h'];

	/**
	 * The ODT tracked-changes record, in Clark notation.
	 */
	private const ODT_TRACKED_CHANGES = '{' . self::NS_TEXT . '}tracked-changes';

	/**
	 * The ODT table row, in Clark notation.
	 */
	private const ODT_TABLE_ROW = '{' . self::NS_TABLE . '}table-row';

	/**
	 * ODT inline elements that always read as the same text.
	 */
	private const ODT_INLINE_LITERALS = ['tab' => "\t", 'line-break' => "\n", 'note-citation' => '', 'annotation' => ''];

	/**
	 * ODT inline elements whose text starts on a new line.
	 */
	private const ODT_INLINE_PREFIXES = ['p' => "\n", 'h' => "\n"];

	/**
	 * Read the text of a package.
	 *
	 * @param string $bytes  The package bytes.
	 * @param string $format `Word2007` (DOCX) or `ODText` (ODT).
	 *
	 * @return string|null The text, or null when the package cannot be read or the format is not supported.
	 *
	 * @spec openspec/specs/text-extraction-word/spec.md
	 */
	public function read(string $bytes, string $format): ?string {
		if ($format !== 'Word2007' && $format !== 'ODText') {
			return null;
		}

		$tempFile = tmpfile();
		fwrite($tempFile, $bytes);
		$text = $this->readFile(path: stream_get_meta_data($tempFile)['uri'], format: $format);
		fclose($tempFile);

		return $text;
	}//end read()

	/**
	 * Open a package file as a zip and read its text.
	 *
	 * @param string $path   The package file path.
	 * @param string $format `Word2007` (DOCX) or `ODText` (ODT).
	 *
	 * @return string|null The text, or null when the zip cannot be opened or has no main part.
	 */
	private function readFile(string $path, string $format): ?string {
		$zip = new ZipArchive();
		if ($zip->open($path, ZipArchive::RDONLY) !== true) {
			return null;
		}

		$package = new OoxmlPackage(zip: $zip, maxPartBytes: PresentationExtractor::MAX_PART_BYTES);
		$text = match ($format) {
			'ODText' => $this->readOdt(package: $package),
			default => $this->readDocx(package: $package),
		};
		$zip->close();

		return $text;
	}//end readFile()

	/**
	 * The text of a DOCX package, or null without a main document part.
	 *
	 * @param OoxmlPackage $package The opened package.
	 *
	 * @return string|null
	 */
	private function readDocx(OoxmlPackage $package): ?string {
		$mainPath = $package->mainPartPath();
		if ($mainPath === null) {
			return null;
		}

		$main = $package->readXml(path: $mainPath);
		if ($main === null) {
			return null;
		}

		$parts = $this->docxSideParts(package: $package, mainPath: $mainPath);

		return $parts['header'] . $this->docxBlocks(node: $main->documentElement) . $parts['footer'] . $parts['footnotes'] . $parts['endnotes'];
	}//end readDocx()

	/**
	 * The text of the headers, footers, footnotes and endnotes of the main part, by kind.
	 *
	 * @param OoxmlPackage $package  The opened package.
	 * @param string       $mainPath The main document part path.
	 *
	 * @return array{header: string, footer: string, footnotes: string, endnotes: string}
	 */
	private function docxSideParts(OoxmlPackage $package, string $mainPath): array {
		$parts = ['header' => '', 'footer' => '', 'footnotes' => '', 'endnotes' => ''];
		foreach ($package->relationships(partPath: $mainPath) as $relationship) {
			$kind = substr($relationship['type'], (strrpos($relationship['type'], '/') + 1));
			if ($relationship['external'] === true || isset($parts[$kind]) === false) {
				continue;
			}

			$part = $package->readXml(path: $relationship['target']);
			if ($part !== null) {
				$parts[$kind] .= $this->docxBlocks(node: $part->documentElement);
			}
		}

		return $parts;
	}//end docxSideParts()

	/**
	 * Text of the block-level content under a node: paragraphs, and table rows.
	 *
	 * @param DOMNode $node The node to walk.
	 *
	 * @return string
	 */
	private function docxBlocks(DOMNode $node): string {
		$text = '';
		foreach ($node->childNodes as $child) {
			if (($child instanceof DOMElement) === true && $child->namespaceURI === self::NS_W) {
				$text .= $this->docxBlock(element: $child);
			}
		}

		return $text;
	}//end docxBlocks()

	/**
	 * Text of one WordprocessingML element: a paragraph line, or its blocks.
	 *
	 * Footnote and endnote separators are skipped; a table row ends with a line break.
	 *
	 * @param DOMElement $element An element in the WordprocessingML namespace.
	 *
	 * @return string
	 */
	private function docxBlock(DOMElement $element): string {
		if ($element->localName === 'p') {
			return $this->docxParagraph(paragraph: $element) . "\n";
		}

		if (in_array($element->getAttributeNS(self::NS_W, 'type'), self::DOCX_SEPARATORS, true) === true) {
			return '';
		}

		$text = $this->docxBlocks(node: $element);
		if ($element->localName === 'tr') {
			$text .= "\n";
		}

		return $text;
	}//end docxBlock()

	/**
	 * The text of one paragraph: its text runs, tabs and breaks in order.
	 *
	 * @param DOMElement $paragraph A `w:p` element.
	 *
	 * @return string
	 */
	private function docxParagraph(DOMElement $paragraph): string {
		$xpath = new DOMXPath($paragraph->ownerDocument);
		$xpath->registerNamespace('w', self::NS_W);

		$text = '';
		foreach ($xpath->query('.//w:t | .//w:tab | .//w:br | .//w:cr', $paragraph) as $node) {
			$text .= match ($node->localName) {
				't' => $node->textContent,
				'tab' => "\t",
				default => "\n",
			};
		}

		return $text;
	}//end docxParagraph()

	/**
	 * The text of an ODT package: master-page headers and footers, then the body.
	 *
	 * @param OoxmlPackage $package The opened package.
	 *
	 * @return string|null
	 */
	private function readOdt(OoxmlPackage $package): ?string {
		$content = $package->readXml(path: 'content.xml');
		if ($content === null) {
			return null;
		}

		return $this->odtMasterPages(package: $package) . $this->odtBlocks(node: $content->documentElement);
	}//end readOdt()

	/**
	 * The text of the master-page headers and footers in `styles.xml`.
	 *
	 * @param OoxmlPackage $package The opened package.
	 *
	 * @return string The text, or an empty string without a readable `styles.xml`.
	 */
	private function odtMasterPages(OoxmlPackage $package): string {
		$styles = $package->readXml(path: 'styles.xml');
		if (($styles instanceof DOMDocument) === false) {
			return '';
		}

		$text = '';
		foreach ($styles->getElementsByTagNameNS('urn:oasis:names:tc:opendocument:xmlns:style:1.0', '*') as $element) {
			if (in_array($element->localName, ['header', 'footer'], true) === true) {
				$text .= $this->odtBlocks(node: $element);
			}
		}

		return $text;
	}//end odtMasterPages()

	/**
	 * Text of the block-level content under an ODT node.
	 *
	 * @param DOMNode $node The node to walk.
	 *
	 * @return string
	 */
	private function odtBlocks(DOMNode $node): string {
		$text = '';
		foreach ($node->childNodes as $child) {
			if ($child instanceof DOMElement) {
				$text .= $this->odtBlock(element: $child);
			}
		}

		return $text;
	}//end odtBlocks()

	/**
	 * Text of one ODT block element: a paragraph line, nothing for tracked
	 * changes, else its blocks, with a line break after a table row.
	 *
	 * @param DOMElement $element The element.
	 *
	 * @return string
	 */
	private function odtBlock(DOMElement $element): string {
		$name = '{' . $element->namespaceURI . '}' . $element->localName;
		if (in_array($name, self::ODT_PARAGRAPHS, true) === true) {
			return $this->odtInline(node: $element) . "\n";
		}

		if ($name === self::ODT_TRACKED_CHANGES) {
			return '';
		}

		$text = $this->odtBlocks(node: $element);
		if ($name === self::ODT_TABLE_ROW) {
			$text .= "\n";
		}

		return $text;
	}//end odtBlock()

	/**
	 * The inline text of an ODT paragraph or span, with spaces, tabs and breaks expanded.
	 *
	 * @param DOMNode $node A paragraph, heading or inline element.
	 *
	 * @return string
	 */
	private function odtInline(DOMNode $node): string {
		$text = '';
		foreach ($node->childNodes as $child) {
			if ($child->nodeType === XML_TEXT_NODE) {
				$text .= $child->textContent;
			}

			if ($child instanceof DOMElement) {
				$text .= $this->odtInlineElement(element: $child);
			}
		}

		return $text;
	}//end odtInline()

	/**
	 * The inline text of one ODT element inside a paragraph.
	 *
	 * @param DOMElement $element The element.
	 *
	 * @return string
	 */
	private function odtInlineElement(DOMElement $element): string {
		$name = $element->localName;
		if (isset(self::ODT_INLINE_LITERALS[$name]) === true) {
			return self::ODT_INLINE_LITERALS[$name];
		}

		if ($name === 's') {
			return str_repeat(' ', max(1, (int) $element->getAttributeNS(self::NS_TEXT, 'c')));
		}

		if ($name === 'note-body') {
			return ' ' . trim($this->odtBlocks(node: $element));
		}

		return (self::ODT_INLINE_PREFIXES[$name] ?? '') . $this->odtInline(node: $element);
	}//end odtInlineElement()
}//end class
