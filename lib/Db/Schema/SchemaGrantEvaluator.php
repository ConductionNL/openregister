<?php

/**
 * SchemaGrantEvaluator: the answer of the deprecated Schema::hasPermission().
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\Schema
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Db\Schema;


/**
 * Answers whether a schema's authorization block lets a group perform an action.
 *
 * The body of Schema::hasPermission(), which is deprecated and has no
 * production callers: the canonical evaluators are PermissionHandler (PHP
 * side) and MagicRbacHandler (SQL side). Admin and owner bypass first, then an
 * empty block allows everything, then an unlisted action is denied.
 *
 * No strict_types: moved out of Schema, which declares none, so every value
 * keeps the coercion it had there.
 *
 * @spec exclude schema plumbing moved out of the entity, not a capability of its own
 */
final class SchemaGrantEvaluator {

	/**
	 * Whether the authorization block grants the query.
	 *
	 * @param array|null $authorization The schema's authorization block.
	 * @param GrantQuery $query         The question.
	 *
	 * @return bool True if the group has permission for the action
	 */
	public function allows(?array $authorization, GrantQuery $query): bool {
		if ($this->bypasses(query: $query) === true) {
			return true;
		}

		// If no authorization is set, everyone has all permissions.
		if (empty($authorization) === true) {
			return true;
		}

		// Fail-closed (rbac-default-deny): once a schema opts into authorization
		// (non-empty block), an action that is not explicitly listed is denied —
		// including the `public` pseudo-group. The empty-block open-default above
		// and the admin/owner bypasses still apply. Kept consistent with the
		// active enforcement paths (PermissionHandler / MagicRbacHandler) even
		// though this entity-level helper currently has no lib/ enforcement callers.
		if (empty($authorization[$query->action]) === true) {
			return false;
		}

		return $this->anyEntryGrants(entries: $authorization[$query->action], query: $query);
	}//end allows()

	/**
	 * Whether the admin group or the object's owner asks; both have all permissions.
	 *
	 * @param GrantQuery $query The question.
	 *
	 * @return bool
	 */
	private function bypasses(GrantQuery $query): bool {
		// Admin group always has all permissions.
		if ($query->groupId === 'admin' || $query->actor->userGroup === 'admin') {
			return true;
		}

		// Object owner always has all permissions for their specific objects.
		return $query->actor->userId !== null && $query->object->owner !== null && $query->object->owner === $query->actor->userId;
	}//end bypasses()

	/**
	 * Whether any entry listed for the action grants the query.
	 *
	 * @param mixed      $entries The entries listed for the action.
	 * @param GrantQuery $query   The question.
	 *
	 * @return bool
	 */
	private function anyEntryGrants(mixed $entries, GrantQuery $query): bool {
		foreach ($entries as $entry) {
			if ($this->entryGrants(entry: $entry, query: $query) === true) {
				return true;
			}
		}

		return false;
	}//end anyEntryGrants()

	/**
	 * Whether one entry grants the query.
	 *
	 * Simple string entry: direct group match. Complex entry with match
	 * conditions: {"group": "...", "match": {"field": "value"}}.
	 *
	 * @param mixed      $entry The entry.
	 * @param GrantQuery $query The question.
	 *
	 * @return bool
	 */
	private function entryGrants(mixed $entry, GrantQuery $query): bool {
		if (is_string($entry) === true) {
			return $entry === $query->groupId;
		}

		if (is_array($entry) === true && isset($entry['group']) === true && $entry['group'] === $query->groupId) {
			return $this->conditionalEntryGrants(entry: $entry, query: $query);
		}

		return false;
	}//end entryGrants()

	/**
	 * Whether a conditional entry for the asked group grants the query.
	 *
	 * If no match conditions, the group match alone is sufficient; otherwise
	 * all match conditions must pass.
	 *
	 * @param array      $entry The entry, naming the asked group.
	 * @param GrantQuery $query The question.
	 *
	 * @return bool
	 */
	private function conditionalEntryGrants(array $entry, GrantQuery $query): bool {
		if (isset($entry['match']) === false || empty($entry['match']) === true) {
			return true;
		}

		return $this->conditionsHold(conditions: $entry['match'], query: $query);
	}//end conditionalEntryGrants()

	/**
	 * Evaluate match conditions against object data.
	 *
	 * Supports simple equality matching and special variables:
	 * - `$organisation`: resolves to the user's active organisation UUID
	 * - `_organisation` field: matches against the object's @self.organisation
	 *
	 * @param array      $conditions The match conditions (field => expected value)
	 * @param GrantQuery $query      The question.
	 *
	 * @return bool True if all conditions match
	 */
	private function conditionsHold(array $conditions, GrantQuery $query): bool {
		foreach ($conditions as $field => $expectedValue) {
			if ($this->conditionHolds(field: $field, expectedValue: $expectedValue, query: $query) === false) {
				return false;
			}
		}

		return true;
	}//end conditionsHold()

	/**
	 * Whether one match condition holds.
	 *
	 * @param int|string $field         The matched field.
	 * @param mixed      $expectedValue The expected value, or `$organisation`.
	 * @param GrantQuery $query         The question.
	 *
	 * @return bool
	 */
	private function conditionHolds(int|string $field, mixed $expectedValue, GrantQuery $query): bool {
		// Resolve $organisation variable in the expected value.
		if ($expectedValue === '$organisation') {
			if ($query->actor->activeOrganisation === null) {
				return false;
			}

			$expectedValue = $query->actor->activeOrganisation;
		}

		return $this->actualValue(field: $field, object: $query->object) === $expectedValue;
	}//end conditionHolds()

	/**
	 * The value a condition compares against.
	 *
	 * Regular field: match against object data. Special field `_organisation`:
	 * match against @self.organisation. A resolved relation (an array with an
	 * `id` key) compares by its id.
	 *
	 * @param int|string  $field  The matched field.
	 * @param GrantObject $object The object asked about.
	 *
	 * @return mixed
	 */
	private function actualValue(int|string $field, GrantObject $object): mixed {
		$actualValue = $object->data[$field] ?? null;
		if ($field === '_organisation') {
			$actualValue = $object->organisation;
		}

		if (is_array($actualValue) === true && isset($actualValue['id']) === true) {
			$actualValue = $actualValue['id'];
		}

		return $actualValue;
	}//end actualValue()
}//end class
