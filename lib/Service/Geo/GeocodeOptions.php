<?php

/**
 * GeocodeOptions
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Geo
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Geo;

/**
 * How a free-text address search is bounded: how many suggestions, and whether
 * only BAG addresses count.
 *
 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-005
 */
final class GeocodeOptions {

	/**
	 * Name the options.
	 *
	 * @param int  $maxItems Maximum suggestions to return.
	 * @param bool $bagOnly  Restrict to BAG `type:adres` results.
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-005
	 */
	public function __construct(
		public readonly int $maxItems = 5,
		public readonly bool $bagOnly = false,
	) {
	}//end __construct()
}//end class
