<?php

/**
 * Decides who may manage the grants and the owning group of one record.
 *
 * The owner, the owning group and an administrator, through the same resolver
 * the read side uses. Beside them a holder of the organisation's special right
 * `grant_manage` on the record ({@see OrganisationSpecialRights}).
 *
 * WHY IT IS NOT INSIDE `ObjectScopeResolver::admitsUnconditionally()`. That
 * method also decides who reads and changes the record, and the special right
 * opens neither. So the two questions are asked here, side by side, and the
 * answer says WHICH of them admitted the caller: a caller admitted by the
 * special right alone is held to one more rule, that it may not let itself in.
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
 * @spec openspec/changes/special-right-to-manage-grants/specs/object-ownership/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The one answer to "may this caller manage this record's grants, and as what".
 *
 * @spec openspec/changes/special-right-to-manage-grants/specs/object-ownership/spec.md
 */
class GrantManageGuard {

	/**
	 * Admitted as the owner, a member of the owning group or an administrator.
	 *
	 * @var string
	 */
	public const AS_OWNER = 'owner';

	/**
	 * Admitted by the special right alone.
	 *
	 * @var string
	 */
	public const AS_HOLDER = 'special-right';

	/**
	 * Constructor.
	 *
	 * @param ObjectScopeResolver $scopeResolver The one definition of who is admitted unconditionally.
	 * @param OrganisationSpecialRights $specialRights Reads the special right from the record's organisation.
	 */
	public function __construct(
		private readonly ObjectScopeResolver $scopeResolver,
		private readonly OrganisationSpecialRights $specialRights,
	) {
	}//end __construct()

	/**
	 * As what a caller may manage a record's grants, or null when it may not.
	 *
	 * @param string|null $userId The caller, or null when anonymous.
	 * @param string[] $userGroups The caller's group ids.
	 * @param ObjectEntity $object The record.
	 *
	 * @return string|null AS_OWNER, AS_HOLDER, or null for a caller who is refused.
	 *
	 * @spec openspec/changes/special-right-to-manage-grants/specs/object-ownership/spec.md
	 */
	public function admittedAs(?string $userId, array $userGroups, ObjectEntity $object): ?string {
		if ($this->scopeResolver->admitsUnconditionally(
			userId: $userId,
			userGroups: $userGroups,
			objectOwner: $object->getOwner(),
			authorization: $object->getAuthorization()
		) === true
		) {
			return self::AS_OWNER;
		}

		if ($userId !== null
			&& $this->specialRights->holdsOn(
				right: OrganisationSpecialRights::GRANT_MANAGE,
				userGroups: $userGroups,
				object: $object
			) === true
		) {
			return self::AS_HOLDER;
		}

		return null;
	}//end admittedAs()

	/**
	 * Whether a holder of the special right is trying to let itself in.
	 *
	 * An owner or an administrator may name themselves; they are admitted to
	 * the record anyway. A caller admitted by the special right alone is not,
	 * and a grant to its own account or to a group it is in would make the
	 * right a read by one extra call.
	 *
	 * @param string|null $admittedAs What {@see admittedAs()} answered.
	 * @param string|null $userId The caller.
	 * @param string[] $userGroups The caller's group ids.
	 * @param string|null $user The user being let in, or null.
	 * @param string|null $group The group being let in, or null.
	 * @param string|null $remote The remote user or remote group being let in, or null.
	 *
	 * @return bool True when the request must be refused.
	 *
	 * @spec openspec/changes/special-right-to-manage-grants/specs/object-ownership/spec.md
	 */
	public function letsItselfIn(?string $admittedAs, ?string $userId, array $userGroups, ?string $user, ?string $group, ?string $remote = null): bool {
		if ($admittedAs !== self::AS_HOLDER) {
			return false;
		}

		if ($remote !== null) {
			return true;
		}

		if ($user !== null && $user === $userId) {
			return true;
		}

		return ($group !== null && in_array(needle: $group, haystack: $userGroups, strict: true) === true);
	}//end letsItselfIn()
}//end class
