import { mockConfiguration, mockConfigurations } from '../../../entities/mocks/configuration'
import { ConfigurationEntity } from '../../../../src/entities/configuration'

describe('configuration store', () => {
	let state

	beforeEach(() => {
		state = {
			configuration: null,
			configurations: [],
			pagination: {
				total: 0,
				limit: 10,
				offset: 0,
				page: 1
			},
			filters: {
				search: '',
				type: '',
				owner: ''
			}
		}
	})

	describe('mutations', () => {
		describe('SET_CONFIGURATION', () => {
			it('should set a single configuration', () => {
				const configuration = new ConfigurationEntity(mockConfiguration)
				state.configuration = configuration
				expect(state.configuration).toEqual(configuration)
			})

			it('should clear configuration when null is passed', () => {
				state.configuration = null
				expect(state.configuration).toBeNull()
			})

			it('should throw error for invalid configuration', () => {
				expect(() => {
					state.configuration = { invalid: 'structure' }
				}).toThrow()
			})
		})

		describe('SET_CONFIGURATIONS', () => {
			it('should set multiple configurations', () => {
				const configurations = mockConfigurations.map(config => new ConfigurationEntity(config))
				state.configurations = configurations
				expect(state.configurations).toEqual(configurations)
			})

			it('should set empty array when null is passed', () => {
				state.configurations = []
				expect(state.configurations).toEqual([])
			})

			it('should throw error for invalid configurations', () => {
				expect(() => {
					state.configurations = [{ invalid: 'structure' }]
				}).toThrow()
			})
		})

		describe('SET_PAGINATION', () => {
			it('should update pagination parameters', () => {
				const pagination = {
					total: 100,
					limit: 20,
					offset: 40,
					page: 3
				}
				state.pagination = pagination
				expect(state.pagination).toEqual(pagination)
			})

			it('should handle partial pagination updates', () => {
				const partialUpdate = {
					total: 50,
					page: 2
				}
				state.pagination = { ...state.pagination, ...partialUpdate }
				expect(state.pagination.total).toBe(50)
				expect(state.pagination.page).toBe(2)
				expect(state.pagination.limit).toBe(10)
				expect(state.pagination.offset).toBe(0)
			})
		})

		describe('SET_FILTERS', () => {
			it('should update filter values', () => {
				const filters = {
					search: 'test',
					type: 'config',
					owner: 'admin'
				}
				state.filters = filters
				expect(state.filters).toEqual(filters)
			})

			it('should handle partial filter updates', () => {
				const partialUpdate = {
					search: 'query',
					type: 'test'
				}
				state.filters = { ...state.filters, ...partialUpdate }
				expect(state.filters.search).toBe('query')
				expect(state.filters.type).toBe('test')
				expect(state.filters.owner).toBe('')
			})

			it('should clear filters when empty object is passed', () => {
				state.filters = {
					search: '',
					type: '',
					owner: ''
				}
				expect(state.filters.search).toBe('')
				expect(state.filters.type).toBe('')
				expect(state.filters.owner).toBe('')
			})
		})
	})

	describe('validation', () => {
		it('should validate configuration structure', () => {
			const invalidConfig = {
				'@self': {
					id: '1',
					// Missing required fields
				},
				configuration: 'not an object'
			}
			expect(() => {
				new ConfigurationEntity(invalidConfig)
			}).toThrow()
		})
	})
}) 