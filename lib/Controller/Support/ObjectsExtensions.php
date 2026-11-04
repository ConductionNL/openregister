<?php

/**
 * OpenRegister objects controller extension services
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

/**
 * The platform services plus the optional geo and linked data services of the objects API.
 *
 * @spec openspec/specs/objects-crud/spec.md
 */
final class ObjectsExtensions {
	/**
	 * Bundle the services.
	 *
	 * @param AppPlatform        $platform   App configuration, app manager and container.
	 * @param GeoServices        $geo        The geo services.
	 * @param LinkedDataServices $linkedData The linked data services.
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function __construct(
		public readonly AppPlatform $platform,
		public readonly GeoServices $geo,
		public readonly LinkedDataServices $linkedData,
	) {
	}//end __construct()
}//end class
