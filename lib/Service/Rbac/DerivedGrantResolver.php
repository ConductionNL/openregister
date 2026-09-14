<?php

/**
 * Access derived from what an identity provider asserts, at sign-in.
 *
 * A new employee starts on Monday, and somebody has to put them in the right
 * groups before they can do their job. That person is the bottleneck, the
 * matrix they maintain is the thing nobody reviews, and the row that stays in it
 * after somebody changes department is the finding an auditor eventually makes.
 *
 * The identity provider already knows the department. This maps what it asserts
 * to roles, groups and an area, once per sign-in, from rules an administrator
 * writes down (design D-11).
 *
 * FAIL CLOSED, IN BOTH DIRECTIONS. No claims means no derived access, not the
 * access from last time: a sign-in that asserts nothing is the case where an
 * account has lost its mapping, and carrying the old set forward would keep
 * somebody in a department they left. A rule that names no claim, or no grant,
 * is skipped and reported rather than guessed at.
 *
 * DERIVATION IS NOT AUTHENTICATION. This never decides whether somebody may sign
 * in, and never removes a group a Nextcloud administrator put a person in. It
 * only adds, and everything it adds is scoped and re-derived at the next
 * sign-in.
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
 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use DateTimeImmutable;

/**
 * Turns the claims of one sign-in into the grants an administrator declared.
 *
 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
 */
class DerivedGrantResolver {

	/**
	 * The rule key naming the claim to read.
	 *
	 * @var string
	 */
	public const CLAIM_KEY = 'claim';

	/**
	 * The rule key holding the one value the claim must carry.
	 *
	 * @var string
	 */
	public const EQUALS_KEY = 'equals';

	/**
	 * The rule key holding the set of values any of which satisfies the rule.
	 *
	 * @var string
	 */
	public const ONE_OF_KEY = 'oneOf';

	/**
	 * Derive the grants one sign-in produces.
	 *
	 * @param array<string, mixed>            $claims The claims the identity provider asserted.
	 * @param array<int, array<string, mixed>>|mixed $rules  The declared rules.
	 *
	 * @return array{grants: array<int, array<string, mixed>>, skipped: array<int, string>}
	 *         The grants, and one sentence per rule that could not be read.
	 *
	 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
	 */
	public function derive(array $claims, mixed $rules): array {
		if (is_array($rules) === false || $rules === []) {
			return ['grants' => [], 'skipped' => []];
		}

		$grants = [];
		$skipped = [];

		foreach ($rules as $index => $rule) {
			$unreadable = $this->reasonToSkip(rule: $rule, index: (string)$index);
			if ($unreadable !== null) {
				$skipped[] = $unreadable;
				continue;
			}

			$claim = (string)$rule[self::CLAIM_KEY];
			if ($this->matches(rule: $rule, asserted: ($claims[$claim] ?? null)) === false) {
				continue;
			}

			$grants[] = [
				'rule' => (string)$index,
				'claim' => $claim,
				'groups' => $this->stringsIn(value: ($rule['groups'] ?? [])),
				'role' => $this->roleNameIn(rule: $rule),
				GrantConstraints::SCOPED_TO_KEY => ($rule[GrantConstraints::SCOPED_TO_KEY] ?? null),
				GrantConstraints::UNTIL_KEY => ($rule[GrantConstraints::UNTIL_KEY] ?? null),
			];
		}//end foreach

		return ['grants' => $grants, 'skipped' => $skipped];
	}//end derive()

	/**
	 * Why one rule cannot be read, or null when it can.
	 *
	 * Reported rather than dropped. A rule an administrator wrote and nothing
	 * ever fires is worse than one that fails: it looks configured.
	 *
	 * @param mixed  $rule  The rule as written.
	 * @param string $index Where it sits in the list, for the message.
	 *
	 * @return string|null The reason, or null when the rule is readable.
	 */
	private function reasonToSkip(mixed $rule, string $index): ?string {
		if (is_array($rule) === false) {
			return sprintf('Rule %s is not an object and was skipped.', $index);
		}

		$claim = ($rule[self::CLAIM_KEY] ?? null);
		if (is_string($claim) === false || $claim === '') {
			return sprintf('Rule %s names no claim and was skipped.', $index);
		}

		if ($this->stringsIn(value: ($rule['groups'] ?? [])) === [] && $this->roleNameIn(rule: $rule) === null) {
			return sprintf('Rule %s grants neither a group nor a role and was skipped.', $index);
		}

		return null;
	}//end reasonToSkip()

	/**
	 * The groups a set of derived grants hands this caller, in one area.
	 *
	 * The area is read here rather than at derivation, because a grant derived
	 * at sign-in is asked about once per register a person opens, and the answer
	 * is different in each.
	 *
	 * @param array<int, array<string, mixed>> $grants      The stored grants.
	 * @param array<string, mixed>             $area        Keys `register` and `schema`.
	 * @param GrantConstraints                 $constraints The reader of `until` and `scopedTo`.
	 *
	 * @return array<int, string> The group ids this caller holds here.
	 *
	 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
	 */
	public function groupsFor(array $grants, array $area, GrantConstraints $constraints): array {
		$now = new DateTimeImmutable();

		$groups = [];
		foreach ($grants as $grant) {
			if (is_array($grant) === false) {
				continue;
			}

			if ($constraints->reaches(entry: $grant, area: $area, now: $now) === false) {
				continue;
			}

			foreach ($this->stringsIn(value: ($grant['groups'] ?? [])) as $group) {
				if (in_array($group, $groups, true) === false) {
					$groups[] = $group;
				}
			}
		}

		return $groups;
	}//end groupsFor()

	/**
	 * Whether the asserted claim satisfies one rule.
	 *
	 * A claim asserted as a list satisfies the rule when any of its values does,
	 * because `groups` and `roles` claims arrive that way and a rule written
	 * against one of them is the ordinary case rather than the exotic one.
	 *
	 * @param array<string, mixed> $rule     The rule.
	 * @param mixed                $asserted What the provider asserted for that claim.
	 *
	 * @return bool True when the rule fires.
	 */
	private function matches(array $rule, mixed $asserted): bool {
		if ($asserted === null) {
			return false;
		}

		$wanted = [];
		if (array_key_exists(self::EQUALS_KEY, $rule) === true && is_scalar($rule[self::EQUALS_KEY]) === true) {
			$wanted[] = (string)$rule[self::EQUALS_KEY];
		}

		foreach ($this->stringsIn(value: ($rule[self::ONE_OF_KEY] ?? [])) as $value) {
			$wanted[] = $value;
		}

		// A rule that names no value it wants fires on nothing. The other
		// reading, "any value at all", turns a half-written rule into a grant
		// for everybody the provider knows about.
		if ($wanted === []) {
			return false;
		}

		$values = [$asserted];
		if (is_array($asserted) === true) {
			$values = $asserted;
		}

		$assertedValues = [];
		foreach ($values as $value) {
			if (is_scalar($value) === true) {
				$assertedValues[] = (string)$value;
			}
		}

		return array_intersect($wanted, $assertedValues) !== [];
	}//end matches()

	/**
	 * The role one rule names, or null when it names none.
	 *
	 * @param array<string, mixed> $rule The rule.
	 *
	 * @return string|null The role name.
	 */
	private function roleNameIn(array $rule): ?string {
		$role = ($rule['role'] ?? null);
		if (is_string($role) === true && $role !== '') {
			return $role;
		}

		return null;
	}//end roleNameIn()

	/**
	 * The strings in a value that may be a list, a string, or nothing.
	 *
	 * @param mixed $value The value.
	 *
	 * @return array<int, string> The strings.
	 */
	private function stringsIn(mixed $value): array {
		if (is_string($value) === true && $value !== '') {
			return [$value];
		}

		if (is_array($value) === false) {
			return [];
		}

		return array_values(array_filter($value, static fn (mixed $item): bool => is_string($item) === true && $item !== ''));
	}//end stringsIn()
}//end class
