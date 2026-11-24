/**
 * The Tables page's personal lenses as list query params
 * (record-star-follow-and-unread-on-screen, task 3.1).
 *
 * @spec openspec/changes/record-star-follow-and-unread-on-screen/tasks.md
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { lensSearchParams, TABLE_LENSES } from './personalLensQuery.js'

const CLEARED = { page: 1, _watching: null, _recent: null, _unread: null }

describe('lensSearchParams', () => {
	it('offers Following, Recent and Unread in that order', () => {
		expect(TABLE_LENSES).toEqual(['watching', 'recent', 'unread'])
	})

	it('clears every lens on the All tab', () => {
		expect(lensSearchParams(0)).toEqual(CLEARED)
	})

	it('sets the chosen lens and clears the others', () => {
		expect(lensSearchParams(1)).toEqual({ ...CLEARED, _watching: true })
		expect(lensSearchParams(3)).toEqual({ ...CLEARED, _unread: true })
	})

	it('drops the column sort for Recent, which owns the order', () => {
		expect(lensSearchParams(2)).toEqual({ ...CLEARED, _recent: true, sortKey: null, sortOrder: null })
	})

	it('follows the lenses the page passes', () => {
		expect(lensSearchParams(1, ['unread'])).toEqual({ ...CLEARED, _unread: true })
	})

	it('clears every lens for an index the tabs do not have', () => {
		expect(lensSearchParams(7)).toEqual(CLEARED)
		expect(lensSearchParams(null)).toEqual(CLEARED)
		expect(lensSearchParams(1, ['favourite'])).toEqual(CLEARED)
	})
})
