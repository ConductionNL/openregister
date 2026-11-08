<?php

/**
 * Where the admin settings page reads the push notification status from
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
use OCP\IAppConfig;

/**
 * The two platform services the push status of the admin page is read from.
 *
 * @spec openspec/changes/add-live-updates/tasks.md#task-10
 */
final class PushStatusSources {
	/**
	 * Bundle the services.
	 *
	 * @param IAppManager $appManager App manager, for whether notify_push is installed.
	 * @param IAppConfig  $appConfig  App config, for the push_available flag.
	 */
	public function __construct(
		public readonly IAppManager $appManager,
		public readonly IAppConfig $appConfig,
	) {
	}//end __construct()
}//end class
