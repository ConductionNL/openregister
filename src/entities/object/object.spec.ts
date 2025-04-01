/* eslint-disable @typescript-eslint/no-explicit-any */
import { ObjectEntity } from './object'
import { mockObjectData } from './object.mock'
import { TObject } from './object.types'

describe('Object Entity', () => {
	it('should create an Object entity with full data', () => {
		const mockData = mockObjectData()[0]
		const object = new ObjectEntity(mockData)

		expect(object).toBeInstanceOf(Object)
		expect(object['@self']).toEqual(mockData['@self'])
		expect(object.validate().success).toBe(true)
	})

	it('should create an Object entity with partial data', () => {
		const partialData: TObject = {
			'@self': {
				id: '',
				uri: 'test-uri',
				register: 'test-register',
				schema: 'test-schema',
				relations: '',
				files: '',
				folder: '',
				updated: '',
				created: '',
				locked: null,
				owner: '',
				organisation: null,
				application: null,
				version: null,
				deleted: null,
				geo: null,
				retention: null,
			},
		}
		const object = new ObjectEntity(partialData)

		expect(object).toBeInstanceOf(Object)
		expect(object['@self'].id).toBe('')
		expect(object['@self'].uri).toBe('test-uri')
		expect(object['@self'].register).toBe('test-register')
		expect(object['@self'].schema).toBe('test-schema')
		expect(object['@self'].relations).toBe('')
		expect(object['@self'].files).toBe('')
		expect(object['@self'].updated).toBe('')
		expect(object['@self'].created).toBe('')
		expect(object['@self'].locked).toBe(null)
		expect(object['@self'].owner).toBe('')
		expect(object.validate().success).toBe(true)
	})

	it('should handle locked array and owner string', () => {
		const mockData = mockObjectData()[0]
		mockData['@self'].locked = ['token1', 'token2']
		mockData['@self'].owner = 'user1'
		const object = new ObjectEntity(mockData)

		expect(object['@self'].locked).toEqual(['token1', 'token2'])
		expect(object['@self'].owner).toBe('user1')
		expect(object.validate().success).toBe(true)
	})

	it('should support additional properties outside @self', () => {
		const mockData: TObject = {
			'@self': mockObjectData()[0]['@self'],
			customField: 'custom value',
			nestedField: {
				key: 'value',
			},
		}
		const object = new ObjectEntity(mockData)

		expect(object.customField).toBe('custom value')
		expect(object.nestedField).toEqual({ key: 'value' })
		expect(object.validate().success).toBe(true)
	})

	it('should fail validation with invalid @self data', () => {
		const invalidData: TObject = {
			'@self': {
				...mockObjectData()[0]['@self'],
				id: '', // Invalid empty id
			},
		}
		const object = new ObjectEntity(invalidData)

		const validation = object.validate()
		expect(validation.success).toBe(false)

		if (!validation.success) {
			expect(validation.error.issues).toContainEqual(
				expect.objectContaining({
					path: ['@self', 'id'],
					message: 'String must contain at least 1 character(s)',
				}),
			)
		}
	})

	it('should handle null values in @self properly', () => {
		const mockData = mockObjectData()[0]
		mockData['@self'].locked = null
		const object = new ObjectEntity(mockData)

		expect(object['@self'].locked).toBeNull()
		expect(object.validate().success).toBe(true)
	})

	it('should create empty strings for undefined @self properties', () => {
		const minimalData: TObject = {
			'@self': {
				id: '',
				uri: 'test-uri',
				register: 'test-register',
				schema: 'test-schema',
				relations: '',
				files: '',
				folder: '',
				updated: '',
				created: '',
				locked: null,
				owner: '',
				organisation: null,
				application: null,
				version: null,
				deleted: null,
				geo: null,
				retention: null,
			},
		}
		const object = new ObjectEntity(minimalData)

		expect(object['@self'].id).toBe('')
		expect(object['@self'].folder).toBe('')
		expect(object.validate().success).toBe(true)
	})
})
