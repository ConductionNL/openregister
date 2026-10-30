<?php

/**
 * OpenRegister Example Set Values
 *
 * Replaces `{{...}}` in declared values. The forms:
 * - `{{cast:key.path}}` a field of a cast person, organisation or group;
 * - `{{date:n}}` and `{{datetime:n HH:MM}}` a day n days from the install day;
 * - `{{object:key.path}}` a field of an object declared earlier in the same fragment;
 * - `{{file:key.n}}` the Nextcloud file id of the n-th file (from 0) an object declared earlier in the same
 *   fragment carries, so a record can name a file the set adds;
 * - `{{view:key.uuid}}` the uuid of a saved view the same fragment declares (user preferences only);
 * - `{{task:key.uuid}}` the uuid of a task the same fragment declares (the parts written after its tasks: notes, read states);
 * - `{{flow:key.uuid}}` the uuid of a flow the same fragment declares (changes and the engine parts, all written after its flows);
 * - `{{lookup:name}}` the id a lookup by business key found;
 * - `{{account:key.field}}` a field of a service account a fragment declares;
 * - `{{organisation:slug-or-name}}` the uuid of an OpenRegister organisation;
 * - `{{instance:baseUrl}}` the address of this instance (`overwrite.cli.url`).
 *
 * A declared object's `verbatimFields` are left as declared (see fillObject()).
 *
 * A day may be pinned to a weekday: `{{date:+7@thu}}` is the first Thursday
 * on or after the day seven days from the install day; `{{datetime:+7@tue 19:30}}`
 * likewise with a time.
 *
 * A value that is exactly one placeholder takes the type of what it stands
 * for, so a house number stays a number. A placeholder inside a longer string
 * is interpolated as text. A placeholder that cannot be filled is reported to
 * the caller and never becomes an empty string.
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
 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-every-placeholder-resolves-or-the-set-is-refused-req-exs-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use DateInterval;
use DateTimeImmutable;

/**
 * Fills placeholders in declared values.
 *
 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-every-placeholder-resolves-or-the-set-is-refused-req-exs-002
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) One resolver per placeholder kind.
 */
class ExampleSetValues {
	/**
	 * Marks a row that stands in for an object a check has not written; a
	 * field it does not declare (one the register makes, such as a case
	 * number) resolves to a stand-in text instead of a gap.
	 */
	public const PLANNED = '@planned';

	/**
	 * One placeholder anywhere in a string.
	 */
	private const ANY = '/\{\{([^{}]+)\}\}/';

	/**
	 * A string that is exactly one placeholder.
	 */
	private const WHOLE = '/^\{\{([^{}]+)\}\}$/';

	/**
	 * Fill a declared value at any depth.
	 *
	 * @param mixed                $value   The declared value.
	 * @param array<string, mixed> $context `cast` (entries by key), `objects` (rows by key, this fragment only),
	 *                                      `lookups` (id by name), `today` (DateTimeImmutable), `accounts` (service
	 *                                      accounts by key), `organisations` (uuid by slug and by name),
	 *                                      `instance` (`baseUrl`), `registers` (id by slug) and `schemas`
	 *                                      (id by `register/schema`).
	 * @param array<int, string>   $gaps    Collects every placeholder that could not be filled.
	 *
	 * @return mixed The filled value; an unfilled placeholder stays as written and is named in `$gaps`.
	 *
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-every-placeholder-resolves-or-the-set-is-refused-req-exs-002
	 */
	public function fill(mixed $value, array $context, array &$gaps): mixed {
		if (is_array($value) === true) {
			$filled = [];
			foreach ($value as $key => $item) {
				$filled[$key] = $this->fill(value: $item, context: $context, gaps: $gaps);
			}

			return $filled;
		}

		if (is_string($value) === false || str_contains($value, '{{') === false) {
			return $value;
		}

		if (preg_match(self::WHOLE, $value, $whole) === 1) {
			$found = $this->valueOf(placeholder: trim($whole[1]), context: $context);
			if ($found === null) {
				$gaps[] = $value;
				return $value;
			}

			return $found;
		}

		return preg_replace_callback(
			self::ANY,
			function (array $match) use ($context, &$gaps): string {
				return $this->asText(placeholder: $match[0], found: $this->valueOf(placeholder: trim($match[1]), context: $context), gaps: $gaps);
			},
			$value
		);
	}//end fill()

	/**
	 * The owner a declared object names, filled: an account the set's cast declares, or none when it names none.
	 *
	 * @param array<string, mixed> $object  The declared object; `owner` is optional.
	 * @param array<string, mixed> $context See fill(); `cast` holds the entries by key.
	 *
	 * @return array{owner: string|null, refusal: string} The account id, or why the owner cannot be used.
	 *
	 * @spec openspec/changes/example-set-object-owner/specs/example-sets/spec.md#requirement-a-declared-object-may-name-its-owner-from-the-cast
	 */
	public function ownerOf(array $object, array $context): array {
		if (array_key_exists('owner', $object) === false) {
			return ['owner' => null, 'refusal' => ''];
		}

		$gaps  = [];
		$owner = $this->fill(value: $object['owner'], context: $context, gaps: $gaps);
		$uids  = array_column(array_filter((array) ($context['cast'] ?? []), 'is_array'), 'uid');
		if ($gaps !== [] || is_string($owner) === false || in_array($owner, $uids, true) === false) {
			return ['owner' => null, 'refusal' => 'owner "'.json_encode($object['owner']).'" is no account the cast declares'];
		}

		return ['owner' => $owner, 'refusal' => ''];
	}//end ownerOf()

	/**
	 * Fill a declared object's data, leaving the fields it names in `verbatimFields` as declared: they hold
	 * placeholders of their own (a mail template's `{{zaakNummer}}`), not the set's. Every other field is filled,
	 * and a placeholder there that cannot be filled is still named in `$gaps`.
	 *
	 * @param array<string, mixed> $object  The declared object: `data`, optionally `verbatimFields`.
	 * @param array<string, mixed> $context See fill().
	 * @param array<int, string>   $gaps    Collects every placeholder that could not be filled.
	 *
	 * @return array<string, mixed> The data, in the declared order.
	 *
	 * @spec openspec/changes/example-set-verbatim-fields/specs/example-sets/spec.md#requirement-a-declared-object-may-keep-fields-verbatim
	 */
	public function fillObject(array $object, array $context, array &$gaps): array {
		$data     = (array) ($object['data'] ?? []);
		$verbatim = array_flip(array_filter((array) ($object['verbatimFields'] ?? []), 'is_string'));
		$filled   = (array) $this->fill(value: array_diff_key($data, $verbatim), context: $context, gaps: $gaps);

		return array_replace($data, $filled);
	}//end fillObject()

	/**
	 * The views a fragment declares, by key, for `{{view:key.uuid}}`: as the record holds them after the install
	 * wrote them, or with a stand-in uuid for the check (`$record` null), since none is written yet.
	 *
	 * @param array<string, mixed>      $fragment The fragment.
	 * @param array<string, mixed>|null $record   The set's record, or null for the check.
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @spec openspec/changes/example-set-view-placeholder/specs/example-sets/spec.md#requirement-a-preference-may-quote-a-view-of-its-fragment
	 */
	public function viewsOf(array $fragment, ?array $record): array {
		$views = [];
		foreach (($fragment['views'] ?? []) as $view) {
			$key = (string) $view['key'];
			if ($record === null) {
				$views[$key] = ['uuid' => 'example-set-stand-in:view:'.$key];
				continue;
			}

			$written = ($record['views'][$fragment['app'].'/'.$key] ?? null);
			if (is_array($written) === true) {
				$views[$key] = $written;
			}
		}

		return $views;
	}//end viewsOf()

	/**
	 * The tasks a fragment declares, by key, for `{{task:key.uuid}}`: as the record holds them after the install
	 * wrote them, or with a stand-in uuid for the check (`$record` null), since none is written yet.
	 *
	 * @param array<string, mixed>      $fragment The fragment.
	 * @param array<string, mixed>|null $record   The set's record, or null for the check.
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @spec openspec/changes/example-set-task-placeholder/specs/example-sets/spec.md#requirement-a-note-or-read-state-may-quote-a-task-of-its-fragment
	 */
	public function tasksOf(array $fragment, ?array $record): array {
		return $this->writtenRowsOf(fragment: $fragment, part: 'tasks', noun: 'task', record: $record);
	}//end tasksOf()

	/**
	 * The flows a fragment declares, by key, for `{{flow:key.uuid}}`: as the record holds them after the install
	 * wrote them, or with a stand-in uuid for the check (`$record` null), since none is written yet.
	 *
	 * @param array<string, mixed>      $fragment The fragment.
	 * @param array<string, mixed>|null $record   The set's record, or null for the check.
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @spec openspec/changes/example-set-flow-placeholder/specs/example-sets/spec.md#requirement-a-change-may-quote-a-flow-of-its-fragment
	 */
	public function flowsOf(array $fragment, ?array $record): array {
		return $this->writtenRowsOf(fragment: $fragment, part: 'flows', noun: 'flow', record: $record);
	}//end flowsOf()

	/**
	 * The rows of one engine part a fragment declares, by key: as the record holds them, or stand-ins for the check.
	 *
	 * @param array<string, mixed>      $fragment The fragment.
	 * @param string                    $part     The fragment part, such as `tasks`.
	 * @param string                    $noun     The word for one row in a stand-in uuid.
	 * @param array<string, mixed>|null $record   The set's record, or null for the check.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function writtenRowsOf(array $fragment, string $part, string $noun, ?array $record): array {
		$rows = [];
		foreach (($fragment[$part] ?? []) as $row) {
			$key = (string) $row['key'];
			if ($record === null) {
				$rows[$key] = ['uuid' => 'example-set-stand-in:'.$noun.':'.$key];
				continue;
			}

			$written = ($record[$part][$fragment['app'].'/'.$key] ?? null);
			if (is_array($written) === true) {
				$rows[$key] = $written;
			}
		}

		return $rows;
	}//end writtenRowsOf()

	/**
	 * A found value as text inside a longer string; a value that is no scalar is a gap.
	 *
	 * @param string             $placeholder The placeholder as written.
	 * @param mixed              $found       What it stands for.
	 * @param array<int, string> $gaps        Collects the placeholder when it cannot be text.
	 *
	 * @return string
	 */
	private function asText(string $placeholder, mixed $found, array &$gaps): string {
		if (is_scalar($found) === false) {
			$gaps[] = $placeholder;
			return $placeholder;
		}

		if ($found === true) {
			return 'true';
		}

		if ($found === false) {
			return 'false';
		}

		return (string) $found;
	}//end asText()

	/**
	 * What one placeholder stands for, or null when it cannot be filled.
	 *
	 * @param string               $placeholder The text between the braces.
	 * @param array<string, mixed> $context     See fill().
	 *
	 * @return mixed
	 *
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-register-or-schema-is-named-by-slug-and-written-as-its-id-req-exs-021
	 */
	private function valueOf(string $placeholder, array $context): mixed {
		[$kind, $rest] = array_pad(explode(':', $placeholder, 2), 2, '');
		$rest          = trim($rest);

		return match ($kind) {
			'cast'   => $this->pathIn(entries: (array) ($context['cast'] ?? []), reference: $rest),
			'object' => $this->objectField(objects: (array) ($context['objects'] ?? []), reference: $rest),
			'file'   => $this->fileId(files: (array) ($context['files'] ?? []), reference: $rest),
			'view'   => $this->pathIn(entries: (array) ($context['views'] ?? []), reference: $rest),
			'task'   => $this->pathIn(entries: (array) ($context['tasks'] ?? []), reference: $rest),
			'flow'   => $this->pathIn(entries: (array) ($context['flows'] ?? []), reference: $rest),
			'lookup' => $this->present(value: ($context['lookups'][$rest] ?? null)),
			'account' => $this->pathIn(entries: (array) ($context['accounts'] ?? []), reference: $rest),
			'organisation' => $this->present(value: ($context['organisations'][$rest] ?? null)),
			'instance' => $this->present(value: ($context['instance'][$rest] ?? null)),
			'register' => $this->present(value: ($context['registers'][$rest] ?? null)),
			'schema' => $this->present(value: ($context['schemas'][$rest] ?? null)),
			'date', 'datetime' => $this->moment(kind: $kind, rest: $rest, today: ($context['today'] ?? null)),
			default  => null,
		};
	}//end valueOf()

	/**
	 * The id of one file an object declared earlier in the fragment carries.
	 *
	 * @param array<string, array<int, int>> $files     File ids by object key, in declared order.
	 * @param string                         $reference `key.n`, n counted from 0.
	 *
	 * @return int|null The file id, or null when there is no such file.
	 *
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-an-object-of-a-set-may-carry-files-from-its-fragment-req-exs-013
	 */
	private function fileId(array $files, string $reference): ?int {
		[$key, $index] = array_pad(explode('.', $reference, 2), 2, '');
		if ($index === '' || ctype_digit($index) === false) {
			return null;
		}

		$id = ($files[$key][(int) $index] ?? 0);
		if (is_int($id) === false || $id <= 0) {
			return null;
		}

		return $id;
	}//end fileId()

	/**
	 * A field of an object declared earlier in the fragment.
	 *
	 * @param array<string, array<string, mixed>> $objects   Rows by key.
	 * @param string                              $reference `key.path`.
	 *
	 * @return mixed
	 */
	private function objectField(array $objects, string $reference): mixed {
		$found = $this->pathIn(entries: $objects, reference: $reference);
		if ($found !== null) {
			return $found;
		}

		[$key] = explode('.', $reference, 2);
		if (($objects[$key][self::PLANNED] ?? false) === true && str_contains($reference, '.') === true) {
			return 'example-set-stand-in:'.$reference;
		}

		return null;
	}//end objectField()

	/**
	 * The value at `key.path` in a map of entries, or null.
	 *
	 * @param array<string, mixed> $entries   Entries by key.
	 * @param string               $reference `key.path`, the path dot-separated.
	 *
	 * @return mixed
	 */
	private function pathIn(array $entries, string $reference): mixed {
		$parts = explode('.', $reference);
		if (count($parts) < 2) {
			return null;
		}

		$here = $entries;
		foreach ($parts as $part) {
			if (is_array($here) === false || array_key_exists($part, $here) === false) {
				return null;
			}

			$here = $here[$part];
		}

		return $this->present(value: $here);
	}//end pathIn()

	/**
	 * A found value, or null when it is nothing.
	 *
	 * @param mixed $value The found value.
	 *
	 * @return mixed
	 */
	private function present(mixed $value): mixed {
		if ($value === null || $value === '' || $value === []) {
			return null;
		}

		return $value;
	}//end present()

	/**
	 * The first given weekday on or after a day.
	 *
	 * @param DateTimeImmutable $day     The day.
	 * @param string            $weekday `mon` to `sun`.
	 *
	 * @return DateTimeImmutable
	 */
	private function onOrAfter(DateTimeImmutable $day, string $weekday): DateTimeImmutable {
		$wanted = (int) array_search($weekday, ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], true) + 1;
		$days   = ((($wanted - (int) $day->format('N')) + 7) % 7);

		return $day->add(new DateInterval('P'.$days.'D'));
	}//end onOrAfter()

	/**
	 * A day, or a moment on a day, counted from the install day.
	 *
	 * @param string $kind  `date` or `datetime`.
	 * @param string $rest  The number of days, and for a moment the time as HH:MM.
	 * @param mixed  $today The install day.
	 *
	 * @return string|null `2026-10-05` or `2026-10-05T09:12:00+02:00`; null when malformed.
	 */
	private function moment(string $kind, string $rest, mixed $today): ?string {
		$pattern = '/^([+-]?\d{1,4})(?:@(mon|tue|wed|thu|fri|sat|sun))?$/';
		if ($kind === 'datetime') {
			$pattern = '/^([+-]?\d{1,4})(?:@(mon|tue|wed|thu|fri|sat|sun))? ([01]\d|2[0-3]):([0-5]\d)$/';
		}

		if (($today instanceof DateTimeImmutable) === false || preg_match($pattern, $rest, $parts) !== 1) {
			return null;
		}

		$day = $today->modify(sprintf('%+d days', (int) $parts[1]));
		if (($parts[2] ?? '') !== '') {
			$day = $this->onOrAfter(day: $day, weekday: $parts[2]);
		}

		if ($kind === 'date') {
			return $day->format('Y-m-d');
		}

		return $day->setTime((int) $parts[3], (int) $parts[4])->format('c');
	}//end moment()
}//end class
