<?php

/**
 * OpenRegister HtmlToOdt
 *
 * Turns the fixed HTML of export sections and dashboard reports into the
 * body of an ODT content part.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Export\Odt
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/export-pdf-format/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Export\Odt;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Converts a small HTML subset into ODF text elements.
 *
 * Understood: `h1`, `h2`, `p`, `table` (with `thead` and `tbody`), `dl`, and
 * the containers `div`, `section`, `main`, `header`. A `div.pdf-section`
 * after the first starts on a new page; `div.widget-headline` is a large
 * number. Paragraph classes pick a style. Everything else, including
 * scripts, styles, links and images, is not written; only text is copied,
 * never markup or URLs.
 *
 * @spec openspec/specs/export-pdf-format/spec.md
 */
class HtmlToOdt {

	public const NS_OFFICE = 'urn:oasis:names:tc:opendocument:xmlns:office:1.0';

	public const NS_TEXT = 'urn:oasis:names:tc:opendocument:xmlns:text:1.0';

	public const NS_TABLE = 'urn:oasis:names:tc:opendocument:xmlns:table:1.0';

	public const NS_STYLE = 'urn:oasis:names:tc:opendocument:xmlns:style:1.0';

	public const NS_FO = 'urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0';

	/**
	 * Paragraph style per HTML class.
	 */
	private const PARAGRAPH_STYLES = [
		'meta'               => 'OR_Meta',
		'report-meta'        => 'OR_Meta',
		'report-description' => 'OR_Subtle',
		'widget-subtitle'    => 'OR_Subtle',
		'widget-empty'       => 'OR_Subtle',
		'widget-error'       => 'OR_Error',
		'widget-headline'    => 'OR_Headline',
	];

	/**
	 * Elements whose children are walked as blocks.
	 */
	private const CONTAINERS = ['html', 'body', 'div', 'section', 'main', 'header'];

	/**
	 * The content document being built.
	 *
	 * @var DOMDocument
	 */
	private DOMDocument $content;

	/**
	 * Text of the first `h1`, or empty.
	 *
	 * @var string
	 */
	private string $title = '';

	/**
	 * Number of export sections seen so far.
	 *
	 * @var integer
	 */
	private int $sections = 0;

	/**
	 * Whether the next heading starts a new page.
	 *
	 * @var boolean
	 */
	private bool $breakBefore = false;

	/**
	 * Reads the text of HTML elements.
	 *
	 * @var VisibleText
	 */
	private VisibleText $visibleText;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->visibleText = new VisibleText();
	}//end __construct()

	/**
	 * Convert HTML into an `office:text` element of a new content document.
	 *
	 * @param string $html The HTML.
	 *
	 * @return DOMElement The filled `office:text` element; its owner document is the content part.
	 *
	 * @spec openspec/specs/export-pdf-format/spec.md
	 */
	public function convert(string $html): DOMElement {
		$this->content = new DOMDocument('1.0', 'UTF-8');
		$this->title = '';
		$this->sections = 0;
		$this->breakBefore = false;

		$text = $this->content->createElementNS(self::NS_OFFICE, 'office:text');

		$source = new DOMDocument();
		$previous = libxml_use_internal_errors(true);
		$source->loadHTML('<?xml encoding="UTF-8">' . $html, (LIBXML_NONET | LIBXML_NOERROR));
		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		$body = (new DOMXPath($source))->query('//body')->item(0);
		if ($body instanceof DOMNode) {
			$this->blocks(parent: $body, into: $text);
		}

		return $text;
	}//end convert()

	/**
	 * Text of the first `h1` of the last conversion, or empty.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/export-pdf-format/spec.md
	 */
	public function title(): string {
		return $this->title;
	}//end title()

	/**
	 * Write the block-level children of an HTML element.
	 *
	 * @param DOMNode    $parent The HTML element.
	 * @param DOMElement $into   The ODF element to append to.
	 *
	 * @return void
	 */
	private function blocks(DOMNode $parent, DOMElement $into): void {
		foreach ($parent->childNodes as $child) {
			if ($child instanceof DOMElement) {
				$this->block(element: $child, into: $into);
			}
		}
	}//end blocks()

	/**
	 * Write one block-level HTML element.
	 *
	 * @param DOMElement $element The HTML element.
	 * @param DOMElement $into    The ODF element to append to.
	 *
	 * @return void
	 */
	private function block(DOMElement $element, DOMElement $into): void {
		$tag = strtolower($element->tagName);
		$class = $this->firstClass(element: $element);

		if ($tag === 'div' && $class === 'pdf-section') {
			$this->breakBefore = ($this->sections > 0);
			$this->sections++;
		}

		match (true) {
			$tag === 'h1', $tag === 'h2' => $into->appendChild($this->heading(element: $element, level: (int) substr($tag, 1))),
			$tag === 'p', isset(self::PARAGRAPH_STYLES[$class]) => $into->appendChild(
				$this->paragraph(style: (self::PARAGRAPH_STYLES[$class] ?? 'OR_Body'), text: $this->visibleText->read(node: $element))
			),
			$tag === 'table' => $into->appendChild((new OdtTableBuilder(content: $this->content, html: $element))->table()),
			$tag === 'dl' => $into->appendChild((new OdtTableBuilder(content: $this->content, html: $element))->definitionTable()),
			in_array($tag, self::CONTAINERS, true) => $this->blocks(parent: $element, into: $into),
			default => null,
		};
	}//end block()

	/**
	 * An ODF heading for an `h1` or `h2`.
	 *
	 * @param DOMElement $element The heading element.
	 * @param int        $level   1 or 2.
	 *
	 * @return DOMElement
	 */
	private function heading(DOMElement $element, int $level): DOMElement {
		$text = $this->visibleText->read(node: $element);
		if ($level === 1 && $this->title === '') {
			$this->title = $text;
		}

		$style = 'OR_H' . $level;
		if ($level === 1 && $this->breakBefore === true) {
			$style = 'OR_H1_Break';
			$this->breakBefore = false;
		}

		$heading = $this->content->createElementNS(self::NS_TEXT, 'text:h');
		$heading->setAttributeNS(self::NS_TEXT, 'text:style-name', $style);
		$heading->setAttributeNS(self::NS_TEXT, 'text:outline-level', (string) $level);
		$heading->appendChild($this->content->createTextNode($text));

		return $heading;
	}//end heading()

	/**
	 * A `text:p` with a style and plain text.
	 *
	 * @param string $style The paragraph style name.
	 * @param string $text  The text.
	 *
	 * @return DOMElement
	 */
	private function paragraph(string $style, string $text): DOMElement {
		$paragraph = $this->content->createElementNS(self::NS_TEXT, 'text:p');
		$paragraph->setAttributeNS(self::NS_TEXT, 'text:style-name', $style);
		$paragraph->appendChild($this->content->createTextNode($text));

		return $paragraph;
	}//end paragraph()

	/**
	 * The first class name of an element, or empty.
	 *
	 * @param DOMElement $element The element.
	 *
	 * @return string
	 */
	private function firstClass(DOMElement $element): string {
		$classes = explode(' ', trim((string) preg_replace('/\s+/', ' ', $element->getAttribute('class'))));

		return $classes[0];
	}//end firstClass()
}//end class
