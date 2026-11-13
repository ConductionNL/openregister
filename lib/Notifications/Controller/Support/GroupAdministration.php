<?php

/**
 * The authorities on who administers a group.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Controller
 * @package  OCA\OpenRegister\Notifications\Controller\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Controller\Support;

use OCP\Group\ISubAdmin;
use OCP\IGroupManager;

/**
 * The group manager, which knows the instance administrators, and the sub-admin
 * relation, which knows who administers one particular group.
 *
 * @spec openspec/specs/notificatie-engine/spec.md#requirement-the-effective-preference-merges-schema-group-and-user-and-names-the-layer-req-nrg-002
 */
final readonly class GroupAdministration {
	/**
	 * Bundle the two authorities.
	 *
	 * @param IGroupManager $groupManager The authority on who is an administrator, and on which groups exist.
	 * @param ISubAdmin     $subAdmin     The authority on who administers one particular group.
	 */
	public function __construct(
		public IGroupManager $groupManager,
		public ISubAdmin $subAdmin,
	) {
	}//end __construct()
}//end class
