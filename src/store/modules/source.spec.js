/* eslint-disable no-console */
import { setActivePinia, createPinia } from 'pinia'

import { useSourceStore } from './source.js'
import { Source, mockSource } from '../../entities/index.js'

describe('Source Store', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('sets item correctly', () => {
		const store = useSourceStore()
		const mockData = mockSource()

		store.setItem(mockData[0])

		expect(store.item).toBeInstanceOf(Source)
		expect(store.item).toEqual(mockData[0])
		expect(store.item.validate().success).toBe(true)
	})

	it('sets list correctly', () => {
		const store = useSourceStore()
		const mockData = mockSource()

		store.setList(mockData)

		expect(store.list).toHaveLength(mockData.length)

		store.list.forEach((item, index) => {
			expect(item).toBeInstanceOf(Source)
			expect(item).toEqual(mockData[index])
			expect(item.validate().success).toBe(true)
		})
	})

	it('handles null item correctly', () => {
		const store = useSourceStore()

		store.setItem(null)

		expect(store.item).toBeNull()
	})

	it('has correct initial state', () => {
		const store = useSourceStore()

		expect(store.item).toBeNull()
		expect(store.list).toEqual([])
		expect(store.filters).toEqual({})
		expect(store.pagination).toEqual({ page: 1, limit: 20 })
	})

	it('cleanForSave strips default fields', () => {
		const store = useSourceStore()
		const item = {
			id: 1,
			uuid: 'test-uuid',
			name: 'Test Source',
			created: '2024-01-01',
			updated: '2024-01-02',
		}

		const cleaned = store.cleanForSave(item)

		expect(cleaned.name).toBe('Test Source')
		expect(cleaned.id).toBeUndefined()
		expect(cleaned.uuid).toBeUndefined()
		expect(cleaned.created).toBeUndefined()
		expect(cleaned.updated).toBeUndefined()
	})
})
