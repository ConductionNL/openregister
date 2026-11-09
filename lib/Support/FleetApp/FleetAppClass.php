<?php

/**
 * FleetAppClass — A cross-app class, named by its path below the app namespace, before the id is resolved.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Support
 * @package  OCA\OpenRegister\Support\FleetApp
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Support\FleetApp;

/**
 * A cross-app class, named by its path below the app namespace, before the id is resolved.
 */
final class FleetAppClass {
	/**
	 * @param string $canonical Canonical (new) app name, e.g. 'integriq'.
	 * @param string $relativeClass Class path below the namespace, e.g. 'Service\CallService'.
	 */
	public function __construct(
		public readonly string $canonical,
		public readonly string $relativeClass,
	) {
	}//end __construct()
}//end class
