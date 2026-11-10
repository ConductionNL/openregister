<?php

/**
 * GeoResponseCrsInterface: what callers outside the Search component use of GeoResponseCrs.
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
 * A negotiated `Accept-Crs` for a GeoJSON response: whether it can be served, and the collection in it.
 */
interface GeoResponseCrsInterface {

	/**
	 * Whether this request can be served.
	 *
	 * @return bool False when the client asked for a CRS this instance does not serve.
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-015
	 */
	public function isServable(): bool;

	/**
	 * The body of a refusal.
	 *
	 * @return array{error: string, supported: string[]} What to answer with a 406.
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-015
	 */
	public function refusal(): array;

	/**
	 * The `Content-Crs` header value, or null when the client asked for none.
	 *
	 * @return string|null The CRS of the response.
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-015
	 */
	public function contentCrs(): ?string;

	/**
	 * A FeatureCollection in the requested CRS.
	 *
	 * @param array<string, mixed> $collection The collection, in WGS84.
	 *
	 * @return array<string, mixed> The collection to answer with.
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-015
	 */
	public function applyTo(array $collection): array;
}//end interface
