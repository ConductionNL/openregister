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
				uuid: 'test-uuid',
				uri: 'test-uri',
				version: null,
				register: 'test-register',
				schema: 'test-schema',
				schemaVersion: null,
				relations: null,
				files: null,
				folder: null,
				textRepresentation: null,
				locked: null,
				owner: null,
				authorization: null,
				application: null,
				organisation: null,
				validation: null,
				deleted: null,
				geo: null,
				retention: null,
				size: null,
				updated: '2023-01-01T00:00:00Z',
				created: '2023-01-01T00:00:00Z',
				published: null,
				depublished: null,
			},
		}
		const object = new ObjectEntity(partialData)

		expect(object).toBeInstanceOf(Object)
		expect(object['@self'].id).toBe('')
		expect(object['@self'].uuid).toBe('test-uuid')
		expect(object['@self'].uri).toBe('test-uri')
		expect(object['@self'].register).toBe('test-register')
		expect(object['@self'].schema).toBe('test-schema')
		expect(object['@self'].relations).toBe(null)
		expect(object['@self'].files).toBe(null)
		expect(object['@self'].updated).toBe('2023-01-01T00:00:00Z')
		expect(object['@self'].created).toBe('2023-01-01T00:00:00Z')
		expect(object['@self'].published).toBe(null)
		expect(object['@self'].depublished).toBe(null)
		expect(object['@self'].locked).toBe(null)
		expect(object['@self'].owner).toBe(null)
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
		mockData['@self'].published = null
		mockData['@self'].depublished = null
		mockData['@self'].validation = null
		mockData['@self'].authorization = null
		const object = new ObjectEntity(mockData)

		expect(object['@self'].locked).toBeNull()
		expect(object['@self'].published).toBeNull()
		expect(object['@self'].depublished).toBeNull()
		expect(object['@self'].validation).toBeNull()
		expect(object['@self'].authorization).toBeNull()
		expect(object.validate().success).toBe(true)
	})

	it('should create empty strings for undefined @self properties', () => {
		const minimalData: TObject = {
			'@self': {
				id: '',
				uuid: 'test-uuid',
				uri: 'test-uri',
				version: null,
				register: 'test-register',
				schema: 'test-schema',
				schemaVersion: null,
				relations: null,
				files: null,
				folder: null,
				textRepresentation: null,
				locked: null,
				owner: null,
				authorization: null,
				application: null,
				organisation: null,
				validation: null,
				deleted: null,
				geo: null,
				retention: null,
				size: null,
				updated: '2023-01-01T00:00:00Z',
				created: '2023-01-01T00:00:00Z',
				published: null,
				depublished: null,
			},
		}
		const object = new ObjectEntity(minimalData)

		expect(object['@self'].id).toBe('')
		expect(object['@self'].folder).toBe(null)
		expect(object['@self'].published).toBe(null)
		expect(object['@self'].depublished).toBe(null)
		expect(object['@self'].validation).toBe(null)
		expect(object.validate().success).toBe(true)
	})
})
