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
 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-may-change-an-object-it-did-not-create-and-undo-exactly-that-req-exs-011
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef;

/**
 * Applies, proves and reverts changes to existing objects.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-may-change-an-object-it-did-not-create-and-undo-exactly-that-req-exs-011
 */
class ExampleSetChanges {
	/**
	 * What resolve() answers besides the refusal when a change cannot be made.
	 */
	private const NONE = ['refusal' => '', 'row' => [], 'merged' => [], 'set' => [], 'append' => []];

	/**
	 * Compares and records written values.
	 *
	 * @var ExampleSetWrittenValue
	 */
	private readonly ExampleSetWrittenValue $written;

	/**
	 * Reads the fields a change touches.
	 *
	 * @var ExampleSetChangeFields
	 */
	private readonly ExampleSetChangeFields $fields;

	/**
	 * Proves and reverts made changes.
	 *
	 * @var ExampleSetChangeReadBack
	 */
	private readonly ExampleSetChangeReadBack $readBack;

	/**
	 * The write step of follow().
	 *
	 * @var ExampleSetChangeFollower
	 */
	private readonly ExampleSetChangeFollower $follower;

	/**
	 * Reads and writes values at dot paths.
	 *
	 * @var ExampleSetPaths
	 */
	private readonly ExampleSetPaths $paths;

	/**
	 * Constructor. The path reader and the proof (whether a value read back
	 * is the value written) are stateless and built here.
	 *
	 * @param ExampleSetStore  $store  Register access.
	 * @param ExampleSetValues $values Fills placeholders.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ExampleSetStore $store,
		private readonly ExampleSetValues $values,
	) {
		$this->paths    = new ExampleSetPaths();
		$this->written  = new ExampleSetWrittenValue(proof: new ExampleSetProof());
		$this->fields   = new ExampleSetChangeFields(store: $store, paths: $this->paths);
		$this->readBack = new ExampleSetChangeReadBack(store: $store, paths: $this->paths, fields: $this->fields, written: $this->written);
		$this->follower = new ExampleSetChangeFollower(paths: $this->paths, fields: $this->fields, written: $this->written);
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
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-may-change-an-object-it-did-not-create-and-undo-exactly-that-req-exs-011
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-field-a-schema-keeps-as-json-text-is-sent-as-text-req-exs-012
	 */
	public function resolve(array $change, array $context): array {
		$found = $this->located(change: $change, context: $context);
		if ($found['refusal'] !== '') {
			return array_merge(self::NONE, ['refusal' => $found['refusal']]);
		}

		return $this->merged(change: $change, found: $found);
	}//end resolve()

	/**
	 * Fill a change's placeholders and find the one object it changes.
	 *
	 * @param array<string, mixed> $change  The declared change.
	 * @param array<string, mixed> $context See resolve().
	 *
	 * @return array{refusal: string, row?: array<string, mixed>|null, fill?: array<string, mixed>} The object and the
	 *                                                                                               filled change, or why
	 *                                                                                               it cannot be made.
	 */
	private function located(array $change, array $context): array {
		$gaps = [];
		$fill = (array) $this->values->fill(value: $this->declaredParts(change: $change), context: $context, gaps: $gaps);
		if ($gaps !== []) {
			return ['refusal' => 'cannot resolve '.implode(', ', array_unique($gaps))];
		}

		$filled = array_merge($change, ['where' => $fill['where']]);
		$found  = $this->target(change: $filled, objects: (array) ($context['objects'] ?? []));

		return ['refusal' => $this->refusal(change: $filled, found: $found), 'row' => ($found[0] ?? null), 'fill' => $fill];
	}//end located()

	/**
	 * The parts of a change that hold placeholders: `where`, `set` and `append`, each empty when not declared.
	 *
	 * @param array<string, mixed> $change The declared change.
	 *
	 * @return array{where: mixed, set: mixed, append: mixed}
	 */
	private function declaredParts(array $change): array {
		$parts = [];
		foreach (['where', 'set', 'append'] as $part) {
			$parts[$part] = ($change[$part] ?? []);
		}

		return $parts;
	}//end declaredParts()

	/**
	 * Why a change cannot be made on what its target found: none, or more than one object.
	 *
	 * @param array<string, mixed>             $change The declared change, its `where` filled.
	 * @param array<int, array<string, mixed>> $found  What target() found.
	 *
	 * @return string Empty when exactly one object was found.
	 */
	private function refusal(array $change, array $found): string {
		if (count($found) === 1) {
			return '';
		}

		$where = (string) json_encode($change['where'], JSON_UNESCAPED_UNICODE);
		if (count($found) > 1) {
			return count($found).' '.$change['register'].' '.$change['schema'].' rows match '.$where.'; a change needs exactly one to change';
		}

		return $this->noneFound(change: $change, where: $where);
	}//end refusal()

	/**
	 * Why a change whose target found nothing cannot be made.
	 *
	 * @param array<string, mixed> $change The declared change.
	 * @param string               $where  The filled `where`, as JSON.
	 *
	 * @return string
	 */
	private function noneFound(array $change, string $where): string {
		if (is_string($change['object'] ?? null) === true) {
			return 'object "'.$change['object'].'" is not written earlier in this fragment';
		}

		return 'no '.$change['register'].' '.$change['schema'].' with '.$where.' to change';
	}//end noneFound()

	/**
	 * A located change as resolve() answers it: the object, and the object as the change makes it.
	 *
	 * A field the schema keeps as JSON text is changed as its structure and written as text again; so is a text
	 * property the store reads back as a structure, which the change does not have to name.
	 *
	 * @param array<string, mixed> $change The declared change.
	 * @param array<string, mixed> $found  From located(), without a refusal.
	 *
	 * @return array{refusal: string, row: array<string, mixed>, merged: array<string, mixed>, set: array<string, mixed>,
	 *               append: array<string, mixed>}
	 */
	private function merged(array $change, array $found): array {
		$fields = $this->fields->textFields(change: $change, row: $found['row']);
		$row    = $this->paths->withJsonDecoded(row: $found['row'], fields: $fields);
		$set    = (array) $found['fill']['set'];
		$append = (array) $found['fill']['append'];
		$merged = $this->withAppended(row: $this->withSet(row: $row, set: $set), append: $append);
		$merged = $this->paths->withJsonText(row: $merged, fields: $fields);

		return ['refusal' => '', 'row' => $row, 'merged' => $merged, 'set' => $set, 'append' => $append];
	}//end merged()

	/**
	 * The row with every value of a filled `set` at its path.
	 *
	 * @param array<string, mixed> $row The row.
	 * @param array<string, mixed> $set The values by path.
	 *
	 * @return array<string, mixed>
	 */
	private function withSet(array $row, array $set): array {
		foreach ($set as $path => $value) {
			$row = $this->paths->withPath(row: $row, set: new ExampleSetPathValue(path: (string) $path, value: $value));
		}

		return $row;
	}//end withSet()

	/**
	 * The row with every value of a filled `append` in the list at its path, once.
	 *
	 * @param array<string, mixed> $row    The row.
	 * @param array<string, mixed> $append The values by list path.
	 *
	 * @return array<string, mixed>
	 */
	private function withAppended(array $row, array $append): array {
		foreach ($append as $path => $value) {
			$list = (array) ($this->paths->atPath(row: $row, path: (string) $path)['value'] ?? []);
			if (in_array($value, $list, true) === false) {
				$list[] = $value;
			}

			$row = $this->paths->withPath(row: $row, set: new ExampleSetPathValue(path: (string) $path, value: array_values($list)));
		}

		return $row;
	}//end withAppended()

	/**
	 * Make a resolved change, writing only what differs.
	 *
	 * @param array<string, mixed> $change   The declared change.
	 * @param array<string, mixed> $resolved From resolve(), without a refusal.
	 *
	 * @return array{error: string, entry: array<string, mixed>|null} The record entry (null when nothing had to change),
	 *                                                                 or why the write failed.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-may-change-an-object-it-did-not-create-and-undo-exactly-that-req-exs-011
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-field-a-schema-keeps-as-json-text-is-sent-as-text-req-exs-012
	 */
	public function apply(array $change, array $resolved): array {
		$entry = $this->entryOf(change: $change, resolved: $resolved);
		if ($entry['set'] === [] && $entry['append'] === []) {
			return ['error' => '', 'entry' => null];
		}

		$error = $this->fields->write(object: $entry, data: $resolved['merged']);
		if ($error !== '') {
			return ['error' => $error, 'entry' => null];
		}

		return ['error' => '', 'entry' => $entry];
	}//end apply()

	/**
	 * The record entry of a resolved change: every field it sets that differs, and every value it appends that is absent.
	 *
	 * @param array<string, mixed> $change   The declared change.
	 * @param array<string, mixed> $resolved From resolve(), without a refusal.
	 *
	 * @return array<string, mixed>
	 */
	private function entryOf(array $change, array $resolved): array {
		$entry = ['register' => $change['register'], 'schema' => $change['schema'], 'id' => $this->store->idOf(row: $resolved['row'])];
		$entry += ['set' => [], 'append' => []];
		// The fields kept as JSON text, so the proof and remove read them the same way; none: no key.
		$entry += array_filter(['jsonFields' => $this->fields->jsonFields(change: $change)]);

		$rows            = $this->fields->setRows(resolved: $resolved, paths: array_keys($resolved['set']));
		$entry['set']    = $this->written->recordedRows(change: $change, rows: $rows);
		$entry['append'] = $this->appendedRows(resolved: $resolved);

		return $entry;
	}//end entryOf()

	/**
	 * The record rows of the values a resolved change appends that their list does not hold yet.
	 *
	 * @param array<string, mixed> $resolved From resolve(), without a refusal.
	 *
	 * @return array<int, array{path: string, value: mixed}>
	 */
	private function appendedRows(array $resolved): array {
		$rows = [];
		foreach ($resolved['append'] as $path => $value) {
			$append = ['path' => (string) $path, 'value' => $value];
			if ($this->fields->inList(row: $resolved['row'], append: $append) === false) {
				$rows[] = $append;
			}
		}

		return $rows;
	}//end appendedRows()

	/**
	 * What the install last wrote into the reference fields of a kept object.
	 *
	 * The value a refresh wrote when there was one (the newest `new` of the
	 * `#references` change), else the value the object was created with.
	 *
	 * The refresh change is the record's `<app>/<key>#references` entry.
	 *
	 * @param array<string, mixed> $record The record.
	 * @param string               $key    The record key of the object, `<app>/<key>`.
	 *
	 * @return array<string, mixed> The value by field; a field of a record that predates this is missing.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-kept-object-follows-the-objects-it-references-req-exs-023
	 */
	public function writtenReferences(array $record, string $key): array {
		$written = (array) ($record['objects'][$key]['refs'] ?? []);
		foreach ((array) ($record['changes'][$key.'#references']['set'] ?? []) as $set) {
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
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-held-change-or-preference-follows-its-declaration
	 */
	public function follow(array $change, array $entry, array $context, bool $refresh): array {
		$entry  = $this->follower->heldOnly(change: $change, entry: $entry);
		$gained = $this->follower->gained(change: $change, entry: $entry);
		if ($refresh === false && $gained === []) {
			return ['error' => '', 'entry' => $entry, 'wrote' => false];
		}

		$resolved = $this->resolve(change: $change, context: $context);
		$held     = new ExampleSetHeldChange(change: $change, entry: $entry, resolved: $resolved, refresh: $refresh);

		return $this->follower->write(held: $held, gained: $gained);
	}//end follow()

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
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-kept-object-follows-the-objects-it-references-req-exs-023
	 */
	public function refresh(array $object, array $row, array $values, array $written=[]): array {
		$change = $this->refreshChange(object: $object, row: $row);
		$row    = $this->paths->withJsonDecoded(row: $row, fields: $change['jsonFields']);
		$plan   = $this->refreshPlan(row: $row, declared: ['values' => $values, 'written' => $written]);
		$merged = $this->paths->withJsonText(row: array_merge($row, $plan['todo']), fields: $change['jsonFields']);

		// Nothing to write (every field already holds its value, or was edited) makes apply() answer no entry.
		$made = $this->apply(change: $change, resolved: ['row' => $row, 'merged' => $merged, 'set' => $plan['todo'], 'append' => []]);

		return ($made + ['left' => $plan['left']]);
	}//end refresh()

	/**
	 * The change a refresh makes on a declared object: its register and schema, and every field read as a structure.
	 *
	 * @param array<string, mixed> $object The declared object (`register`, `schema`, `jsonFields`).
	 * @param array<string, mixed> $row    The object as stored.
	 *
	 * @return array{register: string, schema: string, jsonFields: array<int, string>}
	 */
	private function refreshChange(array $object, array $row): array {
		$change = ['register' => (string) $object['register'], 'schema' => (string) $object['schema']];
		$change['jsonFields'] = $this->fields->jsonFields(change: $object);
		$change['jsonFields'] = $this->fields->textFields(change: $change, row: $row);

		return $change;
	}//end refreshChange()

	/**
	 * Which fields a refresh writes, and which it leaves because somebody changed them.
	 *
	 * @param array<string, mixed>                                              $row      The object, JSON text decoded.
	 * @param array{values: array<string, mixed>, written: array<string, mixed>} $declared The resolved values and what
	 *                                                                                    the set last wrote, by field.
	 *
	 * @return array{todo: array<string, mixed>, left: array<int, string>}
	 */
	private function refreshPlan(array $row, array $declared): array {
		$plan = ['same' => [], 'write' => [], 'left' => []];
		foreach ($declared['values'] as $field => $value) {
			$held = $this->paths->atPath(row: $row, path: (string) $field);
			$plan[$this->refreshVerdict(held: $held, field: ['name' => $field, 'value' => $value] + $declared)][$field] = $value;
		}

		return ['todo' => $plan['write'], 'left' => array_map('strval', array_keys($plan['left']))];
	}//end refreshPlan()

	/**
	 * What a refresh does with one field: nothing (`same`), write it (`write`), or leave somebody's edit (`left`).
	 *
	 * @param array{found: bool, value: mixed} $held  The field as read back.
	 * @param array<string, mixed>             $field The field's `name`, resolved `value` and the set's `written` values.
	 *
	 * @return string
	 */
	private function refreshVerdict(array $held, array $field): string {
		if ($held['found'] === true && $this->written->sameAs(stored: $held['value'], value: $field['value']) === true) {
			return 'same';
		}

		if ($this->holdsWritten(held: $held, field: $field) === true) {
			return 'write';
		}

		return 'left';
	}//end refreshVerdict()

	/**
	 * Whether a field still holds what the set last wrote into it.
	 *
	 * @param array{found: bool, value: mixed} $held  The field as read back.
	 * @param array<string, mixed>             $field The field's `name` and the set's `written` values.
	 *
	 * @return bool
	 */
	private function holdsWritten(array $held, array $field): bool {
		return $held['found'] === true && array_key_exists($field['name'], $field['written']) === true
			&& $this->written->sameAs(stored: $held['value'], value: $field['written'][$field['name']]) === true;
	}//end holdsWritten()

	/**
	 * The paths of a made change that the stored object does not hold.
	 *
	 * @param array<string, mixed> $entry A record entry from apply().
	 *
	 * @return array<int, string> Empty when the change is on the instance; `(gone)` when the object is.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-an-install-proves-what-arrived-req-exs-006
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-field-a-schema-keeps-as-json-text-is-sent-as-text-req-exs-012
	 */
	public function lost(array $entry): array {
		return $this->readBack->lost(entry: $entry);
	}//end lost()

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
	 * @spec openspec/specs/example-sets/spec.md#requirement-remove-deletes-exactly-what-the-install-made-req-exs-007
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-field-a-schema-keeps-as-json-text-is-sent-as-text-req-exs-012
	 */
	public function revert(array $entry): array {
		return $this->readBack->revert(entry: $entry);
	}//end revert()

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
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-field-a-schema-keeps-as-json-text-is-sent-as-text-req-exs-012
	 */
	public function asSent(array $row, array $fields): array {
		return $this->paths->withJsonText(row: $row, fields: $fields);
	}//end asSent()

	/**
	 * The objects a change describes: the object written earlier in the same
	 * fragment by its key, or every object its `where` finds.
	 *
	 * @param array<string, mixed>                $change  The declared change, its `where` filled.
	 * @param array<string, array<string, mixed>> $objects The fragment's objects so far, by key.
	 *
	 * @return array<int, array<string, mixed>> None, one, or every match; a change is made only on exactly one.
	 */
	private function target(array $change, array $objects): array {
		if (is_string($change['object'] ?? null) === true) {
			return array_values(array_filter([($objects[$change['object']] ?? null)], static fn ($earlier): bool => $earlier !== null));
		}

		$where = (array) $change['where'];
		if ($where === []) {
			return [];
		}

		return $this->store->rowsMatching(
			table: new RegisterSchemaRef(register: (string) $change['register'], schema: (string) $change['schema']),
			where: $where
		);
	}//end target()
}//end class
