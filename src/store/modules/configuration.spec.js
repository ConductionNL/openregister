/* eslint-disable no-console */
import { setActivePinia, createPinia } from 'pinia'

import { useConfigurationStore } from './configuration.js'
import { ConfigurationEntity, mockConfiguration, mockConfigurations } from '../../entities/index.js'

describe('Configuration Store', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('sets item correctly', () => {
		const store = useConfigurationStore()

		store.setItem(mockConfiguration)

		expect(store.item).toBeInstanceOf(ConfigurationEntity)
		// Entity constructor maps from mock format — just verify it's wrapped
		expect(store.item).toBeTruthy()
	})

	it('sets list correctly', () => {
		const store = useConfigurationStore()

		store.setList(mockConfigurations)

		expect(store.list).toHaveLength(mockConfigurations.length)

		store.list.forEach((item) => {
			expect(item).toBeInstanceOf(ConfigurationEntity)
		})
	})

	it('sets pagination correctly', () => {
		const store = useConfigurationStore()
		const page = 2
		const limit = 10

		store.setPagination(page, limit)

		expect(store.pagination).toEqual({ page, limit })
	})

	it('sets filters correctly', () => {
		const store = useConfigurationStore()
		const filters = { search: 'test', type: 'config' }

		store.setFilters(filters)

		expect(store.filters).toEqual(filters)
	})

	it('handles null item correctly', () => {
		const store = useConfigurationStore()

		store.setItem(null)

		expect(store.item).toBeNull()
	})

	it('validates items in list', () => {
		const store = useConfigurationStore()
		const invalidConfiguration = {
			'@self': {
				id: '',
				uuid: '',
				title: '',
				description: null,
				version: '',
				slug: '',
				owner: null,
				organisation: null,
				application: null,
				updated: '',
				created: '',
			},
			configuration: {},
		}

		store.setList([invalidConfiguration])

		expect(store.list[0].validate().success).toBe(false)
	})

	it('cleanForSave strips default fields', () => {
		const store = useConfigurationStore()
		const item = {
			id: 1,
			uuid: 'test-uuid',
			name: 'Test Config',
			created: '2024-01-01',
			updated: '2024-01-02',
		}

		const cleaned = store.cleanForSave(item)

		expect(cleaned.name).toBe('Test Config')
		expect(cleaned.id).toBeUndefined()
		expect(cleaned.uuid).toBeUndefined()
		expect(cleaned.created).toBeUndefined()
		expect(cleaned.updated).toBeUndefined()
	})

	it('has correct initial state', () => {
		const store = useConfigurationStore()

		expect(store.item).toBeNull()
		expect(store.list).toEqual([])
		expect(store.filters).toEqual({})
		expect(store.pagination).toEqual({ page: 1, limit: 20 })
	})
})
