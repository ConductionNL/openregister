import { createPinia, setActivePinia } from 'pinia'
import { useEndpointStore } from './endpoints.ts'

const API = '/index.php/apps/openregister/api/endpoints'

/**
 * A fetch Response stand-in.
 *
 * @param {boolean} ok   Whether the status is 2xx.
 * @param {object}  body The JSON body.
 * @return {object}
 */
function answer(ok, body = {}) {
	return { ok, json: () => Promise.resolve(body) }
}

/**
 * Let the fire-and-forget list refresh settle.
 *
 * @return {Promise<void>}
 */
async function flush() {
	for (let i = 0; i < 5; i++) await Promise.resolve()
}

describe('endpoint store — list, delete and test actions', () => {
	let store
	let errorSpy

	beforeEach(() => {
		setActivePinia(createPinia())
		global.fetch = jest.fn()
		errorSpy = jest.spyOn(console, 'error').mockImplementation(() => {})
		store = useEndpointStore()
	})

	afterEach(() => {
		errorSpy.mockRestore()
		delete global.fetch
	})

	it('setters and getters round-trip the item and list, viewMode defaults to cards', () => {
		store.setEndpointItem({ id: 1 })
		store.setEndpointList([{ id: 1 }, { id: 2 }])
		expect(store.getEndpointItem()).toEqual({ id: 1 })
		expect(store.getEndpointList()).toEqual([{ id: 1 }, { id: 2 }])
		expect(store.viewMode).toBe('cards')
	})

	it('refreshEndpointList GETs the list and stores its results', async () => {
		global.fetch.mockResolvedValue(answer(true, { results: [{ id: 9 }] }))
		store.refreshEndpointList()
		await flush()
		expect(global.fetch).toHaveBeenCalledWith(API, { method: 'GET' })
		expect(store.endpointList).toEqual([{ id: 9 }])
	})

	it('refreshEndpointList logs a rejected fetch and keeps the list', async () => {
		store.setEndpointList([{ id: 1 }])
		const err = new Error('offline')
		global.fetch.mockRejectedValue(err)
		store.refreshEndpointList()
		await flush()
		expect(errorSpy).toHaveBeenCalledWith('Error fetching endpoint list:', err)
		expect(store.endpointList).toEqual([{ id: 1 }])
	})

	it('updateEndpoint refuses an item without an id before any request', () => {
		expect(() => store.updateEndpoint({ name: 'x' })).toThrow('Endpoint ID is required for update')
		expect(global.fetch).not.toHaveBeenCalled()
	})

	describe('deleteEndpoint', () => {
		it('refuses an item without an id before any request', () => {
			expect(() => store.deleteEndpoint({})).toThrow('Endpoint ID is required for deletion')
			expect(global.fetch).not.toHaveBeenCalled()
		})

		it('DELETEs the endpoint, clears the matching active item and refreshes the list', async () => {
			store.setEndpointItem({ id: 4 })
			global.fetch
				.mockResolvedValueOnce(answer(true))
				.mockResolvedValueOnce(answer(true, { results: [] }))
			await store.deleteEndpoint({ id: 4 })
			await flush()
			expect(global.fetch).toHaveBeenNthCalledWith(1, `${API}/4`, { method: 'DELETE' })
			expect(global.fetch).toHaveBeenNthCalledWith(2, API, { method: 'GET' })
			expect(store.endpointItem).toBeNull()
			expect(store.endpointList).toEqual([])
		})

		it('keeps a different active item', async () => {
			store.setEndpointItem({ id: 5 })
			global.fetch.mockResolvedValue(answer(true, { results: [] }))
			await store.deleteEndpoint({ id: 4 })
			expect(store.endpointItem).toEqual({ id: 5 })
		})

		it('rejects and logs when the server refuses, leaving the active item', async () => {
			store.setEndpointItem({ id: 4 })
			global.fetch.mockResolvedValue(answer(false))
			await expect(store.deleteEndpoint({ id: 4 })).rejects.toThrow('Failed to delete endpoint')
			expect(errorSpy).toHaveBeenCalledWith('Error deleting endpoint:', expect.any(Error))
			expect(store.endpointItem).toEqual({ id: 4 })
			expect(global.fetch).toHaveBeenCalledTimes(1)
		})
	})

	describe('testEndpoint', () => {
		it('refuses an item without an id before any request', () => {
			expect(() => store.testEndpoint({})).toThrow('Endpoint ID is required for testing')
			expect(global.fetch).not.toHaveBeenCalled()
		})

		it('POSTs the test data wrapped in { data } and returns the answer', async () => {
			global.fetch.mockResolvedValue(answer(true, { status: 200, body: 'ok' }))
			await expect(store.testEndpoint({ id: 3 }, { q: 1 })).resolves.toEqual({ status: 200, body: 'ok' })
			expect(global.fetch).toHaveBeenCalledWith(`${API}/3/test`, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({ data: { q: 1 } }),
			})
		})

		it('sends empty test data by default', async () => {
			global.fetch.mockResolvedValue(answer(true, {}))
			await store.testEndpoint({ id: 3 })
			expect(global.fetch.mock.calls[0][1].body).toBe('{"data":{}}')
		})

		it.each([
			[{ error: 'bad mapping', message: 'm' }, 'bad mapping'],
			[{ message: 'timeout' }, 'timeout'],
			[{}, 'Failed to test endpoint'],
		])('rejects a failed test with the server reason %j', async (body, reason) => {
			global.fetch.mockResolvedValue(answer(false, body))
			await expect(store.testEndpoint({ id: 3 })).rejects.toThrow(reason)
			expect(errorSpy).toHaveBeenCalledWith('Error testing endpoint:', expect.any(Error))
		})
	})
})
