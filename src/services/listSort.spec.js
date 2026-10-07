import { AUDIT_SORT_FIELDS, headerSortOf, sortSchemas } from './listSort.js'

const schemas = [
	{
		id: 1,
		title: 'Zaak',
		created: '2026-01-03T10:00:00Z',
		updated: '2026-10-01T10:00:00Z',
	},
	{
		id: 3,
		title: 'adres',
		created: '2026-01-01T10:00:00Z',
		updated: '2026-10-03T10:00:00Z',
	},
	{
		id: 2,
		title: 'Besluit',
		created: '2026-01-02T10:00:00Z',
		updated: '2026-10-02T10:00:00Z',
	},
]

describe('schemas list header sort (live audit G2)', () => {
	it('keeps newest first without a header', () => {
		expect(sortSchemas(schemas, null).map((s) => s.id)).toEqual([3, 2, 1])
	})

	it('sorts by title, case-insensitive, both ways', () => {
		expect(sortSchemas(schemas, 'title', 'asc').map((s) => s.title)).toEqual([
			'adres',
			'Besluit',
			'Zaak',
		])
		expect(sortSchemas(schemas, 'title', 'desc').map((s) => s.title)).toEqual([
			'Zaak',
			'Besluit',
			'adres',
		])
	})

	it('sorts dates as dates', () => {
		expect(sortSchemas(schemas, 'created', 'asc').map((s) => s.id)).toEqual([
			3, 2, 1,
		])
		expect(sortSchemas(schemas, 'updated', 'desc').map((s) => s.id)).toEqual([
			3, 2, 1,
		])
		expect(sortSchemas(schemas, 'updated', 'asc').map((s) => s.id)).toEqual([
			1, 2, 3,
		])
	})

	it('does not change the list it was given', () => {
		const copy = [...schemas]
		sortSchemas(schemas, 'title', 'asc')
		expect(schemas).toEqual(copy)
	})
})

describe('audit trail header sort (live audit G2)', () => {
	it('asks the server for its column names', () => {
		expect(AUDIT_SORT_FIELDS.userName).toBe('user_name')
		expect(AUDIT_SORT_FIELDS.size).toBeUndefined()
	})

	it('shows the server default as newest first', () => {
		expect(headerSortOf({})).toEqual({ sortKey: 'created', sortOrder: 'desc' })
	})

	it('shows a chosen sort on its header', () => {
		expect(headerSortOf({ user_name: 'ASC' })).toEqual({
			sortKey: 'userName',
			sortOrder: 'asc',
		})
	})
})
