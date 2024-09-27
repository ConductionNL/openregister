import { Register } from './register'
import { mockRegisterData } from './register.mock'

describe('Register Entity', () => {
	it('should create a Register entity with full data', () => {
		const register = new Register(mockRegisterData()[0])

		expect(register).toBeInstanceOf(Register)
		expect(register).toEqual(mockRegisterData()[0])
		expect(register.validate().success).toBe(true)
	})

	it('should create a Register entity with partial data', () => {
		const partialData = {
			name: 'Partial Register',
			description: 'A register with partial data',
			schemas: [] as any[], // Explicitly typing schemas as any[]
			databaseId: 'db1-a1e5-b54d-43ad-abd1-4b5bff5fcd3f',
		}
		const register = new Register(partialData)

		expect(register).toBeInstanceOf(Register)
		expect(register.id).toBe('')
		expect(register.name).toBe(partialData.name)
		expect(register.tablePrefix).toBe('')
		expect(register.validate().success).toBe(true)
	})

	it('should fail validation with invalid data', () => {
		const invalidData = {
			name: '',
			description: 'Invalid register',
			schemas: [] as any[], // Explicitly type the schemas property
			databaseId: '',
		}
		const register = new Register(invalidData)

		expect(register).toBeInstanceOf(Register)
		expect(register.validate().success).toBe(false)
	})

	it('should correctly combine database and register prefixes', () => {
		const register = new Register(mockRegisterData()[0])

		expect(register.getFullTablePrefix('myorg_')).toBe('myorg_character_')
		expect(register.getFullTablePrefix('myorg_')).toBe('myorg_character_')
		expect(register.getFullTablePrefix('')).toBe('character_')
	})
})
