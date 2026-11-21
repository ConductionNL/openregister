<?php

/**
 * AppRouteBase: where the frontend routes of an app start.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Notification
 * @package  OCA\OpenRegister\Notifications\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

use OCP\IURLGenerator;

/**
 * The base URL an action's route or object link is built on.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final class AppRouteBase {
	/**
	 * Constructor
	 *
	 * @param IURLGenerator|null $urlGenerator URL generator; null gives relative paths.
	 *
	 * @return void
	 */
	public function __construct(private readonly ?IURLGenerator $urlGenerator = null) {
	}//end __construct()

	/**
	 * Resolve the absolute frontend route base for an app.
	 *
	 * Falls back to the app base path when the app has no dashboard route, and
	 * to a relative base when the URL generator is unavailable.
	 *
	 * @param string $app The app id.
	 *
	 * @return string The route base.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function forApp(string $app): string {
		if ($this->urlGenerator === null) {
			return '/index.php/apps/' . $app . '/';
		}

		try {
			return $this->urlGenerator->linkToRouteAbsolute($app . '.dashboard.page');
		} catch (\Throwable) {
			return $this->urlGenerator->getAbsoluteURL('/index.php/apps/' . $app . '/');
		}
	}//end forApp()
}//end class
