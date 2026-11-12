<?php

/**
 * OpenRegister admin user ids
 *
 * The uids of the instance's administrators, whose Nextcloud Tables tables
 * the `tables` virtual register is reconciled from.
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/tables-virtual-register/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCP\IGroupManager;

/**
 * Resolves the admin user ids for table enumeration.
 *
 * @spec openspec/specs/tables-virtual-register/spec.md
 */
final class AdminUserIds {

	/**
	 * The uids of the members of the `admin` group.
	 *
	 * @param IGroupManager $groupManager Admin-user enumeration.
	 *
	 * @return array<int, string> The admin user ids (may be empty).
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	public static function of(IGroupManager $groupManager): array {
		$admins = $groupManager->get('admin');
		if ($admins === null) {
			return [];
		}

		$ids = [];
		foreach ($admins->getUsers() as $user) {
			$ids[] = $user->getUID();
		}

		return $ids;
	}//end of()
}//end class
