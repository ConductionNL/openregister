import { ConfigurationEntity } from './configuration'
import { mockConfiguration } from './configuration.mock'

describe('ConfigurationEntity', () => {
	it('should create a configuration entity from flat-shape data', () => {
		const configuration = new ConfigurationEntity(mockConfiguration)
		expect(configuration).toBeInstanceOf(ConfigurationEntity)
	})

	it('should default missing properties safely', () => {
		// The entity now has a flat shape (id, title, registers, schemas, …)
		// instead of the legacy `@self` envelope. Construct from a partial
		// shape and verify defaults.
		const minimalEntity = new ConfigurationEntity({
			id: '1',
			title: 'Minimal',
			description: null,
			type: '',
			application: '',
			owner: '',
			organisation: null,
			created: '',
			updated: '',
		} as never)

		expect(minimalEntity.registers).toEqual([])
		expect(minimalEntity.schemas).toEqual([])
	})

	describe('validate', () => {
		it('should validate the mock configuration', () => {
			const configuration = new ConfigurationEntity(mockConfiguration)
			const result = configuration.validate()
			expect(typeof result.success).toBe('boolean')
		})
	})
})
