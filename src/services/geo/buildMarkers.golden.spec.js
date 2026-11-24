/**
 * Golden record of buildMarkers: the marker list for rows with every kind of
 * id and title source, rows without a point, and input that is not a list.
 */
import { buildMarkers } from './mapData.js'

const POINT = { type: 'Point', coordinates: [5, 52] }

const ROWS = [
	{ id: 1, title: 'T', name: 'N', geo: POINT },
	{ id: 0, title: '', geo: POINT },
	{ name: 'only name', '@self': { id: 'self-1' }, geo: POINT },
	{ '@self': { id: 'self-2' }, geo: POINT },
	{ '@self': null, geo: POINT },
	{ '@self': {}, geo: POINT },
	{ id: null, title: null, name: null, '@self': { id: 7 }, geo: POINT },
	{ id: 9, title: 12, geo: POINT },
	{ id: false, title: false, geo: POINT },
	{ geo: POINT },
	{ id: 3, geo: { type: 'Unknown' } },
	{ id: 4, other: POINT, geo: null },
	null,
	'row',
	5,
]

describe('buildMarkers golden', () => {
	it.each([
		['auto-detect', [ROWS]],
		['explicit property', [ROWS, 'geo']],
		['explicit missing property', [ROWS, 'none']],
		['undefined property', [ROWS, undefined]],
		['not a list', [{ length: 1, 0: ROWS[0] }]],
		['null', [null]],
		['empty', [[]]],
	])('%s', (_label, args) => {
		const markers = buildMarkers(...args)
		expect(
			markers.map((m) => ({
				...m,
				propertiesIsRow: ROWS.includes(m.properties),
				properties: undefined,
			})),
		).toMatchSnapshot()
	})
})
