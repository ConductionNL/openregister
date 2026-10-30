<?php

/**
 * Who may see a saved view, and what they may do to it (ledger row 9.4).
 *
 * A view had `isPublic`, `isDefault` and `favoredBy` and nothing in between: it
 * was private or it was everyone's, so a department could not have a view of
 * its own. nextcloud-vue's control has specified "a group multiselect and a
 * read or write mode per selected group" for as long as it has existed and said
 * the persistence was "proposed in the OpenRegister repo" without naming a
 * change. This is that half.
 *
 * THE DECISION IS HERE AND THE QUERY IS NOT. Whether a caller may see a view,
 * and at which level, is a rule over four values: the owner, the public flag,
 * the share list and the caller's groups. Keeping it in one pure place means it
 * can be tested against a table of views rather than against a database, and
 * that the list endpoint, the update guard and the delete guard cannot answer
 * it three different ways.
 *
 * 🔴 `write` DOES NOT MEAN OWNER. A write member may change what the view
 * SHOWS: its query, its presentation and its alert. They may not change who
 * else sees it, who owns it, or whether it exists. That distinction is the
 * whole reason the mode is two words rather than a boolean, and it is the one
 * a guard written as `if (canWrite) { save($everything); }` silently loses: a
 * member would hand themselves the view by rewriting `owner`, or lock the owner
 * out by rewriting `sharedWith`, and the audit would show a legitimate update.
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
 * @spec openspec/specs/saved-search-views/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

/**
 * Resolves a caller's access to a saved view, and validates a share list.
 *
 * @spec openspec/specs/saved-search-views/spec.md
 */
class ViewShareResolver {

	/**
	 * The access levels, widest first.
	 *
	 * @var string
	 */
	public const ACCESS_OWNER = 'owner';

	/**
	 * A member of a group shared in `write` mode.
	 *
	 * @var string
	 */
	public const ACCESS_WRITE = 'write';

	/**
	 * A member of a group shared in `read` mode, or anybody on a public view.
	 *
	 * @var string
	 */
	public const ACCESS_READ = 'read';

	/**
	 * The modes a share may carry.
	 *
	 * @var string[]
	 */
	public const MODES = [self::ACCESS_READ, self::ACCESS_WRITE];

	/**
	 * The fields a `write` member may change.
	 *
	 * Everything about what the view SHOWS, and nothing about who sees it. See
	 * the class docblock: this list is the difference between a share and a
	 * handover.
	 *
	 * @var string[]
	 */
	public const WRITABLE_BY_MEMBER = ['query', 'presentation', 'alert'];

	/**
	 * The access a caller holds on one view.
	 *
	 * OWNER WINS, then write, then read, and an administrator is resolved as an
	 * owner by the caller rather than here: this answers what the VIEW grants,
	 * and an administrator reaches it because they are an administrator, not
	 * because the view said so. Mixing the two would put `owner` on a row an
	 * administrator does not own and cannot hand back.
	 *
	 * @param array<string, mixed> $view The view, as the entity serialises it.
	 * @param string $userId The caller.
	 * @param string[] $userGroups The caller's group ids.
	 *
	 * @return string|null One of owner, write, read, or null when the view grants nothing.
	 *
	 * @spec openspec/specs/saved-search-views/spec.md
	 */
	public function accessFor(array $view, string $userId, array $userGroups): ?string {
		if ($userId !== '' && (string)($view['owner'] ?? '') === $userId) {
			return self::ACCESS_OWNER;
		}

		// The widest share that names the caller or one of their groups. A
		// caller reached read by name and write by a group holds write: the
		// shares are ways in, not ceilings on each other.
		$best = null;
		foreach ($this->sharesOf(view: $view) as $share) {
			if ($this->shareNames(share: $share, userId: $userId, userGroups: $userGroups) === false) {
				continue;
			}

			if ($share['mode'] === self::ACCESS_WRITE) {
				return self::ACCESS_WRITE;
			}

			$best = self::ACCESS_READ;
		}

		if ($best !== null) {
			return $best;
		}

		if (($view['isPublic'] ?? false) === true) {
			return self::ACCESS_READ;
		}

		return null;
	}//end accessFor()

	/**
	 * Whether one share names the caller, by user id or by one of their groups.
	 *
	 * @param array{group?: string, user?: string, mode: string} $share      The share.
	 * @param string                                           $userId     The caller.
	 * @param string[]                                         $userGroups The caller's group ids.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-10-30-saved-view-user-shares/tasks.md#task-1
	 */
	private function shareNames(array $share, string $userId, array $userGroups): bool {
		if (isset($share['user']) === true) {
			return $userId !== '' && $share['user'] === $userId;
		}

		return in_array($share['group'], $userGroups, true);
	}//end shareNames()

	/**
	 * One share's target as a single string, `user:<uid>` or the group id.
	 *
	 * @param array{group?: string, user?: string, mode: string} $share The share.
	 *
	 * @return string
	 */
	private function targetKey(array $share): string {
		if (isset($share['user']) === true) {
			return 'user:'.$share['user'];
		}

		return $share['group'];
	}//end targetKey()

	/**
	 * Whether this caller may change the shares, the owner, or delete the view.
	 *
	 * @param array<string, mixed> $view The view.
	 * @param string $userId The caller.
	 * @param bool $isAdmin Whether the caller is an administrator.
	 *
	 * @return bool True for the owner and for an administrator.
	 *
	 * @spec openspec/specs/saved-search-views/spec.md
	 */
	public function mayAdminister(array $view, string $userId, bool $isAdmin): bool {
		if ($isAdmin === true) {
			return true;
		}

		return ($userId !== '' && (string)($view['owner'] ?? '') === $userId);
	}//end mayAdminister()

	/**
	 * The fields of an update whose value differs from the stored view.
	 *
	 * The edit screen sends the whole view on every save, so judging the fields
	 * a body CARRIES would refuse a member on fields they never touched. The
	 * comparison is by value: an equal `query` with its keys in another order,
	 * or the same shares in another order, is not a change.
	 *
	 * @param array<string, mixed> $update The fields being written.
	 * @param array<string, mixed> $view   The stored view, as the entity serialises it.
	 *
	 * @return array<string, mixed> The fields that change, with their new values.
	 *
	 * @spec openspec/specs/saved-search-views/spec.md
	 */
	public function changedFields(array $update, array $view): array {
		$changed = [];
		foreach ($update as $field => $value) {
			$field = (string)$field;
			if ($this->canonical(field: $field, value: $value) !== $this->canonical(field: $field, value: ($view[$field] ?? null))) {
				$changed[$field] = $value;
			}
		}

		return $changed;
	}//end changedFields()

	/**
	 * One field's value in a form two equal values share.
	 *
	 * @param string $field The field.
	 * @param mixed  $value Its value.
	 *
	 * @return mixed
	 */
	private function canonical(string $field, mixed $value): mixed {
		switch ($field) {
			case 'name':
			case 'description':
			case 'owner':
				return (string)($value ?? '');
			case 'isPublic':
			case 'isDefault':
				return (bool)$value;
			case 'sharedWith':
				$shares = array_map(
					fn (array $share): string => $this->targetKey(share: $share).'|'.$share['mode'],
					$this->sharesOf(view: ['sharedWith' => $value])
				);
				sort($shares);
				return $shares;
			default:
				return $this->sortedKeys(value: $value);
		}
	}//end canonical()

	/**
	 * A value with every object's keys sorted; lists keep their order.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed
	 */
	private function sortedKeys(mixed $value): mixed {
		if (is_array($value) === false) {
			return $value;
		}

		$value = array_map(fn ($item) => $this->sortedKeys(value: $item), $value);
		if (array_is_list($value) === false) {
			ksort($value);
		}

		return $value;
	}//end sortedKeys()

	/**
	 * The fields of an update this caller is not allowed to have sent.
	 *
	 * Answering with the REFUSED FIELDS rather than a boolean is deliberate: a
	 * guard that only says no leaves the endpoint to guess what to say, and the
	 * message a member needs is which field was refused, not that something
	 * was.
	 *
	 * @param array<string, mixed> $update The fields being written.
	 * @param string $access The caller's resolved access.
	 * @param bool $mayAdminister Whether the caller owns it or administers the instance.
	 *
	 * @return string[] The refused field names, empty when the update is allowed.
	 *
	 * @spec openspec/specs/saved-search-views/spec.md
	 */
	public function refusedFields(array $update, string $access, bool $mayAdminister): array {
		if ($mayAdminister === true) {
			return [];
		}

		if ($access !== self::ACCESS_WRITE) {
			// A read member and a stranger may change nothing at all. Every
			// field they sent is refused, which is what lets the endpoint
			// answer with a sentence rather than an empty 403.
			return array_keys($update);
		}

		$refused = [];
		foreach (array_keys($update) as $field) {
			if (in_array((string)$field, self::WRITABLE_BY_MEMBER, true) === false) {
				$refused[] = (string)$field;
			}
		}

		return $refused;
	}//end refusedFields()

	/**
	 * Findings for a share list being written.
	 *
	 * A group that does not exist is refused rather than stored: a share on a
	 * name nobody holds looks, in the grid, exactly like a share somebody has,
	 * and the view is then narrower than its own screen says. Sharing with a
	 * group the OWNER is not in is allowed, on purpose — an administrator
	 * setting up a department's view is not thereby a member of it.
	 *
	 * A share names a group or a user, never both. Without `$userExists` no
	 * user is known, so every user share is refused: the fail-closed default.
	 *
	 * @param mixed         $sharedWith  The declared share list.
	 * @param callable      $groupExists Answers whether a group id exists.
	 * @param callable|null $userExists  Answers whether a user id exists.
	 *
	 * @return array<int, array{code: string, message: string}> The findings.
	 *
	 * @spec openspec/specs/saved-search-views/spec.md
	 * @spec openspec/changes/archive/2026-10-30-saved-view-user-shares/tasks.md#task-2
	 */
	public function validateShares(mixed $sharedWith, callable $groupExists, ?callable $userExists=null): array {
		return (new ViewShareListValidator())->findings(
			sharedWith: $sharedWith,
			exists: [
				'group' => $groupExists,
				'user'  => $userExists ?? static fn (): bool => false,
			]
		);
	}//end validateShares()

	/**
	 * The groups a view is shared with, in any mode.
	 *
	 * @param array<string, mixed> $view The view.
	 *
	 * @return array<int, string> The group ids.
	 *
	 * @spec openspec/specs/saved-search-views/spec.md
	 */
	public function shareGroups(array $view): array {
		return array_values(array_unique(array_column($this->sharesOf(view: $view), 'group')));
	}//end shareGroups()

	/**
	 * The share list of a view, normalised and with the unusable entries gone.
	 *
	 * @param array<string, mixed> $view The view.
	 *
	 * @return array<int, array{group?: string, user?: string, mode: string}> The shares.
	 */
	private function sharesOf(array $view): array {
		$raw = ($view['sharedWith'] ?? null);
		if (is_string($raw) === true) {
			// The column is TEXT and some read paths hand back the raw JSON.
			// Decoding here rather than at every call site is what keeps the
			// difference from becoming "this view is shared with nobody".
			$decoded = json_decode($raw, true);
			$raw = [];
			if (is_array($decoded) === true) {
				$raw = $decoded;
			}
		}

		if (is_array($raw) === false) {
			return [];
		}

		$shares = [];
		foreach ($raw as $share) {
			if (is_array($share) === false) {
				continue;
			}

			$group = trim((string)($share['group'] ?? ''));
			$user = trim((string)($share['user'] ?? ''));
			$mode = trim((string)($share['mode'] ?? ''));
			if (($group === '') === ($user === '') || in_array($mode, self::MODES, true) === false) {
				// An unreadable share grants NOTHING: one naming both a user and
				// a group, or neither, or a mode this resolver does not know. An
				// unknown mode is not "probably read": that is the direction that
				// admits somebody on a typo.
				continue;
			}

			if ($user !== '') {
				$shares[] = ['user' => $user, 'mode' => $mode];
				continue;
			}

			$shares[] = ['group' => $group, 'mode' => $mode];
		}

		return $shares;
	}//end sharesOf()
}//end class
