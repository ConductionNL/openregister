<?php

/**
 * OpenRegister platform configuration
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Hardening
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

namespace OCA\OpenRegister\Service\Hardening;

use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IConfig;

/**
 * The system configuration, the app configuration and the app manager the security reader inspects.
 *
 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md
 */
final class PlatformConfiguration {
	/**
	 * Bundle the collaborators.
	 *
	 * @param IConfig     $config     Reads the session and brute-force system configuration.
	 * @param IAppConfig  $appConfig  Reads the password policy app's own values.
	 * @param IAppManager $appManager Answers whether the password policy is running.
	 */
	public function __construct(
		public readonly IConfig $config,
		public readonly IAppConfig $appConfig,
		public readonly IAppManager $appManager,
	) {
	}//end __construct()
}//end class
