<?php

/**
 * The platform services the integrations admin page builds its links with
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Settings
 * @package  OCA\OpenRegister\Settings
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

namespace OCA\OpenRegister\Settings;

use OCP\App\IAppManager;
use OCP\IURLGenerator;

/**
 * The app manager and URL generator the integrations admin page builds its
 * required-app checks and its configure and test links with.
 *
 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-23
 */
final class IntegrationLinks {
	/**
	 * Bundle the services.
	 *
	 * @param IAppManager   $appManager   NC app manager.
	 * @param IURLGenerator $urlGenerator URL generator (Configure deep-link).
	 */
	public function __construct(
		public readonly IAppManager $appManager,
		public readonly IURLGenerator $urlGenerator,
	) {
	}//end __construct()
}//end class
