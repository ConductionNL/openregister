<?php

/**
 * Deny-entry matcher — decides whether one written deny entry reaches one caller.
 *
 * The deny grammar is small but has two shapes: a bare string that names a
 * principal, and an object that names a `group` or a `user`. Reading that
 * grammar — "does THIS entry name one of THESE principals" — and turning the
 * entries of one action into the provenance records an answer carries is a
 * cohesive job of its own, split out of {@see DenyResolver} so the resolver
 * stays the definition of the deny CASCADE and this class stays the definition
 * of the deny VOCABULARY. Both live in one namespace, read one grammar, and
 * every enforcement path reaches them through {@see DenyResolver}.
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

/**
 * Matches deny entries against a caller's principals and records the matches.
 */
class DenyEntryMatcher {

	/**
	 * The reserved scope token that describes an agent surface.
	 *
	 * It never matches a caller on the grant side, so it must never match one
	 * on the deny side either — a token that denies but cannot grant would give
	 * `mcp` a meaning nobody declared.
	 *
	 * @var string
	 */
	private const SCOPE_MCP = 'mcp';

	/**
	 * Whether one deny entry names one of these principals.
	 *
	 * @param mixed    $entry      A single deny entry.
	 * @param string[] $principals The caller's principal names.
	 *
	 * @return string|null The principal the entry named, or null when it names none.
	 *
	 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
	 */
	public function entryNames(mixed $entry, array $principals): ?string {
		if (is_string($entry) === true) {
			return $this->namesStringEntry(entry: $entry, principals: $principals);
		}

		if (is_array($entry) === true) {
			return $this->namesArrayEntry(entry: $entry, principals: $principals);
		}

		return null;
	}//end entryNames()

	/**
	 * Whether a bare-string deny entry names one of these principals.
	 *
	 * @param string   $entry      A string deny entry.
	 * @param string[] $principals The caller's principal names.
	 *
	 * @return string|null The principal the entry named, or null when it names none.
	 */
	private function namesStringEntry(string $entry, array $principals): ?string {
		if ($entry === self::SCOPE_MCP) {
			return null;
		}

		if (in_array(needle: $entry, haystack: $principals, strict: true) === true) {
			return $entry;
		}

		return null;
	}//end namesStringEntry()

	/**
	 * Whether an object deny entry — one naming `group` or `user` — reaches these principals.
	 *
	 * @param array    $entry      An array deny entry.
	 * @param string[] $principals The caller's principal names.
	 *
	 * @return string|null The principal the entry named, or null when it names none.
	 */
	private function namesArrayEntry(array $entry, array $principals): ?string {
		$group = ($entry['group'] ?? null);
		if (is_string($group) === true && $group !== self::SCOPE_MCP
			&& in_array(needle: $group, haystack: $principals, strict: true) === true
		) {
			return $group;
		}

		$user = ($entry['user'] ?? null);
		if (is_string($user) === true && $user !== '') {
			$named = (DenyResolver::USER_PREFIX . $user);
			if (in_array(needle: $named, haystack: $principals, strict: true) === true) {
				return $named;
			}
		}

		return null;
	}//end namesArrayEntry()

	/**
	 * The deny entries that reach this caller, as provenance records.
	 *
	 * Each result is a provenance record, not a bare entry: the rule as written,
	 * the principal it named, the action it removes and whether it carries a
	 * `match` clause. That record is what an answer carries when it has to say
	 * WHY a verb is absent.
	 *
	 * @param array<int, mixed> $entries    The deny entries written for the action, in declaration order.
	 * @param string            $action     The verb being decided.
	 * @param string[]          $principals The caller's principal names.
	 *
	 * @return array<int, array{rule: mixed, principal: string, action: string, conditional: bool}> The matching denials.
	 *
	 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
	 */
	public function matchingDenials(array $entries, string $action, array $principals): array {
		$matches = [];

		foreach ($entries as $entry) {
			$named = $this->entryNames(entry: $entry, principals: $principals);
			if ($named === null) {
				continue;
			}

			$conditional = (is_array($entry) === true
				&& isset($entry['match']) === true
				&& is_array($entry['match']) === true
				&& $entry['match'] !== []);

			$matches[] = [
				'rule' => $entry,
				'principal' => $named,
				'action' => $action,
				'conditional' => $conditional,
			];
		}

		return $matches;
	}//end matchingDenials()
}//end class
