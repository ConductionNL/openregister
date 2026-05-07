/* eslint-disable no-console */
import { setActivePinia, createPinia } from 'pinia'

import { useConfigurationStore } from './configuration.js'
import { ConfigurationEntity, mockConfiguration, mockConfigurations } from '../../entities/index.js'

describe('Configuration Store', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('wraps the active configuration in a ConfigurationEntity', () => {
		const store = useConfigurationStore()

		store.setConfigurationItem(mockConfiguration)

		expect(store.configurationItem).toBeInstanceOf(ConfigurationEntity)
		expect(store.configurationItem.id).toBe(mockConfiguration.id)
		expect(store.configurationItem.title).toBe(mockConfiguration.title)
		expect(store.configurationItem.validate().success).toBe(true)
	})

	it('wraps every entry of the list in a ConfigurationEntity', () => {
		const store = useConfigurationStore()

		store.setConfigurationList(mockConfigurations)

		expect(store.configurationList).toHaveLength(mockConfigurations.length)
		store.configurationList.forEach((item, index) => {
			expect(item).toBeInstanceOf(ConfigurationEntity)
			expect(item.id).toBe(mockConfigurations[index].id)
			expect(item.validate().success).toBe(true)
		})
	})

	it('sets pagination correctly', () => {
		const store = useConfigurationStore()
		store.setPagination(2, 10)
		expect(store.pagination).toEqual({ page: 2, limit: 10 })
	})

	it('sets filters correctly', () => {
		const store = useConfigurationStore()
		const filters = { search: 'test', type: 'config' }
		store.setFilters(filters)
		expect(store.filters).toEqual(filters)
	})

	it('handles null configuration item correctly', () => {
		const store = useConfigurationStore()
		store.setConfigurationItem(null)
		expect(store.configurationItem).toBeNull()
	})

	it('flags invalid items in the list as failing validation', () => {
		const store = useConfigurationStore()
		// Empty id + title fail z.string().min(1).
		const invalidConfiguration = {
			id: '',
			title: '',
			description: null,
			version: '',
			type: '',
			application: '',
			owner: '',
			organisation: null,
			registers: [],
			schemas: [],
			created: '',
			updated: '',
		}

		store.setConfigurationList([invalidConfiguration])

		expect(store.configurationList[0].validate().success).toBe(false)
	})
})
