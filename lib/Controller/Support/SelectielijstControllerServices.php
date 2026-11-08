<?php

/**
 * OpenRegister selectielijst controller services
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
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

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Service\Archival\SelectielijstImportServiceInterface;
use OCA\OpenRegister\Service\Settings\ArchivalSettingsReaderInterface;

/**
 * The services of SelectielijstController, bundled so its constructor stays small.
 *
 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
 */
final class SelectielijstControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param SelectielijstImportServiceInterface $selectielijst   Imports, versions and diffs a list.
	 * @param ArchivalSettingsReaderInterface     $settingsHandler Names the version in force.
	 */
	public function __construct(
		public readonly SelectielijstImportServiceInterface $selectielijst,
		public readonly ArchivalSettingsReaderInterface $settingsHandler,
	) {
	}//end __construct()
}//end class
