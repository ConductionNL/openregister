<?php

/**
 * BaserowCells: one Baserow export cell as the value a base row holds.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Import
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Import;

/**
 * Converts the cells of a Baserow export: link ids as strings, select option
 * ids as their labels, decimals as numbers.
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md#REQ-IPC-006
 */
final class BaserowCells {

	/**
	 * One cell's value: link ids as strings, select options by their label, numbers as numbers.
	 *
	 * @param mixed                $raw   The serialised cell.
	 * @param array<string, mixed> $field The serialised field.
	 *
	 * @return mixed
	 *
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md#REQ-IPC-006
	 */
	public function value(mixed $raw, array $field): mixed {
		if ($raw === null || $raw === '') {
			return null;
		}

		return match ((string) ($field['type'] ?? 'text')) {
			'link_row' => array_map(fn (mixed $id): string => $this->linkedId(link: $id), (array) $raw),
			'single_select' => $this->optionLabel(optionId: $raw, field: $field),
			'multiple_select' => array_map(fn (mixed $id): ?string => $this->optionLabel(optionId: $id, field: $field), (array) $raw),
			'number' => $this->number(raw: $raw),
			'rating' => (int) $raw,
			default => $raw,
		};
	}//end value()

	/**
	 * The label of a select option by its id (the export stores option ids).
	 *
	 * @param mixed                $optionId The stored option id.
	 * @param array<string, mixed> $field    The serialised select field.
	 *
	 * @return string|null The label, or the id itself when the field lists no such option.
	 */
	private function optionLabel(mixed $optionId, array $field): ?string {
		foreach (($field['select_options'] ?? []) as $option) {
			if (is_array($option) === true && (string) ($option['id'] ?? '') === (string) $optionId) {
				return (string) ($option['value'] ?? '');
			}
		}

		if (is_scalar($optionId) === false) {
			return null;
		}

		return (string) $optionId;
	}//end optionLabel()

	/**
	 * A linked row's id as a string (the export holds ids, older ones `{id, value}`).
	 *
	 * @param mixed $link The serialised link.
	 *
	 * @return string
	 */
	private function linkedId(mixed $link): string {
		if (is_array($link) === true) {
			return (string) ($link['id'] ?? '');
		}

		return (string) $link;
	}//end linkedId()

	/**
	 * A number cell as a number (the export holds decimals as strings).
	 *
	 * @param mixed $raw The serialised cell.
	 *
	 * @return mixed
	 */
	private function number(mixed $raw): mixed {
		if (is_numeric($raw) === false) {
			return $raw;
		}

		return ($raw + 0);
	}//end number()
}//end class
