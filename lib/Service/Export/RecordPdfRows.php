<?php

/**
 * OpenRegister RecordPdfRows
 *
 * The label and value rows of one record, for its PDF.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Export
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/export-pdf-house-style/specs/export-pdf-format/spec.md#requirement-a-single-record-exports-as-a-pdf
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Export;

/**
 * Turns one record into a two-column table: each readable field's label and value.
 *
 * A field is left out when the schema marks it not visible or when the reader
 * says the user may not read it, so a field the user cannot read never reaches
 * the PDF, not even its label. It reads nothing itself.
 *
 * @spec openspec/changes/export-pdf-house-style/specs/export-pdf-format/spec.md#requirement-a-single-record-exports-as-a-pdf
 */
final class RecordPdfRows {

	/**
	 * The columns of the table.
	 *
	 * @var array<string, string>
	 */
	public const COLUMNS = ['field' => 'Field', 'value' => 'Value'];

	/**
	 * Keys that are record metadata, not fields.
	 *
	 * @var list<string>
	 */
	private const METADATA_KEYS = ['id', 'uuid', 'uri', 'register', 'schema', 'created', 'updated'];

	/**
	 * The table of one record.
	 *
	 * @param string                              $title      The heading: the record's name or id.
	 * @param string|null                         $uuid       The record's id, the first row.
	 * @param array<string, array<string, mixed>> $properties The schema's properties, in order.
	 * @param callable(string): bool              $readable   Whether the user may read a field.
	 * @param callable(string): (string|null)     $value      A field's value as text.
	 *
	 * @return RowsTable The rows, one per readable field.
	 *
	 * @spec openspec/changes/export-pdf-house-style/specs/export-pdf-format/spec.md#requirement-a-single-record-exports-as-a-pdf
	 */
	public function table(string $title, ?string $uuid, array $properties, callable $readable, callable $value): RowsTable {
		$rows = [];
		if ($uuid !== null && $uuid !== '') {
			$rows[] = ['field' => 'ID', 'value' => $uuid];
		}

		foreach ($properties as $name => $property) {
			$name = (string) $name;
			if ($this->isShown(name: $name, property: $property) === false || $readable($name) !== true) {
				continue;
			}

			$rows[] = ['field' => $this->label(name: $name, property: $property), 'value' => $value($name)];
		}

		return new RowsTable(title: $title, columns: self::COLUMNS, rows: $rows);
	}//end table()

	/**
	 * Whether a property is a field the record page shows.
	 *
	 * @param string $name     The property key.
	 * @param mixed  $property The property definition.
	 *
	 * @return bool False for metadata keys and properties marked not visible.
	 */
	private function isShown(string $name, mixed $property): bool {
		if (in_array($name, self::METADATA_KEYS, true) === true) {
			return false;
		}

		return is_array($property) === false || ($property['visible'] ?? true) !== false;
	}//end isShown()

	/**
	 * A field's label: its title, else its key.
	 *
	 * @param string $name     The property key.
	 * @param mixed  $property The property definition.
	 *
	 * @return string The label.
	 */
	private function label(string $name, mixed $property): string {
		if (is_array($property) === false) {
			return $name;
		}

		$title = ($property['title'] ?? null);
		if (is_string($title) === true && trim($title) !== '') {
			return $title;
		}

		return $name;
	}//end label()
}//end class
