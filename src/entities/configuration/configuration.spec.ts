import { ConfigurationEntity } from './configuration'
import { mockConfiguration } from './configuration.mock'
import { TConfiguration } from './configuration.types'

describe('ConfigurationEntity', () => {
	let configuration: ConfigurationEntity

	beforeEach(() => {
		configuration = new ConfigurationEntity(mockConfiguration)
	})

	it('should create a configuration entity', () => {
		expect(configuration).toBeInstanceOf(ConfigurationEntity)
		expect(configuration['@self'].id).toBe(mockConfiguration['@self'].id)
		expect(configuration['@self'].title).toBe(mockConfiguration['@self'].title)
		expect(configuration.configuration.registers).toEqual(mockConfiguration.configuration.registers)
	})

	it('should handle missing optional properties', () => {
		const minimalConfig: TConfiguration = {
			'@self': {
				id: '1',
				uuid: '550e8400-e29b-41d4-a716-446655440000',
				title: 'Minimal Config',
				description: null,
				version: '1.0.0',
				slug: 'minimal-config',
				owner: null,
				organisation: null,
				application: null,
				updated: '2024-03-20T12:00:00Z',
				created: '2024-03-20T12:00:00Z',
			},
			configuration: {},
		}

		const minimalEntity = new ConfigurationEntity(minimalConfig)
		expect(minimalEntity.configuration.registers).toEqual([])
		expect(minimalEntity.configuration.schemas).toEqual([])
		expect(minimalEntity.configuration.endpoints).toEqual([])
	})

	describe('validate', () => {
		it('should validate a valid configuration', () => {
			const result = configuration.validate()
			expect(result.success).toBe(true)
		})

		it('should fail validation for missing required fields', () => {
			const invalidConfig: TConfiguration = {
				'@self': {
					id: '', // Invalid: empty string
					uuid: '550e8400-e29b-41d4-a716-446655440000',
					title: '', // Invalid: empty string
					description: null,
					version: '', // Invalid: empty string
					slug: '', // Invalid: empty string
					owner: null,
					organisation: null,
					application: null,
					updated: '', // Invalid: empty string
					created: '', // Invalid: empty string
				},
				configuration: {},
			}

			const invalidEntity = new ConfigurationEntity(invalidConfig)
			const result = invalidEntity.validate()
			expect(result.success).toBe(false)
			if (!result.success) {
				expect(result.error.errors).toHaveLength(5)
				expect(result.error.errors[0].path).toContain('id')
				expect(result.error.errors[1].path).toContain('title')
				expect(result.error.errors[2].path).toContain('version')
				expect(result.error.errors[3].path).toContain('slug')
			}
		})

		it('should allow additional properties', () => {
			const configWithExtra: TConfiguration = {
				...mockConfiguration,
				extraProp: 'test',
				anotherExtra: { key: 'value' },
			}

			const entityWithExtra = new ConfigurationEntity(configWithExtra)
			const result = entityWithExtra.validate()
			expect(result.success).toBe(true)
			expect(entityWithExtra.extraProp).toBe('test')
			expect(entityWithExtra.anotherExtra).toEqual({ key: 'value' })
		})
	})
})
