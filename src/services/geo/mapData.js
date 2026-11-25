/**
 * mapData — pure helpers for the OpenRegister geo map view.
 *
 * Keeps map *data shaping* (objects -> markers, bounds, PDOK layer
 * configuration, RD/WGS84 display formatting) separate from map
 * rendering* (Leaflet), so the data logic is unit-testable without a
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
 *
 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-003
 */
export function buildMarkers(rows, geoProperty = null) {
	if (!Array.isArray(rows)) {
		return []
	}
	const markers = []
	for (const row of rows) {
		const marker = markerFor(row, geoProperty)
		if (marker !== null) {
			markers.push(marker)
		}
	}
	return markers
}

/**
 * The marker for one object row, or null when the row has no point.
 *
 * @param {unknown}     row         The object row.
 * @param {string|null} geoProperty Geo property name, or null to auto-detect.
 * @return {object|null} The marker.
 *
 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-003
 */
function markerFor(row, geoProperty) {
	if (row === null || typeof row !== 'object') {
		return null
	}
	const point = representativePoint(locateGeometry(row, geoProperty))
	if (point === null) {
		return null
	}
	return {
		id: rowId(row),
		title: rowTitle(row),
		lon: point[0],
		lat: point[1],
		properties: row,
	}
}

/**
 * The id of an object row: its own id, else its `@self` id, else null.
 *
 * @param {object} row The object row.
 * @return {string|number|null} The id.
 *
 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-003
 */
function rowId(row) {
	return row.id ?? (row['@self'] && row['@self'].id) ?? null
}

/**
 * The marker title of an object row: title, name or id, as text.
 *
 * @param {object} row The object row.
 * @return {string} The title.
 *
 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-003
 */
function rowTitle(row) {
	return String(row.title ?? row.name ?? row.id ?? '')
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
	const vertices = Array.isArray(ring) ? ring.filter(isLonLat) : []
	if (vertices.length === 0) {
		return null
	}
	let sumLon = 0
	let sumLat = 0
	for (const vertex of vertices) {
		sumLon += vertex[0]
		sumLat += vertex[1]
	}
	return [sumLon / vertices.length, sumLat / vertices.length]
}

/**
 * Whether a ring vertex holds a numeric longitude and latitude.
 *
 * @param {Array|object|string|number|null} vertex The vertex.
 * @return {boolean} True for `[lon, lat, ...]` with numbers.
 */
function isLonLat(vertex) {
	return (
		Array.isArray(vertex)
		&& vertex.length >= 2
		&& typeof vertex[0] === 'number'
		&& typeof vertex[1] === 'number'
	)
}

/**
 * The geo types a schema property can declare, by `format` or by `type`. The
 * same list as GeoJsonGeometryValidator::GEO_TYPES on the server.
 *
 * @spec openspec/changes/geometry-on-a-map/specs/geo-metadata-kaart/spec.md#requirement-req-geomap-002-records-with-a-geometry-can-be-seen-and-searched-on-a-map
 */
export const GEO_TYPES = Object.freeze([
	'geo:point',
	'geo:polygon',
	'geo:multipolygon',
	'geo:linestring',
	'geo:geometry',
	'geo:bag',
])

/**
 * Whether a schema property holds a geometry: its `format` or its `type`
 * names a geo type, compared without case. The same test as
 * GeoJsonGeometryValidator::declaredGeoType() on the server.
 *
 * @param {object|null} property The property definition.
 * @return {boolean} True for a geometry property.
 * @spec openspec/changes/geometry-on-a-map/specs/geo-metadata-kaart/spec.md#requirement-req-geomap-002-records-with-a-geometry-can-be-seen-and-searched-on-a-map
 */
export function isGeoProperty(property) {
	if (property === null || typeof property !== 'object' || Array.isArray(property)) {
		return false
	}
	return ['format', 'type'].some((key) => (
		typeof property[key] === 'string' && GEO_TYPES.includes(property[key].toLowerCase())
	))
}

/**
 * The first geometry property of a schema, or null when it has none.
 *
 * @param {object|null} schema The schema (with `properties`).
 * @return {string|null} The property name.
 * @spec openspec/changes/geometry-on-a-map/specs/geo-metadata-kaart/spec.md#requirement-req-geomap-002-records-with-a-geometry-can-be-seen-and-searched-on-a-map
 */
export function geoPropertyOf(schema) {
	const properties = schema && typeof schema === 'object' ? schema.properties : null
	if (!properties || typeof properties !== 'object') {
		return null
	}
	return Object.keys(properties).find((name) => isGeoProperty(properties[name])) ?? null
}

/**
 * The tile URL template Leaflet reads a base layer from. A PDOK WMTS layer is
 * served in Web Mercator (EPSG:3857) under its layer name; a TMS layer
 * carries its own template.
 *
 * @param {object} layer A base layer descriptor from BASE_LAYERS.
 * @return {string} The `{z}/{x}/{y}` template.
 * @spec openspec/changes/geometry-on-a-map/specs/geo-metadata-kaart/spec.md#requirement-req-geomap-002-records-with-a-geometry-can-be-seen-and-searched-on-a-map
 */
export function tileUrl(layer) {
	if (!layer || typeof layer.url !== 'string') {
		return ''
	}
	if (layer.type === 'wmts') {
		const extension = layer.id === 'luchtfoto' ? 'jpeg' : 'png'
		return `${layer.url}/${layer.layer}/EPSG:3857/{z}/{x}/{y}.${extension}`
	}
	return layer.url
}

/**
 * The polygon a Leaflet draw layer describes, as a GeoJSON geometry.
 *
 * @param {object} layer A drawn Leaflet layer (with `toGeoJSON()`).
 * @return {object|null} The Polygon or MultiPolygon, or null for another shape.
 * @spec openspec/changes/geometry-on-a-map/specs/geo-metadata-kaart/spec.md#requirement-req-geomap-002-records-with-a-geometry-can-be-seen-and-searched-on-a-map
 */
export function drawnArea(layer) {
	const feature = layer && typeof layer.toGeoJSON === 'function' ? layer.toGeoJSON() : null
	const geometry = feature && feature.type === 'Feature' ? feature.geometry : feature
	if (!geometry || (geometry.type !== 'Polygon' && geometry.type !== 'MultiPolygon')) {
		return null
	}
	return { type: geometry.type, coordinates: geometry.coordinates }
}
