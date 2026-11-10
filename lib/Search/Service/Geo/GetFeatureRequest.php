<?php

/**
 * GetFeatureRequest — what a WFS GetFeature call asks of the feature builder.
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
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-008
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Geo;

/**
 * Which property holds the geometry and how many features a WFS GetFeature answer may carry.
 *
 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-008
 */
final class GetFeatureRequest {
	/**
	 * Describe a GetFeature request.
	 *
	 * @param string|null $geoProperty Geo property name; null = auto-detect.
	 * @param int|null    $maxFeatures Optional cap on returned features.
	 */
	public function __construct(
		public readonly ?string $geoProperty=null,
		public readonly ?int $maxFeatures=null,
	) {
	}//end __construct()
}//end class
