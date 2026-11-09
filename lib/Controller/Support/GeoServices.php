<?php

/**
 * OpenRegister geo services
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

use OCA\OpenRegister\Search\Service\Geo\GeoFeatureCollectionBuilderInterface;
use OCA\OpenRegister\Search\Service\Geo\GeoFilterApplierInterface;
use OCA\OpenRegister\Search\Service\Geo\GeoFilterParserInterface;
use OCA\OpenRegister\Service\Geo\PdokGeocoder;

/**
 * Geo filter parsing and applying, GeoJSON feature building and geocoding; each is null when absent.
 *
 * @spec openspec/specs/geo-metadata-kaart/spec.md
 */
final class GeoServices {
	/**
	 * Bundle the services.
	 *
	 * @param ?GeoFilterParserInterface             $geoFilterParser   The geo filter parser.
	 * @param ?GeoFilterApplierInterface            $geoFilterApplier  The geo post-filter.
	 * @param ?GeoFeatureCollectionBuilderInterface $geoFeatureBuilder The GeoJSON feature builder.
	 * @param ?PdokGeocoder                         $pdokGeocoder      The geocoder.
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md
	 */
	public function __construct(
		public readonly ?GeoFilterParserInterface $geoFilterParser = null,
		public readonly ?GeoFilterApplierInterface $geoFilterApplier = null,
		public readonly ?GeoFeatureCollectionBuilderInterface $geoFeatureBuilder = null,
		public readonly ?PdokGeocoder $pdokGeocoder = null,
	) {
	}//end __construct()
}//end class
