<?php

/**
 * OpenRegister FpdfTableRenderer
 *
 * Experimental export table renderer through setasign/fpdf (MIT). Off by default.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Export\Pdf
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Export\Pdf;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Draws export table sections with FPDF's cell API instead of an HTML engine.
 *
 * The sections are parsed back from their fixed HTML shape. FPDF's core
 * fonts cover Windows-1252 only, so text outside that set becomes `?`.
 */
class FpdfTableRenderer implements PdfTableRenderer {

	/**
	 * Usable page width in mm: A4 landscape (297) minus two 12 mm margins.
	 */
	private const PAGE_WIDTH = 273.0;

	/**
	 * Line height of a body cell in mm.
	 */
	private const LINE_HEIGHT = 4.0;

	/**
	 * Characters of the longest cell that still widen its column.
	 */
	private const MAX_WIDTH_WEIGHT = 40;

	/**
	 * The document being drawn.
	 *
	 * @var FpdfDocument
	 */
	private FpdfDocument $pdf;

	/**
	 * Render the sections into one A4-landscape PDF.
	 *
	 * @param string[] $sections Escaped HTML fragments, one per section.
	 *
	 * @return string Raw PDF bytes.
	 *
	 * @spec openspec/specs/export-pdf-format/spec.md
	 */
	public function render(array $sections): string {
		$this->pdf = new FpdfDocument('L', 'mm', 'A4');
		$this->pdf->SetMargins(12, 12, 12);
		$this->pdf->SetAutoPageBreak(true, 14);
		$this->pdf->AliasNbPages();

		foreach ($sections as $section) {
			$this->pdf->AddPage();
			$this->drawSection(table: $this->parseSection(html: $section));
		}

		if ($sections === []) {
			$this->pdf->AddPage();
		}

		return $this->pdf->Output('S');
	}//end render()

	/**
	 * Read the title, meta line, header and rows out of one section fragment.
	 *
	 * @param string $html One escaped section fragment.
	 *
	 * @return array{title: string, meta: string, header: list<string>, rows: list<list<string>>}
	 */
	private function parseSection(string $html): array {
		$document = new DOMDocument();
		$previous = libxml_use_internal_errors(true);
		$document->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>', (LIBXML_NONET | LIBXML_NOERROR));
		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		$xpath = new DOMXPath($document);
		$header = [];
		foreach ($xpath->query('//thead//th') as $cell) {
			$header[] = trim($cell->textContent);
		}

		$rows = [];
		foreach ($xpath->query('//tbody/tr') as $row) {
			$cells = [];
			foreach ($row->childNodes as $cell) {
				if ($cell instanceof DOMElement && $cell->tagName === 'td') {
					$cells[] = trim($cell->textContent);
				}
			}

			$rows[] = $cells;
		}

		return [
			'title'  => trim((string) $xpath->evaluate('string(//h1)')),
			'meta'   => trim((string) $xpath->evaluate('string(//p)')),
			'header' => $header,
			'rows'   => $rows,
		];
	}//end parseSection()

	/**
	 * Draw one parsed section: heading, meta line and the table.
	 *
	 * @param array{title: string, meta: string, header: list<string>, rows: list<list<string>>} $table The parsed section.
	 *
	 * @return void
	 */
	private function drawSection(array $table): void {
		$this->pdf->SetTextColor(26, 26, 26);
		$this->pdf->SetFont('Helvetica', 'B', 14);
		$this->pdf->Cell(0, 7, $this->encode(text: $table['title']), 0, 1);
		$this->pdf->SetFont('Helvetica', '', 8);
		$this->pdf->SetTextColor(85, 85, 85);
		$this->pdf->Cell(0, 5, $this->encode(text: $table['meta']), 0, 1);
		$this->pdf->Ln(2);

		if ($table['header'] === []) {
			return;
		}

		$widths = $this->columnWidths(table: $table);
		$this->drawHeader(header: $table['header'], widths: $widths);
		$this->pdf->SetFont('Helvetica', '', 9);
		foreach ($table['rows'] as $index => $row) {
			$this->drawRow(cells: $row, layout: ['widths' => $widths, 'header' => $table['header'], 'shaded' => ($index % 2) === 1]);
		}
	}//end drawSection()

	/**
	 * Column widths in mm, by the length of each column's longest text (capped).
	 *
	 * @param array{header: list<string>, rows: list<list<string>>} $table The parsed section.
	 *
	 * @return list<float>
	 */
	private function columnWidths(array $table): array {
		$weights = [];
		foreach ($table['header'] as $column => $label) {
			$longest = mb_strlen($label);
			foreach ($table['rows'] as $row) {
				$longest = max($longest, mb_strlen($row[$column] ?? ''));
			}

			$weights[] = max(4, min($longest, self::MAX_WIDTH_WEIGHT));
		}

		$total = array_sum($weights);
		return array_map(static fn (int $weight): float => self::PAGE_WIDTH * $weight / $total, $weights);
	}//end columnWidths()

	/**
	 * Draw the dark header row.
	 *
	 * @param list<string> $header Column labels.
	 * @param list<float>  $widths Column widths in mm.
	 *
	 * @return void
	 */
	private function drawHeader(array $header, array $widths): void {
		$this->pdf->SetFont('Helvetica', 'B', 9);
		$this->pdf->SetFillColor(44, 62, 80);
		$this->pdf->SetTextColor(255, 255, 255);
		$this->pdf->SetDrawColor(204, 204, 204);
		$this->drawCells(cells: $header, layout: ['widths' => $widths, 'fill' => true]);
		$this->pdf->SetTextColor(26, 26, 26);
	}//end drawHeader()

	/**
	 * Draw one body row, starting a new page with a repeated header when it does not fit.
	 *
	 * @param list<string>                                                $cells  Cell texts.
	 * @param array{widths: list<float>, header: list<string>, shaded: bool} $layout Widths, header and zebra flag.
	 *
	 * @return void
	 */
	private function drawRow(array $cells, array $layout): void {
		$height = $this->rowHeight(cells: $cells, widths: $layout['widths']);
		if ($this->pdf->GetY() + $height > $this->pdf->GetPageHeight() - 14) {
			$this->pdf->AddPage();
			$this->drawHeader(header: $layout['header'], widths: $layout['widths']);
			$this->pdf->SetFont('Helvetica', '', 9);
		}

		$this->pdf->SetFillColor(242, 242, 242);
		$this->drawCells(cells: $cells, layout: ['widths' => $layout['widths'], 'fill' => $layout['shaded']]);
	}//end drawRow()

	/**
	 * Draw a row of wrapped cells of equal height.
	 *
	 * @param list<string>                            $cells  Cell texts.
	 * @param array{widths: list<float>, fill: bool} $layout Widths and whether to fill the background.
	 *
	 * @return void
	 */
	private function drawCells(array $cells, array $layout): void {
		$height = $this->rowHeight(cells: $cells, widths: $layout['widths']);
		$top = $this->pdf->GetY();
		$left = $this->pdf->GetX();
		$style = 'D';
		if ($layout['fill'] === true) {
			$style = 'DF';
		}

		foreach ($layout['widths'] as $column => $width) {
			$this->pdf->Rect($left, $top, $width, $height, $style);
			$this->pdf->SetXY($left + 0.5, $top + 0.75);
			$this->pdf->MultiCell($width - 1, self::LINE_HEIGHT, $this->encode(text: $cells[$column] ?? ''), 0, 'L');
			$left += $width;
		}

		$this->pdf->SetXY(12, $top + $height);
	}//end drawCells()

	/**
	 * The height in mm a row needs so every cell's wrapped text fits.
	 *
	 * @param list<string> $cells  Cell texts.
	 * @param list<float>  $widths Column widths in mm.
	 *
	 * @return float
	 */
	private function rowHeight(array $cells, array $widths): float {
		$lines = 1;
		foreach ($widths as $column => $width) {
			$lines = max($lines, $this->pdf->lineCount(width: $width - 1, text: $this->encode(text: $cells[$column] ?? '')));
		}

		return ($lines * self::LINE_HEIGHT) + 1.5;
	}//end rowHeight()

	/**
	 * Convert UTF-8 to the Windows-1252 set FPDF's core fonts can draw; other characters become `?`.
	 *
	 * @param string $text UTF-8 text.
	 *
	 * @return string
	 */
	private function encode(string $text): string {
		return (string) mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
	}//end encode()
}//end class
