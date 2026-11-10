<?php

/**
 * OpenRegister OdtTableBuilder
 *
 * Builds an ODF table from an HTML table or definition list.
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
 * One ODF table, built from an HTML `table` or `dl` element.
 *
 * Header rows (`thead`) repeat on every page. Body rows alternate between a
 * plain and a shaded cell style.
 *
 * @spec openspec/specs/export-pdf-format/spec.md
 */
class OdtTableBuilder {

	/**
	 * Constructor.
	 *
	 * @param DOMDocument $content The ODF content document to create elements in.
	 * @param DOMElement  $html    The HTML `table` or `dl`.
	 */
	public function __construct(
		private readonly DOMDocument $content,
		private readonly DOMElement $html,
	) {
	}//end __construct()

	/**
	 * The ODF table for an HTML table.
	 *
	 * @return DOMElement
	 *
	 * @spec openspec/specs/export-pdf-format/spec.md
	 */
	public function table(): DOMElement {
		$xpath = new DOMXPath($this->html->ownerDocument);
		$header = [];
		foreach ($xpath->query('./thead/tr', $this->html) as $row) {
			$header[] = $this->cellTexts(row: $row);
		}

		$body = [];
		foreach ($xpath->query('./tbody/tr | ./tr', $this->html) as $row) {
			$body[] = $this->cellTexts(row: $row);
		}

		return $this->build(header: $header, body: $body);
	}//end table()

	/**
	 * A two-column ODF table for a definition list: term, then description.
	 *
	 * @return DOMElement
	 *
	 * @spec openspec/specs/export-pdf-format/spec.md
	 */
	public function definitionTable(): DOMElement {
		$body = [];
		foreach ($this->html->childNodes as $child) {
			if (($child instanceof DOMElement) === false) {
				continue;
			}

			$text = (new VisibleText())->read(node: $child);
			if (strtolower($child->tagName) === 'dt') {
				$body[] = [$text, ''];
				continue;
			}

			if (strtolower($child->tagName) === 'dd' && $body !== []) {
				$body[count($body) - 1][1] = $text;
			}
		}

		return $this->build(header: [], body: $body);
	}//end definitionTable()

	/**
	 * Texts of the `th` and `td` cells of an HTML row.
	 *
	 * @param DOMNode $row The `tr` element.
	 *
	 * @return list<string>
	 */
	private function cellTexts(DOMNode $row): array {
		$cells = [];
		foreach ($row->childNodes as $cell) {
			if ($cell instanceof DOMElement && in_array(strtolower($cell->tagName), ['th', 'td'], true) === true) {
				$cells[] = (new VisibleText())->read(node: $cell);
			}
		}

		return $cells;
	}//end cellTexts()

	/**
	 * Assemble the ODF table.
	 *
	 * @param list<list<string>> $header Header rows.
	 * @param list<list<string>> $body   Body rows.
	 *
	 * @return DOMElement
	 */
	private function build(array $header, array $body): DOMElement {
		$columns = max(1, ...array_map('count', [...$header, ...$body, []]));

		$table = $this->element(name: 'table:table', style: 'OR_Table');
		$column = $this->content->createElementNS(HtmlToOdt::NS_TABLE, 'table:table-column');
		$column->setAttributeNS(HtmlToOdt::NS_TABLE, 'table:number-columns-repeated', (string) $columns);
		$table->appendChild($column);

		if ($header !== []) {
			$headerRows = $this->content->createElementNS(HtmlToOdt::NS_TABLE, 'table:table-header-rows');
			foreach ($header as $cells) {
				$headerRows->appendChild($this->row(cells: array_pad($cells, $columns, ''), style: 'OR_TH'));
			}

			$table->appendChild($headerRows);
		}

		$styles = ['OR_TD', 'OR_TD_Alt'];
		foreach ($body as $index => $cells) {
			$table->appendChild($this->row(cells: array_pad($cells, $columns, ''), style: $styles[$index % 2]));
		}

		return $table;
	}//end build()

	/**
	 * One table row with a cell style.
	 *
	 * @param list<string> $cells Cell texts.
	 * @param string       $style Cell style name.
	 *
	 * @return DOMElement
	 */
	private function row(array $cells, string $style): DOMElement {
		$row = $this->content->createElementNS(HtmlToOdt::NS_TABLE, 'table:table-row');
		$paragraphStyle = 'OR_Cell';
		if ($style === 'OR_TH') {
			$paragraphStyle = 'OR_HeadCell';
		}

		foreach ($cells as $text) {
			$cell = $this->element(name: 'table:table-cell', style: $style);
			$cell->setAttributeNS(HtmlToOdt::NS_OFFICE, 'office:value-type', 'string');
			$paragraph = $this->content->createElementNS(HtmlToOdt::NS_TEXT, 'text:p');
			$paragraph->setAttributeNS(HtmlToOdt::NS_TEXT, 'text:style-name', $paragraphStyle);
			$paragraph->appendChild($this->content->createTextNode($text));
			$cell->appendChild($paragraph);
			$row->appendChild($cell);
		}

		return $row;
	}//end row()

	/**
	 * A `table:` element with a style name.
	 *
	 * @param string $name  Qualified element name.
	 * @param string $style Style name.
	 *
	 * @return DOMElement
	 */
	private function element(string $name, string $style): DOMElement {
		$element = $this->content->createElementNS(HtmlToOdt::NS_TABLE, $name);
		$element->setAttributeNS(HtmlToOdt::NS_TABLE, 'table:style-name', $style);

		return $element;
	}//end element()
}//end class
