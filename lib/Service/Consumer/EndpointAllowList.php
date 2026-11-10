<?php

/**
 * An endpoint's users/groups allow-list for a credential-authenticated user.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Consumer
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/authorization-service-public-hardened/specs/auth-system/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Consumer;

use OCA\OpenRegister\Exception\AuthenticationException;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;

/**
 * Empty lists allow every authenticated user; otherwise the user must be named
 * (uid or e-mail address) or be in one of the groups.
 *
 * @spec openspec/changes/authorization-service-public-hardened/specs/auth-system/spec.md
 */
class EndpointAllowList {

	/**
	 * Constructor.
	 *
	 * @param IGroupManager|null $groupManager Group lookups; without it only the users list can admit.
	 *
	 * @spec openspec/changes/authorization-service-public-hardened/specs/auth-system/spec.md
	 */
	public function __construct(
		private readonly ?IGroupManager $groupManager = null,
	) {
	}//end __construct()

	/**
	 * Refuse a user outside an endpoint's allow-list (empty lists allow everyone).
	 *
	 * @param IUser $user   The authenticated user.
	 * @param array $users  Allowed uids or e-mail addresses.
	 * @param array $groups Allowed group ids.
	 *
	 * @return void
	 *
	 * @throws AuthenticationException When the user is in neither list.
	 *
	 * @spec openspec/changes/authorization-service-public-hardened/specs/auth-system/spec.md
	 */
	public function assertAllowed(IUser $user, array $users, array $groups): void {
		$this->assertAdmits(user: $user, audience: new EndpointAudience(users: $users, groups: $groups));
	}//end assertAllowed()

	/**
	 * Refuse a user the audience does not admit (an open audience admits everyone).
	 *
	 * @param IUser            $user     The authenticated user.
	 * @param EndpointAudience $audience The endpoint's allowed users and groups.
	 *
	 * @return void
	 *
	 * @throws AuthenticationException When the user is in neither list.
	 *
	 * @spec openspec/changes/authorization-service-public-hardened/specs/auth-system/spec.md
	 */
	public function assertAdmits(IUser $user, EndpointAudience $audience): void {
		if ($audience->isOpen() === true) {
			return;
		}

		if (array_intersect($audience->users, [$user->getUID(), $user->getEMailAddress()]) !== []) {
			return;
		}

		if (array_intersect($audience->groups, $this->groupIds(user: $user)) === []) {
			throw new AuthenticationException(
				message: 'Not authorized',
				details: ['reason' => 'The selected user is not allowed to login on this endpoint']
			);
		}
	}//end assertAdmits()

	/**
	 * The ids of the user's groups; none without a group manager.
	 *
	 * @param IUser $user The authenticated user.
	 *
	 * @return array<string> The group ids.
	 *
	 * @spec openspec/changes/authorization-service-public-hardened/specs/auth-system/spec.md
	 */
	private function groupIds(IUser $user): array {
		if ($this->groupManager === null) {
			return [];
		}

		return array_map(
			static fn (IGroup $group): string => $group->getGID(),
			$this->groupManager->getUserGroups($user)
		);
	}//end groupIds()
}//end class
