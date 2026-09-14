<?php

/**
 * Deny resolver — the single definition of a rule that TAKES a verb away.
 *
 * Every rule in the authorization chain before this one ADDS. A register
 * default grants, a schema rule grants, a role grants, a per-object grant
 * grants, and an ancestor's grant reaches its descendants. So the only way to
 * remove a right was to stop granting it somewhere else, which is the opposite
 * of what an administrator is looking at when they ask "why can this person
 * still open that dossier".
 *
 * A deny is NOT a grant with a lower score. It removes the verb inside its
 * scope and no broader grant puts it back, including a grant inherited from an
 * ancestor object. That single precedence rule is the whole design: letting a
 * more specific grant override a broader deny gives an administrator two rules
 * to reason about at once, and is how a deny quietly stops working.
 *
 * WHY IT LIVES IN ONE CLASS. The verb has to be taken away in the same four
 * places the `private` scope is honoured — the single-object verdict, the
 * relation-path verdict, and both list emitters. A deny honoured on the object
 * read but not in the list is the worst of both answers: the caller sees the
 * row, learns the identifier, and is refused on the read. So the vocabulary,
 * the PHP match and the SQL predicate are defined here exactly once and every
 * path is a caller, for the reasons {@see ObjectScopeResolver} sets out at
 * length.
 *
 * STORAGE. A deny is the `deny` key of an authorization block, and the block
 * under it uses the SAME grammar as the grants beside it:
 *
 *   {
 *     "read":   ["behandelaars"],
 *     "deny":   { "read": ["waarnemers", "user:bob"] }
 *   }
 *
 * One grammar, one expander, one matcher. `deny.roles` expands through the same
 * role expander as the grants do, so a deny may name a role rather than the
 * groups behind it.
 *
 * The key is read at three levels — register, schema and the object's own
 * `_authorization` column — and the three UNION rather than override. An object
 * block that replaced the schema's deny would let an object undo a deny written
 * above it, which is the same "a more specific rule puts it back" failure in
 * another coat.
 *
 * FAIL-CLOSED. An entry this version cannot read still denies when it names one
 * of the caller's principals. Over-denying hides a row the caller was entitled
 * to, which is visible the moment somebody looks for it; under-denying leaks
 * one, which is not.
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
 * Resolves the denied verbs of an authorization block and emits the matching SQL.
 */
class DenyResolver {

	/**
	 * The authorization-block key carrying the denials.
	 *
	 * @var string
	 */
	public const DENY_KEY = 'deny';

	/**
	 * The pseudo-principal every caller carries, authenticated or not.
	 *
	 * @var string
	 */
	public const PRINCIPAL_PUBLIC = 'public';

	/**
	 * The pseudo-principal every signed-in caller carries.
	 *
	 * @var string
	 */
	public const PRINCIPAL_AUTHENTICATED = 'authenticated';

	/**
	 * The prefix that makes a deny entry name one user rather than a group.
	 *
	 * @var string
	 */
	public const USER_PREFIX = 'user:';

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
	 * The characters an action verb may use before it reaches a JSON path.
	 *
	 * @var string
	 */
	private const SAFE_ACTION_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

	/**
	 * A SQL predicate that is never true.
	 *
	 * @var string
	 */
	private const IMPOSSIBLE_SQL_CONDITION = '1 = 0';

	/**
	 * The deny sub-block of one authorization block.
	 *
	 * @param array|null $authorization An authorization block, or null.
	 *
	 * @return array<string, array> The deny block, keyed by action; empty when none is declared.
	 *
	 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
	 */
	public function denyBlock(?array $authorization): array {
		if (is_array($authorization) === false) {
			return [];
		}

		$deny = ($authorization[self::DENY_KEY] ?? null);
		if (is_array($deny) === false) {
			return [];
		}

		return $deny;
	}//end denyBlock()

	/**
	 * Whether a block declares any denial at all.
	 *
	 * The whole deny pass is skipped when this is false, so an instance that
	 * declares no deny resolves exactly as it did before this capability
	 * existed — which is the backwards-compatibility promise, made structural
	 * rather than asserted.
	 *
	 * @param array|null $authorization An authorization block, or null.
	 *
	 * @return bool True when at least one action carries a non-empty deny list.
	 *
	 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
	 */
	public function declaresAnyDeny(?array $authorization): bool {
		foreach ($this->denyBlock(authorization: $authorization) as $entries) {
			if (is_array($entries) === true && $entries !== []) {
				return true;
			}
		}

		return false;
	}//end declaresAnyDeny()

	/**
	 * The deny entries written for one action.
	 *
	 * @param array|null $authorization An authorization block, or null.
	 * @param string     $action        The verb being decided.
	 *
	 * @return array<int, mixed> The entries, in declaration order.
	 *
	 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
	 */
	public function entriesFor(?array $authorization, string $action): array {
		$entries = ($this->denyBlock(authorization: $authorization)[$action] ?? null);
		if (is_array($entries) === false) {
			return [];
		}

		return array_values($entries);
	}//end entriesFor()

	/**
	 * Union two deny blocks, so a narrower level can only ADD denials.
	 *
	 * The grant side of the cascade replaces action by action: a key on the
	 * object takes the schema's place. A deny may not work that way. If an
	 * object's own block could replace the deny written on its schema, then
	 * writing a deny on the object would REMOVE one, and the precedence rule
	 * this whole class exists to keep would hold everywhere except the one
	 * level an author is most likely to edit.
	 *
	 * @param array|null $baseline The deny block resolved from the schema or register.
	 * @param array|null $override The deny block declared closer to the object.
	 *
	 * @return array<string, array> The union, keyed by action.
	 *
	 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
	 */
	public function mergeDeny(?array $baseline, ?array $override): array {
		$merged = [];

		foreach ([$baseline, $override] as $block) {
			if (is_array($block) === false) {
				continue;
			}

			foreach ($block as $action => $entries) {
				if (is_array($entries) === false) {
					continue;
				}

				$merged[$action] = array_merge(($merged[$action] ?? []), array_values($entries));
			}
		}

		return $this->deduplicate(block: $merged);
	}//end mergeDeny()

	/**
	 * Drop entries repeated by the union, keeping declaration order.
	 *
	 * @param array<string, array> $block The merged deny block.
	 *
	 * @return array<string, array> The same block without duplicates.
	 */
	private function deduplicate(array $block): array {
		$result = [];

		foreach ($block as $action => $entries) {
			$seen = [];
			$kept = [];
			foreach ($entries as $entry) {
				$fingerprint = json_encode($entry);
				if ($fingerprint === false) {
					$kept[] = $entry;
					continue;
				}

				if (array_key_exists($fingerprint, $seen) === true) {
					continue;
				}

				$seen[$fingerprint] = true;
				$kept[] = $entry;
			}

			$result[$action] = $kept;
		}

		return $result;
	}//end deduplicate()

	/**
	 * The principal names a deny entry may use to reach one caller.
	 *
	 * `public` is always present: a deny written for the public pseudo-group
	 * takes the verb from everybody it would otherwise have reached, and an
	 * authenticated caller inherits `public` rights on almost every schema, so
	 * a deny that stopped at the anonymous door would be a deny in name only.
	 *
	 * @param string|null $userId     The caller, or null when anonymous.
	 * @param string[]    $userGroups The caller's group IDs.
	 *
	 * @return string[] The principal names, deduplicated.
	 *
	 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
	 */
	public function principalsFor(?string $userId, array $userGroups): array {
		$principals = [self::PRINCIPAL_PUBLIC];

		if ($userId !== null && $userId !== '') {
			$principals[] = self::PRINCIPAL_AUTHENTICATED;
			$principals[] = (self::USER_PREFIX . $userId);
		}

		foreach ($userGroups as $groupId) {
			if (is_string($groupId) === false || $groupId === '') {
				continue;
			}

			$principals[] = $groupId;
		}

		return array_values(array_unique($principals));
	}//end principalsFor()

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
			if ($entry === self::SCOPE_MCP) {
				return null;
			}

			if (in_array(needle: $entry, haystack: $principals, strict: true) === true) {
				return $entry;
			}

			return null;
		}

		if (is_array($entry) === false) {
			return null;
		}

		$group = ($entry['group'] ?? null);
		if (is_string($group) === true && $group !== self::SCOPE_MCP
			&& in_array(needle: $group, haystack: $principals, strict: true) === true
		) {
			return $group;
		}

		$user = ($entry['user'] ?? null);
		if (is_string($user) === true && $user !== '') {
			$named = (self::USER_PREFIX . $user);
			if (in_array(needle: $named, haystack: $principals, strict: true) === true) {
				return $named;
			}
		}

		return null;
	}//end entryNames()

	/**
	 * The deny entries for one action that reach this caller.
	 *
	 * Each result is a provenance record, not a bare entry: the rule as written,
	 * the principal it named, the action it removes and whether it carries a
	 * `match` clause. That record is what an answer carries when it has to say
	 * WHY a verb is absent.
	 *
	 * @param array|null $authorization The resolved authorization block.
	 * @param string     $action        The verb being decided.
	 * @param string[]   $principals    The caller's principal names.
	 *
	 * @return array<int, array{rule: mixed, principal: string, action: string, conditional: bool}> The matching denials.
	 *
	 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
	 */
	public function matchingDenials(?array $authorization, string $action, array $principals): array {
		$matches = [];

		foreach ($this->entriesFor(authorization: $authorization, action: $action) as $entry) {
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

	/**
	 * The first denial that removes the verb outright, whatever the object.
	 *
	 * A conditional denial removes the verb only for the rows its `match` clause
	 * selects, so it is never the answer here.
	 *
	 * @param array|null $authorization The resolved authorization block.
	 * @param string     $action        The verb being decided.
	 * @param string[]   $principals    The caller's principal names.
	 *
	 * @return array{rule: mixed, principal: string, action: string, conditional: bool}|null The denial, or null.
	 *
	 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
	 */
	public function unconditionalDenial(?array $authorization, string $action, array $principals): ?array {
		foreach ($this->matchingDenials(authorization: $authorization, action: $action, principals: $principals) as $denial) {
			if ($denial['conditional'] === false) {
				return $denial;
			}
		}

		return null;
	}//end unconditionalDenial()

	/**
	 * The denials that only bite on the rows their `match` clause selects.
	 *
	 * @param array|null $authorization The resolved authorization block.
	 * @param string     $action        The verb being decided.
	 * @param string[]   $principals    The caller's principal names.
	 *
	 * @return array<int, array{rule: mixed, principal: string, action: string, conditional: bool}> The conditional denials.
	 *
	 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
	 */
	public function conditionalDenials(?array $authorization, string $action, array $principals): array {
		$conditional = [];

		foreach ($this->matchingDenials(authorization: $authorization, action: $action, principals: $principals) as $denial) {
			if ($denial['conditional'] === true) {
				$conditional[] = $denial;
			}
		}

		return $conditional;
	}//end conditionalDenials()

	/**
	 * One platform-appropriate predicate for "this row is NOT denied to this caller".
	 *
	 * This is the object-level half of the deny, and it is the half that has to
	 * be IN the query. A denial that names one object cannot be resolved before
	 * the rows are known, so evaluating it on a fetched page would give a
	 * correct page of wrong data: the total counts rows the caller may not read,
	 * the facet counts are computed over them, and page three is missing what
	 * page two should have shown.
	 *
	 * Emitted as a raw fragment so BOTH list emitters use the same string, for
	 * the reasons {@see ObjectScopeResolver::notPrivateSql()} sets out.
	 *
	 * The entry grammar it can read in SQL is the plain one — a group name, a
	 * `user:<uid>` string, or an object naming `group` or `user`. A `match`
	 * clause inside an OBJECT-level denial is not compiled: containment cannot
	 * express it, and the object naming the row is already the narrowest scope
	 * there is. Such an entry denies the row outright here, which is the
	 * fail-closed direction and matches the PHP verdict for every row its
	 * condition would have selected.
	 *
	 * @param string   $columnName The `_authorization` column, qualified by the caller.
	 * @param string   $action     The verb being filtered.
	 * @param bool     $isPostgres Whether the connected platform is PostgreSQL.
	 * @param string[] $principals The caller's principal names.
	 *
	 * @return string A SQL predicate true for rows this caller is not denied.
	 *
	 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
	 */
	public function notDeniedRowSql(string $columnName, string $action, bool $isPostgres, array $principals): string {
		if ($principals === []) {
			return '1 = 1';
		}

		// An action name reaches a JSON path, so it is checked rather than
		// trusted. A verb that cannot be expressed as a path is refused
		// outright: denying every row is recoverable and visible, emitting a
		// path built from an unchecked string is neither.
		if (preg_match(self::SAFE_ACTION_PATTERN, $action) !== 1) {
			return self::IMPOSSIBLE_SQL_CONDITION;
		}

		$denied = [];
		foreach ($principals as $principal) {
			foreach ($this->candidatesFor(principal: $principal) as $candidate) {
				$denied[] = $this->containsDenyCandidateSql(
					columnName: $columnName,
					action: $action,
					isPostgres: $isPostgres,
					candidateJson: $candidate
				);
			}
		}

		if ($denied === []) {
			return '1 = 1';
		}

		// The cheap disjunct comes FIRST, and it is what keeps this predicate
		// affordable on the schemas that declare no denial at all. A deny may be
		// written on an OBJECT, so the term cannot be skipped for a schema whose
		// own block is silent — that is the same silent-leak reasoning that puts
		// the private-scope predicate on every list query. What it CAN do is
		// decide the overwhelmingly common row, whose `_authorization` was never
		// written or never mentions a denial, with a string test instead of a
		// JSON parse.
		//
		// COALESCE is not decoration: `NULL NOT LIKE ...` is NULL, a WHERE
		// clause reads that as false, and the row would be filtered out. A
		// three-valued predicate here would hide every object whose block is
		// unwritten, which is most of them.
		$guard = $this->denyKeyProbeSql(columnName: $columnName, isPostgres: $isPostgres);

		return "({$guard} OR NOT (" . implode(' OR ', $denied) . '))';
	}//end notDeniedRowSql()

	/**
	 * The cheap test for "this row's block cannot carry a denial".
	 *
	 * @param string $columnName The `_authorization` column, qualified by the caller.
	 * @param bool   $isPostgres Whether the connected platform is PostgreSQL.
	 *
	 * @return string A SQL predicate true when the row mentions no deny key at all.
	 */
	private function denyKeyProbeSql(string $columnName, bool $isPostgres): string {
		$asText = "COALESCE({$columnName}, '')";
		if ($isPostgres === true) {
			$asText = "COALESCE({$columnName}::text, '')";
		}

		$needle = $this->quoteLiteral(value: '%"' . self::DENY_KEY . '"%');

		return "({$asText} NOT LIKE {$needle})";
	}//end denyKeyProbeSql()

	/**
	 * The JSON candidates that mean "this list denies this principal".
	 *
	 * Each candidate is a one-element ARRAY. Containment of a bare object inside
	 * an array is asymmetric between the two platforms — PostgreSQL's `@>` takes
	 * the array form and not the bare one — so both shapes are wrapped and the
	 * two emitters cannot drift on it.
	 *
	 * @param string $principal One principal name.
	 *
	 * @return string[] JSON documents, already encoded.
	 */
	private function candidatesFor(string $principal): array {
		$candidates = [];

		$asString = json_encode([$principal]);
		if ($asString !== false) {
			$candidates[] = $asString;
		}

		$key = 'group';
		$value = $principal;
		if (str_starts_with($principal, self::USER_PREFIX) === true) {
			$key = 'user';
			$value = substr($principal, strlen(self::USER_PREFIX));
		}

		$asObject = json_encode([[$key => $value]]);
		if ($asObject !== false) {
			$candidates[] = $asObject;
		}

		return $candidates;
	}//end candidatesFor()

	/**
	 * One containment test against `$.deny.<action>` of a row's block.
	 *
	 * @param string $columnName    The `_authorization` column.
	 * @param string $action        The verb, already checked against the safe pattern.
	 * @param bool   $isPostgres    Whether the connected platform is PostgreSQL.
	 * @param string $candidateJson A one-element JSON array to look for.
	 *
	 * @return string A SQL predicate true when the row denies this candidate.
	 */
	private function containsDenyCandidateSql(
		string $columnName,
		string $action,
		bool $isPostgres,
		string $candidateJson,
	): string {
		$quotedCandidate = $this->quoteLiteral(value: $candidateJson);

		if ($isPostgres === true) {
			$subDocument = "COALESCE(COALESCE({$columnName}, '{}')::jsonb -> '" . self::DENY_KEY . "' -> '{$action}', '[]'::jsonb)";

			return "({$subDocument} @> {$quotedCandidate}::jsonb)";
		}

		$path = "'$." . self::DENY_KEY . ".{$action}'";
		$subDocument = "COALESCE(JSON_EXTRACT({$columnName}, {$path}), JSON_ARRAY())";

		return "(COALESCE(JSON_CONTAINS({$subDocument}, {$quotedCandidate}), 0) = 1)";
	}//end containsDenyCandidateSql()

	/**
	 * Quote a string for safe use in raw SQL.
	 *
	 * @param string $value The value to quote.
	 *
	 * @return string The quoted literal.
	 */
	private function quoteLiteral(string $value): string {
		$escaped = str_replace("'", "''", $value);

		return "'{$escaped}'";
	}//end quoteLiteral()
}//end class
