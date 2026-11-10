<?php

/**
 * OpenRegister AppHost — settings placement
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Settings
 * @package  OCA\OpenRegister\AppHost\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Settings;

/**
 * Where a leaf app's settings surface sits: the owning app, the admin
 * section id and the ordering priority within it.
 *
 * Shared by {@see GenericAdminSettings} (priority = the form's priority in
 * the section) and {@see GenericSettingsSection} (priority = the section's
 * priority in the admin navigation).
 *
 * @spec openspec/changes/apphost-boilerplate-controllers/tasks.md#task-2.4
 */
final class SettingsPlacement {
	/**
	 * Build the placement.
	 *
	 * @param string $appId     The leaf app id (template, version, icon path).
	 * @param string $sectionId The admin settings section id.
	 * @param int    $priority  Ordering priority.
	 */
	public function __construct(
		public readonly string $appId,
		public readonly string $sectionId,
		public readonly int $priority,
	) {
	}//end __construct()
}//end class
