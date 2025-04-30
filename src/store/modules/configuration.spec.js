/* eslint-disable no-console */
import { setActivePinia, createPinia } from 'pinia'

import { useConfigurationStore } from './configuration.js'
import { ConfigurationEntity, mockConfiguration, mockConfigurations } from '../../entities/index.js'

describe('Configuration Store', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('sets configuration item correctly', () => {
		const store = useConfigurationStore()

		store.setConfigurationItem(mockConfiguration)

		expect(store.configurationItem).toBeInstanceOf(ConfigurationEntity)
		expect(store.configurationItem).toEqual(mockConfiguration)

		expect(store.configurationItem.validate().success).toBe(true)
	})

	it('sets configuration list correctly', () => {
		const store = useConfigurationStore()

		store.setConfigurationList(mockConfigurations)

		expect(store.configurationList).toHaveLength(mockConfigurations.length)

		store.configurationList.forEach((item, index) => {
			expect(item).toBeInstanceOf(ConfigurationEntity)
			expect(item).toEqual(mockConfigurations[index])
			expect(item.validate().success).toBe(true)
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

	it('handles null configuration item correctly', () => {
		const store = useConfigurationStore()

		store.setConfigurationItem(null)

		expect(store.configurationItem).toBeNull()
	})

	it('validates configuration items in list', () => {
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

		store.setConfigurationList([invalidConfiguration])

		expect(store.configurationList[0].validate().success).toBe(false)
	})
})
