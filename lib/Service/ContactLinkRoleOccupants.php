<?php

/**
 * ContactLinkRoleOccupants: the role occupants of an object, read from its contact links.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\ContactLink;
use OCA\OpenRegister\Db\ContactLinkMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectRoleOccupantsInterface;
use Throwable;

/**
 * Reads the link rows on every call: the occupant is whoever holds the role now.
 *
 * @spec openspec/changes/grants-that-follow-a-slot-a-relation-or-a-reason/specs/rbac-scopes/spec.md#requirement-a-grant-may-name-a-role-slot-on-the-record-rather-than-a-principal-req-gsr-001
 */
final class ContactLinkRoleOccupants implements ObjectRoleOccupantsInterface {

	/**
	 * Constructor.
	 *
	 * @param ContactLinkMapperInterface $links The link rows a party role lives in.
	 */
	public function __construct(
		private readonly ContactLinkMapperInterface $links,
	) {
	}//end __construct()

	/**
	 * The roles a Nextcloud user occupies on one object at this moment.
	 *
	 * @param string $objectUuid The object.
	 * @param string $userId     The user.
	 *
	 * @return array<int, string> The roles held, each once.
	 *
	 * @spec openspec/changes/grants-that-follow-a-slot-a-relation-or-a-reason/specs/rbac-scopes/spec.md#requirement-a-grant-may-name-a-role-slot-on-the-record-rather-than-a-principal-req-gsr-001
	 */
	public function activeUserRoles(string $objectUuid, string $userId): array {
		$roles = [];
		foreach ($this->links->findByObjectUuid($objectUuid) as $link) {
			$role = self::occupiedRole(link: $link, userId: $userId);
			if ($role !== null) {
				$roles[$role] = true;
			}
		}

		return array_keys($roles);
	}//end activeUserRoles()

	/**
	 * The role a link gives this user today, or null when it gives none.
	 *
	 * @param mixed  $link   One link row.
	 * @param string $userId The caller.
	 *
	 * @return string|null The role.
	 */
	private static function occupiedRole(mixed $link, string $userId): ?string {
		if (($link instanceof ContactLink) === false || $link->isUserLink() === false || $link->getUserId() !== $userId) {
			return null;
		}

		$role = trim((string)($link->getRole() ?? ''));
		if ($role === '') {
			return null;
		}

		try {
			if ($link->isActiveOn() === false) {
				return null;
			}
		} catch (Throwable $e) {
			return null;
		}

		return $role;
	}//end occupiedRole()
}//end class
