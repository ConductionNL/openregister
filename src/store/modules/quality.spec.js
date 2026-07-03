/* eslint-disable no-console */
import { setActivePinia, createPinia } from 'pinia'

import axios from '@nextcloud/axios'
import { useQualityStore } from './quality.js'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: {
		get: jest.fn(),
		post: jest.fn(),
	},
}))

jest.mock('@nextcloud/router', () => ({
	__esModule: true,
	generateUrl: jest.fn((path) => `/index.php${path}`),
}))

describe('Quality Store', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		jest.clearAllMocks()
	})

	describe('selection', () => {
		it('has no selection by default', () => {
			const store = useQualityStore()
			expect(store.hasSelection).toBe(false)
		})

		it('setSelection commits the (register, schema) pair', () => {
			const store = useQualityStore()
			store.setSelection('16', '1207')
			expect(store.selectedRegister).toBe('16')
			expect(store.selectedSchema).toBe('1207')
			expect(store.hasSelection).toBe(true)
		})

		it('setSelection with falsy values clears the selection', () => {
			const store = useQualityStore()
			store.setSelection('16', '1207')
			store.setSelection(null, null)
			expect(store.hasSelection).toBe(false)
		})
	})

	describe('fetchQualityStats', () => {
		it('does not call the API when register or schema is missing', async () => {
			const store = useQualityStore()
			const result = await store.fetchQualityStats(null, '1207')
			expect(result).toBeNull()
			expect(axios.get).not.toHaveBeenCalled()
		})

		it('fetches and stores the stats envelope', async () => {
			const envelope = {
				average: 72.5,
				total: 10,
				buckets: { good: 5, fair: 3, poor: 2 },
				histogram: [0, 0, 1, 1, 1, 2, 2, 2, 1, 0],
			}
			axios.get.mockResolvedValueOnce({ data: envelope })

			const store = useQualityStore()
			const result = await store.fetchQualityStats('16', '1207')

			expect(axios.get).toHaveBeenCalledWith(
				expect.stringContaining('/objects/quality/16/1207/stats'),
			)
			expect(result).toEqual(envelope)
			expect(store.qualityStats).toEqual(envelope)
		})

		it('records an error on failure', async () => {
			axios.get.mockRejectedValueOnce({ message: 'boom' })
			const store = useQualityStore()

			await expect(store.fetchQualityStats('16', '1207')).rejects.toBeTruthy()
			expect(store.error).toBe('boom')
		})
	})

	describe('fetchLowQualityObjects', () => {
		it('paginates via limit/offset from the response envelope', async () => {
			axios.get.mockResolvedValueOnce({
				data: {
					items: [{ id: 'a', qualityScore: 10, qualityStatus: 'poor' }],
					total: 1,
					limit: 20,
					offset: 0,
				},
			})

			const store = useQualityStore()
			await store.fetchLowQualityObjects('16', '1207')

			expect(store.lowQualityObjects).toHaveLength(1)
			expect(store.lowQualityTotal).toBe(1)
			expect(store.lowQualityLimit).toBe(20)
			expect(store.lowQualityOffset).toBe(0)
		})
	})

	describe('fetchDuplicates', () => {
		it('stores candidate pairs read-only', async () => {
			axios.get.mockResolvedValueOnce({
				data: {
					items: [{ objectA: 'a', objectB: 'b', score: 0.9, matchedOn: ['email'] }],
					total: 1,
					limit: 20,
					offset: 0,
				},
			})

			const store = useQualityStore()
			await store.fetchDuplicates('16', '1207')

			expect(store.duplicates).toEqual([
				{ objectA: 'a', objectB: 'b', score: 0.9, matchedOn: ['email'] },
			])
			expect(store.duplicatesTotal).toBe(1)
		})
	})

	describe('fetchWebhookHealth', () => {
		it('short-circuits per-webhook stats when no webhooks are configured', async () => {
			axios.get.mockResolvedValueOnce({ data: { results: [], total: 0 } })

			const store = useQualityStore()
			const result = await store.fetchWebhookHealth()

			expect(result).toEqual({ webhooks: [], stats: {}, failures: [] })
			expect(axios.get).toHaveBeenCalledTimes(1)
		})

		it('fetches per-webhook stats and recent failures', async () => {
			axios.get
				.mockResolvedValueOnce({ data: { results: [{ id: 1, name: 'wh-1' }], total: 1 } })
				.mockResolvedValueOnce({ data: { total: 5, successful: 4, failed: 1, pendingRetries: 0 } })
				.mockResolvedValueOnce({ data: { results: [{ id: 99, webhook: 1, success: false }], total: 1 } })

			const store = useQualityStore()
			await store.fetchWebhookHealth()

			expect(store.webhooks).toHaveLength(1)
			expect(store.webhookStats[1]).toEqual({ total: 5, successful: 4, failed: 1, pendingRetries: 0 })
			expect(store.webhookFailures).toHaveLength(1)
		})
	})
})
