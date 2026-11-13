<?php

/**
 * OpenRegister AppHost — settings section appearance
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
 * How a leaf app's admin settings section is shown: its display name and
 * the icon file in the app's img/ directory.
 *
 * @spec openspec/changes/apphost-boilerplate-controllers/tasks.md#task-2.4
 */
final class SectionAppearance {
	/**
	 * Build the appearance.
	 *
	 * @param string $name     Human-readable section display name.
	 * @param string $iconFile Icon file name within the app's img/ dir.
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $iconFile,
	) {
	}//end __construct()
}//end class
