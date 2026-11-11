<?php

/**
 * OpenRegister UnknownFilterKeys.
 *
 * The filter keys of one request that named no property, and where they came from.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Support
 * @package  OCA\OpenRegister\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Support;

use OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef;

/**
 * What {@see FilterParams::warnUnknownKeys()} reports: the unknown keys, the route and the register and schema from the URL.
 *
 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-both-filter-spellings-mean-the-same-filter-on-object-search-and-the-aggregations
 */
final class UnknownFilterKeys {

	/**
	 * Constructor.
	 *
	 * @param string[]          $keys     The unknown keys.
	 * @param string            $endpoint The route name, e.g. `objects#index`.
	 * @param RegisterSchemaRef $table    The register and schema references from the URL.
	 */
	public function __construct(
		public readonly array $keys,
		public readonly string $endpoint,
		public readonly RegisterSchemaRef $table,
	) {
	}//end __construct()
}//end class
