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
		const register = new Register(mockRegisterData()[0])

		expect(register).toBeInstanceOf(Register)
		expect(register.id).toBe('')
		expect(register.title).toBe(mockRegisterData()[0].title)
		expect(register.tablePrefix).toBe('')
		expect(register.slug).toBe(mockRegisterData()[0].slug) // Added slug property check
		expect(register.validate().success).toBe(true)
	})

	it('should fail validation with invalid data', () => {
		const register = new Register(mockRegisterData()[1])

		expect(register).toBeInstanceOf(Register)
		expect(register.validate().success).toBe(false)
		expect(register.validate().error?.issues).toContainEqual(expect.objectContaining({
			path: ['name'],
			message: 'String must contain at least 1 character(s)',
		}))
	})

	it('should correctly combine database and register prefixes', () => {
		const register = new Register(mockRegisterData()[0])

		expect(register.getFullTablePrefix('myorg_')).toBe('myorg_character_')
		expect(register.getFullTablePrefix('myorg_')).toBe('myorg_character_')
		expect(register.getFullTablePrefix('')).toBe('character_')
	})

	it('should create a Register entity with stats', () => {
		const register = new Register(mockRegisterData()[0])
		expect(register.stats).toBeDefined()
		expect(register.stats?.objects?.total).toBe(20)
		expect(register.stats?.logs?.total).toBe(3)
		expect(register.stats?.files?.size).toBe(256)
	})
})
