<?php

/**
 * OpenRegister address query
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Geo
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

namespace OCA\OpenRegister\Service\Geo;

/**
 * One free-text address search against the PDOK Locatieserver: the text, how many suggestions, and whether only BAG addresses count.
 *
 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-005
 */
final class AddressQuery {
	/**
	 * Constructor.
	 *
	 * @param string $query    The free-text address, trimmed.
	 * @param int    $maxItems Maximum suggestions to return.
	 * @param bool   $bagOnly  Restrict to BAG `type:adres` results.
	 */
	public function __construct(
		public readonly string $query,
		public readonly int $maxItems = 5,
		public readonly bool $bagOnly = false,
	) {
	}//end __construct()
}//end class
