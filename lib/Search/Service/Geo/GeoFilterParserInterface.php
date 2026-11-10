<?php

/**
 * GeoFilterParserInterface: what callers outside the Search component use of GeoFilterParser.
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

use InvalidArgumentException;

/**
 * Parses spatial filters out of request parameters or a geo-search body.
 */
interface GeoFilterParserInterface {

	/**
	 * Parse spatial filters (geo.bbox, geo.near + geo.radius, geo.property) out of a query-parameter array.
	 *
	 * @param array $params HTTP query params.
	 *
	 * @return GeoFilter[]
	 *
	 * @throws InvalidArgumentException When `geo.near`/`geo.radius` are mismatched.
	 */
	public function fromQueryParams(array $params): array;

	/**
	 * Parse spatial filters out of a `_geo-search` request body.
	 *
	 * @param array $body Decoded JSON body.
	 *
	 * @return GeoFilter[]
	 *
	 * @throws InvalidArgumentException When malformed.
	 */
	public function fromGeoSearchBody(array $body): array;
}//end interface
