import { Register } from './register'
import { mockRegisterData } from './register.mock'

describe('Register Entity', () => {
	it('should create a Register entity with full data', () => {
		const register = new Register(mockRegisterData()[0])

		expect(register).toBeInstanceOf(Register)
		expect(register.id).toBe(mockRegisterData()[0].id)
		expect(register.title).toBe(mockRegisterData()[0].title)
		expect(register.validate().success).toBe(true)
	})

	it('should populate fields from mock data', () => {
		const register = new Register(mockRegisterData()[0])

		expect(register).toBeInstanceOf(Register)
		expect(register.id).toBe(mockRegisterData()[0].id)
		expect(register.title).toBe(mockRegisterData()[0].title)
		expect(register.tablePrefix).toBe(mockRegisterData()[0].tablePrefix)
		expect(register.slug).toBe(mockRegisterData()[0].slug)
		expect(register.validate().success).toBe(true)
	})

	it('should fail validation when required fields are empty', () => {
		// Schema requires non-empty id / title / databaseId / slug.
		// Pass an empty-ish object to trigger failures.
		const register = new Register({
			id: '',
			title: '',
			description: '',
			schemas: [],
			source: '',
			databaseId: '',
			tablePrefix: '',
			created: '',
			updated: '',
			slug: '',
		})

		expect(register).toBeInstanceOf(Register)
		const result = register.validate()
		expect(result.success).toBe(false)
		expect(result.error?.issues.some(i => i.path[0] === 'title')).toBe(true)
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
