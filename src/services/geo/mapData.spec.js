/**
 * Unit tests for mapData — pure geo map-data helpers.
 *
 * Covers REQ-GEO-003 (markers, bounds, PDOK base layers) and the RD/WGS84
 * display formatting referenced by REQ-GEO-015. Pure functions, no DOM,
 * no map library.
 */

import {
	BASE_LAYERS,
	buildMarkers,
	coerceGeometry,
	defaultBaseLayer,
	drawnArea,
	formatWgs84,
	geoPropertyOf,
	isGeoProperty,
	markerBounds,
	representativePoint,
	tileUrl,
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

describe('isGeoProperty (the server\'s declaredGeoType test)', () => {
	it('accepts every geo format and geo type, without case', () => {
		for (const format of ['geo:point', 'geo:linestring', 'geo:polygon', 'geo:multipolygon', 'geo:geometry', 'geo:bag']) {
			expect(isGeoProperty({ type: 'object', format })).toBe(true)
		}
		expect(isGeoProperty({ type: 'GEO:Polygon' })).toBe(true)
	})

	it('refuses other formats, other types and non-objects', () => {
		expect(isGeoProperty({ type: 'string', format: 'date' })).toBe(false)
		expect(isGeoProperty({ type: 'object' })).toBe(false)
		expect(isGeoProperty({ type: 'string', format: 'geo:unknown' })).toBe(false)
		expect(isGeoProperty(null)).toBe(false)
		expect(isGeoProperty(['geo:point'])).toBe(false)
		expect(isGeoProperty('geo:point')).toBe(false)
	})
})

describe('geoPropertyOf', () => {
	it('names the first geometry property of a schema', () => {
		const schema = { properties: { title: { type: 'string' }, where: { type: 'object', format: 'geo:point' }, area: { type: 'geo:polygon' } } }
		expect(geoPropertyOf(schema)).toBe('where')
	})

	it('is null for a schema without one, or without properties', () => {
		expect(geoPropertyOf({ properties: { title: { type: 'string' } } })).toBeNull()
		expect(geoPropertyOf({})).toBeNull()
		expect(geoPropertyOf(null)).toBeNull()
	})
})

describe('tileUrl', () => {
	it('serves a PDOK WMTS layer in Web Mercator under its layer name', () => {
		expect(tileUrl(defaultBaseLayer())).toBe('https://service.pdok.nl/brt/achtergrondkaart/wmts/v2_0/standaard/EPSG:3857/{z}/{x}/{y}.png')
		expect(tileUrl(BASE_LAYERS.find((layer) => layer.id === 'luchtfoto'))).toBe('https://service.pdok.nl/hwh/luchtfotorgb/wmts/v1_0/Actueel_orthoHR/EPSG:3857/{z}/{x}/{y}.jpeg')
	})

	it('keeps a TMS template as it is, and is empty for no layer', () => {
		expect(tileUrl(BASE_LAYERS.find((layer) => layer.id === 'osm'))).toBe('https://tile.openstreetmap.org/{z}/{x}/{y}.png')
		expect(tileUrl(null)).toBe('')
	})
})

describe('drawnArea', () => {
	const ring = [[5.1, 52.0], [5.2, 52.0], [5.2, 52.1], [5.1, 52.0]]

	it('reads the polygon of a drawn layer', () => {
		const layer = { toGeoJSON: () => ({ type: 'Feature', properties: {}, geometry: { type: 'Polygon', coordinates: [ring] } }) }
		expect(drawnArea(layer)).toEqual({ type: 'Polygon', coordinates: [ring] })
	})

	it('refuses a line, a point and a layer without GeoJSON', () => {
		expect(drawnArea({ toGeoJSON: () => ({ type: 'Feature', geometry: { type: 'LineString', coordinates: ring } }) })).toBeNull()
		expect(drawnArea({ toGeoJSON: () => ({ type: 'Point', coordinates: [5, 52] }) })).toBeNull()
		expect(drawnArea({})).toBeNull()
	})
})
