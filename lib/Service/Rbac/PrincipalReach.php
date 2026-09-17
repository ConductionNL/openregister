<?php

/**
 * PrincipalReach — the closed vocabulary of where a principal's reach comes from.
 *
 * "Welke toegang had deze persoon" is a question with four honest answers in
 * this instance, and naming them once is what lets the listing and the
 * revocation agree about what was found and what was taken away. A source
 * outside this list is a source nobody can revoke, which is why the list is
 * closed rather than free strings on an array key.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Rbac
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

/**
 * The sources a principal's reach can come from, and what each costs to remove.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @spec openspec/changes/data-subject-rights-across-the-instance/specs/authorization-rbac/spec.md
 */
final class PrincipalReach {
	/**
	 * A rule in a schema, register or object authorization block.
	 *
	 * @var string
	 */
	public const SOURCE_AUTHORIZATION = 'authorization';

	/**
	 * A grant derived from the claims the account signed in with.
	 *
	 * @var string
	 */
	public const SOURCE_DERIVED = 'derived';

	/**
	 * A delegation somebody granted to this principal.
	 *
	 * @var string
	 */
	public const SOURCE_DELEGATION = 'delegation';

	/**
	 * A group the principal is a member of, which rules then name.
	 *
	 * @var string
	 */
	public const SOURCE_GROUP = 'group';

	/**
	 * Every source, in the order an administrator reads them.
	 *
	 * @var array<int, string>
	 */
	public const SOURCES = [
		self::SOURCE_AUTHORIZATION,
		self::SOURCE_GROUP,
		self::SOURCE_DERIVED,
		self::SOURCE_DELEGATION,
	];

	/**
	 * The sources a revocation can actually remove through the authorization
	 * layer, without editing a group.
	 *
	 * GROUP IS NOT IN THIS LIST, AND THAT IS ADR-010. Removing a person from a
	 * group is a change to the group, not to the grant, and it silently moves
	 * every other right that group carries. A group-sourced reach is listed so
	 * the administrator sees it, and reported as `retained` by the revocation
	 * with the group named, so the act says plainly what it did not take.
	 *
	 * @var array<int, string>
	 */
	public const REVOCABLE = [
		self::SOURCE_AUTHORIZATION,
		self::SOURCE_DERIVED,
		self::SOURCE_DELEGATION,
	];

	/**
	 * Whether a revocation can remove a reach from this source.
	 *
	 * @param string|null $source The source.
	 *
	 * @return bool True when the authorization layer can remove it.
	 *
	 * @spec openspec/changes/data-subject-rights-across-the-instance/specs/authorization-rbac/spec.md
	 */
	public static function isRevocable(?string $source): bool {
		return in_array(needle: (string)$source, haystack: self::REVOCABLE, strict: true);
	}//end isRevocable()

	/**
	 * Whether a string names a source this vocabulary knows.
	 *
	 * @param string|null $source The candidate source.
	 *
	 * @return bool True when the source is a member.
	 *
	 * @spec openspec/changes/data-subject-rights-across-the-instance/specs/authorization-rbac/spec.md
	 */
	public static function isMember(?string $source): bool {
		return in_array(needle: (string)$source, haystack: self::SOURCES, strict: true);
	}//end isMember()
}//end class
