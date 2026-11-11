import { applyFilterChange, filterRows } from './listFilter.js'

const schemas = [
	{
		id: 1,
		title: 'Case Type',
		created: '2026-01-03T10:00:00Z',
		properties: { a: {} },
	},
	{ id: 2, title: 'Client', created: '2026-03-15T10:00:00Z', properties: {} },
	{
		id: 3,
		title: 'Lead Product',
		created: '2026-06-01T10:00:00Z',
		properties: {},
	},
]

describe('header filters on OpenRegister list pages', () => {
	it('narrows on contains, case-insensitive, and clears again', () => {
		let active = applyFilterChange({}, { key: 'title[like]', values: ['case'] })
		expect(active).toEqual({ 'title[like]': ['case'] })
		expect(filterRows(schemas, active).map((s) => s.title)).toEqual([
			'Case Type',
		])

		active = applyFilterChange(active, { key: 'title[like]', values: [] })
		expect(active).toEqual({})
		expect(filterRows(schemas, active)).toHaveLength(3)
	})

	it('filters a date range, a date-only lower bound covering its day', () => {
		let active = applyFilterChange(
			{},
			{ key: 'created[gte]', values: ['2026-03-15'] },
		)
		active = applyFilterChange(active, {
			key: 'created[lte]',
			values: ['2026-05-31T23:59:59'],
		})
		expect(filterRows(schemas, active).map((s) => s.id)).toEqual([2])
	})

	it('combines filters with AND', () => {
		const active = { 'title[like]': ['c'], 'created[gte]': ['2026-02-01'] }
		expect(filterRows(schemas, active).map((s) => s.id)).toEqual([2, 3])
	})

	it('matches an exact value against any chosen value', () => {
		expect(filterRows(schemas, { id: ['1', '3'] }).map((s) => s.id)).toEqual([
			1, 3,
		])
	})

	it('reads a field through a custom reader', () => {
		const count = (row, field) =>
			field === 'properties' ? Object.keys(row.properties).length : row[field]
		expect(
			filterRows(schemas, { 'properties[gte]': ['1'] }, count).map(
				(s) => s.id,
			),
		).toEqual([1])
	})

	it('drops a row without a value from a range', () => {
		expect(filterRows([{ id: 9 }], { 'created[gte]': ['2026-01-01'] })).toEqual(
			[],
		)
	})

	it('ignores a payload without a key and keeps the input untouched', () => {
		const active = { 'title[like]': ['x'] }
		expect(applyFilterChange(active, null)).toEqual(active)
		expect(
			applyFilterChange(active, { key: 'slug[like]', values: ['y'] }),
		).not.toBe(active)
		expect(active).toEqual({ 'title[like]': ['x'] })
	})
})
