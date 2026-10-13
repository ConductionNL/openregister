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
 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-set-may-change-an-object-it-did-not-create-and-undo-exactly-that-req-exs-011
 */
class ExampleSetChanges {
	/**
	 * Constructor.
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
	 */
	public function resolve(array $change, array $context): array {
		$none = ['refusal' => '', 'row' => [], 'merged' => [], 'set' => [], 'append' => []];
		$gaps = [];
		$fill = (array) $this->values->fill(
			value: ['where' => $change['where'], 'set' => ($change['set'] ?? []), 'append' => ($change['append'] ?? [])],
			context: $context,
			gaps: $gaps
		);
		if ($gaps !== []) {
			return array_merge($none, ['refusal' => 'cannot resolve '.implode(', ', array_unique($gaps))]);
		}

		$row = $this->target(register: (string) $change['register'], schema: (string) $change['schema'], where: (array) $fill['where']);
		if ($row === null) {
			return array_merge(
				$none,
				['refusal' => 'no '.$change['register'].' '.$change['schema'].' with '.json_encode($fill['where'], JSON_UNESCAPED_UNICODE).' to change']
			);
		}

		$merged = $row;
		foreach ((array) $fill['set'] as $path => $value) {
			$merged = self::withPath(row: $merged, path: (string) $path, value: $value);
		}

		foreach ((array) $fill['append'] as $path => $value) {
			$list = (array) (self::atPath(row: $merged, path: (string) $path)['value'] ?? []);
			if (in_array($value, $list, true) === false) {
				$list[] = $value;
			}

			$merged = self::withPath(row: $merged, path: (string) $path, value: array_values($list));
		}

		return ['refusal' => '', 'row' => $row, 'merged' => $merged, 'set' => (array) $fill['set'], 'append' => (array) $fill['append']];
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
	 */
	public function apply(array $change, array $resolved): array {
		$row   = $resolved['row'];
		$entry = ['register' => $change['register'], 'schema' => $change['schema'], 'id' => $this->store->idOf(row: $row), 'set' => [], 'append' => []];
		foreach ($resolved['set'] as $path => $value) {
			$held = self::atPath(row: $row, path: (string) $path);
			if ($held['found'] === true && $held['value'] === $value) {
				continue;
			}

			$entry['set'][] = [
				'path' => (string) $path,
				'had'  => $held['found'],
				'old'  => $held['value'],
				'new'  => $value,
				'from' => self::firstMissing(row: $row, path: (string) $path),
			];
		}

		foreach ($resolved['append'] as $path => $value) {
			if (in_array($value, (array) (self::atPath(row: $row, path: (string) $path)['value'] ?? []), true) === false) {
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
	 * The paths of a made change that the stored object does not hold.
	 *
	 * @param array<string, mixed> $entry A record entry from apply().
	 *
	 * @return array<int, string> Empty when the change is on the instance; `(gone)` when the object is.
	 *
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-an-install-proves-what-arrived-req-exs-006
	 */
	public function lost(array $entry): array {
		$row = $this->store->get(register: (string) $entry['register'], schema: (string) $entry['schema'], id: (string) $entry['id']);
		if ($row === null) {
			return ['(gone)'];
		}

		$lost = [];
		foreach ($entry['set'] as $set) {
			$held = self::atPath(row: $row, path: $set['path']);
			if ($held['found'] === false || $held['value'] !== $set['new']) {
				$lost[] = $set['path'];
			}
		}

		foreach ($entry['append'] as $append) {
			if (in_array($append['value'], (array) (self::atPath(row: $row, path: $append['path'])['value'] ?? []), true) === false) {
				$lost[] = $append['path'];
			}
		}

		return $lost;
	}//end lost()

	/**
	 * Put back what a change replaced and take out what it appended.
	 *
	 * @param array<string, mixed> $entry A record entry from apply().
	 *
	 * @return bool True when the object no longer carries the change, or is gone.
	 *
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-remove-deletes-exactly-what-the-install-made-req-exs-007
	 */
	public function revert(array $entry): bool {
		$row = $this->store->get(register: (string) $entry['register'], schema: (string) $entry['schema'], id: (string) $entry['id']);
		if ($row === null) {
			return true;
		}

		foreach (array_reverse((array) $entry['set']) as $set) {
			$row = self::withoutPath(row: $row, path: $set['path']);
			if ($set['had'] === true) {
				$row = self::withPath(row: $row, path: $set['path'], value: $set['old']);
				continue;
			}

			// A map the change made on the way to its path goes again when nothing else filled it since.
			$from = (string) ($set['from'] ?? $set['path']);
			if ($from !== $set['path'] && self::atPath(row: $row, path: $from)['value'] === []) {
				$row = self::withoutPath(row: $row, path: $from);
			}
		}

		foreach ((array) $entry['append'] as $append) {
			$list = (array) (self::atPath(row: $row, path: $append['path'])['value'] ?? []);
			$kept = array_values(array_filter($list, static fn ($item): bool => $item !== $append['value']));
			$row  = self::withPath(row: $row, path: $append['path'], value: $kept);
		}

		return $this->store->update(register: (string) $entry['register'], schema: (string) $entry['schema'], id: (string) $entry['id'], data: $row) === '';
	}//end revert()

	/**
	 * The one object a change's `where` describes, or null.
	 *
	 * @param string               $register The register slug.
	 * @param string               $schema   The schema slug.
	 * @param array<string, mixed> $where    The asked values.
	 *
	 * @return array<string, mixed>|null
	 */
	private function target(string $register, string $schema, array $where): ?array {
		if ($where === []) {
			return null;
		}

		foreach ([$where, []] as $filters) {
			foreach ($this->store->find(register: $register, schema: $schema, filters: $filters) as $row) {
				$match = true;
				foreach ($where as $field => $value) {
					$match = ($match === true && ($row[$field] ?? null) === $value);
				}

				if ($match === true) {
					return $row;
				}
			}
		}

		return null;
	}//end target()

	/**
	 * The shortest part of a dot path that the object does not hold yet.
	 *
	 * @param array<string, mixed> $row  The object.
	 * @param string               $path The dot path.
	 *
	 * @return string The path itself when only its last step is missing (or nothing is).
	 */
	private static function firstMissing(array $row, string $path): string {
		$parts = explode('.', $path);
		$steps = count($parts);
		for ($length = 1; $length < $steps; $length++) {
			$prefix = implode('.', array_slice($parts, 0, $length));
			if (self::atPath(row: $row, path: $prefix)['found'] === false) {
				return $prefix;
			}
		}

		return $path;
	}//end firstMissing()

	/**
	 * The value at a dot path, and whether there is one.
	 *
	 * @param array<string, mixed> $row  The object.
	 * @param string               $path The dot path.
	 *
	 * @return array{found: bool, value: mixed}
	 */
	private static function atPath(array $row, string $path): array {
		$here = $row;
		foreach (explode('.', $path) as $part) {
			if (is_array($here) === false || array_key_exists($part, $here) === false) {
				return ['found' => false, 'value' => null];
			}

			$here = $here[$part];
		}

		return ['found' => true, 'value' => $here];
	}//end atPath()

	/**
	 * The object with a value at a dot path, making the maps on the way.
	 *
	 * @param array<string, mixed> $row   The object.
	 * @param string               $path  The dot path.
	 * @param mixed                $value The value.
	 *
	 * @return array<string, mixed>
	 */
	private static function withPath(array $row, string $path, mixed $value): array {
		[$head, $rest] = array_pad(explode('.', $path, 2), 2, null);
		if ($rest === null) {
			$row[$head] = $value;
			return $row;
		}

		$child      = $row[$head] ?? [];
		$row[$head] = self::withPath(row: (array) $child, path: $rest, value: $value);

		return $row;
	}//end withPath()

	/**
	 * The object without the value at a dot path.
	 *
	 * @param array<string, mixed> $row  The object.
	 * @param string               $path The dot path.
	 *
	 * @return array<string, mixed>
	 */
	private static function withoutPath(array $row, string $path): array {
		[$head, $rest] = array_pad(explode('.', $path, 2), 2, null);
		if ($rest === null) {
			unset($row[$head]);
			return $row;
		}

		if (is_array($row[$head] ?? null) === true) {
			$row[$head] = self::withoutPath(row: $row[$head], path: $rest);
		}

		return $row;
	}//end withoutPath()
}//end class
