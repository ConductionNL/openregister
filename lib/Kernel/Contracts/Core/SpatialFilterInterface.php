<?php
/**
 * SpatialFilterInterface: the geo filters of a listing, as the object API asks for them.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Core
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Core;

use InvalidArgumentException;

/**
 * Reads spatial filters from a request and narrows listing rows by them.
 *
 * The object API (Core) narrows its RBAC-scoped listing rows by `geo.*` query params
 * and by a geo-search body; Search parses and evaluates the geometry and binds its
 * answer in its own wiring. The filters are opaque to the caller: it only hands back
 * what it was given.
 *
 * @spec openspec/specs/geo-metadata-kaart/spec.md
 */
interface SpatialFilterInterface {

	/**
	 * The spatial filters (geo.bbox, geo.near + geo.radius, geo.property) of a query-parameter array.
	 *
	 * @param array $params HTTP query params, `geo.*` keys in dotted form.
	 *
	 * @return array The filters, opaque to the caller; empty when the params name none.
	 *
	 * @throws InvalidArgumentException When the params are malformed.
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-008
	 */
	public function fromQueryParams(array $params): array;

	/**
	 * The spatial filters of a geo-search request body.
	 *
	 * @param array $body Decoded JSON body.
	 *
	 * @return array The filters, opaque to the caller.
	 *
	 * @throws InvalidArgumentException When the body is malformed.
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-004
	 */
	public function fromGeoSearchBody(array $body): array;

	/**
	 * The rows that match every filter (AND), in their original order.
	 *
	 * @param array $rows    The rows to narrow.
	 * @param array $filters Filters this port returned.
	 *
	 * @return array The matching rows.
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-008
	 */
	public function applyAll(array $rows, array $filters): array;
}//end interface
