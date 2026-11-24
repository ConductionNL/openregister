/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * geometryValue: the shapes the geometry editor offers per geo format, the
 * GeoJSON a drawn shape becomes, and how its JSON text is read.
 *
 * @spec openspec/changes/geometry-on-a-map/specs/geo-metadata-kaart/spec.md#requirement-req-geomap-001-a-geometry-is-validated-on-save
 */

import { drawnGeometry, drawShapes, parseGeometryText } from './geometryValue.js'

const ring = [[5.1, 52.0], [5.2, 52.0], [5.2, 52.1], [5.1, 52.0]]
const polygonLayer = { toGeoJSON: () => ({ type: 'Feature', properties: {}, geometry: { type: 'Polygon', coordinates: [ring] } }) }

describe('drawShapes', () => {
	it('offers the one shape a single-shape format allows', () => {
		expect(drawShapes('geo:point')).toEqual({ marker: true, polyline: false, polygon: false })
		expect(drawShapes('geo:linestring')).toEqual({ marker: false, polyline: true, polygon: false })
		expect(drawShapes('GEO:Polygon')).toEqual({ marker: false, polyline: false, polygon: true })
		expect(drawShapes('geo:multipolygon')).toEqual({ marker: false, polyline: false, polygon: true })
	})

	it('offers every shape for any geometry or an unknown format', () => {
		expect(drawShapes('geo:geometry')).toEqual({ marker: true, polyline: true, polygon: true })
		expect(drawShapes(null)).toEqual({ marker: true, polyline: true, polygon: true })
	})
})

describe('drawnGeometry', () => {
	it('keeps a drawn polygon as a Polygon', () => {
		expect(drawnGeometry(polygonLayer, 'geo:polygon')).toEqual({ type: 'Polygon', coordinates: [ring] })
	})

	it('wraps a polygon drawn for a multipolygon property', () => {
		expect(drawnGeometry(polygonLayer, 'geo:multipolygon')).toEqual({ type: 'MultiPolygon', coordinates: [[ring]] })
	})

	it('reads a drawn marker as a Point, and nothing from a layer without GeoJSON', () => {
		const marker = { toGeoJSON: () => ({ type: 'Feature', geometry: { type: 'Point', coordinates: [5.12, 52.09] } }) }
		expect(drawnGeometry(marker, 'geo:point')).toEqual({ type: 'Point', coordinates: [5.12, 52.09] })
		expect(drawnGeometry({}, 'geo:point')).toBeNull()
		expect(drawnGeometry({ toGeoJSON: () => ({ type: 'Point' }) }, 'geo:point')).toBeNull()
	})
})

describe('parseGeometryText', () => {
	it('reads JSON text as the value', () => {
		expect(parseGeometryText('{"type":"Point","coordinates":[5,52]}')).toEqual({ value: { type: 'Point', coordinates: [5, 52] }, error: '' })
	})

	it('clears the value for empty text', () => {
		expect(parseGeometryText('  ')).toEqual({ value: null, error: '' })
	})

	it('refuses text that is not JSON, or JSON that is not an object', () => {
		expect(parseGeometryText('{type:').error).not.toBe('')
		expect(parseGeometryText('{type:').value).toBeNull()
		expect(parseGeometryText('[5, 52]')).toEqual({ value: null, error: 'not an object' })
	})
})
