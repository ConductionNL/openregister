<?php

/**
 * OpenRegister geographic point
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Search\Service\Geo
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Geo;

/**
 * A WGS84 position: a longitude and a latitude in degrees.
 *
 * @spec openspec/specs/geo-metadata-kaart/spec.md#requirement-req-geo-012-solr-and-elasticsearch-spatial-query-support
 */
final class GeoPoint {
	/**
	 * A position.
	 *
	 * @param float $lon Longitude in degrees.
	 * @param float $lat Latitude in degrees.
	 */
	public function __construct(
		public readonly float $lon,
		public readonly float $lat,
	) {
	}//end __construct()
}//end class
