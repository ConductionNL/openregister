<?php

/**
 * OpenRegister Example Set Values
 *
 * Replaces `{{...}}` in declared values. The forms:
 * - `{{cast:key.path}}` a field of a cast person, organisation or group;
 * - `{{date:n}}` and `{{datetime:n HH:MM}}` a day n days from the install day (a moment as its UTC instant);
 * - `{{object:key.path}}` a field of an object declared earlier in the same fragment;
 * - `{{file:key.n}}` the Nextcloud file id of the n-th file (from 0) an object declared earlier in the same
 *   fragment carries, so a record can name a file the set adds;
 * - `{{view:key.uuid}}` the uuid of a saved view the same fragment declares (user preferences only);
 * - `{{task:key.uuid}}` the uuid of a task the same fragment declares (the parts written after its tasks: notes, read states);
 * - `{{flow:key.uuid}}` the uuid of a flow the same fragment declares (changes and the engine parts, all written after its flows);
 * - `{{accessLink:key.id|uuid|url}}` an access link the same fragment declares (changes and the engine parts, all
 *   written after its links);
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
 * @spec openspec/specs/example-sets/spec.md#requirement-every-placeholder-resolves-or-the-set-is-refused-req-exs-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * Fills placeholders in declared values.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-every-placeholder-resolves-or-the-set-is-refused-req-exs-002
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
	 * The placeholder kinds that name a path in a map of entries (`kind:key.path`), by the context key of that map.
	 */
	private const PATH_KINDS = [
		'cast'       => 'cast',
		'view'       => 'views',
		'task'       => 'tasks',
		'flow'       => 'flows',
		'accessLink' => 'accessLinks',
		'account'    => 'accounts',
	];

	/**
	 * The placeholder kinds that name one value by its name (`kind:name`), by the context key of their map.
	 */
	private const NAMED_KINDS = [
		'lookup'       => 'lookups',
		'organisation' => 'organisations',
		'instance'     => 'instance',
		'register'     => 'registers',
		'schema'       => 'schemas',
	];

	/**
	 * Constructor.
	 *
	 * @param ExampleSetFragmentRows $rows   The rows a fragment's engine parts are quoted from.
	 * @param ExampleSetMoment       $moment Days and moments counted from the install day.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ExampleSetFragmentRows $rows=new ExampleSetFragmentRows(),
		private readonly ExampleSetMoment $moment=new ExampleSetMoment(),
	) {
	}//end __construct()

	/**
	 * Fill a declared value at any depth.
	 *
	 * @param mixed               $value The declared value.
	 * @param ExampleSetFillScope $scope The context: `cast` (entries by key), `objects` (rows by key, this fragment
	 *                                   only), `lookups` (id by name), `today` (DateTimeImmutable), `accounts`
	 *                                   (service accounts by key), `organisations` (uuid by slug and by name),
	 *                                   `instance` (`baseUrl`), `registers` (id by slug) and `schemas` (id by
	 *                                   `register/schema`); and the gap list, which collects every placeholder
	 *                                   that could not be filled.
	 *
	 * @return mixed The filled value; an unfilled placeholder stays as written and is named in `$gaps`.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-every-placeholder-resolves-or-the-set-is-refused-req-exs-002
	 */
	public function fill(mixed $value, ExampleSetFillScope $scope): mixed {
		$run    = new ExampleSetFillRun(context: $scope->context);
		$filled = $this->filled(value: $value, run: $run);
		array_push($scope->gaps, ...$run->gaps);

		return $filled;
	}//end fill()

	/**
	 * Fill a declared value at any depth within one fill.
	 *
	 * @param mixed             $value The declared value.
	 * @param ExampleSetFillRun $run   The fill's context, collecting its gaps.
	 *
	 * @return mixed The filled value; an unfilled placeholder stays as written and is named in the run's gaps.
	 */
	private function filled(mixed $value, ExampleSetFillRun $run): mixed {
		if (is_array($value) === true) {
			return array_map(fn (mixed $item): mixed => $this->filled(value: $item, run: $run), $value);
		}

		if ($this->holdsPlaceholder(value: $value) === false) {
			return $value;
		}

		if (preg_match(self::WHOLE, $value, $whole) === 1) {
			return $this->wholeValue(placeholder: ['written' => $value, 'inner' => trim($whole[1])], run: $run);
		}

		return preg_replace_callback(self::ANY, fn (array $match): string => $this->asText(match: $match, run: $run), $value);
	}//end filled()

	/**
	 * Whether a declared value is text that holds a placeholder.
	 *
	 * @param mixed $value The declared value.
	 *
	 * @return bool
	 *
	 * @phpstan-assert-if-true string $value
	 */
	private function holdsPlaceholder(mixed $value): bool {
		return is_string($value) === true && str_contains($value, '{{') === true;
	}//end holdsPlaceholder()

	/**
	 * What a string that is exactly one placeholder stands for, typed; the string as written when it cannot be filled.
	 *
	 * @param array{written: string, inner: string} $placeholder The string as written, and the text between the braces.
	 * @param ExampleSetFillRun                     $run         The fill's context, collecting its gaps.
	 *
	 * @return mixed
	 */
	private function wholeValue(array $placeholder, ExampleSetFillRun $run): mixed {
		$found = $this->valueOf(placeholder: $placeholder['inner'], context: $run->context);
		if ($found === null) {
			$run->gaps[] = $placeholder['written'];
			return $placeholder['written'];
		}

		return $found;
	}//end wholeValue()

	/**
	 * The owner a declared object names, filled: an account the set's cast declares, or none when it names none.
	 *
	 * @param array<string, mixed> $object  The declared object; `owner` is optional.
	 * @param array<string, mixed> $context See fill(); `cast` holds the entries by key.
	 *
	 * @return array{owner: string|null, refusal: string} The account id, or why the owner cannot be used.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-declared-object-may-name-its-owner-from-the-cast
	 */
	public function ownerOf(array $object, array $context): array {
		if (array_key_exists('owner', $object) === false) {
			return ['owner' => null, 'refusal' => ''];
		}

		$gaps  = [];
		$owner = $this->fill(value: $object['owner'], scope: new ExampleSetFillScope(context: $context, gaps: $gaps));
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
	 * @param array<string, mixed> $object The declared object: `data`, optionally `verbatimFields`.
	 * @param ExampleSetFillScope  $scope  The context, see fill(), and the gap list that collects every placeholder
	 *                                     that could not be filled.
	 *
	 * @return array<string, mixed> The data, in the declared order.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-declared-object-may-keep-fields-verbatim
	 */
	public function fillObject(array $object, ExampleSetFillScope $scope): array {
		$data     = (array) ($object['data'] ?? []);
		$verbatim = array_flip(array_filter((array) ($object['verbatimFields'] ?? []), 'is_string'));
		$filled   = (array) $this->fill(value: array_diff_key($data, $verbatim), scope: $scope);

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
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-preference-may-quote-a-view-of-its-fragment
	 */
	public function viewsOf(array $fragment, ?array $record): array {
		return $this->rows->viewsOf(fragment: $fragment, record: $record);
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
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-note-or-read-state-may-quote-a-task-of-its-fragment
	 */
	public function tasksOf(array $fragment, ?array $record): array {
		return $this->rows->tasksOf(fragment: $fragment, record: $record);
	}//end tasksOf()

	/**
	 * The flows a fragment declares, by key, for `{{flow:key.uuid}}` and `{{flow:key.runs.run}}`: as the record
	 * holds them after the install wrote them, or with stand-in uuids for the check (`$record` null), since none
	 * is written yet. A stand-in names its keyed runs, and the step each waiting one waits on (`waitsOn`).
	 *
	 * @param array<string, mixed>      $fragment The fragment.
	 * @param array<string, mixed>|null $record   The set's record, or null for the check.
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-change-may-quote-a-flow-of-its-fragment
	 * @spec openspec/specs/example-sets/spec.md
	 */
	public function flowsOf(array $fragment, ?array $record): array {
		return $this->rows->flowsOf(fragment: $fragment, record: $record);
	}//end flowsOf()


	/**
	 * One placeholder inside a longer string, as text; a value that is no scalar, or none, is a gap.
	 *
	 * @param array<int, string> $match The placeholder as written, and the text between its braces.
	 * @param ExampleSetFillRun  $run   The fill's context, collecting its gaps.
	 *
	 * @return string
	 */
	private function asText(array $match, ExampleSetFillRun $run): string {
		$found = $this->valueOf(placeholder: trim($match[1]), context: $run->context);
		if (is_scalar($found) === false) {
			$run->gaps[] = $match[0];
			return $match[0];
		}

		return $this->scalarText(found: $found);
	}//end asText()

	/**
	 * A scalar as text: a boolean as `true` or `false`.
	 *
	 * @param bool|int|float|string $found The value.
	 *
	 * @return string
	 */
	private function scalarText(bool|int|float|string $found): string {
		if ($found === true) {
			return 'true';
		}

		if ($found === false) {
			return 'false';
		}

		return (string) $found;
	}//end scalarText()

	/**
	 * What one placeholder stands for, or null when it cannot be filled.
	 *
	 * @param string               $placeholder The text between the braces.
	 * @param array<string, mixed> $context     See fill().
	 *
	 * @return mixed
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-register-or-schema-is-named-by-slug-and-written-as-its-id-req-exs-021
	 */
	private function valueOf(string $placeholder, array $context): mixed {
		$parts = $this->partsOf(placeholder: $placeholder);
		if (isset(self::PATH_KINDS[$parts['kind']]) === true) {
			return $this->pathIn(entries: $this->entries(context: $context, key: self::PATH_KINDS[$parts['kind']]), reference: $parts['rest']);
		}

		if (isset(self::NAMED_KINDS[$parts['kind']]) === true) {
			return $this->present(value: ($context[self::NAMED_KINDS[$parts['kind']]][$parts['rest']] ?? null));
		}

		return $this->otherValue(parts: $parts, context: $context);
	}//end valueOf()

	/**
	 * A placeholder's kind and the reference after it, with a flow's `self` read as `self.uuid`.
	 *
	 * @param string $placeholder The text between the braces.
	 *
	 * @return array{kind: string, rest: string}
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragments-flow-may-quote-its-own-uuid
	 */
	private function partsOf(string $placeholder): array {
		[$kind, $rest] = array_pad(explode(':', $placeholder, 2), 2, '');
		$rest          = trim($rest);
		if ($kind === 'flow' && $rest === 'self') {
			$rest = 'self.uuid';
		}

		return ['kind' => $kind, 'rest' => $rest];
	}//end partsOf()

	/**
	 * What a placeholder of a kind that is neither a path nor a name stands for: an object field, a file id or a day.
	 *
	 * @param array{kind: string, rest: string} $parts   The placeholder's kind and reference.
	 * @param array<string, mixed>              $context See fill().
	 *
	 * @return mixed Null for an unknown kind.
	 */
	private function otherValue(array $parts, array $context): mixed {
		return match ($parts['kind']) {
			'object' => $this->objectField(objects: $this->entries(context: $context, key: 'objects'), reference: $parts['rest']),
			'file'   => $this->fileId(files: $this->entries(context: $context, key: 'files'), reference: $parts['rest']),
			'date', 'datetime' => $this->moment->dayOrMoment(parts: $parts, today: ($context['today'] ?? null)),
			default  => null,
		};
	}//end otherValue()

	/**
	 * One map of entries of the context, empty when it has none.
	 *
	 * @param array<string, mixed> $context See fill().
	 * @param string               $key     The context key.
	 *
	 * @return array<mixed>
	 */
	private function entries(array $context, string $key): array {
		return (array) ($context[$key] ?? []);
	}//end entries()

	/**
	 * The id of one file an object declared earlier in the fragment carries.
	 *
	 * @param array<string, array<int, int>> $files     File ids by object key, in declared order.
	 * @param string                         $reference `key.n`, n counted from 0.
	 *
	 * @return int|null The file id, or null when there is no such file.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-an-object-of-a-set-may-carry-files-from-its-fragment-req-exs-013
	 */
	private function fileId(array $files, string $reference): ?int {
		[$key, $index] = array_pad(explode('.', $reference, 2), 2, '');
		if (ctype_digit($index) === false) {
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

		return $this->present(value: $this->dig(here: $entries, parts: $parts));
	}//end pathIn()

	/**
	 * The value at a path of keys, or null when a key on the way is missing.
	 *
	 * @param mixed              $here  Where the path starts.
	 * @param array<int, string> $parts The keys, outermost first.
	 *
	 * @return mixed
	 */
	private function dig(mixed $here, array $parts): mixed {
		foreach ($parts as $part) {
			if (is_array($here) === false || array_key_exists($part, $here) === false) {
				return null;
			}

			$here = $here[$part];
		}

		return $here;
	}//end dig()


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

}//end class
