<?php

/**
 * OpenRegister AppHost — admin settings form services
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

use OCP\App\IAppManager;
use OCP\AppFramework\Services\IInitialState;
use OCP\IAppConfig;

/**
 * The platform services the generic admin settings form renders through.
 *
 * @spec openspec/changes/apphost-boilerplate-controllers/tasks.md#task-2.4
 */
final class AdminFormServices {
	/**
	 * Bundle the services.
	 *
	 * @param IAppManager     $appManager   App manager (version lookup).
	 * @param IInitialState   $initialState Initial-state service.
	 * @param IAppConfig|null $appConfig    App config — when provided, enables
	 *                                      the real up-to-date check against
	 *                                      the stored config version.
	 */
	public function __construct(
		public readonly IAppManager $appManager,
		public readonly IInitialState $initialState,
		public readonly ?IAppConfig $appConfig = null,
	) {
	}//end __construct()
}//end class
