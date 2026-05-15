/**
 * Unit tests for notificationSubscriptions API client.
 *
 * Closes notificatie-engine task: "Users MUST be able to manage their
 * notification preferences".
 */

import {
	listSubscriptions,
	subscribe,
	unsubscribe,
	hasSubscription,
} from './notificationSubscriptions.js'

describe('notificationSubscriptions API helpers', () => {
	let fetchMock

	beforeEach(() => {
		fetchMock = jest.fn()
		global.fetch = fetchMock
	})

	afterEach(() => {
		jest.clearAllMocks()
	})

	describe('listSubscriptions', () => {
		it('GETs the index endpoint and returns the results array', async () => {
			fetchMock.mockResolvedValueOnce({
				ok: true,
				json: () => Promise.resolve({ results: [{ id: 1, registerId: 5, schemaId: null }], total: 1 }),
			})

			const result = await listSubscriptions()

			expect(fetchMock).toHaveBeenCalledTimes(1)
			expect(fetchMock.mock.calls[0][0]).toBe('/index.php/apps/openregister/api/notification-subscriptions')
			expect(fetchMock.mock.calls[0][1].method).toBe('GET')
			expect(result).toEqual([{ id: 1, registerId: 5, schemaId: null }])
		})

		it('throws on HTTP error', async () => {
			fetchMock.mockResolvedValueOnce({ ok: false, status: 401 })
			await expect(listSubscriptions()).rejects.toThrow('HTTP 401')
		})
	})

	describe('subscribe', () => {
		it('POSTs the registerId/schemaId tuple', async () => {
			fetchMock.mockResolvedValueOnce({
				ok: true,
				json: () => Promise.resolve({ id: 7, registerId: 5, schemaId: 28 }),
			})

			const result = await subscribe({ registerId: 5, schemaId: 28 })

			expect(fetchMock).toHaveBeenCalledTimes(1)
			const [, opts] = fetchMock.mock.calls[0]
			expect(opts.method).toBe('POST')
			expect(JSON.parse(opts.body)).toEqual({ registerId: 5, schemaId: 28 })
			expect(result.id).toBe(7)
		})

		it('defaults missing fields to null', async () => {
			fetchMock.mockResolvedValueOnce({
				ok: true,
				json: () => Promise.resolve({ id: 8, registerId: 5, schemaId: null }),
			})

			await subscribe({ registerId: 5 })

			const body = JSON.parse(fetchMock.mock.calls[0][1].body)
			expect(body).toEqual({ registerId: 5, schemaId: null })
		})
	})

	describe('unsubscribe', () => {
		it('DELETEs with the tuple in the query string', async () => {
			fetchMock.mockResolvedValueOnce({
				ok: true,
				json: () => Promise.resolve({ deleted: true }),
			})

			const result = await unsubscribe({ registerId: 5, schemaId: 28 })

			const [url, opts] = fetchMock.mock.calls[0]
			expect(opts.method).toBe('DELETE')
			expect(url).toContain('registerId=5')
			expect(url).toContain('schemaId=28')
			expect(result.deleted).toBe(true)
		})

		it('omits null fields from the query string', async () => {
			fetchMock.mockResolvedValueOnce({
				ok: true,
				json: () => Promise.resolve({ deleted: true }),
			})

			await unsubscribe({ registerId: 5 })

			const url = fetchMock.mock.calls[0][0]
			expect(url).toContain('registerId=5')
			expect(url).not.toContain('schemaId')
		})
	})

	describe('hasSubscription', () => {
		const subs = [
			{ id: 1, registerId: 5, schemaId: null },
			{ id: 2, registerId: 5, schemaId: 28 },
			{ id: 3, registerId: null, schemaId: 99 },
		]

		it('matches exact tuples', () => {
			expect(hasSubscription(subs, { registerId: 5, schemaId: 28 })).toBe(true)
			expect(hasSubscription(subs, { registerId: 5, schemaId: null })).toBe(true)
			expect(hasSubscription(subs, { registerId: null, schemaId: 99 })).toBe(true)
		})

		it('non-matching tuples return false', () => {
			expect(hasSubscription(subs, { registerId: 5, schemaId: 100 })).toBe(false)
			expect(hasSubscription(subs, { registerId: 999, schemaId: 28 })).toBe(false)
			expect(hasSubscription(subs, { registerId: null, schemaId: null })).toBe(false)
		})

		it('handles empty/non-array input', () => {
			expect(hasSubscription([], { registerId: 5 })).toBe(false)
			expect(hasSubscription(null, { registerId: 5 })).toBe(false)
			expect(hasSubscription(undefined, { registerId: 5 })).toBe(false)
		})
	})
})
