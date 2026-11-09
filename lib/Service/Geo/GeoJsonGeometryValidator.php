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
	 * The GeoJSON geometry type each single-shape property type requires.
	 *
	 * @var array<string, string>
	 */
	private const GEOMETRY_BY_TYPE = [
		'geo:point'        => 'Point',
		'geo:polygon'      => 'Polygon',
		'geo:multipolygon' => 'MultiPolygon',
		'geo:linestring'   => 'LineString',
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
	 * The geo type a schema property declares, by `format` or by `type`, or null.
	 *
	 * @param mixed $property The property definition.
	 *
	 * @return string|null The geo type, lower-cased.
	 *
	 * @spec openspec/changes/geometry-on-a-map/specs/geo-metadata-kaart/spec.md
	 */
	public function declaredGeoType(mixed $property): ?string {
		if (is_array($property) === false) {
			return null;
		}

		foreach (['format', 'type'] as $key) {
			$declared = $this->geoTypeOf(declared: ($property[$key] ?? null));
			if ($declared !== null) {
				return $declared;
			}
		}

		return null;
	}//end declaredGeoType()

	/**
	 * The lower-cased geo type a single `format` / `type` value names, or null.
	 *
	 * @param mixed $declared The declared value.
	 *
	 * @return string|null
	 */
	private function geoTypeOf(mixed $declared): ?string {
		if (is_string($declared) === true && $this->isGeoType(type: $declared) === true) {
			return strtolower($declared);
		}

		return null;
	}//end geoTypeOf()

	/**
	 * Validate every geometry property of an object, keyed by property name.
	 *
	 * A property counts as a geometry when its definition declares a geo type
	 * by `format` (`{"type": "object", "format": "geo:polygon"}`, which a JSON
	 * Schema validator accepts) or by `type` (`geo:point`, the older spelling).
	 * An absent or null value is left to the required rule.
	 *
	 * @param array<string, mixed>          $object The object as it will be saved.
	 * @param \OCA\OpenRegister\Db\Schema $schema The schema it is saved under.
	 *
	 * @return array<string, string[]> Errors per invalid property; empty when every geometry is valid.
	 *
	 * @spec openspec/changes/geometry-on-a-map/specs/geo-metadata-kaart/spec.md
	 */
	public function validateObject(array $object, \OCA\OpenRegister\Db\Schema $schema): array {
		$errors = [];
		foreach (($schema->getProperties() ?? []) as $name => $property) {
			$type = $this->declaredGeoType(property: $property);
			if ($type === null || isset($object[$name]) === false) {
				continue;
			}

			$found = $this->validate(type: $type, value: $object[$name]);
			if ($found !== []) {
				$errors[(string)$name] = $found;
			}
		}

		return $errors;
	}//end validateObject()

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

		if ($type === 'geo:geometry') {
			return $this->validateAny(geometryType: ($value['type'] ?? null), value: $value);
		}

		if (isset(self::GEOMETRY_BY_TYPE[$type]) === false) {
			return ["unknown geo property type: {$type}"];
		}

		return $this->validateShape(geometryType: self::GEOMETRY_BY_TYPE[$type], value: $value);
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
		if (in_array($geometryType, self::GEOMETRY_BY_TYPE, true) === false) {
			return ['geometry type MUST be one of Point, Polygon, MultiPolygon, LineString'];
		}

		return $this->validateShape(geometryType: $geometryType, value: $value);
	}//end validateAny()

	/**
	 * Validate a value against the rules of one GeoJSON geometry type.
	 *
	 * @param string $geometryType One of Point, Polygon, MultiPolygon, LineString.
	 * @param array  $value        The GeoJSON value.
	 *
	 * @return string[]
	 */
	private function validateShape(string $geometryType, array $value): array {
		return match ($geometryType) {
			'Point' => $this->validatePoint(value: $value),
			'Polygon' => $this->validatePolygon(value: $value),
			'MultiPolygon' => $this->validateMultiPolygon(value: $value),
			default => $this->validateLineString(value: $value),
		};
	}//end validateShape()

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

		return $this->rangeErrors(lon: (float)$position[0], lat: (float)$position[1]);
	}//end validatePosition()

	/**
	 * The RFC 7946 range errors of a numeric (lon, lat) pair.
	 *
	 * @param float $lon The longitude (first element).
	 * @param float $lat The latitude (second element).
	 *
	 * @return string[]
	 */
	private function rangeErrors(float $lon, float $lat): array {
		$errors = [];
		if ($lon < -180.0 || $lon > 180.0) {
			$errors[] = 'longitude MUST be between -180 and 180 (it is the first element per RFC 7946)';
		}

		if ($lat < -90.0 || $lat > 90.0) {
			$errors[] = 'latitude MUST be between -90 and 90 (it is the second element per RFC 7946)';
		}

		return $errors;
	}//end rangeErrors()

	/**
	 * Validate every position of a list; a non-array position yields `$nonArrayError`.
	 *
	 * @param array  $positions     The positions.
	 * @param string $nonArrayError The error for a position that is not an array.
	 *
	 * @return string[]
	 */
	private function validatePositions(array $positions, string $nonArrayError): array {
		$errors = [];
		foreach ($positions as $position) {
			if (is_array($position) === false) {
				$errors[] = $nonArrayError;
				continue;
			}

			$errors = array_merge($errors, $this->validatePosition(position: $position));
		}

		return $errors;
	}//end validatePositions()

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

		return $this->validatePositions(positions: $coords, nonArrayError: 'each LineString position MUST be a coordinate array');
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
			$errors = array_merge($errors, $this->validateMemberPolygon(index: $index, rings: $rings));
		}

		return $errors;
	}//end validateMultiPolygon()

	/**
	 * Validate one polygon of a MultiPolygon.
	 *
	 * @param int|string $index The polygon's index in the MultiPolygon.
	 * @param mixed      $rings The polygon's rings.
	 *
	 * @return string[]
	 */
	private function validateMemberPolygon(int|string $index, mixed $rings): array {
		if (is_array($rings) === false || count($rings) === 0) {
			return ["MultiPolygon polygon #{$index} MUST have at least one ring"];
		}

		return $this->validateRings(rings: $rings, label: "MultiPolygon polygon #{$index}");
	}//end validateMemberPolygon()

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
			$errors = array_merge($errors, $this->validateRing(ring: $ring, ringLabel: "{$label} ring #{$ringIndex}"));
		}

		return $errors;
	}//end validateRings()

	/**
	 * Validate one polygon ring: min 4 positions, closure, then each position.
	 *
	 * @param mixed  $ring      The ring.
	 * @param string $ringLabel Context label for error messages (`<label> ring #<n>`).
	 *
	 * @return string[]
	 */
	private function validateRing(mixed $ring, string $ringLabel): array {
		if (is_array($ring) === false || count($ring) < 4) {
			return ["{$ringLabel} MUST contain at least 4 coordinate positions"];
		}

		$errors = [];
		if ($ring[0] !== $ring[(count($ring) - 1)]) {
			$errors[] = "{$ringLabel} is unclosed: first and last coordinate MUST be identical";
		}

		return array_merge($errors, $this->validatePositions(positions: $ring, nonArrayError: "{$ringLabel} has a non-array position"));
	}//end validateRing()

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
