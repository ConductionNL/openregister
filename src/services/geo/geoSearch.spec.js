/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * geoSearch: the records list's search parameters and a drawn area go to
 * objects#geoSearch as one body, `geometry.within` beside the filters.
 *
 * @spec openspec/changes/geometry-on-a-map/specs/geo-metadata-kaart/spec.md#requirement-req-geomap-002-records-with-a-geometry-can-be-seen-and-searched-on-a-map
 */

import { geoSearchBody, searchWithinArea } from './geoSearch.js'

jest.mock('@nextcloud/router', () => ({
	__esModule: true,
	generateUrl: (path, vars = {}) => path.replace(/\{(\w+)\}/g, (_m, key) => vars[key]),
}))
jest.mock('../requestToken.js', () => ({
	__esModule: true,
	withRequestToken: (init) => ({ ...init, headers: { ...init.headers, requesttoken: 'tok' } }),
}))

const AREA = { type: 'Polygon', coordinates: [[[5.1, 52.0], [5.2, 52.0], [5.2, 52.1], [5.1, 52.0]]] }

describe('geoSearchBody', () => {
	it('keeps the active filters, spreads the filters object, and adds the area and property', () => {
		const params = { register: 'r', schema: 's', _search: 'park', _limit: 20, sortKey: null, filters: { status: ['open'], empty: [] } }

		expect(geoSearchBody(params, AREA, 'location')).toEqual({
			_search: 'park',
			_limit: 20,
			status: ['open'],
			geometry: { within: AREA },
			property: 'location',
		})
	})

	it('sends only the area when nothing else is active', () => {
		expect(geoSearchBody({ register: 'r', schema: 's' }, AREA)).toEqual({ geometry: { within: AREA } })
	})
})

describe('searchWithinArea', () => {
	afterEach(() => {
		delete global.fetch
	})

	it('posts to the schema\'s geo-search and returns its results', async () => {
		global.fetch = jest.fn().mockResolvedValue({ ok: true, json: () => Promise.resolve({ results: [{ id: 'a' }] }) })

		const results = await searchWithinArea({ register: 'reg', schema: 'sch', _search: 'x' }, AREA, 'location')

		expect(results).toEqual([{ id: 'a' }])
		const [url, init] = global.fetch.mock.calls[0]
		expect(url).toBe('/apps/openregister/api/objects/reg/sch/geo-search')
		expect(init.method).toBe('POST')
		expect(init.headers).toEqual({ 'Content-Type': 'application/json', requesttoken: 'tok' })
		expect(JSON.parse(init.body)).toEqual({ _search: 'x', geometry: { within: AREA }, property: 'location' })
	})

	it('throws the server\'s refusal', async () => {
		global.fetch = jest.fn().mockResolvedValue({ ok: false, status: 422, json: () => Promise.resolve({ error: 'Invalid polygon' }) })

		await expect(searchWithinArea({ register: 'reg', schema: 'sch' }, AREA)).rejects.toThrow('Invalid polygon')
	})

	it('reads an answer without results as none', async () => {
		global.fetch = jest.fn().mockResolvedValue({ ok: true, json: () => Promise.resolve({}) })

		expect(await searchWithinArea({ register: 'reg', schema: 'sch' }, AREA)).toEqual([])
	})
})
