<?php

/**
 * FleetAppPath — An app-scoped route path, `/apps/<id>[/suffix]`, before the id is resolved.
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
 * An app-scoped route path, `/apps/<id>[/suffix]`, before the id is resolved.
 */
final class FleetAppPath {
	/**
	 * @param string $canonical Canonical (new) app name, e.g. 'integriq'.
	 * @param string $suffix Path after the app segment, no leading slash.
	 */
	public function __construct(
		public readonly string $canonical,
		public readonly string $suffix = '',
	) {
	}//end __construct()
}//end class
