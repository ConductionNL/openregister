<?php

/**
 * ConfirmedMapping: the column mapping a user confirmed for a record import.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Import\RecordImport
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/widget-record-import/specs/widget-record-import/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Import\RecordImport;

/**
 * Every column mapped or ignored, and the identifying property if one was nominated.
 *
 * @spec openspec/changes/widget-record-import/specs/widget-record-import/spec.md
 */
final class ConfirmedMapping {

	/**
	 * Constructor.
	 *
	 * @param array<string, string> $map        Column => property.
	 * @param list<string>          $ignored    The columns the user chose to ignore.
	 * @param string|null           $identifier The identifying property, or null to create only.
	 */
	public function __construct(
		public readonly array $map,
		public readonly array $ignored,
		public readonly ?string $identifier,
	) {
	}//end __construct()

	/**
	 * Whether every row will be created as a new object.
	 *
	 * @return bool True without an identifying property.
	 *
	 * @spec openspec/changes/widget-record-import/specs/widget-record-import/spec.md
	 */
	public function createsOnly(): bool {
		return $this->identifier === null;
	}//end createsOnly()

	/**
	 * What the import will do with existing objects, said plainly at confirmation.
	 *
	 * @return string The statement.
	 *
	 * @spec openspec/changes/widget-record-import/specs/widget-record-import/spec.md
	 */
	public function statement(): string {
		if ($this->identifier === null) {
			return 'No identifying column is nominated: every row will be created as a new object.';
		}

		return sprintf('A row whose "%s" matches an existing object updates it; any other row is created.', $this->identifier);
	}//end statement()

	/**
	 * A file row as object data: mapped columns renamed to their properties, ignored columns left out.
	 *
	 * An empty cell is left out too, so it does not overwrite a value with an empty string.
	 *
	 * @param array<string, mixed> $values The row, by column.
	 *
	 * @return array<string, mixed> The object data.
	 *
	 * @spec openspec/changes/widget-record-import/specs/widget-record-import/spec.md
	 */
	public function apply(array $values): array {
		$data = [];
		foreach ($this->map as $column => $property) {
			$value = $values[$column] ?? null;
			if ($value === null || $value === '') {
				continue;
			}

			$data[$property] = $value;
		}

		return $data;
	}//end apply()
}//end class
