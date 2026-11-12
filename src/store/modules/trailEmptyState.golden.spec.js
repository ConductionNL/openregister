/**
 * Golden record of the empty pagination and statistics of the audit trail,
 * search trail and deleted stores: in a new store, after the store is
 * cleared, and from getStatistics when the statistics fail to load. Each
 * empty value is a fresh object.
 *
 * @spec exclude Golden record of client-side empty state; no backend contract.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { createPinia, setActivePinia } from 'pinia'
import { useAuditTrailStore } from './auditTrail.js'
import { useDeletedStore } from './deleted.js'
import { useSearchTrailStore } from './searchTrail.js'

const STORES = [
	[
		'auditTrail',
		useAuditTrailStore,
		'auditTrailPagination',
		'clearAuditTrailStore',
	],
	[
		'searchTrail',
		useSearchTrailStore,
		'searchTrailPagination',
		'clearSearchTrailStore',
	],
	['deleted', useDeletedStore, 'deletedPagination', 'clearDeletedStore'],
]

describe('trail stores empty state golden', () => {
	beforeEach(() => {
		global.OC = { requestToken: 'token' }
		global.fetch = jest.fn(async () => {
			throw new TypeError('Failed to fetch')
		})
		jest.spyOn(console, 'error').mockImplementation(() => {})
	})

	afterEach(() => {
		console.error.mockRestore()
	})

	for (const [name, useStore, paginationKey, clear] of STORES) {
		it(`${name} starts, clears and falls back empty`, async () => {
			setActivePinia(createPinia())
			const store = useStore()
			const initial = {
				pagination: JSON.parse(JSON.stringify(store[paginationKey])),
				statistics: JSON.parse(JSON.stringify(store.statistics)),
			}
			const other = useStore(createPinia())
			const sharedInitially = other.statistics === store.statistics

			store[paginationKey].page = 9
			store.statistics.total = 9
			const before = {
				pagination: store[paginationKey],
				statistics: store.statistics,
			}
			store[clear]()
			const cleared = {
				pagination: JSON.parse(JSON.stringify(store[paginationKey])),
				statistics: JSON.parse(JSON.stringify(store.statistics)),
				freshPagination: store[paginationKey] !== before.pagination,
				freshStatistics: store.statistics !== before.statistics,
			}

			let fallback = null
			if (typeof store.getStatistics === 'function') {
				const first = await store.getStatistics()
				const firstCopy = JSON.parse(JSON.stringify(first))
				first.total = 7
				const second = await store.getStatistics()
				fallback = {
					value: firstCopy,
					fresh: first !== second && second !== store.statistics,
					secondTotal: second.total,
				}
			}

			expect({ initial, sharedInitially, cleared, fallback }).toMatchSnapshot()
		})
	}
})
