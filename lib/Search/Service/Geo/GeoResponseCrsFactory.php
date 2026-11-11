<?php

/**
 * GeoResponseCrsFactory: negotiates the CRS of a GeoJSON response from an `Accept-Crs` header.
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
 * A controller negotiates the response CRS per request, from a header value, so the
 * negotiation cannot come from the container. It is built here, so code outside the Search
 * component names only the interface.
 */
final class GeoResponseCrsFactory {

	/**
	 * The CRS a request's `Accept-Crs` header asks for.
	 *
	 * @param string $accept The `Accept-Crs` header value, empty when absent.
	 *
	 * @return GeoResponseCrsInterface
	 */
	public static function fromAccept(string $accept): GeoResponseCrsInterface {
		return new GeoResponseCrs(accept: $accept);
	}//end fromAccept()
}//end class
