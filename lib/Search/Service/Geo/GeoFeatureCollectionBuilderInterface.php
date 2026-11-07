<?php

/**
 * GeoFeatureCollectionBuilderInterface: what callers outside the Search component use of GeoFeatureCollectionBuilder.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Search
 * @package  OCA\OpenRegister\Search\Service\Geo
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Geo;

/**
 * Shapes object rows into a GeoJSON FeatureCollection or a WFS envelope.
 *
 * @spec openspec/specs/geo-metadata-kaart/spec.md
 */
interface GeoFeatureCollectionBuilderInterface {

	/**
	 * Build a GeoJSON FeatureCollection from a list of object rows.
	 *
	 * @param array         $rows        Object rows (assoc arrays).
	 * @param ?string       $geoProperty Geo property name; null = auto-detect.
	 * @param string[]|null $fields      Property allow-list, or null for all.
	 * @param bool          $includeArea Add `_area_m2` for polygonal geometries.
	 *
	 * @return array A GeoJSON FeatureCollection.
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md
	 */
	public function buildFeatureCollection(array $rows, ?string $geoProperty = null, ?array $fields = null, bool $includeArea = false): array;

	/**
	 * Build a WFS GetFeature-compatible response envelope.
	 *
	 * @param array    $rows        Object rows (assoc arrays).
	 * @param ?string  $geoProperty Geo property name; null = auto-detect.
	 * @param int|null $maxFeatures Optional cap on returned features.
	 *
	 * @return array A WFS-compatible GeoJSON FeatureCollection.
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md
	 */
	public function buildWfsResponse(array $rows, ?string $geoProperty = null, ?int $maxFeatures = null): array;
}//end interface
