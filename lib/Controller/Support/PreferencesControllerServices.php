<?php

/**
 * OpenRegister PreferencesController services
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

use OCP\IConfig;

/**
 * The collaborators of PreferencesController, wired by DI autowiring.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @spec openspec/specs/apphost-boilerplate/spec.md#requirement-generic-preferences-controller
 */
final class PreferencesControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param IConfig           $config  The Nextcloud config (user values).
	 * @param ControllerSession $session The session user.
	 *
	 * @spec openspec/specs/apphost-boilerplate/spec.md#requirement-generic-preferences-controller
	 */
	public function __construct(
		public readonly IConfig $config,
		public readonly ControllerSession $session,
	) {
	}//end __construct()
}//end class
