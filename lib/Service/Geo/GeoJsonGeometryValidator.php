<?php

/**
 * GeoJsonGeometryValidator — RFC 7946 validation for geo property types.
 *
 * Validates the geospatial property types defined in REQ-GEO-001:
 *
 *   - geo:point        — GeoJSON Point, lon/lat range checks (RFC 7946).
 *   - geo:polygon      — GeoJSON Polygon, ring closure + min positions.
 *   - geo:multipolygon — GeoJSON MultiPolygon, per-polygon closure.
 *   - geo:linestring   — GeoJSON LineString, >= 2 positions.
 *   - geo:geometry     — any of the above.
 *   - geo:bag          — BAG nummeraanduiding id (16-digit string).
 *
 * Pure data-shaping: no I/O, no DB, no framework. Every method is a
 * total function over its input so the rules are unit-testable in
 * isolation (mandatory geo-metadata-shaping test target).
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
 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Geo;

/**
 * Validates GeoJSON values against the geo property types of REQ-GEO-001.
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 *   One branch per GeoJSON geometry type plus shape guards — the
 *   complexity is inherent to RFC 7946 and clearer kept in one place.
 * @SuppressWarnings(PHPMD.NPathComplexity)
 *   Same rationale: each geometry type is a single validation branch.
 */
class GeoJsonGeometryValidator {

	/**
	 * Property types recognised by this validator (REQ-GEO-001).
	 *
	 * @var string[]
	 */
	public const GEO_TYPES = [
		'geo:point',
		'geo:polygon',
		'geo:multipolygon',
		'geo:linestring',
		'geo:geometry',
		'geo:bag',
	];

	/**
	 * Whether a schema property type string is a geo type.
	 *
	 * @param string $type The property type (e.g. `geo:point`).
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-001
	 */
	public function isGeoType(string $type): bool {
		return in_array(strtolower($type), self::GEO_TYPES, true);
	}//end isGeoType()

	/**
	 * Validate a value for a given geo property type.
	 *
	 * Returns a list of human-readable error strings; an empty list
	 * means the value is valid. Never throws — callers map a non-empty
	 * list to a 422 response (REQ-GEO-001).
	 *
	 * @param string $type The geo property type.
	 * @param mixed $value The candidate value.
	 *
	 * @return string[] Validation errors (empty = valid).
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-001
	 */
	public function validate(string $type, mixed $value): array {
		$type = strtolower($type);

		if ($type === 'geo:bag') {
			return $this->validateBag(value: $value);
		}

		if (is_array($value) === false) {
			return ['geometry MUST be a GeoJSON object'];
		}

		$geometryType = ($value['type'] ?? null);

		switch ($type) {
			case 'geo:point':
				return $this->validatePoint(value: $value);
			case 'geo:polygon':
				return $this->validatePolygon(value: $value);
			case 'geo:multipolygon':
				return $this->validateMultiPolygon(value: $value);
			case 'geo:linestring':
				return $this->validateLineString(value: $value);
			case 'geo:geometry':
				return $this->validateAny(geometryType: $geometryType, value: $value);
			default:
				return ["unknown geo property type: {$type}"];
		}

	}//end validate()

	/**
	 * Validate any supported GeoJSON geometry (geo:geometry).
	 *
	 * @param mixed $geometryType The declared `type` field.
	 * @param array $value The GeoJSON value.
	 *
	 * @return string[]
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-001
	 */
	private function validateAny(mixed $geometryType, array $value): array {
		switch ($geometryType) {
			case 'Point':
				return $this->validatePoint(value: $value);
			case 'Polygon':
				return $this->validatePolygon(value: $value);
			case 'MultiPolygon':
				return $this->validateMultiPolygon(value: $value);
			case 'LineString':
				return $this->validateLineString(value: $value);
			default:
				return ['geometry type MUST be one of Point, Polygon, MultiPolygon, LineString'];
		}

	}//end validateAny()

	/**
	 * Validate a GeoJSON Point.
	 *
	 * @param array $value The candidate value.
	 *
	 * @return string[]
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-001
	 */
	private function validatePoint(array $value): array {
		if (($value['type'] ?? null) !== 'Point') {
			return ['Point geometry MUST have type "Point"'];
		}

		$coords = ($value['coordinates'] ?? null);
		if (is_array($coords) === false || count($coords) < 2) {
			return ['Point coordinates MUST be a [longitude, latitude] array'];
		}

		return $this->validatePosition(position: $coords);
	}//end validatePoint()

	/**
	 * Validate a single position (lon, lat) per RFC 7946.
	 *
	 * @param array $position The position array.
	 *
	 * @return string[]
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-001
	 */
	private function validatePosition(array $position): array {
		if (is_numeric($position[0] ?? null) === false || is_numeric($position[1] ?? null) === false) {
			return ['coordinates MUST be numeric'];
		}

		$lon = (float)$position[0];
		$lat = (float)$position[1];
		$errors = [];
		if ($lon < -180.0 || $lon > 180.0) {
			$errors[] = 'longitude MUST be between -180 and 180 (it is the first element per RFC 7946)';
		}

		if ($lat < -90.0 || $lat > 90.0) {
			$errors[] = 'latitude MUST be between -90 and 90 (it is the second element per RFC 7946)';
		}

		return $errors;
	}//end validatePosition()

	/**
	 * Validate a GeoJSON LineString (>= 2 positions).
	 *
	 * @param array $value The candidate value.
	 *
	 * @return string[]
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-001
	 */
	private function validateLineString(array $value): array {
		if (($value['type'] ?? null) !== 'LineString') {
			return ['LineString geometry MUST have type "LineString"'];
		}

		$coords = ($value['coordinates'] ?? null);
		if (is_array($coords) === false || count($coords) < 2) {
			return ['LineString MUST contain at least 2 coordinate positions'];
		}

		$errors = [];
		foreach ($coords as $position) {
			if (is_array($position) === false) {
				$errors[] = 'each LineString position MUST be a coordinate array';
				continue;
			}

			$errors = array_merge($errors, $this->validatePosition(position: $position));
		}

		return $errors;
	}//end validateLineString()

	/**
	 * Validate a GeoJSON Polygon (closed rings, >= 4 positions per ring).
	 *
	 * @param array $value The candidate value.
	 *
	 * @return string[]
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-001
	 */
	private function validatePolygon(array $value): array {
		if (($value['type'] ?? null) !== 'Polygon') {
			return ['Polygon geometry MUST have type "Polygon"'];
		}

		$rings = ($value['coordinates'] ?? null);
		if (is_array($rings) === false || count($rings) === 0) {
			return ['Polygon MUST have at least one linear ring'];
		}

		return $this->validateRings(rings: $rings, label: 'Polygon');
	}//end validatePolygon()

	/**
	 * Validate a GeoJSON MultiPolygon (each polygon individually closed).
	 *
	 * @param array $value The candidate value.
	 *
	 * @return string[]
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-001
	 */
	private function validateMultiPolygon(array $value): array {
		if (($value['type'] ?? null) !== 'MultiPolygon') {
			return ['MultiPolygon geometry MUST have type "MultiPolygon"'];
		}

		$polygons = ($value['coordinates'] ?? null);
		if (is_array($polygons) === false || count($polygons) === 0) {
			return ['MultiPolygon MUST contain at least one polygon'];
		}

		$errors = [];
		foreach ($polygons as $index => $rings) {
			if (is_array($rings) === false || count($rings) === 0) {
				$errors[] = "MultiPolygon polygon #{$index} MUST have at least one ring";
				continue;
			}

			$errors = array_merge($errors, $this->validateRings(rings: $rings, label: "MultiPolygon polygon #{$index}"));
		}

		return $errors;
	}//end validateMultiPolygon()

	/**
	 * Validate a set of polygon rings: min 4 positions and closure.
	 *
	 * @param array $rings The rings to validate.
	 * @param string $label Context label for error messages.
	 *
	 * @return string[]
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-001
	 */
	private function validateRings(array $rings, string $label): array {
		$errors = [];
		foreach ($rings as $ringIndex => $ring) {
			if (is_array($ring) === false || count($ring) < 4) {
				$errors[] = "{$label} ring #{$ringIndex} MUST contain at least 4 coordinate positions";
				continue;
			}

			$first = $ring[0];
			$last = $ring[(count($ring) - 1)];
			if ($first !== $last) {
				$errors[] = "{$label} ring #{$ringIndex} is unclosed: first and last coordinate MUST be identical";
			}

			foreach ($ring as $position) {
				if (is_array($position) === false) {
					$errors[] = "{$label} ring #{$ringIndex} has a non-array position";
					continue;
				}

				$errors = array_merge($errors, $this->validatePosition(position: $position));
			}
		}//end foreach

		return $errors;
	}//end validateRings()

	/**
	 * Validate a BAG nummeraanduiding identifier (REQ-GEO-001).
	 *
	 * Format: 4-digit gemeentecode + 2-digit objecttypecode + 10-digit
	 * volgnummer = 16 digits total.
	 *
	 * @param mixed $value The candidate BAG id.
	 *
	 * @return string[]
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-001
	 */
	private function validateBag(mixed $value): array {
		if (is_string($value) === false) {
			return ['BAG reference MUST be a 16-digit nummeraanduiding string'];
		}

		if (preg_match('/^[0-9]{4}[0-9]{2}[0-9]{10}$/', $value) !== 1) {
			return [
				'BAG nummeraanduiding MUST be 16 digits: 4-digit gemeentecode + '
				. '2-digit objecttypecode + 10-digit volgnummer',
			];
		}

		return [];
	}//end validateBag()
}//end class
