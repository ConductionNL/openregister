<?php

/**
 * OpenRegister Property Access Request
 *
 * Who asks for a property-level action, and on which object.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author	Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link	  https://www.OpenRegister.app
 *
 * @spec openspec/specs/row-field-level-security/spec.md#field-level-security
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * The object a property rule is checked against, whether it is being created, and the
 * current user's id and groups once resolved. Immutable: withUser() returns a copy.
 *
 * @spec openspec/specs/row-field-level-security/spec.md#field-level-security
 */
final class PropertyAccessRequest {

	/**
	 * Current user id, null when anonymous.
	 *
	 * @var string|null
	 */
	private ?string $userId = null;

	/**
	 * Current user's group ids.
	 *
	 * @var array<int, string>
	 */
	private array $userGroups = [];

	/**
	 * Constructor.
	 *
	 * @param array $object   Object data for conditional matching.
	 * @param bool  $isCreate Whether this is a create operation.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md#field-level-security
	 */
	public function __construct(
		public readonly array $object,
		public readonly bool $isCreate = false,
	) {
	}//end __construct()

	/**
	 * A copy for the given user.
	 *
	 * @param string|null        $userId     User id, null when anonymous.
	 * @param array<int, string> $userGroups The user's group ids.
	 *
	 * @return self The copy.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md#field-level-security
	 */
	public function withUser(?string $userId, array $userGroups): self {
		$copy = clone $this;
		$copy->userId     = $userId;
		$copy->userGroups = $userGroups;
		return $copy;
	}//end withUser()

	/**
	 * Whether the user is in the admin group.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md#field-level-security
	 */
	public function isAdmin(): bool {
		return in_array('admin', $this->userGroups, true);
	}//end isAdmin()

	/**
	 * Whether the user qualifies for a rule's group: `public` always, `authenticated`
	 * with a user, any other group by membership.
	 *
	 * @param string $group Group name from the rule.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md#field-level-security
	 */
	public function qualifiesFor(string $group): bool {
		if ($group === 'public') {
			return true;
		}

		if ($group === 'authenticated' && $this->userId !== null) {
			return true;
		}

		return in_array($group, $this->userGroups, true);
	}//end qualifiesFor()
}//end class
