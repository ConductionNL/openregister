<?php
/**
 * SpatialFilter: Search's answer to the object API's spatial-filter port.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Search\Service\Geo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Geo;

use OCA\OpenRegister\Kernel\Contracts\Core\SpatialFilterInterface;

/**
 * Parses spatial filters with the geo filter parser and applies them with the geo post-filter.
 *
 * @spec openspec/specs/geo-metadata-kaart/spec.md
 */
final class SpatialFilter implements SpatialFilterInterface {

	/**
	 * Hold the parser and the post-filter.
	 *
	 * @param GeoFilterParserInterface  $parser  Parses `geo.*` params and geo-search bodies.
	 * @param GeoFilterApplierInterface $applier Post-filters rows by the parsed filters.
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md
	 */
	public function __construct(
		private readonly GeoFilterParserInterface $parser,
		private readonly GeoFilterApplierInterface $applier,
	) {
	}//end __construct()

	/**
	 * The spatial filters of a query-parameter array.
	 *
	 * @param array $params HTTP query params.
	 *
	 * @return GeoFilter[]
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-008
	 */
	public function fromQueryParams(array $params): array {
		return $this->parser->fromQueryParams(params: $params);
	}//end fromQueryParams()

	/**
	 * The spatial filters of a geo-search request body.
	 *
	 * @param array $body Decoded JSON body.
	 *
	 * @return GeoFilter[]
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-004
	 */
	public function fromGeoSearchBody(array $body): array {
		return $this->parser->fromGeoSearchBody(body: $body);
	}//end fromGeoSearchBody()

	/**
	 * The rows that match every filter, in their original order.
	 *
	 * @param array       $rows    The rows to narrow.
	 * @param GeoFilter[] $filters The filters.
	 *
	 * @return array The matching rows.
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-008
	 */
	public function applyAll(array $rows, array $filters): array {
		return $this->applier->applyAll(rows: $rows, filters: $filters);
	}//end applyAll()
}//end class
