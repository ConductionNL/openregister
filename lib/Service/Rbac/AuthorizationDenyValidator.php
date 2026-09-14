<?php

/**
 * Authorization deny validator — the two ways a deny is refused at save.
 *
 * A deny is the first rule in this layer that SUBTRACTS, and two of its failure
 * modes are cheap to catch when the block is written and expensive to discover
 * afterwards.
 *
 * 1. A GRANT AND A DENY AT ONE LEVEL. `{"read": ["behandelaars"], "deny":
 *    {"read": ["behandelaars"]}}` is not a rule, it is a question. Resolving it
 *    silently either way teaches nobody: the author reads their own block as
 *    granting, the resolver reads it as denying, and the two only ever meet in
 *    a support ticket. So the save is refused and BOTH rules are named.
 *
 * 2. ADMINISTRATION DENIED AWAY. A register whose `manage` verb has been denied
 *    to the last principal holding it has no administrator left inside its own
 *    rules, and an authorization model that cannot be edited any more is
 *    recoverable only from the database. The check runs at save time, names
 *    what would be orphaned, and refuses.
 *
 * Both refusals carry HTTP 422 through {@see AuthorizationBlockException}. The
 * block was understood; it contradicts itself.
 *
 * WHAT THIS DELIBERATELY DOES NOT DO. It does not decide access, and it holds no
 * copy of the resolution rules. A validator that re-implemented the resolver
 * would be a second definition of the grammar, which is how the two enforcement
 * paths in the predecessor change drifted apart. It reads the block through the
 * same {@see DenyResolver} every enforcement path reads it through.
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

use OCA\OpenRegister\Exception\AuthorizationBlockException;

/**
 * Refuses an authorization block whose deny contradicts its grants.
 */
class AuthorizationDenyValidator {

	/**
	 * The verb that carries administration of a register.
	 *
	 * @var string
	 */
	public const ACTION_MANAGE = 'manage';

	/**
	 * The authorization-block key holding role assignments rather than a verb.
	 *
	 * @var string
	 */
	private const ROLES_KEY = 'roles';

	/**
	 * Constructor.
	 *
	 * @param DenyResolver $denyResolver The one reader of the deny grammar.
	 */
	public function __construct(
		private readonly DenyResolver $denyResolver,
	) {
	}//end __construct()

	/**
	 * The findings of one block, as sentences an author can act on.
	 *
	 * Returned rather than thrown so a caller can report every contradiction at
	 * once. A validator that stops at the first one turns a five-rule mistake
	 * into five round trips.
	 *
	 * @param array|null $authorization The block as written, roles NOT expanded.
	 * @param string     $subject       What the block belongs to, for the message.
	 *
	 * @return string[] One sentence per contradiction; empty when the block is storable.
	 *
	 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
	 */
	public function findings(?array $authorization, string $subject): array {
		if (is_array($authorization) === false || $authorization === []) {
			return [];
		}

		$findings = $this->collisionFindings(authorization: $authorization, subject: $subject);

		$orphan = $this->orphanedManageFinding(authorization: $authorization, subject: $subject);
		if ($orphan !== null) {
			$findings[] = $orphan;
		}

		return $findings;
	}//end findings()

	/**
	 * Refuse the block, or return.
	 *
	 * @param array|null $authorization The block as written, roles NOT expanded.
	 * @param string     $subject       What the block belongs to, for the message.
	 *
	 * @return void
	 *
	 * @throws AuthorizationBlockException When the block contradicts itself.
	 *
	 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
	 */
	public function assertStorable(?array $authorization, string $subject): void {
		$findings = $this->findings(authorization: $authorization, subject: $subject);
		if ($findings === []) {
			return;
		}

		throw new AuthorizationBlockException(implode(' ', $findings));
	}//end assertStorable()

	/**
	 * Every principal granted and denied the same verb at this level.
	 *
	 * @param array  $authorization The block as written.
	 * @param string $subject       What the block belongs to.
	 *
	 * @return string[] One sentence per collision.
	 */
	private function collisionFindings(array $authorization, string $subject): array {
		$findings = [];

		foreach ($this->denyResolver->denyBlock(authorization: $authorization) as $action => $denyEntries) {
			if (is_string($action) === false || is_array($denyEntries) === false) {
				continue;
			}

			// `roles` is a role-to-group map, not a verb's rule list. It is
			// expanded into verbs long before enforcement, and reading it here
			// as if it were one would compare a role name against a group name.
			if ($action === self::ROLES_KEY) {
				continue;
			}

			$granted = $this->principalsNamedIn(entries: ($authorization[$action] ?? null));
			$denied = $this->principalsNamedIn(entries: $denyEntries);

			foreach (array_intersect($denied, $granted) as $principal) {
				$findings[] = sprintf(
					'The authorization of %s both grants and denies "%s" to "%s" at the same level: '
					. 'the rule "%s" in %s and the rule "%s" in %s.deny. '
					. 'Remove one of the two; a deny wins, so leaving both stored would grant nothing '
					. 'and read as a grant.',
					$subject,
					$action,
					$principal,
					$principal,
					$action,
					$principal,
					$action
				);
			}
		}

		return $findings;
	}//end collisionFindings()

	/**
	 * The refusal that keeps a register administrable, or null.
	 *
	 * @param array  $authorization The block as written.
	 * @param string $subject       What the block belongs to.
	 *
	 * @return string|null The sentence, or null when administration survives.
	 */
	private function orphanedManageFinding(array $authorization, string $subject): ?string {
		$denied = $this->principalsNamedIn(
			entries: ($this->denyResolver->denyBlock(authorization: $authorization)[self::ACTION_MANAGE] ?? null)
		);
		if ($denied === []) {
			return null;
		}

		$granted = $this->principalsNamedIn(entries: ($authorization[self::ACTION_MANAGE] ?? null));
		$surviving = array_values(array_diff($granted, $denied));
		if ($surviving !== []) {
			return null;
		}

		return sprintf(
			'The authorization of %s would deny "%s" to %s and leave no principal holding it, '
			. 'so nobody could edit the access rules of %s afterwards. '
			. 'Grant "%s" to another group first, then write the deny.',
			$subject,
			self::ACTION_MANAGE,
			$this->quoteList(values: $denied),
			$subject,
			self::ACTION_MANAGE
		);
	}//end orphanedManageFinding()

	/**
	 * The principal names one rule list mentions.
	 *
	 * Every entry shape the grammar allows is read through {@see
	 * DenyResolver::entryNames()}, which is also what the resolver matches
	 * with, so the validator cannot recognise a principal the enforcer misses
	 * or the other way round.
	 *
	 * @param mixed $entries A rule list, or anything else.
	 *
	 * @return string[] The principal names, deduplicated and in order.
	 */
	private function principalsNamedIn(mixed $entries): array {
		if (is_array($entries) === false) {
			return [];
		}

		$named = [];
		foreach ($entries as $entry) {
			$principal = $this->principalOf(entry: $entry);
			if ($principal === null) {
				continue;
			}

			$named[] = $principal;
		}

		return array_values(array_unique($named));
	}//end principalsNamedIn()

	/**
	 * The principal one entry names, whoever the caller is.
	 *
	 * {@see DenyResolver::entryNames()} answers "does this entry reach THIS
	 * caller". Here there is no caller, so the entry is asked what it names by
	 * offering it its own name back.
	 *
	 * @param mixed $entry A single rule entry.
	 *
	 * @return string|null The principal, or null when the entry names none.
	 */
	private function principalOf(mixed $entry): ?string {
		$candidate = null;

		if (is_string($entry) === true) {
			$candidate = $entry;
		}

		if (is_array($entry) === true) {
			$group = ($entry['group'] ?? null);
			if (is_string($group) === true) {
				$candidate = $group;
			}

			$user = ($entry['user'] ?? null);
			if ($candidate === null && is_string($user) === true && $user !== '') {
				$candidate = (DenyResolver::USER_PREFIX . $user);
			}
		}

		if ($candidate === null || $candidate === '') {
			return null;
		}

		return $this->denyResolver->entryNames(entry: $entry, principals: [$candidate]);
	}//end principalOf()

	/**
	 * Render a list of principals for a message.
	 *
	 * @param string[] $values The principal names.
	 *
	 * @return string A quoted, comma-separated list.
	 */
	private function quoteList(array $values): string {
		$quoted = [];
		foreach ($values as $value) {
			$quoted[] = '"' . $value . '"';
		}

		return implode(', ', $quoted);
	}//end quoteList()
}//end class
