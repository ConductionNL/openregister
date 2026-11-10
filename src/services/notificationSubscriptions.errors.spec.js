import { subscribe, unsubscribe } from './notificationSubscriptions.js'

const BASE = '/index.php/apps/openregister/api/notification-subscriptions'

describe('notification subscriptions — request shapes and HTTP errors', () => {
	beforeEach(() => {
		global.fetch = jest.fn()
	})

	afterEach(() => {
		delete global.fetch
	})

	it('subscribe POSTs nulls by default and throws on an HTTP error', async () => {
		global.fetch.mockResolvedValue({ ok: false, status: 403 })
		await expect(subscribe()).rejects.toThrow('Failed to subscribe: HTTP 403')
		expect(global.fetch).toHaveBeenCalledWith(BASE, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({ registerId: null, schemaId: null }),
		})
	})

	it('unsubscribe sends only the ids it is given and throws on an HTTP error', async () => {
		global.fetch.mockResolvedValue({ ok: false, status: 500 })
		await expect(unsubscribe({ schemaId: 4 })).rejects.toThrow('Failed to unsubscribe: HTTP 500')
		expect(global.fetch).toHaveBeenCalledWith(`${BASE}?schemaId=4`, {
			method: 'DELETE',
			headers: { 'Content-Type': 'application/json' },
		})
	})

	it('unsubscribe without ids sends an empty query', async () => {
		global.fetch.mockResolvedValue({ ok: true, json: () => Promise.resolve({ deleted: true }) })
		await expect(unsubscribe()).resolves.toEqual({ deleted: true })
		expect(global.fetch.mock.calls[0][0]).toBe(`${BASE}?`)
	})
})
