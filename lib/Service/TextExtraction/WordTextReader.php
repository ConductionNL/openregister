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

namespace OCA\OpenRegister\Service\TextExtraction;

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
		$zip = new ZipArchive();
		if ($zip->open(stream_get_meta_data($tempFile)['uri'], ZipArchive::RDONLY) !== true) {
			fclose($tempFile);
			return null;
		}

		$package = new OoxmlPackage(zip: $zip, maxPartBytes: PresentationExtractor::MAX_PART_BYTES);
		$text = match ($format) {
			'ODText' => $this->readOdt(package: $package),
			default => $this->readDocx(package: $package),
		};

		$zip->close();
		fclose($tempFile);

		return $text;
	}//end read()

	/**
	 * The text of a DOCX package, or null without a main document part.
	 *
	 * @param OoxmlPackage $package The opened package.
	 *
	 * @return string|null
	 */
	private function readDocx(OoxmlPackage $package): ?string {
		$mainPath = $package->mainPartPath();
		$main = null;
		if ($mainPath !== null) {
			$main = $package->readXml(path: $mainPath);
		}

		if ($main === null) {
			return null;
		}

		$parts = ['header' => '', 'footer' => '', 'footnotes' => '', 'endnotes' => ''];
		foreach ($package->relationships(partPath: (string) $mainPath) as $relationship) {
			$kind = substr($relationship['type'], (strrpos($relationship['type'], '/') + 1));
			if ($relationship['external'] === true || isset($parts[$kind]) === false) {
				continue;
			}

			$part = $package->readXml(path: $relationship['target']);
			if ($part !== null) {
				$parts[$kind] .= $this->docxBlocks(node: $part->documentElement);
			}
		}

		return $parts['header'] . $this->docxBlocks(node: $main->documentElement) . $parts['footer'] . $parts['footnotes'] . $parts['endnotes'];
	}//end readDocx()

	/**
	 * Text of the block-level content under a node: paragraphs, and table rows.
	 *
	 * Footnote and endnote separators are skipped.
	 *
	 * @param DOMNode $node The node to walk.
	 *
	 * @return string
	 */
	private function docxBlocks(DOMNode $node): string {
		$text = '';
		foreach ($node->childNodes as $child) {
			if (($child instanceof DOMElement) === false || $child->namespaceURI !== self::NS_W) {
				continue;
			}

			if ($child->localName === 'p') {
				$text .= $this->docxParagraph(paragraph: $child) . "\n";
				continue;
			}

			if (in_array($child->getAttributeNS(self::NS_W, 'type'), ['separator', 'continuationSeparator', 'continuationNotice'], true) === true) {
				continue;
			}

			$text .= $this->docxBlocks(node: $child);
			if ($child->localName === 'tr') {
				$text .= "\n";
			}
		}//end foreach

		return $text;
	}//end docxBlocks()

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

		$text = '';
		$styles = $package->readXml(path: 'styles.xml');
		if ($styles instanceof DOMDocument) {
			foreach ($styles->getElementsByTagNameNS('urn:oasis:names:tc:opendocument:xmlns:style:1.0', '*') as $element) {
				if (in_array($element->localName, ['header', 'footer'], true) === true) {
					$text .= $this->odtBlocks(node: $element);
				}
			}
		}

		return $text . $this->odtBlocks(node: $content->documentElement);
	}//end readOdt()

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
			if (($child instanceof DOMElement) === false) {
				continue;
			}

			if ($child->namespaceURI === self::NS_TEXT && in_array($child->localName, ['p', 'h'], true) === true) {
				$text .= $this->odtInline(node: $child) . "\n";
				continue;
			}

			if ($child->namespaceURI === self::NS_TEXT && $child->localName === 'tracked-changes') {
				continue;
			}

			$text .= $this->odtBlocks(node: $child);
			if ($child->namespaceURI === self::NS_TABLE && $child->localName === 'table-row') {
				$text .= "\n";
			}
		}

		return $text;
	}//end odtBlocks()

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

			if (($child instanceof DOMElement) === false) {
				continue;
			}

			$text .= match ($child->localName) {
				's' => str_repeat(' ', max(1, (int) $child->getAttributeNS(self::NS_TEXT, 'c'))),
				'tab' => "\t",
				'line-break' => "\n",
				'p', 'h' => "\n" . $this->odtInline(node: $child),
				'note-citation', 'annotation' => '',
				'note-body' => ' ' . trim($this->odtBlocks(node: $child)),
				default => $this->odtInline(node: $child),
			};
		}

		return $text;
	}//end odtInline()
}//end class
