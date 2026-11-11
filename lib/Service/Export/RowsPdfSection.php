<?php

/**
 * OpenRegister RowsPdfSection
 *
 * The HTML table section for rows a caller already fetched, rendered by
 * ExportService::renderRowsToPdf() (portaliq#765).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Export
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

namespace OCA\OpenRegister\Service\Export;

use DateTime;

/**
 * Builds the escaped table section; reads nothing itself.
 */
class RowsPdfSection {

	/**
	 * The longest cell text, as the object export cuts it.
	 */
	private const MAX_CELL_LENGTH = 200;

	/**
	 * Build one PDF section from caller-supplied rows
	 *
	 * @param RowsTable $table The heading, the columns and the rows.
	 *
	 * @return string The section HTML, every value escaped.
	 *
	 * @spec openspec/specs/export-pdf-format/spec.md
	 */
	public function build(RowsTable $table): string {
		$columns = $table->labels();

		return $this->sectionHead(table: $table)
			. '<table><thead><tr>' . $this->headerCells(columns: $columns) . '</tr></thead><tbody>'
			. $this->bodyRows(table: $table, columns: $columns)
			. '</tbody></table></div>';
	}//end build()

	/**
	 * The section's opening: its title and when it was exported with how many rows.
	 *
	 * @param RowsTable $table The table.
	 *
	 * @return string The HTML.
	 */
	private function sectionHead(RowsTable $table): string {
		$html = '<div class="pdf-section">';
		$html .= '<h1>' . htmlspecialchars($table->title, ENT_QUOTES, 'UTF-8') . '</h1>';
		$html .= '<p class="meta">Exported: ' . htmlspecialchars((new DateTime())->format('Y-m-d H:i:s'), ENT_QUOTES, 'UTF-8')
			. ' &middot; Rows: ' . count($table->rows) . '</p>';

		return $html;
	}//end sectionHead()

	/**
	 * One header cell per column label.
	 *
	 * @param array<int|string, mixed> $columns The column labels.
	 *
	 * @return string The HTML.
	 */
	private function headerCells(array $columns): string {
		$html = '';
		foreach ($columns as $label) {
			$html .= '<th>' . htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8') . '</th>';
		}

		return $html;
	}//end headerCells()

	/**
	 * One table row per data row, one truncated cell per column.
	 *
	 * @param RowsTable                $table   The table.
	 * @param array<int|string, mixed> $columns The column labels, keyed as the rows are.
	 *
	 * @return string The HTML.
	 */
	private function bodyRows(RowsTable $table, array $columns): string {
		$html = '';
		foreach ($table->rows as $row) {
			$html .= '<tr>';
			foreach (array_keys($columns) as $key) {
				$cell = $this->cellText(value: $this->cellOf(row: $row, key: $key));
				$html .= '<td>' . htmlspecialchars($this->truncate(value: $cell), ENT_QUOTES, 'UTF-8') . '</td>';
			}

			$html .= '</tr>';
		}

		return $html;
	}//end bodyRows()

	/**
	 * The text of one caller-supplied cell
	 *
	 * @param mixed $value The cell value.
	 *
	 * @return string|null The text, or null for an empty cell.
	 */
	private function cellText(mixed $value): ?string {
		if ($value === null) {
			return null;
		}

		if (is_bool($value) === true) {
			return var_export($value, true);
		}

		if (is_array($value) === true && array_is_list($value) === true) {
			return implode(', ', array_map(fn (mixed $item): string => (string) $this->cellText(value: $item), $value));
		}

		if (is_scalar($value) === true) {
			return (string) $value;
		}

		return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}//end cellText()

	/**
	 * The value a row holds for a column key
	 *
	 * @param mixed      $row The row.
	 * @param int|string $key The column key.
	 *
	 * @return mixed The value, or null when the row has none.
	 */
	private function cellOf(mixed $row, int|string $key): mixed {
		if (is_array($row) === false) {
			return null;
		}

		return ($row[$key] ?? null);
	}//end cellOf()

	/**
	 * Cut a cell to the length the object export uses
	 *
	 * @param string|null $value The cell text.
	 *
	 * @return string The text, at most 200 characters and an ellipsis.
	 */
	private function truncate(?string $value): string {
		if ($value === null) {
			return '';
		}

		if (mb_strlen($value) > self::MAX_CELL_LENGTH) {
			return mb_substr($value, 0, self::MAX_CELL_LENGTH) . '…';
		}

		return $value;
	}//end truncate()
}//end class
