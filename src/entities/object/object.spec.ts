/* eslint-disable @typescript-eslint/no-explicit-any */
import { ObjectEntity } from './object'
import { mockObjectData } from './object.mock'

describe('Object Entity', () => {
	it('should create an Object entity with full data', () => {
		const object = new ObjectEntity(mockObjectData()[0])

		expect(object).toBeInstanceOf(Object)
		expect(object).toEqual(mockObjectData()[0])
		expect(object.validate().success).toBe(true)
	})

	it('should create an Object entity with partial data', () => {
		const object = new ObjectEntity(mockObjectData()[0])

		expect(object).toBeInstanceOf(Object)
		expect(object.id).toBe('')
		expect(object.uuid).toBe(mockObjectData()[0].uuid)
		expect(object.uri).toBe(mockObjectData()[0].uri)
		expect(object.register).toBe(mockObjectData()[0].register)
		expect(object.schema).toBe(mockObjectData()[0].schema)
		expect(object.object).toBe(mockObjectData()[0].object)
		expect(object.relations).toBe(mockObjectData()[0].relations)
		expect(object.files).toBe(mockObjectData()[0].files)
		expect(object.updated).toBe(mockObjectData()[0].updated)
		expect(object.created).toBe(mockObjectData()[0].created)
		expect(object.locked).toBe(null)
		expect(object.owner).toBe('')
		expect(object.validate().success).toBe(true)
	})

	it('should handle locked array and owner string', () => {
		const mockData = mockObjectData()[0]
		mockData.locked = ['token1', 'token2']
		mockData.owner = 'user1'
		const object = new ObjectEntity(mockData)

		expect(object.locked).toEqual(['token1', 'token2'])
		expect(object.owner).toBe('user1')
		expect(object.validate().success).toBe(true)
	})

	it('should fail validation with invalid data', () => {
		const object = new ObjectEntity(mockObjectData()[1])

		expect(object).toBeInstanceOf(Object)
		expect(object.validate().success).toBe(false)
		expect(object.validate().error?.issues).toContainEqual(expect.objectContaining({
			path: ['id'],
			message: 'String must contain at least 1 character(s)',
		}))
	})
})
