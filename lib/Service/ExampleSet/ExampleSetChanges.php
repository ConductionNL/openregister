<?php

/**
 * OpenRegister Example Set Changes
 *
 * A change sets or appends values on an object the set did not create, found
 * by a business key: for example the sign-in mode an example resident needs
 * on a portal that a site install made. Only what differs is written, the old
 * value of every touched path is recorded, and remove puts back exactly that,
 * so whatever else changed on the object since stays as it is.
 *
 * Paths are dot-separated (`authentication.modes`). `set` replaces the value
 * at a path; `append` adds one value to the list at a path when it is absent.
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
 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-set-may-change-an-object-it-did-not-create-and-undo-exactly-that-req-exs-011
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * Applies, proves and reverts changes to existing objects.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) One change lifecycle: resolve, apply, refresh, prove and revert,
 *                                                   each reading JSON-text fields the same way.
 *
 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-set-may-change-an-object-it-did-not-create-and-undo-exactly-that-req-exs-011
 */
class ExampleSetChanges {
	/**
	 * Constructor.
	 *
	 * @param ExampleSetStore  $store  Register access.
	 * @param ExampleSetValues $values Fills placeholders.
	 * @param ExampleSetPaths  $paths  Reads and writes values at dot paths.
	 * @param ExampleSetProof  $proof  Tells whether a value read back is the value written.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ExampleSetStore $store,
		private readonly ExampleSetValues $values,
		private readonly ExampleSetPaths $paths=new ExampleSetPaths(),
		private readonly ExampleSetProof $proof=new ExampleSetProof(),
	) {
	}//end __construct()

	/**
	 * Resolve a change and find its object, without writing.
	 *
	 * @param array<string, mixed> $change  The declared change.
	 * @param array<string, mixed> $context `cast`, `objects` and `today`, as for ExampleSetValues::fill().
	 *
	 * @return array{refusal: string, row: array<string, mixed>, merged: array<string, mixed>, set: array<string, mixed>,
	 *               append: array<string, mixed>} The object, the object as the change makes it, and the filled
	 *               `set` and `append`; or why the change cannot be made.
	 *
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-set-may-change-an-object-it-did-not-create-and-undo-exactly-that-req-exs-011
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-field-a-schema-keeps-as-json-text-is-sent-as-text-req-exs-012
	 */
	public function resolve(array $change, array $context): array {
		$none = ['refusal' => '', 'row' => [], 'merged' => [], 'set' => [], 'append' => []];
		$gaps = [];
		$fill = (array) $this->values->fill(
			value: ['where' => ($change['where'] ?? []), 'set' => ($change['set'] ?? []), 'append' => ($change['append'] ?? [])],
			context: $context,
			gaps: $gaps
		);
		if ($gaps !== []) {
			return array_merge($none, ['refusal' => 'cannot resolve '.implode(', ', array_unique($gaps))]);
		}

		$found = $this->target(change: $change, where: (array) $fill['where'], objects: (array) ($context['objects'] ?? []));
		$row   = $found[0] ?? null;
		if (count($found) > 1) {
			return array_merge(
				$none,
				[
					'refusal' => count($found).' '.$change['register'].' '.$change['schema'].' rows match '
						.json_encode($fill['where'], JSON_UNESCAPED_UNICODE).'; a change needs exactly one to change',
				]
			);
		}

		if ($row === null && is_string($change['object'] ?? null) === true) {
			return array_merge($none, ['refusal' => 'object "'.$change['object'].'" is not written earlier in this fragment']);
		}

		if ($row === null) {
			return array_merge(
				$none,
				['refusal' => 'no '.$change['register'].' '.$change['schema'].' with '.json_encode($fill['where'], JSON_UNESCAPED_UNICODE).' to change']
			);
		}

		// A field the schema keeps as JSON text is changed as its structure and written as text again; so is a text
		// property the store reads back as a structure, which the change does not have to name.
		$fields = array_values(array_unique(array_merge($this->jsonFields(change: $change), $this->structuredText(change: $change, row: $row))));
		$row    = $this->paths->withJsonDecoded(row: $row, fields: $fields);
		$merged = $row;
		foreach ((array) $fill['set'] as $path => $value) {
			$merged = $this->paths->withPath(row: $merged, path: (string) $path, value: $value);
		}

		foreach ((array) $fill['append'] as $path => $value) {
			$list = (array) ($this->paths->atPath(row: $merged, path: (string) $path)['value'] ?? []);
			if (in_array($value, $list, true) === false) {
				$list[] = $value;
			}

			$merged = $this->paths->withPath(row: $merged, path: (string) $path, value: array_values($list));
		}

		return [
			'refusal' => '',
			'row'     => $row,
			'merged'  => $this->paths->withJsonText(row: $merged, fields: $fields),
			'set'     => (array) $fill['set'],
			'append'  => (array) $fill['append'],
		];
	}//end resolve()

	/**
	 * Make a resolved change, writing only what differs.
	 *
	 * @param array<string, mixed> $change   The declared change.
	 * @param array<string, mixed> $resolved From resolve(), without a refusal.
	 *
	 * @return array{error: string, entry: array<string, mixed>|null} The record entry (null when nothing had to change),
	 *                                                                 or why the write failed.
	 *
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-set-may-change-an-object-it-did-not-create-and-undo-exactly-that-req-exs-011
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-field-a-schema-keeps-as-json-text-is-sent-as-text-req-exs-012
	 */
	public function apply(array $change, array $resolved): array {
		$row   = $resolved['row'];
		$entry = ['register' => $change['register'], 'schema' => $change['schema'], 'id' => $this->store->idOf(row: $row), 'set' => [], 'append' => []];
		// The fields kept as JSON text, so the proof and remove read them the same way; none: no key.
		$entry += array_filter(['jsonFields' => $this->jsonFields(change: $change)]);

		foreach ($resolved['set'] as $path => $value) {
			$held = $this->paths->atPath(row: $row, path: (string) $path);
			if ($held['found'] === true && $held['value'] === $value) {
				continue;
			}

			$entry['set'][] = [
				'path' => (string) $path,
				'had'  => $held['found'],
				'old'  => $held['value'],
				'new'  => $value,
				'from' => $this->paths->firstMissing(row: $row, path: (string) $path),
			];
		}

		foreach ($resolved['append'] as $path => $value) {
			if (in_array($value, (array) ($this->paths->atPath(row: $row, path: (string) $path)['value'] ?? []), true) === false) {
				$entry['append'][] = ['path' => (string) $path, 'value' => $value];
			}
		}

		if ($entry['set'] === [] && $entry['append'] === []) {
			return ['error' => '', 'entry' => null];
		}

		$error = $this->store->update(
			register: (string) $change['register'],
			schema: (string) $change['schema'],
			id: $entry['id'],
			data: $resolved['merged']
		);
		if ($error !== '') {
			return ['error' => $error, 'entry' => null];
		}

		return ['error' => '', 'entry' => $entry];
	}//end apply()

	/**
	 * What the install last wrote into the reference fields of a kept object.
	 *
	 * The value a refresh wrote when there was one (the newest `new` of the
	 * `#references` change), else the value the object was created with.
	 *
	 * @param array<string, mixed> $record The record.
	 * @param string               $name   The record key of the refresh change, `<app>/<key>#references`.
	 * @param string               $key    The record key of the object, `<app>/<key>`.
	 *
	 * @return array<string, mixed> The value by field; a field of a record that predates this is missing.
	 *
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-kept-object-follows-the-objects-it-references-req-exs-023
	 */
	public function writtenReferences(array $record, string $name, string $key): array {
		$written = (array) ($record['objects'][$key]['refs'] ?? []);
		foreach ((array) ($record['changes'][$name]['set'] ?? []) as $set) {
			$written[(string) $set['path']] = $set['new'];
		}

		return $written;
	}//end writtenReferences()


	/**
	 * Keep a held change in step with its declaration.
	 *
	 * The entry lets go of every field the declaration no longer sets (a job may own it now), so the proof and remove
	 * leave it alone. A field the declaration gained is written on any install and recorded like the others. On a
	 * refresh a declared field is written again when it holds what the change replaced (an import put it back) or
	 * what the change wrote while the declaration now resolves to something else; a field with a third value is
	 * somebody's edit and is left.
	 *
	 * @param array<string, mixed> $change  The declared change.
	 * @param array<string, mixed> $entry   The record entry from apply().
	 * @param array<string, mixed> $context The context placeholders draw on.
	 * @param bool                 $refresh Whether this run refreshes.
	 *
	 * @return array{error: string, entry: array<string, mixed>, wrote: bool} The entry to hold, whether a field was
	 *                                                                         written, or why the write failed.
	 *
	 * @spec openspec/changes/example-set-held-entries-follow-the-declaration/specs/example-sets/spec.md#requirement-a-held-change-or-preference-follows-its-declaration
	 */
	public function follow(array $change, array $entry, array $context, bool $refresh): array {
		$declared        = array_map('strval', array_keys((array) ($change['set'] ?? [])));
		$appended        = array_map('strval', array_keys((array) ($change['append'] ?? [])));
		$entry['set']    = $this->onPaths(rows: (array) $entry['set'], paths: $declared);
		$entry['append'] = $this->onPaths(rows: (array) $entry['append'], paths: $appended);
		$gained          = array_values(array_diff($declared, array_map('strval', array_column($entry['set'], 'path'))));
		if ($refresh === false && $gained === []) {
			return ['error' => '', 'entry' => $entry, 'wrote' => false];
		}

		$resolved = $this->resolve(change: $change, context: $context);
		if ($resolved['refusal'] !== '') {
			return ['error' => $resolved['refusal'], 'entry' => $entry, 'wrote' => false];
		}

		$fresh = $this->gainedRows(paths: $gained, resolved: $resolved);
		$again = array_merge($this->refreshed(refresh: $refresh, entry: $entry, resolved: $resolved), array_column($fresh, 'new', 'path'));
		if ($again === []) {
			return ['error' => '', 'entry' => $entry, 'wrote' => false];
		}

		$merged = $resolved['row'];
		foreach ($again as $path => $value) {
			$merged = $this->paths->withPath(row: $merged, path: $path, value: $value);
		}

		$fields = array_merge($this->jsonFields(change: $change), $this->structuredText(change: $change, row: $resolved['row']));
		$fields = array_values(array_unique($fields));
		$error  = $this->store->update(
			register: (string) $change['register'],
			schema: (string) $change['schema'],
			id: (string) $entry['id'],
			data: $this->paths->withJsonText(row: $merged, fields: $fields)
		);
		if ($error !== '') {
			return ['error' => $error, 'entry' => $entry, 'wrote' => false];
		}

		foreach ($entry['set'] as $index => $set) {
			if (array_key_exists((string) $set['path'], $again) === true) {
				$entry['set'][$index]['new'] = $again[(string) $set['path']];
			}
		}

		$entry['set'] = array_merge($entry['set'], $fresh);

		return ['error' => '', 'entry' => $entry, 'wrote' => true];
	}//end follow()

	/**
	 * The held fields this run writes again: those of writtenAgain() on a refresh, none otherwise.
	 *
	 * @param bool                 $refresh  Whether this run refreshes.
	 * @param array<string, mixed> $entry    The record entry, held fields only.
	 * @param array<string, mixed> $resolved From resolve(), without a refusal.
	 *
	 * @return array<string, mixed>
	 */
	private function refreshed(bool $refresh, array $entry, array $resolved): array {
		if ($refresh === false) {
			return [];
		}

		return $this->writtenAgain(entry: $entry, resolved: $resolved);
	}//end refreshed()

	/**
	 * The record rows of fields the declaration gained that do not hold their declared value yet.
	 *
	 * @param array<int, string>   $paths    The gained paths.
	 * @param array<string, mixed> $resolved From resolve(), without a refusal.
	 *
	 * @return array<int, array{path: string, had: bool, old: mixed, new: mixed, from: string}>
	 */
	private function gainedRows(array $paths, array $resolved): array {
		$rows = [];
		foreach ($paths as $path) {
			$want = $resolved['set'][$path];
			$held = $this->paths->atPath(row: $resolved['row'], path: $path);
			if ($held['found'] === true && $held['value'] === $want) {
				continue;
			}

			$rows[] = [
				'path' => $path,
				'had'  => $held['found'],
				'old'  => $held['value'],
				'new'  => $want,
				'from' => $this->paths->firstMissing(row: $resolved['row'], path: $path),
			];
		}

		return $rows;
	}//end gainedRows()

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
	 * The held fields a refresh writes again, by path: back at what the change replaced, or still what it wrote while
	 * the declaration resolves to a new value.
	 *
	 * @param array<string, mixed> $entry    The record entry, held fields only.
	 * @param array<string, mixed> $resolved From resolve(), without a refusal.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/example-set-gone-declarations-are-let-go/specs/example-sets/spec.md#requirement-a-field-that-reads-back-null-is-not-set
	 */
	private function writtenAgain(array $entry, array $resolved): array {
		$again = [];
		foreach ($entry['set'] as $set) {
			$path = (string) $set['path'];
			$want = $resolved['set'][$path];
			$held = $this->paths->atPath(row: $resolved['row'], path: $path);
			if ($held['found'] === true && $this->sameAs(stored: $held['value'], value: $want) === true) {
				continue;
			}

			$moved = ($held['found'] === true && $this->sameAs(stored: $held['value'], value: $set['new']) === true);
			if ($this->undone(held: $held, set: $set) === true || $moved === true) {
				$again[$path] = $want;
			}
		}

		return $again;
	}//end writtenAgain()

	/**
	 * Whether a held field is back at what the change replaced. A field that reads back null was never set, as one
	 * the object did not have.
	 *
	 * @param array{found: bool, value: mixed} $held The field as read back.
	 * @param array<string, mixed>             $set  The record's entry for the field: `had`, `old` and `new`.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/example-set-gone-declarations-are-let-go/specs/example-sets/spec.md#requirement-a-field-that-reads-back-null-is-not-set
	 */
	private function undone(array $held, array $set): bool {
		$isSet  = ($held['found'] === true && $held['value'] !== null);
		$hadSet = ((bool) $set['had'] === true && $set['old'] !== null);

		return $isSet === $hadSet && $this->sameAs(stored: $held['value'], value: $set['old']) === true;
	}//end undone()

	/**
	 * Whether a stored value is the given value as the store keeps it: the same keys and values in any key order,
	 * JSON text read as its structure, and nothing more or less on either side.
	 *
	 * @param mixed $stored The value as read back.
	 * @param mixed $value  The value a declaration or the record names.
	 *
	 * @return bool
	 */
	private function sameAs(mixed $stored, mixed $value): bool {
		$read = $stored;
		if (is_string($stored) === true) {
			$decoded = json_decode($stored, true);
			if (is_array($decoded) === true) {
				$read = $decoded;
			}
		}

		return $this->proof->lostPaths(declared: $value, stored: $stored) === []
			&& $this->proof->lostPaths(declared: $read, stored: $value) === [];
	}//end sameAs()

	/**
	 * Bring top-level fields of an object the set wrote earlier to the values its declaration resolves to now.
	 *
	 * Made like a change, so only a field that differs is written, its old
	 * value is recorded, the install proves it and remove can put it back.
	 * A field is refreshed only while it still holds what the set wrote
	 * (`$written`): a different value is somebody's edit and is left as it
	 * is, and named.
	 *
	 * @param array<string, mixed> $object  The declared object (`register`, `schema`, `jsonFields`).
	 * @param array<string, mixed> $row     The object as stored.
	 * @param array<string, mixed> $values  The resolved values, by top-level field.
	 * @param array<string, mixed> $written What the set last wrote into those fields, by field.
	 *
	 * @return array{error: string, entry: array<string, mixed>|null, left: array<int, string>} The record entry (null
	 *         when no field had to be written), or why the write failed, and the fields left because somebody changed them.
	 *
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-kept-object-follows-the-objects-it-references-req-exs-023
	 */
	public function refresh(array $object, array $row, array $values, array $written=[]): array {
		$change = ['register' => (string) $object['register'], 'schema' => (string) $object['schema'], 'jsonFields' => $this->jsonFields(change: $object)];
		$change['jsonFields'] = array_values(array_unique(array_merge($change['jsonFields'], $this->structuredText(change: $change, row: $row))));
		$row    = $this->paths->withJsonDecoded(row: $row, fields: $change['jsonFields']);

		$same = fn (mixed $first, mixed $second): bool => $this->sameAs(stored: $first, value: $second);
		$todo = [];
		$left = [];
		foreach ($values as $field => $value) {
			$held = $this->paths->atPath(row: $row, path: (string) $field);
			if ($held['found'] === true && $same($held['value'], $value) === true) {
				continue;
			}

			$wrote = array_key_exists($field, $written);
			if ($held['found'] === true && $wrote === true && $same($held['value'], $written[$field]) === true) {
				$todo[$field] = $value;
				continue;
			}

			$left[] = (string) $field;
		}

		// Nothing to write (every field already holds its value, or was edited) makes apply() answer no entry.
		$made = $this->apply(
			change: $change,
			resolved: [
				'row'    => $row,
				'merged' => $this->paths->withJsonText(row: array_merge($row, $todo), fields: $change['jsonFields']),
				'set'    => $todo,
				'append' => [],
			]
		);

		return ($made + ['left' => $left]);
	}//end refresh()

	/**
	 * The paths of a made change that the stored object does not hold.
	 *
	 * @param array<string, mixed> $entry A record entry from apply().
	 *
	 * @return array<int, string> Empty when the change is on the instance; `(gone)` when the object is.
	 *
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-an-install-proves-what-arrived-req-exs-006
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-field-a-schema-keeps-as-json-text-is-sent-as-text-req-exs-012
	 */
	public function lost(array $entry): array {
		$row = $this->store->get(register: (string) $entry['register'], schema: (string) $entry['schema'], id: (string) $entry['id']);
		if ($row === null) {
			return ['(gone)'];
		}

		$row  = $this->paths->withJsonDecoded(row: $row, fields: $this->jsonFields(change: $entry));
		$lost = [];
		foreach ($entry['set'] as $set) {
			$held = $this->paths->atPath(row: $row, path: $set['path']);
			if ($this->stillHolds(held: $held, written: $set['new']) === false) {
				$lost[] = $set['path'];
			}
		}

		foreach ($entry['append'] as $append) {
			if (in_array($append['value'], (array) ($this->paths->atPath(row: $row, path: $append['path'])['value'] ?? []), true) === false) {
				$lost[] = $append['path'];
			}
		}

		return $lost;
	}//end lost()

	/**
	 * Whether a path still holds what a change wrote, in whatever form the
	 * store keeps it (a moment in UTC, an empty text as null).
	 *
	 * @param array{found: bool, value: mixed} $held    The path as read back.
	 * @param mixed                            $written The value the change wrote.
	 *
	 * @return bool
	 */
	private function stillHolds(array $held, mixed $written): bool {
		return $held['found'] === true && $this->proof->lostPaths(declared: $written, stored: $held['value']) === [];
	}//end stillHolds()

	/**
	 * Put back what a change replaced and take out what it appended, while the object still holds what the change wrote.
	 *
	 * A path whose value is no longer the one the change wrote was edited
	 * since: it is left as it is now and named, as app config and user
	 * preferences are. A value the change appended is taken out of its list
	 * wherever the list is now, because that undoes exactly the change.
	 *
	 * @param array<string, mixed> $entry A record entry from apply().
	 *
	 * @return array{ok: bool, left: array<int, string>} Whether the object no longer carries the change (or is gone; false
	 *                                                    too when the object could not be read),
	 *                                                    and the paths left because somebody changed them.
	 *
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-remove-deletes-exactly-what-the-install-made-req-exs-007
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-field-a-schema-keeps-as-json-text-is-sent-as-text-req-exs-012
	 */
	public function revert(array $entry): array {
		// `read()` tells an object that is gone (nothing left to undo) from a read that failed (nothing is known,
		// so nothing is reported as undone and the record keeps the change).
		$read = $this->store->read(register: (string) $entry['register'], schema: (string) $entry['schema'], id: (string) $entry['id']);
		$row  = $read['row'];
		if ($row === null) {
			return ['ok' => ($read['error'] === ''), 'left' => []];
		}

		$fields = array_values(array_unique(array_merge($this->jsonFields(change: $entry), $this->structuredText(change: $entry, row: $row))));
		$row    = $this->paths->withJsonDecoded(row: $row, fields: $fields);
		$left   = [];
		foreach (array_reverse((array) $entry['set']) as $set) {
			$held = $this->paths->atPath(row: $row, path: $set['path']);
			if ($this->stillHolds(held: $held, written: $set['new']) === false) {
				$left[] = (string) $set['path'];
				continue;
			}

			$row = $this->paths->withoutPath(row: $row, path: $set['path']);
			if ($set['had'] === true) {
				$row = $this->paths->withPath(row: $row, path: $set['path'], value: $set['old']);
				continue;
			}

			// A map the change made on the way to its path goes again when nothing else filled it since.
			$from = (string) ($set['from'] ?? $set['path']);
			if ($from !== $set['path'] && $this->paths->atPath(row: $row, path: $from)['value'] === []) {
				$row = $this->paths->withoutPath(row: $row, path: $from);
			}
		}

		$row = $this->withoutAppended(row: $row, appended: (array) $entry['append']);
		$row = $this->paths->withJsonText(row: $row, fields: $fields);
		$why = $this->store->update(register: (string) $entry['register'], schema: (string) $entry['schema'], id: (string) $entry['id'], data: $row);

		return ['ok' => ($why === ''), 'left' => $left];
	}//end revert()

	/**
	 * The row without the values a change appended, wherever their lists are now.
	 *
	 * @param array<string, mixed>              $row      The row.
	 * @param array<int, array<string, mixed>>  $appended The appended values, each with its list `path` and `value`.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function withoutAppended(array $row, array $appended): array {
		foreach ($appended as $append) {
			$list = (array) ($this->paths->atPath(row: $row, path: $append['path'])['value'] ?? []);
			$kept = array_values(array_filter($list, static fn ($item): bool => $item !== $append['value']));
			$row  = $this->paths->withPath(row: $row, path: $append['path'], value: $kept);
		}

		return $row;
	}//end withoutAppended()

	/**
	 * An object as it is sent, with each field it keeps as JSON text encoded.
	 *
	 * The one encoding for `jsonFields`, for the objects a set creates as well as the ones it changes.
	 *
	 * @param array<string, mixed> $row    The object.
	 * @param array<int, mixed>    $fields The field names kept as JSON text.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-field-a-schema-keeps-as-json-text-is-sent-as-text-req-exs-012
	 */
	public function asSent(array $row, array $fields): array {
		return $this->paths->withJsonText(row: $row, fields: $fields);
	}//end asSent()

	/**
	 * The text properties of the changed object's schema that the store read back as a structure.
	 *
	 * @param array<string, mixed> $change The change.
	 * @param array<string, mixed> $row    The object as read.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/example-set-change-keeps-text/specs/example-sets/spec.md#requirement-a-change-sends-a-text-property-as-text
	 */
	private function structuredText(array $change, array $row): array {
		return array_values(
			array_filter(
				$this->store->textProperties(register: (string) $change['register'], schema: (string) $change['schema']),
				static fn (string $field): bool => is_array($row[$field] ?? null) === true
			)
		);
	}//end structuredText()

	/**
	 * The fields a change or a record entry keeps as JSON text.
	 *
	 * @param array<string, mixed> $change The change, or a record entry from apply().
	 *
	 * @return array<int, string>
	 */
	private function jsonFields(array $change): array {
		return array_values(array_unique(array_filter((array) ($change['jsonFields'] ?? []), 'is_string')));
	}//end jsonFields()

	/**
	 * The objects a change describes: the object written earlier in the same
	 * fragment by its key, or every object its `where` finds.
	 *
	 * @param array<string, mixed>                $change  The declared change.
	 * @param array<string, mixed>                $where   The filled `where`.
	 * @param array<string, array<string, mixed>> $objects The fragment's objects so far, by key.
	 *
	 * @return array<int, array<string, mixed>> None, one, or every match; a change is made only on exactly one.
	 */
	private function target(array $change, array $where, array $objects): array {
		if (is_string($change['object'] ?? null) === true) {
			$earlier = ($objects[$change['object']] ?? null);
			if ($earlier === null) {
				return [];
			}

			return [$earlier];
		}

		if ($where === []) {
			return [];
		}

		return $this->store->matching(register: (string) $change['register'], schema: (string) $change['schema'], where: $where);
	}//end target()
}//end class
