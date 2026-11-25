/**
 * The Tables page's column sort as `_order` (case-insensitive-ordering 1.4).
 *
 * @spec openspec/changes/case-insensitive-ordering/specs/case-insensitive-ordering/spec.md
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { headerSortOf, orderParams } from './searchSort.js'

describe('orderParams', () => {
	it('sorts by one column through _order, never through sortKey', () => {
		expect(orderParams('title', 'desc')).toEqual({ _order: { title: 'desc' } })
		expect(orderParams('title', 'asc')).toEqual({ _order: { title: 'asc' } })
	})

	it('sorts ascending for an unknown direction', () => {
		expect(orderParams('title', 'sideways')).toEqual({ _order: { title: 'asc' } })
	})

	it('clears the sort without a column', () => {
		expect(orderParams(null, 'asc')).toEqual({ _order: null })
	})
})

describe('headerSortOf', () => {
	it('reads the column and direction back from _order', () => {
		expect(headerSortOf({ _order: { title: 'desc' } })).toEqual({ sortKey: 'title', sortOrder: 'desc' })
		expect(headerSortOf({ _order: '{"rank":"ASC"}' })).toEqual({ sortKey: 'rank', sortOrder: 'asc' })
	})

	it('has no sorted column without an _order', () => {
		for (const params of [{}, null, { _order: null }, { _order: 'not json' }, { _order: [] }, { _order: {} }]) {
			expect(headerSortOf(params)).toEqual({ sortKey: null, sortOrder: 'asc' })
		}
	})
})
