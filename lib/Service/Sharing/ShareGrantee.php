<?php

/**
 * ShareGrantee — the user a share check asks about, with the groups they are in.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Sharing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Sharing;

/**
 * A user and their groups, matched against validated share entries.
 *
 * An anonymous grantee (an empty user id) holds nothing, whatever the list says.
 */
final class ShareGrantee {

	/**
	 * Constructor.
	 *
	 * @param string             $userId The acting user id; '' for anonymous.
	 * @param array<int, string> $groups The acting user's group ids.
	 */
	public function __construct(
		public readonly string $userId,
		public readonly array $groups,
	) {
	}//end __construct()

	/**
	 * Whether one of the entries names this grantee with one of the permissions.
	 *
	 * @param array<int, array{type: string, id: string, permission: ?string}> $entries     Validated share entries.
	 * @param array<int, string|null>                                          $permissions Permissions that satisfy the check;
	 *                                                                                      null matches an unqualified entry.
	 *
	 * @return bool True when a matching principal holds one of the permissions.
	 */
	public function holdsAnyOf(array $entries, array $permissions): bool {
		if ($this->userId === '') {
			return false;
		}

		foreach ($entries as $entry) {
			if ($this->isNamedBy(entry: $entry) === true && in_array($entry['permission'], $permissions, true) === true) {
				return true;
			}
		}

		return false;
	}//end holdsAnyOf()

	/**
	 * Whether an entry names this user, or a group this user is in.
	 *
	 * @param array{type: string, id: string, permission: ?string} $entry A validated share entry.
	 *
	 * @return bool
	 */
	private function isNamedBy(array $entry): bool {
		return ($entry['type'] === SharePrincipalDeriver::TYPE_USER && $entry['id'] === $this->userId)
			|| ($entry['type'] === SharePrincipalDeriver::TYPE_GROUP && in_array($entry['id'], $this->groups, true) === true);
	}//end isNamedBy()
}//end class
