import { Organisation } from './organisation'
import { mockOrganisationData } from './organisation.mock'

describe('Organisation Entity', () => {
	it('should create an Organisation entity with full data', () => {
		const organisation = new Organisation(mockOrganisationData()[0])

		expect(organisation).toBeInstanceOf(Organisation)
		expect(organisation.name).toBe(mockOrganisationData()[0].name)
		expect(organisation.uuid).toBe(mockOrganisationData()[0].uuid)
		expect(organisation.isDefault).toBe(true)
		expect(organisation.validate().success).toBe(true)
	})

	it('should create an Organisation entity with partial data', () => {
		const partialData = { name: 'Test Organisation' }
		const organisation = new Organisation(partialData)

		expect(organisation).toBeInstanceOf(Organisation)
		expect(organisation.name).toBe('Test Organisation')
		expect(organisation.uuid).toBe('')
		expect(organisation.users).toEqual([])
		expect(organisation.validate().success).toBe(true)
	})

	it('should fail validation with invalid data', () => {
		const invalidData = { name: '' } // Empty name should fail validation
		const organisation = new Organisation(invalidData)

		expect(organisation).toBeInstanceOf(Organisation)
		expect(organisation.validate().success).toBe(false)
	})

	it('should create an Organisation entity with user data', () => {
		const organisation = new Organisation(mockOrganisationData()[1])

		expect(organisation.users).toBeDefined()
		expect(organisation.users?.length).toBe(2)
		expect(organisation.userCount).toBe(2)
		expect(organisation.owner).toBe('alice')
		expect(organisation.isDefault).toBe(false)
	})
})
