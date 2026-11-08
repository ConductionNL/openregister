<?php

/**
 * The `mcp` scope of an authorization block: strip it, and read back what it offers.
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
 * @spec openspec/changes/declared-actions-and-mcp-scope/specs/declared-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

/**
 * Static, pure helpers for the `mcp` scope.
 *
 * Both rule interpreters — PermissionHandler and the SQL-layer MagicRbacHandler —
 * and the grantable-rights index apply these, so they cannot drift apart on the
 * transform. They used to be static methods on PermissionHandler; they live here
 * so those callers name this small class instead of the whole permission handler.
 *
 * @spec openspec/changes/declared-actions-and-mcp-scope/specs/declared-actions/spec.md
 */
final class McpScope {

	/**
	 * The scope name.
	 *
	 * @var string
	 */
	public const SCOPE = 'mcp';

	/**
	 * Remove the `mcp` scope from a block, leaving enforcement exactly as it
	 * was before the annotation was added.
	 *
	 * The scope is removed so it can never satisfy a caller. What it does NOT
	 * do is make the block disappear: an action key emptied by the strip stays
	 * as `[]`, which the grammar reads as "grant this action to nobody", and a
	 * block that held nothing but offers remains a non-empty block.
	 *
	 * 🔴 That shape is deliberate, and an earlier cut of this method got it
	 * backwards. Collapsing an offers-only block to `null` made it "no
	 * authorization configured" — which is default-OPEN — so a schema annotated
	 * `{"read": ["mcp"]}` became readable by every authenticated user AND by
	 * anonymous callers. Between a fail-safe and a fail-open reading of an
	 * ambiguous block, the block gets the fail-safe one.
	 *
	 * So: writing an `mcp` entry into `authorization` opts the schema into
	 * authorization, exactly as writing any other rule does. To record an agent
	 * surface WITHOUT touching enforcement, use the `x-openregister-mcp` dialect.
	 *
	 * Control keys that are not rule lists (notably the `public: true` opt-in,
	 * read back off the RESOLVED block) are carried through untouched.
	 *
	 * @param array|null $authorization The block to strip, or null.
	 *
	 * @return array|null The block with the mcp scope removed, or null when
	 *                    nothing enforceable remains.
	 *
	 * @spec openspec/changes/declared-actions-and-mcp-scope/specs/declared-actions/spec.md
	 */
	public static function strip(?array $authorization): ?array {
		if ($authorization === null || $authorization === []) {
			return $authorization;
		}

		$stripped = [];

		foreach ($authorization as $key => $rules) {
			// Not a rule list (inheritFromPublic, public, objectScope, ...):
			// carry it through untouched. These are behaviour toggles read at
			// runtime, and the mcp scope has no business editing them.
			if (is_array($rules) === false) {
				$stripped[$key] = $rules;
				continue;
			}

			// The deny block is a block of rule lists, not one rule list.
			// Handing it to the list stripper would ask "is this entry the mcp
			// scope" of an entire action's list, which is always no, and would
			// reindex the block's action keys on the way through.
			if ($key === DenyResolverInterface::DENY_KEY) {
				$stripped[$key] = self::strip(authorization: $rules);
				continue;
			}

			// 🔴 A CONTROL KEY THAT HAPPENS TO BE AN ARRAY IS NOT A RULE LIST,
			// and reading it as one does not fail — it REINDEXES. The reindex
			// is what made the whole department matrix inert: `matrix` is the
			// map `{field, userSource, rows}`, and the list stripper walks its
			// VALUES into a fresh list, dropping every key; the schema then
			// granted `read` to nobody at all. `roles` has exactly the same
			// shape and was losing its role names the same way.
			if (in_array($key, PermissionCatalogueInterface::CONTROL_KEYS, true) === true) {
				$stripped[$key] = $rules;
				continue;
			}

			$stripped[$key] = self::stripFromRuleList(rules: $rules);
		}//end foreach

		return $stripped;
	}//end strip()

	/**
	 * Read back the actions a block offers to agents.
	 *
	 * This is the half of the annotation that survives as DATA: the
	 * grantable-rights index serves it as the menu of rights that could be
	 * granted. It answers "what may be offered here", and never "who holds it".
	 *
	 * @param array|null $authorization The block to read.
	 *
	 * @return array<int, string> The offered action names, in declaration order.
	 *
	 * @spec openspec/changes/declared-actions-and-mcp-scope/specs/declared-actions/spec.md
	 */
	public static function offeredActions(?array $authorization): array {
		if ($authorization === null || $authorization === []) {
			return [];
		}

		$offered = [];
		foreach ($authorization as $action => $rules) {
			if (is_array($rules) === false || is_string($action) === false) {
				continue;
			}

			foreach ($rules as $entry) {
				if (self::isScopeEntry(entry: $entry) === true) {
					$offered[] = $action;
					break;
				}
			}
		}

		return $offered;
	}//end offeredActions()

	/**
	 * Whether a single authorization entry names the `mcp` scope.
	 *
	 * Covers both spellings the rule grammar accepts — the bare string and the
	 * `{"group": "mcp"}` object — because they are independent match branches
	 * and a guard that knows only one of them is a guard with a hole.
	 *
	 * @param mixed $entry One entry from an action's rule list.
	 *
	 * @return bool True when the entry names the mcp scope.
	 *
	 * @spec openspec/specs/declared-actions/spec.md
	 */
	public static function isScopeEntry(mixed $entry): bool {
		if (is_array($entry) === true) {
			return (($entry['group'] ?? null) === self::SCOPE);
		}

		return ($entry === self::SCOPE);
	}//end isScopeEntry()

	/**
	 * Strip the mcp scope from one action's rule list.
	 *
	 * An emptied list stays `[]` rather than being dropped. The grammar reads
	 * `[]` as "grant this action to nobody", which is the fail-safe reading of
	 * an action whose only rule was an offer to agents.
	 *
	 * @param array $rules One action's rule list.
	 *
	 * @return array The surviving rules, possibly empty.
	 *
	 * @spec openspec/specs/declared-actions/spec.md
	 */
	private static function stripFromRuleList(array $rules): array {
		$kept = [];
		foreach ($rules as $entry) {
			if (self::isScopeEntry(entry: $entry) === true) {
				continue;
			}

			$kept[] = $entry;
		}

		return $kept;
	}//end stripFromRuleList()
}//end class
