<?php

/**
 * GeoFilterApplier — applies a list of GeoFilters to a list of rows.
 *
 * Rows are post-filtered using the cross-platform PHP fallback in
 * GeoSpatialEvaluator. The PostGIS optimisation tracked in
 * `geo-spatial-queries` will eventually push this down into SQL on
 * PostgreSQL deployments.
 *
 * Filter list semantics: AND. A row passes only when every filter
 * matches. Empty filter list = pass-through.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Geo
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-004
 * @spec openspec/specs/geo-metadata-kaart/spec.md#requirement-req-geo-004-spatial-queries-in-the-api
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Geo;

/**
 * Applies spatial filters to a list of rows by post-filtering.
 */
class GeoFilterApplier {

	private const GEOJSON_TYPES = ['Point', 'Polygon', 'MultiPolygon', 'LineString'];

	/**
	 * Constructor.
	 *
	 * @param GeoSpatialEvaluator $evaluator Underlying PHP-fallback evaluator.
	 */
	public function __construct(
		private readonly GeoSpatialEvaluator $evaluator,
	) {

	}//end __construct()

	/**
	 * Filter a list of rows by every filter (AND semantics).
	 *
	 * @param array $rows The rows to filter.
	 * @param GeoFilter[] $filters The filters to apply.
	 *
	 * @return array The matching rows, in original order.
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md#requirement-req-geo-004-spatial-queries-in-the-api
	 */
	public function applyAll(array $rows, array $filters): array {
		if (count($filters) === 0) {
			return $rows;
		}

		$matched = [];
		foreach ($rows as $row) {
			if ($this->rowMatchesAll(row: $row, filters: $filters) === true) {
				$matched[] = $row;
			}
		}

		return $matched;
	}//end applyAll()

	/**
	 * Test a single row against every filter.
	 *
	 * @param array $row The row.
	 * @param GeoFilter[] $filters The filters.
	 *
	 * @return bool True when the row passes every filter.
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md#requirement-req-geo-004-spatial-queries-in-the-api
	 */
	public function rowMatchesAll(array $row, array $filters): bool {
		foreach ($filters as $filter) {
			$geometry = $this->extractGeometry(row: $row, property: $filter->property);
			if ($this->evaluator->matches(rowGeometry: $geometry, filter: $filter) === false) {
				return false;
			}
		}

		return true;
	}//end rowMatchesAll()

	/**
	 * Pull a geometry value out of the row.
	 *
	 * @param array $row The row.
	 * @param ?string $property Property name; null = first GeoJSON-shaped value.
	 *
	 * @return ?array The GeoJSON geometry, or null when none found.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-geo-metadata-kaart/tasks.md#task-1
	 */
	public function extractGeometry(array $row, ?string $property): ?array {
		if ($property !== null) {
			return $this->coerceGeometry(value: ($row[$property] ?? null));
		}

		foreach ($row as $value) {
			$geometry = $this->coerceGeometry(value: $value);
			if ($geometry !== null) {
				return $geometry;
			}
		}

		return null;
	}//end extractGeometry()

	/**
	 * Coerce a raw value to a GeoJSON geometry array.
	 *
	 * @param mixed $value The candidate.
	 *
	 * @return ?array The geometry when shape matches, null otherwise.
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md#requirement-req-geo-004-spatial-queries-in-the-api
	 */
	private function coerceGeometry(mixed $value): ?array {
		if (is_array($value) === false) {
			return null;
		}

		$type = ($value['type'] ?? null);
		if (in_array($type, self::GEOJSON_TYPES, true) === false) {
			return null;
		}

		if (isset($value['coordinates']) === false) {
			return null;
		}

		return $value;
	}//end coerceGeometry()
}//end class
