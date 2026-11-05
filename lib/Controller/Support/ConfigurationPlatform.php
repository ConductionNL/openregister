<?php

/**
 * OpenRegister configuration platform
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

use OCP\App\IAppManager;

/**
 * The platform services of the configuration API.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class ConfigurationPlatform {
	/**
	 * Bundle the services.
	 *
	 * @param IAppManager $appManager App manager
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function __construct(
		public readonly IAppManager $appManager,
	) {
	}//end __construct()
}//end class
