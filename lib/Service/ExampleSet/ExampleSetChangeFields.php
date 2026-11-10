<?php

/**
 * OpenRegister Example Set Change Fields
 *
 * The fields of a change and its object that ExampleSetChanges reads in
 * more than one step: which fields are read as a structure and written as
 * text, which declared fields do not hold their value yet, and whether a
 * list holds an appended value.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-field-a-schema-keeps-as-json-text-is-sent-as-text-req-exs-012
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use OCA\OpenRegister\Kernel\Contracts\ObjectRef;

/**
 * Reads the fields a change touches.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-field-a-schema-keeps-as-json-text-is-sent-as-text-req-exs-012
 */
class ExampleSetChangeFields {
	/**
	 * Constructor.
	 *
	 * @param ExampleSetStore $store Register access.
	 * @param ExampleSetPaths $paths Reads values at dot paths.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ExampleSetStore $store,
		private readonly ExampleSetPaths $paths,
	) {
	}//end __construct()

	/**
	 * Every field of an object that is read as a structure and written as text: those the change keeps as JSON text,
	 * and the text properties the store read back as a structure.
	 *
	 * @param array<string, mixed> $change The change, or a record entry from ExampleSetChanges::apply().
	 * @param array<string, mixed> $row    The object as read.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-field-a-schema-keeps-as-json-text-is-sent-as-text-req-exs-012
	 */
	public function textFields(array $change, array $row): array {
		return array_values(array_unique(array_merge($this->jsonFields(change: $change), $this->structuredText(change: $change, row: $row))));
	}//end textFields()

	/**
	 * The fields a change or a record entry keeps as JSON text.
	 *
	 * @param array<string, mixed> $change The change, or a record entry from ExampleSetChanges::apply().
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-field-a-schema-keeps-as-json-text-is-sent-as-text-req-exs-012
	 */
	public function jsonFields(array $change): array {
		return array_values(array_unique(array_filter((array) ($change['jsonFields'] ?? []), 'is_string')));
	}//end jsonFields()

	/**
	 * The record rows of the given paths of a resolved change that do not hold their resolved value yet.
	 *
	 * @param array<string, mixed>   $resolved From ExampleSetChanges::resolve(), without a refusal.
	 * @param array<int, int|string> $paths    The paths.
	 *
	 * @return array<int, array{path: string, had: bool, old: mixed, new: mixed, from: string}>
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-field-a-schema-keeps-as-json-text-is-sent-as-text-req-exs-012
	 */
	public function setRows(array $resolved, array $paths): array {
		$rows = [];
		foreach ($paths as $path) {
			$row = $this->setRow(resolved: $resolved, path: (string) $path);
			if ($row !== null) {
				$rows[] = $row;
			}
		}

		return $rows;
	}//end setRows()

	/**
	 * Write data onto the object a change or a record entry names.
	 *
	 * @param array<string, mixed> $object The object's `register`, `schema` and `id`.
	 * @param array<string, mixed> $data   The object as it is sent.
	 *
	 * @return string Why the write failed; empty when it did not.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-may-change-an-object-it-did-not-create-and-undo-exactly-that-req-exs-011
	 */
	public function write(array $object, array $data): string {
		$ref = new ObjectRef(register: (string) $object['register'], schema: (string) $object['schema'], id: (string) $object['id']);

		return $this->store->update(ref: $ref, data: $data);
	}//end write()

	/**
	 * The record row of one path of a resolved change, or null when the object holds the resolved value already.
	 *
	 * @param array<string, mixed> $resolved From ExampleSetChanges::resolve(), without a refusal.
	 * @param string               $path     The path.
	 *
	 * @return array{path: string, had: bool, old: mixed, new: mixed, from: string}|null
	 */
	private function setRow(array $resolved, string $path): ?array {
		$want = $resolved['set'][$path];
		$held = $this->paths->atPath(row: $resolved['row'], path: $path);
		if ($held['found'] === true && $held['value'] === $want) {
			return null;
		}

		$from = $this->paths->firstMissing(row: $resolved['row'], path: $path);

		return ['path' => $path, 'had' => $held['found'], 'old' => $held['value'], 'new' => $want, 'from' => $from];
	}//end setRow()

	/**
	 * Whether a row's list at a path holds an appended value.
	 *
	 * @param array<string, mixed> $row    The row.
	 * @param array<string, mixed> $append The list `path` and the `value`.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-field-a-schema-keeps-as-json-text-is-sent-as-text-req-exs-012
	 */
	public function inList(array $row, array $append): bool {
		return in_array($append['value'], (array) ($this->paths->atPath(row: $row, path: $append['path'])['value'] ?? []), true);
	}//end inList()

	/**
	 * The text properties of the changed object's schema that the store read back as a structure.
	 *
	 * @param array<string, mixed> $change The change.
	 * @param array<string, mixed> $row    The object as read.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-change-sends-a-text-property-as-text
	 */
	private function structuredText(array $change, array $row): array {
		return array_values(
			array_filter(
				$this->store->textProperties(register: (string) $change['register'], schema: (string) $change['schema']),
				static fn (string $field): bool => is_array($row[$field] ?? null) === true
			)
		);
	}//end structuredText()
}//end class
