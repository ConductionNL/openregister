/* eslint-disable no-console */
import { setActivePinia, createPinia } from 'pinia'

import { useAgentStore } from './agent.js'
import { Agent, mockAgent } from '../../entities/index.js'

describe('Agent Store', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('sets item correctly', () => {
		const store = useAgentStore()
		const mockData = mockAgent()

		store.setItem(mockData[0])

		expect(store.item).toBeInstanceOf(Agent)
		expect(store.item).toEqual(mockData[0])
		expect(store.item.validate().success).toBe(true)
	})

	it('sets list correctly', () => {
		const store = useAgentStore()
		const mockData = mockAgent()

		store.setList(mockData)

		expect(store.list).toHaveLength(mockData.length)

		store.list.forEach((item, index) => {
			expect(item).toBeInstanceOf(Agent)
			expect(item).toEqual(mockData[index])
			expect(item.validate().success).toBe(true)
		})
	})

	it('handles null item correctly', () => {
		const store = useAgentStore()

		store.setItem(null)

		expect(store.item).toBeNull()
	})

	it('has correct initial state', () => {
		const store = useAgentStore()

		expect(store.item).toBeNull()
		expect(store.list).toEqual([])
		expect(store.filters).toEqual({})
		expect(store.pagination).toEqual({ page: 1, limit: 20 })
	})

	it('has loading and viewMode features', () => {
		const store = useAgentStore()

		expect(store.loading).toBe(false)
		expect(store.error).toBeNull()
		expect(store.isLoading).toBe(false)
		expect(store.viewMode).toBe('cards')
		expect(store.getViewMode).toBe('cards')
	})

	it('cleanForSave strips default fields', () => {
		const store = useAgentStore()
		const item = {
			id: 1,
			uuid: 'test-uuid',
			name: 'Test Agent',
			created: '2024-01-01',
			updated: '2024-01-02',
		}

		const cleaned = store.cleanForSave(item)

		expect(cleaned.name).toBe('Test Agent')
		expect(cleaned.id).toBeUndefined()
		expect(cleaned.uuid).toBeUndefined()
		expect(cleaned.created).toBeUndefined()
		expect(cleaned.updated).toBeUndefined()
	})

	describe('custom parseListResponse', () => {
		it('handles array response directly', async () => {
			const store = useAgentStore()

			global.fetch = jest.fn().mockResolvedValue({
				ok: true,
				json: () => Promise.resolve([{ id: 1, name: 'Agent 1' }]),
			})

			await store.refreshList()

			expect(store.list).toHaveLength(1)
			expect(store.list[0]).toBeInstanceOf(Agent)
		})

		it('handles { results: [] } response', async () => {
			const store = useAgentStore()

			global.fetch = jest.fn().mockResolvedValue({
				ok: true,
				json: () => Promise.resolve({ results: [{ id: 1, name: 'Agent 1' }, { id: 2, name: 'Agent 2' }] }),
			})

			await store.refreshList()

			expect(store.list).toHaveLength(2)
		})
	})

	describe('getStats', () => {
		it('fetches agent statistics', async () => {
			const store = useAgentStore()
			const mockStats = { totalAgents: 5, activeAgents: 3 }

			global.fetch = jest.fn().mockResolvedValue({
				ok: true,
				json: () => Promise.resolve(mockStats),
			})

			const result = await store.getStats()

			expect(global.fetch).toHaveBeenCalledTimes(1)
			const calledUrl = global.fetch.mock.calls[0][0]
			expect(calledUrl).toContain('/stats')
			expect(result).toEqual(mockStats)
		})

		it('throws on error response', async () => {
			const store = useAgentStore()

			global.fetch = jest.fn().mockResolvedValue({
				ok: false,
				status: 500,
			})

			await expect(store.getStats()).rejects.toThrow('HTTP error')
		})
	})
})
