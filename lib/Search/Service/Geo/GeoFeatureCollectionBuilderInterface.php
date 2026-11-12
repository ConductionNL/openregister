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
	 * @param array        $rows  Object rows (assoc arrays).
	 * @param FeatureShape $shape The geo property, the property allow-list and whether to add `_area_m2`.
	 *
	 * @return array A GeoJSON FeatureCollection.
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md
	 */
	public function buildFeatureCollection(array $rows, FeatureShape $shape=new FeatureShape()): array;

	/**
	 * Build a WFS GetFeature-compatible response envelope.
	 *
	 * @param array             $rows    Object rows (assoc arrays).
	 * @param GetFeatureRequest $request The geo property and the optional cap on returned features.
	 *
	 * @return array A WFS-compatible GeoJSON FeatureCollection.
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md
	 */
	public function buildWfsResponse(array $rows, GetFeatureRequest $request=new GetFeatureRequest()): array;
}//end interface
