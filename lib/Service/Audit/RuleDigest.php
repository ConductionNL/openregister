<?php

/**
 * The digest of the RBAC rule that granted a read, for a read audit entry.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Audit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Audit;

/**
 * A read entry records WHICH rule allowed the read by a SHA-256 of that rule's
 * canonical JSON, not the rule itself: the rule may hold nothing secret, but it
 * is the authorisation model's own data and a copy on every read entry would
 * drift from it. The digest proves "this read was allowed by the rule that
 * hashes to X" without carrying the rule.
 *
 * The canonical form is the hash chain's: keys sorted (here recursively, so a
 * rule serialised with its keys in any order digests the same), compact JSON
 * with `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`. List order is kept —
 * a rule's ordered parts (a list of groups, say) are part of its identity.
 *
 * @spec openspec/changes/read-audit-entries-name-the-rule/specs/audit-trail-immutable/spec.md
 */
final class RuleDigest {

	/**
	 * The encoding flags of the hash chain's canonical JSON.
	 */
	private const JSON_FLAGS = (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

	/**
	 * The SHA-256 digest of a rule's canonical JSON.
	 *
	 * @param array<mixed> $rule The rule that granted the read.
	 *
	 * @return string The 64-character lowercase hex digest.
	 *
	 * @spec openspec/changes/read-audit-entries-name-the-rule/specs/audit-trail-immutable/spec.md
	 */
	public static function of(array $rule): string {
		return hash('sha256', self::canonicalJson(value: self::sortedDeep(value: $rule)));
	}//end of()

	/**
	 * The canonical JSON of an already-sorted value, with the chain's flags.
	 *
	 * @param mixed $value The value to encode.
	 *
	 * @return string The canonical JSON.
	 *
	 * @spec openspec/changes/read-audit-entries-name-the-rule/specs/audit-trail-immutable/spec.md
	 */
	private static function canonicalJson(mixed $value): string {
		return json_encode($value, self::JSON_FLAGS);
	}//end canonicalJson()

	/**
	 * The value with every associative array's keys sorted, recursively, and
	 * every list left in its own order.
	 *
	 * @param mixed $value The value to sort.
	 *
	 * @return mixed The value with associative keys sorted.
	 */
	private static function sortedDeep(mixed $value): mixed {
		if (is_array($value) === false) {
			return $value;
		}

		$sorted = array_map(static fn ($item): mixed => self::sortedDeep(value: $item), $value);

		// A list keeps its order — its sequence is part of what it means. Only
		// an associative array (a map of named parts) is key-order-independent,
		// so only it is sorted.
		if (array_is_list($sorted) === false) {
			ksort($sorted);
		}

		return $sorted;
	}//end sortedDeep()
}//end class
