<?php

/**
 * How one authorization read rule bears on the organisation filter.
 *
 * Read in one place so the query filter, the schema and register lookups and
 * the repair step that marks old crossing rules cannot disagree about which
 * rule is marked, which rule is an unconditional `public` read and which rule
 * binds a row field to the caller's organisation.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
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
 * @spec openspec/changes/rbac-cross-organisation-is-explicit/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Service\ConditionMatcher;

/**
 * Reads the tenancy meaning of a read rule.
 *
 * @spec openspec/changes/rbac-cross-organisation-is-explicit/specs/rbac-scopes/spec.md
 */
final class ReadRuleTenancy {

	/**
	 * Whether a rule declares `crossOrganisation: true`. Any other value is not a marker.
	 *
	 * @param mixed $rule The rule as written.
	 *
	 * @return bool True when the rule is marked.
	 *
	 * @spec openspec/changes/rbac-cross-organisation-is-explicit/specs/rbac-scopes/spec.md
	 */
	public function isMarked(mixed $rule): bool {
		return is_array($rule) === true && ($rule[MatrixRowWhere::CROSS_ORGANISATION_KEY] ?? false) === true;
	}//end isMarked()

	/**
	 * The marker of a rule as a key to carry into a compiled rule: the marker
	 * when the rule is marked, nothing otherwise.
	 *
	 * @param mixed $rule The rule or matrix row as written.
	 *
	 * @return array<string, true> `['crossOrganisation' => true]` or an empty list.
	 *
	 * @spec openspec/changes/rbac-cross-organisation-is-explicit/specs/rbac-scopes/spec.md
	 */
	public function markerOf(mixed $rule): array {
		return array_filter([MatrixRowWhere::CROSS_ORGANISATION_KEY => $this->isMarked(rule: $rule)]);
	}//end markerOf()

	/**
	 * Whether a list of read rules lets the `public` group read every row: the
	 * plain `"public"` rule, or `{"group": "public"}` with no match.
	 *
	 * @param mixed $readRules The `read` rules of an authorization block.
	 *
	 * @return bool True when `public` reads unconditionally.
	 *
	 * @spec openspec/changes/rbac-cross-organisation-is-explicit/specs/rbac-scopes/spec.md
	 */
	public function readsPublicUnconditionally(mixed $readRules): bool {
		if (is_array($readRules) === false) {
			return false;
		}

		foreach ($readRules as $rule) {
			if ($rule === 'public') {
				return true;
			}

			if (is_array($rule) === true && ($rule['group'] ?? null) === 'public'
				&& (is_array($rule['match'] ?? null) === false || $rule['match'] === [])
			) {
				return true;
			}
		}

		return false;
	}//end readsPublicUnconditionally()

	/**
	 * Whether a rule's match binds a field other than `_organisation` to the
	 * caller's organisation or membership (`{"provider": "$organisation"}`,
	 * `{"administrationId": {"$memberOf": {...}}}`).
	 *
	 * @param array $rule The conditional rule.
	 *
	 * @return bool True when such a field is bound.
	 *
	 * @spec openspec/changes/rbac-cross-organisation-is-explicit/specs/rbac-scopes/spec.md
	 */
	public function bindsFieldToCallerTenancy(array $rule): bool {
		$match = ($rule['match'] ?? null);
		if (is_array($match) === false) {
			return false;
		}

		foreach ($match as $field => $value) {
			if ($field !== '_organisation' && $this->bindsCallerTenancy(value: $value) === true) {
				return true;
			}
		}

		return false;
	}//end bindsFieldToCallerTenancy()

	/**
	 * Whether a match value equals the caller's organisation or membership set.
	 *
	 * Only equality binds: the bare token, `{"$eq": token}` and `$memberOf`.
	 *
	 * @param mixed $value The match value as written.
	 *
	 * @return bool True when the value binds the row to the caller's tenancy.
	 *
	 * @spec openspec/changes/rbac-cross-organisation-is-explicit/specs/rbac-scopes/spec.md
	 */
	public function bindsCallerTenancy(mixed $value): bool {
		if (is_array($value) === true && array_key_exists(MemberOfResolver::OPERATOR, $value) === true) {
			return true;
		}

		return $this->pinsCallerOrganisation(value: $value);
	}//end bindsCallerTenancy()

	/**
	 * Whether a match value is the caller's organisation: the bare token or
	 * `{"$eq": token}`, and nothing else.
	 *
	 * @param mixed $value The match value as written.
	 *
	 * @return bool True when the value equals the caller's organisation.
	 *
	 * @spec openspec/changes/rbac-cross-organisation-is-explicit/specs/rbac-scopes/spec.md
	 */
	public function pinsCallerOrganisation(mixed $value): bool {
		if (is_array($value) === true && count($value) === 1 && array_key_exists('$eq', $value) === true) {
			$value = $value['$eq'];
		}

		return is_string($value) === true && in_array($value, ConditionMatcher::ORGANISATION_TOKENS, true) === true;
	}//end pinsCallerOrganisation()
}//end class
