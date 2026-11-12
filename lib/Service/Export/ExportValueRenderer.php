<?php

/**
 * ExportValueRenderer — one value, in the mode the profile chose.
 *
 * Split out of `ExportProfileWriter` because the two answer different
 * questions. The writer decides which fields go out, in which order, and what
 * the file looks like around them. This decides what a single cell says, which
 * is where the stored and rendered modes actually differ: a relation resolved
 * to a name, a code shown as its administered label, a timestamp a person can
 * read.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Export
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Export;

use DateTime;
use Throwable;

/**
 * Renders one exported value, stored or as a surface would show it.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Export
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
class ExportValueRenderer {

	/**
	 * A value written as the object holds it.
	 *
	 * Nothing is resolved and nothing is reformatted. A structure is JSON
	 * encoded rather than flattened, because flattening it would be a rendering
	 * decision, and this mode makes none.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return string The cell.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function stored($value): string {
		if ($value === null) {
			return '';
		}

		if (is_bool($value) === true) {
			if ($value === true) {
				return 'true';
			}

			return 'false';
		}

		if (is_array($value) === true || is_object($value) === true) {
			return (string)json_encode($value);
		}

		return (string)$value;
	}//end stored()

	/**
	 * A value written as a surface would show it.
	 *
	 * @param mixed           $value  The raw value.
	 * @param ColumnRendering $column The column's schema property and the uuid to name map.
	 *
	 * @return string The cell.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function rendered($value, ColumnRendering $column): string {
		if ($value === null) {
			return '';
		}

		if (is_bool($value) === true) {
			return $this->yesOrNo(value: $value);
		}

		if (is_array($value) === true) {
			return $this->renderedList(values: $value, column: $column);
		}

		if (is_object($value) === true) {
			return (string)json_encode($value);
		}

		return $this->renderedScalar(scalar: (string)$value, column: $column);
	}//end rendered()

	/**
	 * A boolean as a surface shows it.
	 *
	 * @param bool $value The value.
	 *
	 * @return string `yes` or `no`.
	 */
	private function yesOrNo(bool $value): string {
		if ($value === true) {
			return 'yes';
		}

		return 'no';
	}//end yesOrNo()

	/**
	 * A list, each item rendered and joined with semicolons.
	 *
	 * @param array           $values The items.
	 * @param ColumnRendering $column The column's schema property and the uuid to name map.
	 *
	 * @return string The cell.
	 */
	private function renderedList(array $values, ColumnRendering $column): string {
		$parts = [];
		foreach ($values as $item) {
			$parts[] = $this->rendered(value: $item, column: $column);
		}

		return implode('; ', $parts);
	}//end renderedList()

	/**
	 * A scalar as its resolved name, its enum label, or its written-out date or text.
	 *
	 * @param string          $scalar The value as text.
	 * @param ColumnRendering $column The column's schema property and the uuid to name map.
	 *
	 * @return string The cell.
	 */
	private function renderedScalar(string $scalar, ColumnRendering $column): string {
		if (isset($column->names[$scalar]) === true) {
			return $column->names[$scalar];
		}

		$label = $this->enumLabel(value: $scalar, property: $column->property);
		if ($label !== null) {
			return $label;
		}

		return $this->formatDate(value: $scalar);
	}//end renderedScalar()

	/**
	 * The administered label for a code list value, when the schema names one.
	 *
	 * JSON Schema has no label field of its own, so the convention every editor
	 * settled on is used: `enumNames` parallel to `enum`. A schema that declares
	 * an enum and no names has no labels to render, and the code is the label.
	 *
	 * @param string               $value    The stored value.
	 * @param array<string, mixed> $property The schema property.
	 *
	 * @return string|null The label, or null when there is none.
	 */
	private function enumLabel(string $value, array $property): ?string {
		$enum = ($property['enum'] ?? null);
		$labels = ($property['enumNames'] ?? ($property['enumLabels'] ?? null));
		if (is_array($enum) === false || is_array($labels) === false) {
			return null;
		}

		$index = array_search($value, $enum, true);
		if ($index === false) {
			return null;
		}

		$label = ($labels[$index] ?? null);
		if (is_string($label) === false) {
			return null;
		}

		return $label;
	}//end enumLabel()

	/**
	 * An ISO 8601 timestamp as a surface would show it, or the value unchanged.
	 *
	 * @param string $value The stored value.
	 *
	 * @return string The cell.
	 */
	private function formatDate(string $value): string {
		if (str_contains(haystack: $value, needle: 'T') === false) {
			return $value;
		}

		try {
			return (new DateTime($value))->format('Y-m-d H:i:s');
		} catch (Throwable $e) {
			return $value;
		}
	}//end formatDate()
}//end class
