/* eslint-disable no-console */
import { setActivePinia, createPinia } from 'pinia'

import { useRegisterStore } from './register.js'
import { Register, mockRegister } from '../../entities/index.js'

describe('Register Store', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('sets item correctly', () => {
		const store = useRegisterStore()
		const mockData = mockRegister()

		store.setItem(mockData[0])

		expect(store.item).toBeInstanceOf(Register)
		expect(store.item).toEqual(mockData[0])
		expect(store.item.validate().success).toBe(true)
	})

	it('sets list correctly', () => {
		const store = useRegisterStore()
		const mockData = mockRegister()

		store.setList(mockData)

		expect(store.list).toHaveLength(mockData.length)

		store.list.forEach((item, index) => {
			expect(item).toBeInstanceOf(Register)
			expect(item).toEqual(mockData[index])
			expect(item.validate().success).toBe(true)
		})
	})

	it('handles null item correctly', () => {
		const store = useRegisterStore()

		store.setItem(null)

		expect(store.item).toBeNull()
	})

	it('has correct initial state', () => {
		const store = useRegisterStore()

		expect(store.item).toBeNull()
		expect(store.list).toEqual([])
		expect(store.filters).toEqual({})
		expect(store.pagination).toEqual({ page: 1, limit: 20 })
		expect(store.activeTab).toBe('stats-tab')
	})

	it('has loading and viewMode features', () => {
		const store = useRegisterStore()

		expect(store.loading).toBe(false)
		expect(store.error).toBeNull()
		expect(store.viewMode).toBe('cards')
	})

	it('sets active tab', () => {
		const store = useRegisterStore()

		store.setActiveTab('schemas-tab')

		expect(store.activeTab).toBe('schemas-tab')
		expect(store.getActiveTab).toBe('schemas-tab')
	})

	it('cleanForSave strips default fields', () => {
		const store = useRegisterStore()
		const item = {
			id: 1,
			uuid: 'test-uuid',
			name: 'Test Register',
			created: '2024-01-01',
			updated: '2024-01-02',
		}

		const cleaned = store.cleanForSave(item)

		expect(cleaned.name).toBe('Test Register')
		expect(cleaned.id).toBeUndefined()
		expect(cleaned.uuid).toBeUndefined()
		expect(cleaned.created).toBeUndefined()
		expect(cleaned.updated).toBeUndefined()
	})
})
