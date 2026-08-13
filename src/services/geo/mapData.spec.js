/**
 * Unit tests for mapData — pure geo map-data helpers.
 *
 * Covers REQ-GEO-003 (markers, bounds, PDOK base layers) and the RD/WGS84
 * display formatting referenced by REQ-GEO-015. Pure functions, no DOM,
 * no map library.
 */

import {
	BASE_LAYERS,
	defaultBaseLayer,
	coerceGeometry,
	representativePoint,
	buildMarkers,
	markerBounds,
	formatWgs84,
} from './mapData.js'

describe('mapData base layers', () => {
	it('exposes PDOK BRT as the default base layer', () => {
		const layer = defaultBaseLayer()
		expect(layer.id).toBe('brt')
		expect(layer.url).toContain('service.pdok.nl')
	})

	it('includes an OpenStreetMap fallback layer', () => {
		expect(BASE_LAYERS.some((l) => l.id === 'osm')).toBe(true)
	})
})

describe('coerceGeometry', () => {
	it('accepts a GeoJSON Point', () => {
		expect(
			coerceGeometry({ type: 'Point', coordinates: [5, 52] }),
		).not.toBeNull()
	})

	it('rejects non-geometry values', () => {
		expect(coerceGeometry(null)).toBeNull()
		expect(coerceGeometry('x')).toBeNull()
		expect(coerceGeometry({ type: 'Point' })).toBeNull()
		expect(coerceGeometry({ type: 'Sphere', coordinates: [] })).toBeNull()
	})
})

describe('representativePoint', () => {
	it('returns Point coordinates directly', () => {
		expect(
			representativePoint({ type: 'Point', coordinates: [5.1, 52.0] }),
		).toEqual([5.1, 52.0])
	})

	it('returns a polygon centroid', () => {
		const polygon = {
			type: 'Polygon',
			coordinates: [
				[
					[0, 0],
					[2, 0],
					[2, 2],
					[0, 2],
					[0, 0],
				],
			],
		}
		expect(representativePoint(polygon)).toEqual([0.8, 0.8])
	})

	it('returns the first vertex of a LineString', () => {
		const line = {
			type: 'LineString',
			coordinates: [
				[5.1, 52.0],
				[5.2, 52.1],
			],
		}
		expect(representativePoint(line)).toEqual([5.1, 52.0])
	})

	it('returns null for invalid geometry', () => {
		expect(
			representativePoint({ type: 'Point', coordinates: ['a', 'b'] }),
		).toBeNull()
	})
})

describe('buildMarkers', () => {
	const rows = [
		{ id: 1, title: 'A', locatie: { type: 'Point', coordinates: [5.1, 52.0] } },
		{ id: 2, title: 'B', locatie: { type: 'Point', coordinates: [4.9, 52.3] } },
		{ id: 3, title: 'C' },
	]

	it('builds markers for rows with a geometry and skips the rest', () => {
		const markers = buildMarkers(rows)
		expect(markers).toHaveLength(2)
		expect(markers[0]).toMatchObject({ id: 1, title: 'A', lon: 5.1, lat: 52.0 })
	})

	it('honours an explicit geo property name', () => {
		const custom = [
			{ id: 9, title: 'Z', plek: { type: 'Point', coordinates: [4, 51] } },
		]
		expect(buildMarkers(custom, 'plek')).toHaveLength(1)
		expect(buildMarkers(custom, 'missing')).toHaveLength(0)
	})

	it('returns an empty list for non-array input', () => {
		expect(buildMarkers(null)).toEqual([])
	})
})

describe('markerBounds', () => {
	it('computes a bounding box containing all markers', () => {
		const markers = buildMarkers([
			{ id: 1, locatie: { type: 'Point', coordinates: [5.1, 52.0] } },
			{ id: 2, locatie: { type: 'Point', coordinates: [4.9, 52.3] } },
		])
		expect(markerBounds(markers)).toEqual({
			west: 4.9,
			south: 52.0,
			east: 5.1,
			north: 52.3,
		})
	})

	it('returns null for an empty marker list', () => {
		expect(markerBounds([])).toBeNull()
	})
})

describe('formatWgs84', () => {
	it('formats as "lat, lon" with six decimals', () => {
		expect(formatWgs84(5.1214, 52.0907)).toBe('52.090700, 5.121400')
	})
})
