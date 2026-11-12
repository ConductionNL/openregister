<?php

/**
 * OpenRegister Example Set Change Follower
 *
 * Keeps a held change in step with its declaration once it is resolved
 * again: lets go of the fields the declaration no longer names, writes the
 * fields it gained and, on a refresh, the held fields an import put back or
 * whose declared value moved, and records what it wrote.
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
 * @spec openspec/specs/example-sets/spec.md#requirement-a-held-change-or-preference-follows-its-declaration
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * The write step of ExampleSetChanges::follow().
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-held-change-or-preference-follows-its-declaration
 */
class ExampleSetChangeFollower {
	/**
	 * Constructor.
	 *
	 * @param ExampleSetPaths        $paths   Reads and writes values at dot paths.
	 * @param ExampleSetChangeFields $fields  Reads the fields a change touches.
	 * @param ExampleSetWrittenValue $written Compares and records written values.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ExampleSetPaths $paths,
		private readonly ExampleSetChangeFields $fields,
		private readonly ExampleSetWrittenValue $written,
	) {
	}//end __construct()

	/**
	 * A record entry without the fields and values the declaration no longer names.
	 *
	 * @param array<string, mixed> $change The declared change.
	 * @param array<string, mixed> $entry  The record entry from ExampleSetChanges::apply().
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-held-change-or-preference-follows-its-declaration
	 */
	public function heldOnly(array $change, array $entry): array {
		$entry['set']    = $this->onPaths(rows: (array) $entry['set'], paths: $this->declared(change: $change, part: 'set'));
		$entry['append'] = $this->onPaths(rows: (array) $entry['append'], paths: $this->declared(change: $change, part: 'append'));

		return $entry;
	}//end heldOnly()

	/**
	 * The paths the declaration sets that the held entry does not record yet.
	 *
	 * @param array<string, mixed> $change The declared change.
	 * @param array<string, mixed> $entry  The record entry, held fields only.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-held-change-or-preference-follows-its-declaration
	 */
	public function gained(array $change, array $entry): array {
		return array_values(array_diff($this->declared(change: $change, part: 'set'), array_map('strval', array_column($entry['set'], 'path'))));
	}//end gained()

	/**
	 * Write the fields a follow writes again or for the first time, and the entry that records them.
	 *
	 * @param ExampleSetHeldChange $held   The held change, resolved again.
	 * @param array<int, string>   $gained The paths the declaration gained.
	 *
	 * @return array{error: string, entry: array<string, mixed>, wrote: bool}
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-held-change-or-preference-follows-its-declaration
	 */
	public function write(ExampleSetHeldChange $held, array $gained): array {
		if ($held->resolved['refusal'] !== '') {
			return ['error' => $held->resolved['refusal'], 'entry' => $held->entry, 'wrote' => false];
		}

		$fresh = $this->fields->setRows(resolved: $held->resolved, paths: $gained);
		$again = array_merge($this->refreshed(held: $held), array_column($fresh, 'new', 'path'));
		if ($again === []) {
			return ['error' => '', 'entry' => $held->entry, 'wrote' => false];
		}

		return $this->writeAgain(held: $held, written: ['again' => $again, 'fresh' => $fresh]);
	}//end write()

	/**
	 * Write the fields a follow writes again or for the first time, and record them in the entry.
	 *
	 * @param ExampleSetHeldChange                                                      $held    The held change.
	 * @param array{again: array<string, mixed>, fresh: array<int, array<string, mixed>>} $written The values written
	 *                                                                                           again, by path, and the
	 *                                                                                           rows of fields written
	 *                                                                                           for the first time.
	 *
	 * @return array{error: string, entry: array<string, mixed>, wrote: bool}
	 */
	private function writeAgain(ExampleSetHeldChange $held, array $written): array {
		$entry = $held->entry;
		$error = $this->update(held: $held, again: $written['again']);
		if ($error !== '') {
			return ['error' => $error, 'entry' => $entry, 'wrote' => false];
		}

		$fresh        = $this->written->recordedRows(change: $held->change, rows: $written['fresh']);
		$entry['set'] = array_merge($this->withAgain(held: $held, again: $written['again']), $fresh);

		return ['error' => '', 'entry' => $entry, 'wrote' => true];
	}//end writeAgain()

	/**
	 * The paths a change declares in one part.
	 *
	 * @param array<string, mixed> $change The declared change.
	 * @param string               $part   `set` or `append`.
	 *
	 * @return array<int, string>
	 */
	private function declared(array $change, string $part): array {
		return array_map('strval', array_keys((array) ($change[$part] ?? [])));
	}//end declared()

	/**
	 * The record rows whose path is one of the given paths.
	 *
	 * @param array<int, array<string, mixed>> $rows  Record rows with a `path`.
	 * @param array<int, string>               $paths The paths to keep.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function onPaths(array $rows, array $paths): array {
		return array_values(array_filter($rows, static fn (array $row): bool => in_array((string) $row['path'], $paths, true)));
	}//end onPaths()

	/**
	 * Write the given values onto the object of a held change.
	 *
	 * @param ExampleSetHeldChange $held  The held change, resolved again.
	 * @param array<string, mixed> $again The values to write, by path.
	 *
	 * @return string Why the write failed; empty when it did not.
	 */
	private function update(ExampleSetHeldChange $held, array $again): string {
		$merged = $held->resolved['row'];
		foreach ($again as $path => $value) {
			$merged = $this->paths->withPath(row: $merged, set: new ExampleSetPathValue(path: $path, value: $value));
		}

		$fields = $this->fields->textFields(change: $held->change, row: $held->resolved['row']);
		$object = ['register' => $held->change['register'], 'schema' => $held->change['schema'], 'id' => $held->entry['id']];

		return $this->fields->write(object: $object, data: $this->paths->withJsonText(row: $merged, fields: $fields));
	}//end update()

	/**
	 * The held rows after a follow wrote some of them again, each value as the record keeps it.
	 *
	 * @param ExampleSetHeldChange $held  The held change.
	 * @param array<string, mixed> $again The values written again, by path.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function withAgain(ExampleSetHeldChange $held, array $again): array {
		$rows = $held->entry['set'];
		foreach ($rows as $index => $set) {
			$path = (string) $set['path'];
			if (array_key_exists($path, $again) === true) {
				$rows[$index]['new'] = $this->written->recorded(change: $held->change, row: ['path' => $path, 'new' => $again[$path]]);
			}
		}

		return $rows;
	}//end withAgain()

	/**
	 * The held fields this run writes again: those of writtenAgain() on a refresh, none otherwise.
	 *
	 * @param ExampleSetHeldChange $held The held change.
	 *
	 * @return array<string, mixed>
	 */
	private function refreshed(ExampleSetHeldChange $held): array {
		if ($held->refresh === false) {
			return [];
		}

		return $this->writtenAgain(held: $held);
	}//end refreshed()

	/**
	 * The held fields a refresh writes again, by path: back at what the change replaced, or still what it wrote while
	 * the declaration resolves to a new value.
	 *
	 * @param ExampleSetHeldChange $held The held change.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-field-that-reads-back-null-is-not-set
	 */
	private function writtenAgain(ExampleSetHeldChange $held): array {
		$again = [];
		foreach ($held->entry['set'] as $set) {
			$path = (string) $set['path'];
			if ($this->writesAgain(held: $held, set: $set) === true) {
				$again[$path] = $held->resolved['set'][$path];
			}
		}

		return $again;
	}//end writtenAgain()

	/**
	 * Whether a refresh writes one held field again.
	 *
	 * @param ExampleSetHeldChange $held The held change.
	 * @param array<string, mixed> $set  The record's entry for the field: `path`, `had`, `old` and `new`.
	 *
	 * @return bool
	 */
	private function writesAgain(ExampleSetHeldChange $held, array $set): bool {
		$path  = (string) $set['path'];
		$value = $this->paths->atPath(row: $held->resolved['row'], path: $path);
		if ($value['found'] === true && $this->written->sameAs(stored: $value['value'], value: $held->resolved['set'][$path]) === true) {
			return false;
		}

		$moved = ($value['found'] === true && $this->written->sameAs(stored: $value['value'], value: $set['new']) === true);

		return $this->written->undone(held: $value, set: $set) === true || $moved === true;
	}//end writesAgain()
}//end class
