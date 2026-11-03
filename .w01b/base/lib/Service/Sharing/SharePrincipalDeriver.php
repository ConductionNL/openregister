<?php

/**
 * Share-principal deriver.
 *
 * Validates a `sharedWith[]` list and recomputes the two derived scalar lists
 * (`sharedUsers`, `sharedGroups`) that the RBAC predicates match.
 *
 * WHY two representations of one fact exist at all: the RBAC match operator
 * `$contains` compares SCALARS, so it cannot test membership of the
 * object-shaped `sharedWith` entries (`{type, id, permission}`); and a match
 * clause resolves WHOLE tokens and cannot build `"user:" + $userId`, so a single
 * prefixed list would be unmatchable by `$userId` too. Two unprefixed lists let
 * both predicates be expressed with the EXISTING vocabulary:
 *
 *   {"sharedUsers":  {"$contains": "$userId"}}
 *   {"sharedGroups": {"$contains": "$user.groups"}}
 *
 * The hazard that creates is drift: a stale derived list is an access-control
 * bug in whichever direction it is stale — it either hides an object from
 * someone entitled to it, or shows it to someone who is not. So the derived
 * lists are ALWAYS recomputed here and any client-supplied value is discarded.
 * They are outputs, never inputs.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Sharing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/shared-credentials-and-flows/specs/credential-sharing/spec.md#requirement-a-brokered-credential-carries-a-principal-share-list
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Sharing;

/**
 * Validates share lists and derives the scalar principal lists RBAC matches.
 */
class SharePrincipalDeriver {

	/**
	 * The rich share list property.
	 *
	 * @var string
	 */
	public const PROP_SHARED_WITH = 'sharedWith';

	/**
	 * The derived user-principal list property.
	 *
	 * @var string
	 */
	public const PROP_SHARED_USERS = 'sharedUsers';

	/**
	 * The derived group-principal list property.
	 *
	 * @var string
	 */
	public const PROP_SHARED_GROUPS = 'sharedGroups';

	/**
	 * Principal kind naming a Nextcloud user.
	 *
	 * @var string
	 */
	public const TYPE_USER = 'user';

	/**
	 * Principal kind naming a Nextcloud group.
	 *
	 * @var string
	 */
	public const TYPE_GROUP = 'group';

	/**
	 * Recompute the derived principal lists on an object body.
	 *
	 * Call this on EVERY write. Any `sharedUsers` / `sharedGroups` the caller
	 * supplied is discarded and replaced — they are derived, and accepting them
	 * would let a client grant itself access without appearing in `sharedWith`.
	 *
	 * An absent or malformed `sharedWith` yields two EMPTY lists rather than
	 * leaving whatever was there before, so a share list cleared by an update
	 * cannot leave stale principals behind still granting access.
	 *
	 * @param array<string, mixed> $object The object body being written.
	 *
	 * @return array<string, mixed> The body with the derived lists recomputed.
	 */
	public function apply(array $object): array {
		$entries = $this->validEntries(sharedWith: ($object[self::PROP_SHARED_WITH] ?? null));

		$users = [];
		$groups = [];

		foreach ($entries as $entry) {
			if ($entry['type'] === self::TYPE_USER) {
				$users[] = $entry['id'];
				continue;
			}

			$groups[] = $entry['id'];
		}

		// Deduplicate: the same principal listed twice must not appear twice in
		// the predicate's haystack. array_values keeps them JSON arrays rather
		// than objects with gappy keys, which jsonb containment would not match.
		$object[self::PROP_SHARED_USERS] = array_values(array_unique($users));
		$object[self::PROP_SHARED_GROUPS] = array_values(array_unique($groups));

		return $object;
	}//end apply()

	/**
	 * The entries of a share list that are well-formed enough to grant anything.
	 *
	 * Fails closed per entry: an unknown `type`, a missing/blank/non-string `id`,
	 * or a non-array entry is DROPPED rather than stored, so a malformed entry
	 * can never widen access. Dropping one entry does not invalidate the others.
	 *
	 * @param mixed $sharedWith The raw `sharedWith` value.
	 *
	 * @return array<int, array{type: string, id: string, permission: string|null}> Valid entries.
	 */
	public function validEntries(mixed $sharedWith): array {
		if (is_array($sharedWith) === false) {
			return [];
		}

		$valid = [];

		foreach ($sharedWith as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$type = ($entry['type'] ?? null);
			if ($type !== self::TYPE_USER && $type !== self::TYPE_GROUP) {
				continue;
			}

			$id = ($entry['id'] ?? null);
			if (is_string($id) === false || trim($id) === '') {
				continue;
			}

			$permission = ($entry['permission'] ?? null);
			if (is_string($permission) === false) {
				$permission = null;
			}

			$valid[] = [
				'type' => $type,
				'id' => trim($id),
				'permission' => $permission,
			];
		}//end foreach

		return $valid;
	}//end validEntries()

	/**
	 * Whether a principal holds one of the given permissions on a share list.
	 *
	 * The RBAC predicate can only grant VISIBILITY — it matches the derived
	 * lists and knows nothing about the permission verb. Anything that enforces
	 * a verb (the flow trigger endpoint refusing a `read`-only recipient) has to
	 * read the rich list, which is what this answers.
	 *
	 * @param mixed $sharedWith The raw `sharedWith` value.
	 * @param string $userId The acting user id.
	 * @param string[] $userGroups The acting user's group ids.
	 * @param array<string|null> $permissions Permissions that satisfy the check.
	 *        null is a MEANINGFUL member, not sloppiness: a share entry that
	 *        carries no explicit permission has `permission => null`, and both
	 *        callers pass `['use', null]` to mean "an unqualified share counts
	 *        as 'use'". Narrowing this to string[] would silently stop matching
	 *        those entries.
	 *
	 * @return bool True when a matching principal holds one of the permissions.
	 */
	public function grants(mixed $sharedWith, string $userId, array $userGroups, array $permissions): bool {
		if ($userId === '') {
			return false;
		}

		foreach ($this->validEntries(sharedWith: $sharedWith) as $entry) {
			$matchesPrincipal = ($entry['type'] === self::TYPE_USER && $entry['id'] === $userId)
				|| ($entry['type'] === self::TYPE_GROUP && in_array($entry['id'], $userGroups, true) === true);

			if ($matchesPrincipal === false) {
				continue;
			}

			if (in_array($entry['permission'], $permissions, true) === true) {
				return true;
			}
		}//end foreach

		return false;
	}//end grants()
}//end class
