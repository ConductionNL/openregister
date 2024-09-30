/* eslint-disable @typescript-eslint/no-explicit-any */
import { Object } from './object'
import { mockObjectData } from './object.mock'

describe('Object Entity', () => {
	it('should create an Object entity with full data', () => {
		const object = new Object(mockObjectData()[0])

		expect(object).toBeInstanceOf(Object)
		expect(object).toEqual(mockObjectData()[0])
		expect(object.validate().success).toBe(true)
	})

	it('should create an Object entity with partial data', () => {
		const object = new Object(mockObjectData()[0])

		expect(object).toBeInstanceOf(Object)
		expect(object.id).toBe('')
		expect(object.uuid).toBe(mockObjectData()[0].uuid)
		expect(object.register).toBe(mockObjectData()[0].register)
		expect(object.schema).toBe(mockObjectData()[0].schema)
		expect(object.object).toBe(mockObjectData()[0].object)
		expect(object.updated).toBe(mockObjectData()[0].updated)
		expect(object.created).toBe(mockObjectData()[0].created)
		expect(object.validate().success).toBe(true)
	})

	it('should fail validation with invalid data', () => {
		const object = new Object(mockObjectData()[1])

		expect(object).toBeInstanceOf(Object)
		expect(object.validate().success).toBe(false)
		expect(object.validate().error?.issues).toContainEqual(expect.objectContaining({
			path: ['id'],
			message: 'String must contain at least 1 character(s)',
		}))
	})
})
