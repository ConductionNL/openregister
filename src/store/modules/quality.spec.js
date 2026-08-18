import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { useQualityStore } from './quality.js'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: {
		get: jest.fn(),
		post: jest.fn(),
		patch: jest.fn(),
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
					items: [
						{
							objectA: 'a',
							objectB: 'b',
							score: 0.9,
							matchedOn: ['email'],
						},
					],
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
				.mockResolvedValueOnce({
					data: { results: [{ id: 1, name: 'wh-1' }], total: 1 },
				})
				.mockResolvedValueOnce({
					data: { total: 5, successful: 4, failed: 1, pendingRetries: 0 },
				})
				.mockResolvedValueOnce({
					data: {
						results: [{ id: 99, webhook: 1, success: false }],
						total: 1,
					},
				})

			const store = useQualityStore()
			await store.fetchWebhookHealth()

			expect(store.webhooks).toHaveLength(1)
			expect(store.webhookStats[1]).toEqual({
				total: 5,
				successful: 4,
				failed: 1,
				pendingRetries: 0,
			})
			expect(store.webhookFailures).toHaveLength(1)
		})
	})

	describe('previewMerge', () => {
		it('posts { from, into } to the preview endpoint and returns the payload', async () => {
			const payload = {
				from: 'obj-a',
				into: 'obj-b',
				postMergeGoldenRecord: { name: 'Acme' },
				attributeProvenance: { name: { sourceSystem: 'obj-b' } },
				reversalDeadline: '2026-07-10T00:00:00Z',
			}
			axios.post.mockResolvedValueOnce({ data: payload })

			const store = useQualityStore()
			const result = await store.previewMerge('obj-a', 'obj-b')

			expect(axios.post).toHaveBeenCalledWith(
				expect.stringContaining('/objects/merge/preview'),
				{ from: 'obj-a', into: 'obj-b' },
			)
			expect(result).toEqual(payload)
		})

		it('records an error on failure', async () => {
			axios.post.mockRejectedValueOnce({
				response: { data: { error: 'Forbidden' } },
			})
			const store = useQualityStore()

			await expect(store.previewMerge('obj-a', 'obj-b')).rejects.toBeTruthy()
			expect(store.error).toBe('Forbidden')
		})
	})

	describe('executeMerge', () => {
		it('posts { from, into, reason } to the execute endpoint and returns the persisted operation', async () => {
			const operation = {
				id: 'op-1',
				from: 'obj-a',
				into: 'obj-b',
				reason: 'duplicate-confirmed',
			}
			axios.post.mockResolvedValueOnce({ data: operation })

			const store = useQualityStore()
			const result = await store.executeMerge(
				'obj-a',
				'obj-b',
				'duplicate-confirmed',
			)

			expect(axios.post).toHaveBeenCalledWith(
				expect.stringContaining('/objects/merge/execute'),
				{ from: 'obj-a', into: 'obj-b', reason: 'duplicate-confirmed' },
			)
			expect(result).toEqual(operation)
		})

		it('records an error on failure', async () => {
			axios.post.mockRejectedValueOnce({ message: 'boom' })
			const store = useQualityStore()

			await expect(
				store.executeMerge('obj-a', 'obj-b', 'reason'),
			).rejects.toBeTruthy()
			expect(store.error).toBe('boom')
		})
	})

	describe('fetchMergeOperations', () => {
		it('fetches and paginates merge-operation rows via the generic object-read surface', async () => {
			axios.get.mockResolvedValueOnce({
				data: {
					results: [
						{
							id: 'op-1',
							from: 'obj-a',
							into: 'obj-b',
							reversible: true,
						},
					],
					total: 1,
					limit: 20,
					offset: 0,
				},
			})

			const store = useQualityStore()
			await store.fetchMergeOperations()

			expect(axios.get).toHaveBeenCalledWith(
				expect.stringContaining('/objects/merge-operation/mergeOperation'),
				expect.any(Object),
			)
			expect(store.mergeOperations).toHaveLength(1)
			expect(store.mergeOperationsTotal).toBe(1)
		})

		it('records an error on failure', async () => {
			axios.get.mockRejectedValueOnce({ message: 'boom' })
			const store = useQualityStore()

			await expect(store.fetchMergeOperations()).rejects.toBeTruthy()
			expect(store.error).toBe('boom')
		})
	})

	describe('setAttributeOverride', () => {
		it('posts { attribute, value, rationale } to the override endpoint and returns the recomputed object', async () => {
			const recomputed = {
				id: 'obj-1',
				goldenRecord: { legalName: 'Steward Co' },
			}
			axios.post.mockResolvedValueOnce({ data: recomputed })

			const store = useQualityStore()
			const result = await store.setAttributeOverride(
				'obj-1',
				'legalName',
				'Steward Co',
				'Confirmed with client',
			)

			expect(axios.post).toHaveBeenCalledWith(
				expect.stringContaining('/objects/survivorship/obj-1/override'),
				{
					attribute: 'legalName',
					value: 'Steward Co',
					rationale: 'Confirmed with client',
				},
			)
			expect(result).toEqual(recomputed)
		})

		it('records an error on failure', async () => {
			axios.post.mockRejectedValueOnce({
				response: { data: { error: 'Forbidden' } },
			})
			const store = useQualityStore()

			await expect(
				store.setAttributeOverride('obj-1', 'legalName', 'Steward Co'),
			).rejects.toBeTruthy()
			expect(store.error).toBe('Forbidden')
		})
	})

	describe('clearAttributeOverride', () => {
		it('posts { attribute, clear: true } to the override endpoint', async () => {
			const recomputed = {
				id: 'obj-1',
				goldenRecord: { legalName: 'Gold Co' },
			}
			axios.post.mockResolvedValueOnce({ data: recomputed })

			const store = useQualityStore()
			const result = await store.clearAttributeOverride('obj-1', 'legalName')

			expect(axios.post).toHaveBeenCalledWith(
				expect.stringContaining('/objects/survivorship/obj-1/override'),
				{ attribute: 'legalName', clear: true },
			)
			expect(result).toEqual(recomputed)
		})

		it('records an error on failure', async () => {
			axios.post.mockRejectedValueOnce({ message: 'boom' })
			const store = useQualityStore()

			await expect(
				store.clearAttributeOverride('obj-1', 'legalName'),
			).rejects.toBeTruthy()
			expect(store.error).toBe('boom')
		})
	})

	describe('persistTrustRule', () => {
		it('posts a trustConfiguration row to the generic objects surface and returns the created row', async () => {
			const created = {
				id: 'trust-1',
				entityType: 'organisation',
				attribute: 'legalName',
				sourceSystem: 'registry',
				trustTier: 'gold',
			}
			axios.post.mockResolvedValueOnce({ data: created })

			const store = useQualityStore()
			const result = await store.persistTrustRule({
				entityType: 'organisation',
				attribute: 'legalName',
				sourceSystem: 'registry',
				trustTier: 'gold',
				rationale: 'Confirmed',
			})

			expect(axios.post).toHaveBeenCalledWith(
				expect.stringContaining(
					'/objects/trust-configuration/trustConfiguration',
				),
				{
					entityType: 'organisation',
					attribute: 'legalName',
					sourceSystem: 'registry',
					trustTier: 'gold',
					rationale: 'Confirmed',
				},
			)
			expect(result).toEqual(created)
		})

		it('records an error on failure', async () => {
			axios.post.mockRejectedValueOnce({ message: 'boom' })
			const store = useQualityStore()

			await expect(
				store.persistTrustRule({
					entityType: 'organisation',
					attribute: 'legalName',
					sourceSystem: 'registry',
					trustTier: 'gold',
				}),
			).rejects.toBeTruthy()
			expect(store.error).toBe('boom')
		})
	})

	describe('touchObject', () => {
		it('sends an empty PATCH to the generic object endpoint and returns the recomputed object', async () => {
			const recomputed = {
				id: 'obj-1',
				goldenRecord: { legalName: 'Gold Co' },
			}
			axios.patch.mockResolvedValueOnce({ data: recomputed })

			const store = useQualityStore()
			const result = await store.touchObject('16', '1207', 'obj-1')

			expect(axios.patch).toHaveBeenCalledWith(
				expect.stringContaining('/objects/16/1207/obj-1'),
				{},
			)
			expect(result).toEqual(recomputed)
		})

		it('records an error on failure', async () => {
			axios.patch.mockRejectedValueOnce({ message: 'boom' })
			const store = useQualityStore()

			await expect(
				store.touchObject('16', '1207', 'obj-1'),
			).rejects.toBeTruthy()
			expect(store.error).toBe('boom')
		})
	})

	describe('reverseMerge', () => {
		it('posts to the reverse endpoint with the operation id and returns the updated operation', async () => {
			const updated = { id: 'op-1', reversible: false }
			axios.post.mockResolvedValueOnce({ data: updated })

			const store = useQualityStore()
			const result = await store.reverseMerge('op-1')

			expect(axios.post).toHaveBeenCalledWith(
				expect.stringContaining('/objects/merge/op-1/reverse'),
			)
			expect(result).toEqual(updated)
		})

		it('records an error on failure', async () => {
			axios.post.mockRejectedValueOnce({
				response: { data: { error: 'Not found' } },
			})
			const store = useQualityStore()

			await expect(store.reverseMerge('op-1')).rejects.toBeTruthy()
			expect(store.error).toBe('Not found')
		})
	})
})
