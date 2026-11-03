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
	 * @param string                           $title   The heading.
	 * @param array<int|string, string>        $columns Column keys to labels, or a list of keys.
	 * @param array<int, array<string, mixed>> $rows    The rows.
	 *
	 * @return string The section HTML, every value escaped.
	 *
	 * @spec openspec/specs/export-pdf-format/spec.md
	 */
	public function build(string $title, array $columns, array $rows): string {
		if (array_is_list($columns) === true) {
			$columns = array_combine(array_map('strval', $columns), array_map('strval', $columns));
		}

		$html = '<div class="pdf-section">';
		$html .= '<h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>';
		$html .= '<p class="meta">Exported: ' . htmlspecialchars((new DateTime())->format('Y-m-d H:i:s'), ENT_QUOTES, 'UTF-8')
			. ' &middot; Rows: ' . count($rows) . '</p>';
		$html .= '<table><thead><tr>';
		foreach ($columns as $label) {
			$html .= '<th>' . htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8') . '</th>';
		}

		$html .= '</tr></thead><tbody>';
		foreach ($rows as $row) {
			$html .= '<tr>';
			foreach (array_keys($columns) as $key) {
				$cell = $this->cellText(value: $this->cellOf(row: $row, key: $key));
				$html .= '<td>' . htmlspecialchars($this->truncate(value: $cell), ENT_QUOTES, 'UTF-8') . '</td>';
			}

			$html .= '</tr>';
		}

		return $html . '</tbody></table></div>';
	}//end build()

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
