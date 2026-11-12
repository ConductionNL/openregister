<?php

/**
 * Where the markers of a map overview come from.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Search\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service;

/**
 * The register and schema a map's markers are read from, the property that
 * holds their geometry, and the filters that narrow the object set.
 *
 * @spec openspec/specs/integration-maps-overview/spec.md
 */
final class MapMarkerSource {
	/**
	 * Constructor.
	 *
	 * @param string|int          $register    Register slug or id the markers come from.
	 * @param string|int          $schema      Schema slug or id the markers come from.
	 * @param string|null         $geoProperty Object property holding the geometry; null = auto-detect.
	 * @param array<string,mixed> $filters     Filters narrowing the object set (property=value).
	 */
	public function __construct(
		public readonly string|int $register,
		public readonly string|int $schema,
		public readonly ?string $geoProperty = null,
		public readonly array $filters = [],
	) {
	}//end __construct()

	/**
	 * The OpenRegister findAll filter array for a map query.
	 *
	 * Register/schema become the scope keys; every other filter is copied
	 * through verbatim (the OR read path validates + RBAC-scopes them). The
	 * reserved scope keys cannot be overridden by the caller.
	 *
	 * @return array<string,mixed> The findAll filter array.
	 */
	public function objectFilters(): array {
		$clean = [];
		foreach ($this->filters as $key => $value) {
			// Reserved scope keys are owned by the source, not the caller.
			if (in_array($key, ['register', 'schema', 'limit', 'offset', 'page'], true) === true) {
				continue;
			}

			$clean[$key] = $value;
		}

		$clean['register'] = $this->register;
		$clean['schema'] = $this->schema;

		return $clean;
	}//end objectFilters()
}//end class
