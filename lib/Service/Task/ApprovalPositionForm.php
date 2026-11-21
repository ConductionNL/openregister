<?php

/**
 * OpenRegister ApprovalPositionForm
 *
 * The form an approval chain position declares, in the record shape a task
 * carries under `metadata.form` (approval-position-task-form, D-1).
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Task
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/approval-position-task-form/specs/flow-approval-consolidation/spec.md#requirement-an-approval-position-may-declare-the-form-its-task-presents
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <dev@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

/**
 * Spells a position's `form` as a task form record.
 *
 * A chain author writes what a flow user-task step declares:
 * `{kind: "fields", fields: [{name, required, order}], requireChecklist}` or
 * `{kind: "external", formId}`. The record a task carries names a field
 * `field`, lists the fields in their declared order and names the subject
 * schema. Only the spelling changes here; {@see TaskFormReader} parses and
 * judges the record, so a chain and a flow cannot drift into two dialects.
 *
 * @spec openspec/changes/approval-position-task-form/specs/flow-approval-consolidation/spec.md#requirement-an-approval-position-may-declare-the-form-its-task-presents
 */
final class ApprovalPositionForm {

	/**
	 * The record of a position's form, or null when it declares none.
	 *
	 * A form without a kind is not carried: the schema save refuses it
	 * ({@see ApprovalPositionFormGuard}), and a chain saved before that check
	 * existed keeps opening tasks without a form, as before.
	 *
	 * @param mixed $declared The `form` of an `approvers` entry.
	 * @param int   $schemaId The schema the chain is declared on, the form's subject.
	 *
	 * @return array<string, mixed>|null The record, or null.
	 *
	 * @spec openspec/changes/approval-position-task-form/specs/flow-approval-consolidation/spec.md#requirement-an-approval-position-may-declare-the-form-its-task-presents
	 */
	public static function record(mixed $declared, int $schemaId): ?array {
		$record = self::declaration(declared: $declared, schemaId: $schemaId);
		if ($record === null || $record['kind'] === null) {
			return null;
		}

		return $record;
	}//end record()

	/**
	 * The record a declared form spells, kind or not; null when nothing is declared.
	 *
	 * @param mixed $declared The `form` of an `approvers` entry.
	 * @param int   $schemaId The schema the chain is declared on, the form's subject.
	 *
	 * @return array{kind: string|null, schema: string, action: string|null, fields: mixed, formId: mixed, requireChecklist: bool}|null The record.
	 *
	 * @spec openspec/changes/approval-position-task-form/specs/flow-approval-consolidation/spec.md#requirement-an-approval-position-may-declare-the-form-its-task-presents
	 */
	public static function declaration(mixed $declared, int $schemaId): ?array {
		if (is_array($declared) === false || $declared === []) {
			return null;
		}

		$schema = trim((string)($declared['schema'] ?? ''));
		if ($schema === '') {
			$schema = (string)$schemaId;
		}

		return [
			'kind' => self::nullIfBlank(value: ($declared['kind'] ?? null)),
			'schema' => $schema,
			'action' => self::nullIfBlank(value: ($declared['action'] ?? null)),
			'fields' => self::fields(declared: ($declared['fields'] ?? null)),
			'formId' => ($declared['formId'] ?? null),
			'requireChecklist' => filter_var(($declared['requireChecklist'] ?? false), FILTER_VALIDATE_BOOLEAN),
		];
	}//end declaration()

	/**
	 * A declared string, trimmed; null when blank.
	 *
	 * @param mixed $value The declared value.
	 *
	 * @return string|null The value, or null.
	 */
	private static function nullIfBlank(mixed $value): ?string {
		$value = trim((string)$value);
		if ($value === '') {
			return null;
		}

		return $value;
	}//end nullIfBlank()

	/**
	 * The field entries with `name` spelled `field`, in their declared order.
	 *
	 * An entry already spelled `field`, a plain name and anything malformed
	 * pass through unchanged, for the reader to accept or refuse.
	 *
	 * @param mixed $declared The declared field list.
	 *
	 * @return mixed The list in record spelling, or the value as given when it is not a list.
	 */
	private static function fields(mixed $declared): mixed {
		if (is_array($declared) === false) {
			return $declared;
		}

		$entries = [];
		foreach (array_values($declared) as $entry) {
			$order = self::orderOf(entry: $entry);
			if (is_array($entry) === true && array_key_exists('field', $entry) === false && array_key_exists('name', $entry) === true) {
				$entry = ['field' => $entry['name']] + array_diff_key($entry, ['name' => true, 'order' => true]);
			}

			$entries[] = ['entry' => $entry, 'order' => $order];
		}

		// PHP's sort is stable: equal orders keep the declared sequence.
		usort($entries, static fn (array $one, array $two): int => ($one['order'] <=> $two['order']));

		return array_map(static fn (array $one): mixed => $one['entry'], $entries);
	}//end fields()

	/**
	 * Where an entry sorts: its declared `order`, else after every ordered entry.
	 *
	 * @param mixed $entry The declared entry.
	 *
	 * @return float The sort key.
	 */
	private static function orderOf(mixed $entry): float {
		if (is_array($entry) === true && is_numeric($entry['order'] ?? null) === true) {
			return (float)$entry['order'];
		}

		return INF;
	}//end orderOf()
}//end class
