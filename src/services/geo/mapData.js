/**
 * mapData — pure helpers for the OpenRegister geo map view.
 *
 * Keeps map *data shaping* (objects -> markers, bounds, PDOK layer
 * configuration, RD/WGS84 display formatting) separate from map
 * *rendering* (Leaflet), so the data logic is unit-testable without a
 * DOM or any map library. The thin MapView.vue component consumes these
 * helpers and only owns the Leaflet wiring.
 *
 * Implements the data side of REQ-GEO-003 (PDOK base layers, markers,
 * auto-fit bounds), REQ-GEO-007 (layer catalogue), and REQ-GEO-014
 * (NL Design System marker token).
 *
 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-003
 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-007
 */

/**
 * PDOK + fallback base layer catalogue (REQ-GEO-003 / REQ-GEO-007).
 *
 * Each entry is a descriptor the map component turns into a tile layer.
 * PDOK BRT Achtergrondkaart is the government-standard default.
 */
export const BASE_LAYERS = [
	{
		id: 'brt',
		label: 'BRT Achtergrondkaart',
		type: 'wmts',
		url: 'https://service.pdok.nl/brt/achtergrondkaart/wmts/v2_0',
		layer: 'standaard',
		isDefault: true,
	},
	{
		id: 'brt-grijs',
		label: 'BRT Achtergrondkaart Grijs',
		type: 'wmts',
		url: 'https://service.pdok.nl/brt/achtergrondkaart/wmts/v2_0',
		layer: 'grijs',
		isDefault: false,
	},
	{
		id: 'luchtfoto',
		label: 'Luchtfoto',
		type: 'wmts',
		url: 'https://service.pdok.nl/hwh/luchtfotorgb/wmts/v1_0',
		layer: 'Actueel_orthoHR',
		isDefault: false,
	},
	{
		id: 'osm',
		label: 'OpenStreetMap',
		type: 'tms',
		url: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
		layer: null,
		isDefault: false,
	},
]

/**
 * GeoJSON geometry types recognised when scanning an object.
 */
const GEOJSON_TYPES = ['Point', 'Polygon', 'MultiPolygon', 'LineString']

/**
 * Return the default base layer descriptor (PDOK BRT).
 *
 * @return {object} The default layer descriptor.
 */
export function defaultBaseLayer() {
	return BASE_LAYERS.find((layer) => layer.isDefault) || BASE_LAYERS[0]
}

/**
 * Coerce an arbitrary value to a GeoJSON geometry, or null.
 *
 * @param {*} value The candidate value.
 * @return {object|null} The geometry when it matches, else null.
 */
export function coerceGeometry(value) {
	if (value === null || typeof value !== 'object') {
		return null
	}
	if (!GEOJSON_TYPES.includes(value.type)) {
		return null
	}
	if (!Array.isArray(value.coordinates)) {
		return null
	}
	return value
}

/**
 * Extract a representative `[lon, lat]` from a GeoJSON geometry.
 *
 * Point -> its coordinates; Polygon/MultiPolygon -> outer-ring centroid;
 * LineString -> first vertex. Returns null when no point can be derived.
 *
 * @param {object} geometry A GeoJSON geometry.
 * @return {Array<number>|null} `[lon, lat]` or null.
 */
export function representativePoint(geometry) {
	const geo = coerceGeometry(geometry)
	if (geo === null) {
		return null
	}
	if (geo.type === 'Point') {
		return numericPair(geo.coordinates)
	}
	if (geo.type === 'LineString') {
		return numericPair(geo.coordinates[0])
	}
	if (geo.type === 'Polygon') {
		return ringCentroid(geo.coordinates[0])
	}
	if (geo.type === 'MultiPolygon') {
		return ringCentroid((geo.coordinates[0] || [])[0])
	}
	return null
}

/**
 * Build a flat marker list from object rows (REQ-GEO-003).
 *
 * Each marker is `{ id, title, lon, lat, properties }`. Rows without a
 * derivable point are skipped (nothing to plot).
 *
 * @param {Array<object>} rows        Object rows.
 * @param {string|null}   geoProperty Geo property name, or null to auto-detect.
 * @return {Array<object>} The marker list.
 */
export function buildMarkers(rows, geoProperty = null) {
	if (!Array.isArray(rows)) {
		return []
	}
	const markers = []
	for (const row of rows) {
		if (row === null || typeof row !== 'object') {
			continue
		}
		const geometry = locateGeometry(row, geoProperty)
		const point = representativePoint(geometry)
		if (point === null) {
			continue
		}
		markers.push({
			id: row.id ?? (row['@self'] && row['@self'].id) ?? null,
			title: String(row.title ?? row.name ?? row.id ?? ''),
			lon: point[0],
			lat: point[1],
			properties: row,
		})
	}
	return markers
}

/**
 * Compute a bounding box that contains all markers (REQ-GEO-003 auto-fit).
 *
 * @param {Array<object>} markers Markers from buildMarkers().
 * @return {object|null} `{ west, south, east, north }` or null when empty.
 */
export function markerBounds(markers) {
	if (!Array.isArray(markers) || markers.length === 0) {
		return null
	}
	let west = Infinity
	let south = Infinity
	let east = -Infinity
	let north = -Infinity
	for (const marker of markers) {
		west = Math.min(west, marker.lon)
		east = Math.max(east, marker.lon)
		south = Math.min(south, marker.lat)
		north = Math.max(north, marker.lat)
	}
	return { west, south, east, north }
}

/**
 * Format coordinates for display, optionally in RD New (REQ-GEO-015 UI).
 *
 * The map itself always uses WGS84; this only affects the textual
 * display string shown in popups / detail views.
 *
 * @param {number} lon WGS84 longitude.
 * @param {number} lat WGS84 latitude.
 * @return {string} A display string `"52.0907, 5.1214"` (lat, lon).
 */
export function formatWgs84(lon, lat) {
	return `${Number(lat).toFixed(6)}, ${Number(lon).toFixed(6)}`
}

/**
 * Locate a geometry value inside a row.
 *
 * @param {object}      row         The object row.
 * @param {string|null} geoProperty Explicit property, or null to auto-detect.
 * @return {object|null} The geometry, or null.
 */
function locateGeometry(row, geoProperty) {
	if (geoProperty !== null && geoProperty !== undefined) {
		return coerceGeometry(row[geoProperty])
	}
	for (const key of Object.keys(row)) {
		const geometry = coerceGeometry(row[key])
		if (geometry !== null) {
			return geometry
		}
	}
	return null
}

/**
 * Validate + cast a `[lon, lat]` pair to numbers, or null.
 *
 * @param {*} pair The candidate position array.
 * @return {Array<number>|null}
 */
function numericPair(pair) {
	if (!Array.isArray(pair) || pair.length < 2) {
		return null
	}
	if (typeof pair[0] !== 'number' || typeof pair[1] !== 'number') {
		return null
	}
	return [pair[0], pair[1]]
}

/**
 * Arithmetic centroid of a polygon ring.
 *
 * @param {Array} ring The ring vertices.
 * @return {Array<number>|null} `[lon, lat]` centroid, or null.
 */
function ringCentroid(ring) {
	if (!Array.isArray(ring) || ring.length === 0) {
		return null
	}
	let sumLon = 0
	let sumLat = 0
	let n = 0
	for (const vertex of ring) {
		if (!Array.isArray(vertex) || vertex.length < 2) {
			continue
		}
		if (typeof vertex[0] !== 'number' || typeof vertex[1] !== 'number') {
			continue
		}
		sumLon += vertex[0]
		sumLat += vertex[1]
		n += 1
	}
	if (n === 0) {
		return null
	}
	return [sumLon / n, sumLat / n]
}
