/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * geometryValue — what the geometry editor offers to draw and how it reads
 * its JSON text, kept apart from the Leaflet wiring so it is testable.
 *
 * @spec openspec/changes/geometry-on-a-map/specs/geo-metadata-kaart/spec.md#requirement-req-geomap-001-a-geometry-is-validated-on-save
 */

/**
 * The shapes the draw control offers for a property's geo format: a point
 * draws a marker, a line a polyline, a polygon or multipolygon a polygon,
 * and any geometry all three.
 *
 * @param {string|null} geoType The property's geo type, lower-cased.
 * @return {{marker: boolean, polyline: boolean, polygon: boolean}} The shapes.
 * @spec openspec/changes/geometry-on-a-map/specs/geo-metadata-kaart/spec.md#requirement-req-geomap-001-a-geometry-is-validated-on-save
 */
export function drawShapes(geoType) {
	const type = String(geoType || '').toLowerCase()
	if (type === 'geo:point') {
		return { marker: true, polyline: false, polygon: false }
	}
	if (type === 'geo:linestring') {
		return { marker: false, polyline: true, polygon: false }
	}
	if (type === 'geo:polygon' || type === 'geo:multipolygon') {
		return { marker: false, polyline: false, polygon: true }
	}
	return { marker: true, polyline: true, polygon: true }
}

/**
 * The geometry of a drawn Leaflet layer, shaped for the property: a polygon
 * drawn for a multipolygon property is wrapped as a MultiPolygon.
 *
 * @param {object} layer A drawn Leaflet layer (with `toGeoJSON()`).
 * @param {string|null} geoType The property's geo type, lower-cased.
 * @return {object|null} The GeoJSON geometry, or null.
 * @spec openspec/changes/geometry-on-a-map/specs/geo-metadata-kaart/spec.md#requirement-req-geomap-001-a-geometry-is-validated-on-save
 */
export function drawnGeometry(layer, geoType) {
	const feature = layer && typeof layer.toGeoJSON === 'function' ? layer.toGeoJSON() : null
	const geometry = feature && feature.type === 'Feature' ? feature.geometry : feature
	if (!geometry || typeof geometry.type !== 'string' || !Array.isArray(geometry.coordinates)) {
		return null
	}
	if (String(geoType || '').toLowerCase() === 'geo:multipolygon' && geometry.type === 'Polygon') {
		return { type: 'MultiPolygon', coordinates: [geometry.coordinates] }
	}
	return { type: geometry.type, coordinates: geometry.coordinates }
}

/**
 * Read the JSON text field: an empty text clears the value, valid JSON
 * becomes the value, anything else is an error with the parser's reason.
 * The server validates the shape on save (REQ-GEOMAP-001).
 *
 * @param {string} text The text.
 * @return {{value: (object|null), error: string}} The value, or the reason it is not one.
 * @spec openspec/changes/geometry-on-a-map/specs/geo-metadata-kaart/spec.md#requirement-req-geomap-001-a-geometry-is-validated-on-save
 */
export function parseGeometryText(text) {
	if (String(text || '').trim() === '') {
		return { value: null, error: '' }
	}
	try {
		const value = JSON.parse(text)
		return value && typeof value === 'object' && !Array.isArray(value)
			? { value, error: '' }
			: { value: null, error: 'not an object' }
	} catch (error) {
		return { value: null, error: error.message }
	}
}
