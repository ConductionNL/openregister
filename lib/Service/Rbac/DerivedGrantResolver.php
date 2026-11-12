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
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use DateTimeImmutable;

/**
 * Turns the claims of one sign-in into the grants an administrator declared.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
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
	 * Constructor.
	 *
	 * @param GrantConstraints $constraints The reader of `until` and `scopedTo`.
	 */
	public function __construct(
		private readonly GrantConstraints $constraints = new GrantConstraints(),
	) {
	}//end __construct()

	/**
	 * Derive the grants one sign-in produces.
	 *
	 * @param array<string, mixed>            $claims The claims the identity provider asserted.
	 * @param array<int, array<string, mixed>>|mixed $rules  The declared rules.
	 *
	 * @return array{grants: array<int, array<string, mixed>>, skipped: array<int, string>}
	 *         The grants, and one sentence per rule that could not be read.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function derive(array $claims, mixed $rules): array {
		if (is_array($rules) === false || $rules === []) {
			return ['grants' => [], 'skipped' => []];
		}

		return [
			'grants' => $this->grantsFor(claims: $claims, rules: $rules),
			'skipped' => $this->skippedIn(rules: $rules),
		];
	}//end derive()

	/**
	 * One grant per readable rule the claims satisfy, in rule order.
	 *
	 * @param array<string, mixed> $claims The claims the identity provider asserted.
	 * @param array<int|string, mixed> $rules The declared rules.
	 *
	 * @return array<int, array<string, mixed>> The grants.
	 */
	private function grantsFor(array $claims, array $rules): array {
		$grants = [];
		foreach ($rules as $index => $rule) {
			if ($this->reasonToSkip(rule: $rule, index: (string)$index) !== null) {
				continue;
			}

			$claim = (string)$rule[self::CLAIM_KEY];
			if ($this->matches(rule: $rule, asserted: ($claims[$claim] ?? null)) === true) {
				$grants[] = ['rule' => (string)$index, 'claim' => $claim] + $this->grantTerms(rule: $rule);
			}
		}

		return $grants;
	}//end grantsFor()

	/**
	 * What a fired rule grants: its groups and role, and the constraints it carries.
	 *
	 * @param array<string, mixed> $rule The readable rule.
	 *
	 * @return array<string, mixed> The grant's groups, role, scope and end.
	 */
	private function grantTerms(array $rule): array {
		return [
			'groups' => $this->stringsIn(value: ($rule['groups'] ?? [])),
			'role' => $this->roleNameIn(rule: $rule),
			GrantConstraints::SCOPED_TO_KEY => ($rule[GrantConstraints::SCOPED_TO_KEY] ?? null),
			GrantConstraints::UNTIL_KEY => ($rule[GrantConstraints::UNTIL_KEY] ?? null),
		];
	}//end grantTerms()

	/**
	 * One sentence per rule that cannot be read, in rule order.
	 *
	 * @param array<int|string, mixed> $rules The declared rules.
	 *
	 * @return array<int, string> The reasons.
	 */
	private function skippedIn(array $rules): array {
		$skipped = [];
		foreach ($rules as $index => $rule) {
			$unreadable = $this->reasonToSkip(rule: $rule, index: (string)$index);
			if ($unreadable !== null) {
				$skipped[] = $unreadable;
			}
		}

		return $skipped;
	}//end skippedIn()

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
	 * @param array<int, array<string, mixed>> $grants The stored grants.
	 * @param array<string, mixed>             $area   Keys `register` and `schema`.
	 *
	 * @return array<int, string> The group ids this caller holds here.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function groupsFor(array $grants, array $area): array {
		$point    = new ConstraintPoint(area: $area, now: new DateTimeImmutable());
		$reaching = array_filter(
			$grants,
			fn (mixed $grant): bool => is_array($grant) === true && $this->constraints->reaches(entry: $grant, point: $point) === true
		);

		return $this->groupsIn(grants: $reaching);
	}//end groupsFor()

	/**
	 * The distinct group ids a set of grants names, in first-seen order.
	 *
	 * @param array<int, array<string, mixed>> $grants The grants.
	 *
	 * @return array<int, string> The group ids.
	 */
	private function groupsIn(array $grants): array {
		$groups = [];
		foreach ($grants as $grant) {
			foreach ($this->stringsIn(value: ($grant['groups'] ?? [])) as $group) {
				if (in_array($group, $groups, true) === false) {
					$groups[] = $group;
				}
			}
		}

		return $groups;
	}//end groupsIn()

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

		$wanted = $this->wantedValues(rule: $rule);

		// A rule that names no value it wants fires on nothing. The other
		// reading, "any value at all", turns a half-written rule into a grant
		// for everybody the provider knows about.
		if ($wanted === []) {
			return false;
		}

		return array_intersect($wanted, self::assertedValues(asserted: $asserted)) !== [];
	}//end matches()

	/**
	 * The values a rule wants: its scalar `equals`, then its `oneOf` strings.
	 *
	 * @param array<string, mixed> $rule The rule.
	 *
	 * @return array<int, string> The wanted values.
	 */
	private function wantedValues(array $rule): array {
		$wanted = [];
		if (array_key_exists(self::EQUALS_KEY, $rule) === true && is_scalar($rule[self::EQUALS_KEY]) === true) {
			$wanted[] = (string)$rule[self::EQUALS_KEY];
		}

		foreach ($this->stringsIn(value: ($rule[self::ONE_OF_KEY] ?? [])) as $value) {
			$wanted[] = $value;
		}

		return $wanted;
	}//end wantedValues()

	/**
	 * The scalar values the provider asserted for a claim, one or a list, as strings.
	 *
	 * @param mixed $asserted What the provider asserted.
	 *
	 * @return array<int, string> The values.
	 */
	private static function assertedValues(mixed $asserted): array {
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

		return $assertedValues;
	}//end assertedValues()

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
