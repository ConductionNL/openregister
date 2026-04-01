/* eslint-disable no-console */
import { setActivePinia, createPinia } from 'pinia'

import { useEndpointStore } from './endpoints.js'

describe('Endpoint Store', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('sets item correctly', () => {
		const store = useEndpointStore()
		const item = { id: 1, name: 'Test Endpoint', method: 'GET' }

		store.setItem(item)

		expect(store.item).toEqual(item)
	})

	it('sets list correctly', () => {
		const store = useEndpointStore()
		const items = [
			{ id: 1, name: 'Endpoint 1' },
			{ id: 2, name: 'Endpoint 2' },
		]

		store.setList(items)

		expect(store.list).toHaveLength(2)
	})

	it('handles null item correctly', () => {
		const store = useEndpointStore()

		store.setItem(null)

		expect(store.item).toBeNull()
	})

	it('has correct initial state', () => {
		const store = useEndpointStore()

		expect(store.item).toBeNull()
		expect(store.list).toEqual([])
		expect(store.filters).toEqual({})
		expect(store.pagination).toEqual({ page: 1, limit: 20 })
	})

	it('has viewMode feature', () => {
		const store = useEndpointStore()

		expect(store.viewMode).toBe('cards')
		expect(store.getViewMode).toBe('cards')

		store.setViewMode('table')
		expect(store.viewMode).toBe('table')
	})

	it('does not have loading feature', () => {
		const store = useEndpointStore()

		expect(store.loading).toBeUndefined()
		expect(store.error).toBeUndefined()
	})

	describe('testEndpoint', () => {
		it('sends POST to test endpoint', async () => {
			const store = useEndpointStore()
			const mockResult = { success: true, status: 200, body: '{}' }

			global.fetch = jest.fn().mockResolvedValue({
				ok: true,
				json: () => Promise.resolve(mockResult),
			})

			const result = await store.testEndpoint({ id: 5 }, { key: 'value' })

			expect(global.fetch).toHaveBeenCalledTimes(1)
			const [url, options] = global.fetch.mock.calls[0]
			expect(url).toContain('/5/test')
			expect(options.method).toBe('POST')
			expect(JSON.parse(options.body)).toEqual({ data: { key: 'value' } })
			expect(result).toEqual(mockResult)
		})

		it('throws if item has no id', async () => {
			const store = useEndpointStore()

			await expect(store.testEndpoint({}, {})).rejects.toThrow('Endpoint ID is required')
		})

		it('throws on error response', async () => {
			const store = useEndpointStore()

			global.fetch = jest.fn().mockResolvedValue({
				ok: false,
				json: () => Promise.resolve({ error: 'Connection refused' }),
			})

			await expect(store.testEndpoint({ id: 1 })).rejects.toThrow('Connection refused')
		})
	})
})
