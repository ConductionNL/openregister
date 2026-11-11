<?php

/**
 * FeatureShape — how an object row becomes a GeoJSON Feature.
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
 * Which property holds the geometry, which properties a Feature keeps, and whether it carries `_area_m2`.
 *
 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-008
 */
final class FeatureShape {
	/**
	 * Describe a shape.
	 *
	 * @param string|null   $geoProperty Geo property name; null = auto-detect.
	 * @param string[]|null $fields      Property allow-list, or null for all; the geometry is always kept.
	 * @param bool          $includeArea Add `_area_m2` for polygonal geometries.
	 */
	public function __construct(
		public readonly ?string $geoProperty=null,
		public readonly ?array $fields=null,
		public readonly bool $includeArea=false,
	) {
	}//end __construct()
}//end class
