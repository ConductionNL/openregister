<?php

/**
 * Who a step is asking: a type and an id, never a bare name.
 *
 * `"jdoe"` on an assignee field is ambiguous on any real instance: a Nextcloud
 * deployment may hold a user AND a group of that name, and the guard this
 * replaces accepted both — uid equality first, then group membership. So the
 * question "may this person answer" had two answers and took whichever came
 * first.
 *
 * A reference says which one it means. `{"type": "group", "id": "bezwaar"}` is
 * a group and nothing else, and an app that owns a concept the engine has never
 * heard of — a position on a body, a function, a case role — says so in the
 * same shape.
 *
 * 🔴 A BARE STRING STILL MEANS `user`. Reading it as "try every type" would be
 * friendlier and would silently widen who may answer on every flow already
 * stored. The guard being replaced tries uid first, so `user` is the reading
 * that preserves today's behaviour rather than quietly granting more.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Principal
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Principal;

use JsonSerializable;

/**
 * A typed reference to whoever a step is asking.
 */
final class PrincipalReference implements JsonSerializable {

	/**
	 * The type a bare string is read as.
	 *
	 * @var string
	 */
	public const DEFAULT_TYPE = 'user';

	/**
	 * Constructor.
	 *
	 * @param string $type The principal type, such as `user` or `group`.
	 * @param string $id   The id within that type.
	 */
	private function __construct(
		public readonly string $type,
		public readonly string $id,
	) {

	}//end __construct()

	/**
	 * Read one reference from what an author stored.
	 *
	 * Accepts a bare string (a user), and `{type, id}` in either the `id` or
	 * the legacy `value` spelling. Anything without a usable id is null rather
	 * than an empty reference: a reference to nobody is not a principal, and
	 * making one would push the emptiness down to the resolver, which would
	 * report "resolved to no users" for something that was never a reference.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return self|null The reference, or null when there is none.
	 *
	 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md
	 */
	public static function from(mixed $value): ?self {
		if (is_string($value) === true) {
			$id = trim($value);

			if ($id === '') {
				return null;
			}

			return new self(type: self::DEFAULT_TYPE, id: $id);
		}

		if (is_array($value) === false) {
			return null;
		}

		$id = trim((string)($value['id'] ?? $value['value'] ?? ''));
		if ($id === '') {
			return null;
		}

		$type = trim((string)($value['type'] ?? ''));
		if ($type === '') {
			$type = self::DEFAULT_TYPE;
		}

		return new self(type: $type, id: $id);

	}//end from()

	/**
	 * Read a list, which may mix types and spellings.
	 *
	 * A single value is read as a list of one, because an author who wrote one
	 * assignee and an author who wrote a list of one mean the same thing.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return array<int, self> The references, in order, without the unusable ones.
	 *
	 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md
	 */
	public static function listFrom(mixed $value): array {
		if ($value === null || $value === '' || $value === []) {
			return [];
		}

		// A `{type, id}` map is itself an array, so a list is only a list when
		// its keys are sequential. Treating the map as a list would read its
		// two VALUES as two references.
		$candidates = $value;
		if (is_array($value) === false || array_is_list($value) === false) {
			$candidates = [$value];
		}

		$references = [];
		foreach ($candidates as $candidate) {
			$reference = self::from(value: $candidate);
			if ($reference !== null) {
				$references[] = $reference;
			}
		}

		return $references;

	}//end listFrom()

	/**
	 * Read a list and stamp every bare entry with one type.
	 *
	 * For the legacy fields whose NAME carried the type: `candidateGroups`
	 * holds bare group names, and reading them as users would silently move
	 * who may answer on every flow already stored.
	 *
	 * An entry that already names its own type keeps it.
	 *
	 * @param mixed  $value The stored value.
	 * @param string $type  The type a bare entry means.
	 *
	 * @return array<int, self> The references.
	 *
	 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md
	 */
	public static function listOfType(mixed $value, string $type): array {
		$references = [];
		foreach (self::listFrom(value: $value) as $reference) {
			if (is_string($value) === true || $reference->type === self::DEFAULT_TYPE) {
				// It came in bare, so the field name is what typed it.
				$references[] = new self(type: $type, id: $reference->id);
				continue;
			}

			$references[] = $reference;
		}

		return $references;

	}//end listOfType()

	/**
	 * The reference as it is stored.
	 *
	 * @return array{type: string, id: string} The stored shape.
	 *
	 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md
	 */
	public function jsonSerialize(): array {
		return ['type' => $this->type, 'id' => $this->id];

	}//end jsonSerialize()

	/**
	 * A stable string, for logs and for comparing two references.
	 *
	 * @return string The reference as `type:id`.
	 *
	 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md
	 */
	public function __toString(): string {
		return $this->type . ':' . $this->id;

	}//end __toString()

	/**
	 * A configured performer value, as a single stored string.
	 *
	 * 🔴 A TYPED REFERENCE CAST TO A STRING PRODUCES THE LITERAL "Array". That
	 * is not a near miss. It was written into the task row and into the run's
	 * resume slot, where the answer guard reads it as a legacy bare string,
	 * matches it against no uid and no group, and leaves the task answerable by
	 * NOBODY — the exact silence typed principals exist to remove, put back by
	 * a cast.
	 *
	 * A bare string passes through, trimmed, because every flow ever authored
	 * names people that way. A single reference becomes `type:id`, which is
	 * what an inbox can predicate on in the datastore: the resolver decides
	 * authorisation, not listing.
	 *
	 * 🔑 SEVERAL REFERENCES ARE NOT ONE STORED VALUE. Picking one of them would
	 * silently drop the others, so the answer is '' and the caller decides what
	 * that means — for a task's assignee column it means the task pools, which
	 * is what the candidate fields are for.
	 *
	 * @param mixed $value The configured value.
	 *
	 * @return string The stored form, or '' when there is no single one.
	 *
	 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md
	 */
	public static function storedString(mixed $value): string {
		if (is_string($value) === true) {
			return trim($value);
		}

		$references = self::listFrom(value: $value);
		if (count($references) !== 1) {
			return '';
		}

		return (string)$references[array_key_first($references)];

	}//end storedString()
}//end class
