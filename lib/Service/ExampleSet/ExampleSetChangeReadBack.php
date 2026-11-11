<?php

/**
 * OpenRegister Example Set Change Read Back
 *
 * Reads a made change back from the object it changed: which of its paths
 * the object no longer holds (the proof), and putting back what it replaced
 * while the object still holds what it wrote (remove).
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
 * @spec openspec/specs/example-sets/spec.md#requirement-remove-deletes-exactly-what-the-install-made-req-exs-007
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use OCA\OpenRegister\Kernel\Contracts\ObjectRef;

/**
 * Proves and reverts made changes.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-remove-deletes-exactly-what-the-install-made-req-exs-007
 */
class ExampleSetChangeReadBack {
	/**
	 * Constructor.
	 *
	 * @param ExampleSetStore        $store   Register access.
	 * @param ExampleSetPaths        $paths   Reads and writes values at dot paths.
	 * @param ExampleSetChangeFields $fields  Reads the fields a change touches.
	 * @param ExampleSetWrittenValue $written Compares written values.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ExampleSetStore $store,
		private readonly ExampleSetPaths $paths,
		private readonly ExampleSetChangeFields $fields,
		private readonly ExampleSetWrittenValue $written,
	) {
	}//end __construct()

	/**
	 * The paths of a made change that the stored object does not hold.
	 *
	 * @param array<string, mixed> $entry A record entry from ExampleSetChanges::apply().
	 *
	 * @return array<int, string> Empty when the change is on the instance; `(gone)` when the object is.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-an-install-proves-what-arrived-req-exs-006
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-field-a-schema-keeps-as-json-text-is-sent-as-text-req-exs-012
	 */
	public function lost(array $entry): array {
		$row = $this->store->row(ref: new ObjectRef(register: (string) $entry['register'], schema: (string) $entry['schema'], id: (string) $entry['id']));
		if ($row === null) {
			return ['(gone)'];
		}

		$row = $this->paths->withJsonDecoded(row: $row, fields: $this->fields->jsonFields(change: $entry));

		return array_merge($this->lostSet(row: $row, sets: $entry['set']), $this->lostAppended(row: $row, appended: $entry['append']));
	}//end lost()

	/**
	 * The paths of set fields a row no longer holds as the change wrote them.
	 *
	 * @param array<string, mixed>             $row  The row, JSON text decoded.
	 * @param array<int, array<string, mixed>> $sets The record's set rows.
	 *
	 * @return array<int, string>
	 */
	private function lostSet(array $row, array $sets): array {
		$lost = [];
		foreach ($sets as $set) {
			if ($this->written->stillHolds(held: $this->paths->atPath(row: $row, path: $set['path']), written: $set['new']) === false) {
				$lost[] = $set['path'];
			}
		}

		return $lost;
	}//end lostSet()

	/**
	 * The list paths of appended values a row no longer holds.
	 *
	 * @param array<string, mixed>             $row      The row, JSON text decoded.
	 * @param array<int, array<string, mixed>> $appended The appended values, each with its list `path` and `value`.
	 *
	 * @return array<int, string>
	 */
	private function lostAppended(array $row, array $appended): array {
		$lost = [];
		foreach ($appended as $append) {
			if ($this->fields->inList(row: $row, append: $append) === false) {
				$lost[] = $append['path'];
			}
		}

		return $lost;
	}//end lostAppended()

	/**
	 * Put back what a change replaced and take out what it appended, while the object still holds what the change wrote.
	 *
	 * A path whose value is no longer the one the change wrote was edited
	 * since: it is left as it is now and named, as app config and user
	 * preferences are. A value the change appended is taken out of its list
	 * wherever the list is now, because that undoes exactly the change.
	 *
	 * @param array<string, mixed> $entry A record entry from ExampleSetChanges::apply().
	 *
	 * @return array{ok: bool, left: array<int, string>} Whether the object no longer carries the change (or is gone; false
	 *                                                    too when the object could not be read),
	 *                                                    and the paths left because somebody changed them.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-remove-deletes-exactly-what-the-install-made-req-exs-007
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-field-a-schema-keeps-as-json-text-is-sent-as-text-req-exs-012
	 */
	public function revert(array $entry): array {
		// `read()` tells an object that is gone (nothing left to undo) from a read that failed (nothing is known,
		// so nothing is reported as undone and the record keeps the change).
		$read = $this->store->readRow(
			ref: new ObjectRef(register: (string) $entry['register'], schema: (string) $entry['schema'], id: (string) $entry['id'])
		);
		if ($read['row'] === null) {
			return ['ok' => ($read['error'] === ''), 'left' => []];
		}

		$fields = $this->fields->textFields(change: $entry, row: $read['row']);
		$undone = $this->undoSets(row: $this->paths->withJsonDecoded(row: $read['row'], fields: $fields), sets: (array) $entry['set']);
		$row    = $this->paths->withJsonText(row: $this->withoutAppended(row: $undone['row'], appended: (array) $entry['append']), fields: $fields);
		$why    = $this->fields->write(object: $entry, data: $row);

		return ['ok' => ($why === ''), 'left' => $undone['left']];
	}//end revert()

	/**
	 * The row with every set field that still holds what the change wrote put back, last first.
	 *
	 * @param array<string, mixed>             $row  The row, JSON text decoded.
	 * @param array<int, array<string, mixed>> $sets The record's set rows.
	 *
	 * @return array{row: array<string, mixed>, left: array<int, string>} The row, and the paths left because somebody
	 *                                                                     changed them.
	 */
	private function undoSets(array $row, array $sets): array {
		$left = [];
		foreach (array_reverse($sets) as $set) {
			if ($this->written->stillHolds(held: $this->paths->atPath(row: $row, path: $set['path']), written: $set['new']) === false) {
				$left[] = (string) $set['path'];
				continue;
			}

			$row = $this->undoSet(row: $row, set: $set);
		}

		return ['row' => $row, 'left' => $left];
	}//end undoSets()

	/**
	 * The row with one set field put back: its old value, or gone when the object did not have it.
	 *
	 * @param array<string, mixed> $row The row.
	 * @param array<string, mixed> $set The record's set row: `path`, `had`, `old` and `from`.
	 *
	 * @return array<string, mixed>
	 */
	private function undoSet(array $row, array $set): array {
		$row = $this->paths->withoutPath(row: $row, path: $set['path']);
		if ($set['had'] === true) {
			return $this->paths->withPath(row: $row, set: new ExampleSetPathValue(path: $set['path'], value: $set['old']));
		}

		// A map the change made on the way to its path goes again when nothing else filled it since.
		$from = (string) ($set['from'] ?? $set['path']);
		if ($from !== $set['path'] && $this->paths->atPath(row: $row, path: $from)['value'] === []) {
			return $this->paths->withoutPath(row: $row, path: $from);
		}

		return $row;
	}//end undoSet()

	/**
	 * The row without the values a change appended, wherever their lists are now.
	 *
	 * @param array<string, mixed>             $row      The row.
	 * @param array<int, array<string, mixed>> $appended The appended values, each with its list `path` and `value`.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function withoutAppended(array $row, array $appended): array {
		foreach ($appended as $append) {
			$list = (array) ($this->paths->atPath(row: $row, path: $append['path'])['value'] ?? []);
			$kept = array_values(array_filter($list, static fn ($item): bool => $item !== $append['value']));
			$row  = $this->paths->withPath(row: $row, set: new ExampleSetPathValue(path: $append['path'], value: $kept));
		}

		return $row;
	}//end withoutAppended()
}//end class
