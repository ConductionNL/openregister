<?php

/**
 * How one authorization entry fits one record, and whom it names.
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
 * @spec openspec/changes/an-object-lists-its-grants-with-their-origin/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Service\ConditionMatcher;

/**
 * Evaluates a rule entry's `match` clause against a record for the grants list.
 *
 * The static part of a clause goes through {@see ConditionMatcher}, the
 * matcher the single-object decision uses, so the list and the decision agree
 * by construction. The part that reads who is asking cannot be answered for
 * everybody and is reported as relative instead.
 *
 * @spec openspec/changes/an-object-lists-its-grants-with-their-origin/specs/rbac-scopes/spec.md
 */
class GrantRuleFit {

	/**
	 * Pseudo principals a rule can name, which are not Nextcloud users or groups.
	 *
	 * @var string[]
	 */
	private const PSEUDO_PRINCIPALS = ['public', 'authenticated'];

	/**
	 * Tokens in a match value that read who is asking.
	 *
	 * @var string[]
	 */
	private const CALLER_TOKENS = ['$userId', '$user', '$organisation', '$activeOrganisation'];

	/**
	 * Constructor.
	 *
	 * @param ConditionMatcher $matcher The matcher the single-object decision uses.
	 */
	public function __construct(
		private readonly ConditionMatcher $matcher,
	) {
	}//end __construct()

	/**
	 * How one rule entry fits this record, or null when its condition does not.
	 *
	 * The static part of a `match` clause is evaluated against the record. The
	 * part that reads who is asking cannot be answered for everybody, so the
	 * entry is kept and reported as relative.
	 *
	 * @param HeldRule             $rule     The entry as written and the role it came through.
	 * @param array<string, mixed> $envelope The record as the matcher reads it.
	 *
	 * @return array{matchedOn: string[], relative: bool}|null The fit.
	 *
	 * @spec openspec/changes/an-object-lists-its-grants-with-their-origin/specs/rbac-scopes/spec.md
	 */
	public function fitOf(HeldRule $rule, array $envelope): ?array {
		return $this->fitOfMatch(match: $rule->matchClause(), envelope: $envelope);
	}//end fitOf()

	/**
	 * How one `match` clause fits this record, or null when its static part does not.
	 *
	 * @param mixed                $match    The clause as written.
	 * @param array<string, mixed> $envelope The record as the matcher reads it.
	 *
	 * @return array{matchedOn: string[], relative: bool}|null The fit.
	 */
	private function fitOfMatch(mixed $match, array $envelope): ?array {
		if (is_array($match) === false || $match === []) {
			return ['matchedOn' => [], 'relative' => false];
		}

		$static = $this->staticPart(match: $match);
		if ($static !== [] && $this->matcher->objectMatchesConditions(object: $envelope, match: $static) === false) {
			return null;
		}

		return ['matchedOn' => array_map('strval', array_keys($match)), 'relative' => count($static) !== count($match)];
	}//end fitOfMatch()

	/**
	 * The part of a `match` clause that does not read who is asking.
	 *
	 * @param array<string, mixed> $match The clause.
	 *
	 * @return array<string, mixed> The static conditions.
	 */
	private function staticPart(array $match): array {
		$static = [];
		foreach ($match as $property => $value) {
			if ($this->readsTheCaller(value: $value) === false) {
				$static[$property] = $value;
			}
		}

		return $static;
	}//end staticPart()

	/**
	 * Whether a match value reads who is asking.
	 *
	 * @param mixed $value The value as written, a token, a literal or an operator object.
	 *
	 * @return bool True when it names the caller, the caller's groups or organisation, or `$memberOf`.
	 */
	private function readsTheCaller(mixed $value): bool {
		if (is_string($value) === true) {
			if (in_array($value, self::CALLER_TOKENS, true) === true) {
				return true;
			}

			foreach (self::CALLER_TOKENS as $token) {
				if (str_starts_with($value, $token . '.') === true) {
					return true;
				}
			}

			return false;
		}

		if (is_array($value) === false) {
			return false;
		}

		if (array_key_exists(MemberOfResolver::OPERATOR, $value) === true) {
			return true;
		}

		foreach ($value as $operand) {
			if ($this->readsTheCaller(value: $operand) === true) {
				return true;
			}
		}

		return false;
	}//end readsTheCaller()

	/**
	 * The type and id of the principal a rule names.
	 *
	 * @param string $principal The principal as the rule reader reports it.
	 * @param mixed  $entry     The entry as written, or a role's holder list.
	 *
	 * @return array{0: string, 1: string} The type and id.
	 *
	 * @spec openspec/changes/an-object-lists-its-grants-with-their-origin/specs/rbac-scopes/spec.md
	 */
	public function principalOf(string $principal, mixed $entry): array {
		if (str_starts_with($principal, 'user:') === true) {
			return ['user', substr($principal, strlen('user:'))];
		}

		$entries = [$entry];
		if (is_array($entry) === true && array_is_list($entry) === true) {
			$entries = $entry;
		}

		foreach ($entries as $candidate) {
			if (is_array($candidate) === true && ($candidate['user'] ?? null) === $principal) {
				return ['user', $principal];
			}
		}

		if (in_array($principal, self::PSEUDO_PRINCIPALS, true) === true) {
			return [$principal, $principal];
		}

		return ['group', $principal];
	}//end principalOf()
}//end class
