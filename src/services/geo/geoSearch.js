/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * geoSearch — the records of a schema inside a drawn area.
 *
 * Posts the records list's own search parameters, flattened the way the
 * search store sends them, together with `geometry.within`, to
 * `POST /api/objects/{register}/{schema}/geo-search` (objects#geoSearch).
 * The server runs the ordinary listing with those filters and keeps the
 * records whose geometry lies inside the area.
 *
 * @spec openspec/changes/geometry-on-a-map/specs/geo-metadata-kaart/spec.md#requirement-req-geomap-002-records-with-a-geometry-can-be-seen-and-searched-on-a-map
 */

import { generateUrl } from '@nextcloud/router'
import { withRequestToken } from '../requestToken.js'

/**
 * The request body: the active search parameters without register and
 * schema, the `filters` object spread into field filters, and the area.
 *
 * @param {object} searchParams The records list's search parameters.
 * @param {object} area A GeoJSON Polygon or MultiPolygon.
 * @param {string|null} property The geometry property to test, or null.
 * @return {object} The body.
 * @spec openspec/changes/geometry-on-a-map/specs/geo-metadata-kaart/spec.md#requirement-req-geomap-002-records-with-a-geometry-can-be-seen-and-searched-on-a-map
 */
export function geoSearchBody(searchParams, area, property = null) {
	// eslint-disable-next-line no-unused-vars -- register and schema travel in the URL, not the body
	const { register, schema, filters, ...rest } = searchParams || {}
	const body = {}
	for (const [key, value] of Object.entries(rest)) {
		if (value !== undefined && value !== null && value !== '') {
			body[key] = value
		}
	}
	if (filters && typeof filters === 'object') {
		for (const [field, values] of Object.entries(filters)) {
			if (Array.isArray(values) ? values.length > 0 : Boolean(values)) {
				body[field] = values
			}
		}
	}
	body.geometry = { within: area }
	if (property) {
		body.property = property
	}
	return body
}

/**
 * Ask the server for the records inside an area.
 *
 * @param {object} searchParams The records list's search parameters (with register and schema).
 * @param {object} area A GeoJSON Polygon or MultiPolygon.
 * @param {string|null} property The geometry property to test, or null.
 * @return {Promise<Array<object>>} The records inside.
 * @throws {Error} When the server refuses the search.
 * @spec openspec/changes/geometry-on-a-map/specs/geo-metadata-kaart/spec.md#requirement-req-geomap-002-records-with-a-geometry-can-be-seen-and-searched-on-a-map
 */
export async function searchWithinArea(searchParams, area, property = null) {
	const { register, schema } = searchParams || {}
	const url = generateUrl('/apps/openregister/api/objects/{register}/{schema}/geo-search', { register, schema })
	const response = await fetch(url, withRequestToken({
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify(geoSearchBody(searchParams, area, property)),
	}))
	const data = await response.json().catch(() => ({}))
	if (!response.ok) {
		throw new Error(data.error || `Geo search failed (${response.status})`)
	}
	return Array.isArray(data.results) ? data.results : []
}
