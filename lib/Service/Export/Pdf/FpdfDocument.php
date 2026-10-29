<?php

/**
 * OpenRegister FpdfDocument
 *
 * FPDF page with the export footer (page n of total).
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

use FPDF;

/**
 * An FPDF document that prints "Page n / total" in the bottom-right corner.
 *
 * @SuppressWarnings(PHPMD.CamelCaseMethodName) FPDF's hook methods are named by the library.
 */
class FpdfDocument extends FPDF {

	/**
	 * Draw the page number; FPDF calls this at the end of every page.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/export-pdf-format/spec.md
	 */
	public function Footer(): void {
		$this->SetY(y: -10);
		$this->SetFont(family: 'Helvetica', style: '', size: 8);
		$this->SetTextColor(r: 77, g: 77, b: 77);
		$this->Cell(w: 0, h: 5, txt: 'Page ' . $this->PageNo() . ' / {nb}', border: 0, ln: 0, align: 'R');
	}//end Footer()

	/**
	 * The number of lines MultiCell() will use for the text at the given width.
	 *
	 * Follows MultiCell()'s own wrapping: break at the last space that fits,
	 * or inside a word longer than the line.
	 *
	 * @param float  $width Cell width in mm, as passed to MultiCell().
	 * @param string $text  Text in the current font's encoding.
	 *
	 * @return int
	 *
	 * @spec openspec/specs/export-pdf-format/spec.md
	 */
	public function lineCount(float $width, string $text): int {
		// phpcs:disable Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps -- FPDF's own property names.
		$widths = $this->CurrentFont['cw'];
		$max = ($width - (2 * $this->cMargin)) * 1000 / $this->FontSize;
		// phpcs:enable Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps
		$text = rtrim(str_replace("\r", '', $text), "\n");
		$length = strlen($text);
		$lines = 1;
		$space = -1;
		$start = 0;
		$lineWidth = 0;
		$index = 0;
		while ($index < $length) {
			$char = $text[$index];
			if ($char === "\n" || ($lineWidth + $widths[$char]) > $max) {
				$lines++;
				$index = $this->nextLineStart(char: $char, positions: [$index, $space, $start]);
				$space = -1;
				$start = $index;
				$lineWidth = 0;
				continue;
			}

			if ($char === ' ') {
				$space = $index;
			}

			$lineWidth += $widths[$char];
			$index++;
		}//end while

		return $lines;
	}//end lineCount()

	/**
	 * Where the next line starts after a break.
	 *
	 * @param string    $char      The character that caused the break.
	 * @param list<int> $positions Current index, last space (-1 for none) and line start.
	 *
	 * @return int
	 */
	private function nextLineStart(string $char, array $positions): int {
		[$index, $space, $start] = $positions;
		if ($char === "\n") {
			return $index + 1;
		}

		if ($space !== -1) {
			return $space + 1;
		}

		if ($index === $start) {
			return $index + 1;
		}

		return $index;
	}//end nextLineStart()
}//end class
