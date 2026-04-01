/* eslint-disable no-console */
import { setActivePinia, createPinia } from 'pinia'

import { useSchemaStore } from './schema.js'
import { Schema, mockSchema } from '../../entities/index.js'

describe('Schema Store', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('sets item correctly', () => {
		const store = useSchemaStore()
		const mockData = mockSchema()

		store.setItem(mockData[0])

		expect(store.item).toBeInstanceOf(Schema)
		expect(store.item).toEqual(mockData[0])
	})

	it('sets list with showProperties toggle', () => {
		const store = useSchemaStore()
		const mockData = mockSchema()

		store.setList(mockData)

		expect(store.list).toHaveLength(mockData.length)

		store.list.forEach((item) => {
			// Schema store overrides setList — items are plain objects with showProperties
			expect(item.showProperties).toBe(false)
			expect(typeof item.properties).toBe('object')
			expect(Array.isArray(item.properties)).toBe(false)
		})
	})

	it('preserves existing showProperties when re-setting list', () => {
		const store = useSchemaStore()
		const mockData = mockSchema()

		// First set
		store.setList(mockData)
		// Manually toggle showProperties on first item
		store.list[0].showProperties = true

		// Re-set list with same data
		store.setList(mockData)

		expect(store.list[0].showProperties).toBe(true)
	})

	it('normalizes array properties to object', () => {
		const store = useSchemaStore()

		store.setList([{ id: 1, name: 'Test', properties: ['prop1', 'prop2'] }])

		expect(store.list[0].properties).toEqual({})
	})

	it('handles null item correctly', () => {
		const store = useSchemaStore()

		store.setItem(null)

		expect(store.item).toBeNull()
	})

	it('has correct initial state', () => {
		const store = useSchemaStore()

		expect(store.item).toBeNull()
		expect(store.list).toEqual([])
		expect(store.schemaPropertyKey).toBeNull()
		expect(store.viewMode).toBe('cards')
	})

	it('sets schema property key', () => {
		const store = useSchemaStore()

		store.setSchemaPropertyKey('testKey')

		expect(store.schemaPropertyKey).toBe('testKey')
	})

	it('cleanForSave handles configuration defaults and required conversion', () => {
		const store = useSchemaStore()
		const schemaItem = {
			id: 1,
			title: 'Test Schema',
			created: '2024-01-01',
			updated: '2024-01-02',
			stats: { objects: 5 },
			version: '1.0',
			required: ['name', 'email'],
			properties: {
				name: { type: 'string' },
				email: { type: 'string' },
			},
		}

		const cleaned = store.cleanForSave(schemaItem)

		// Strips read-only fields
		expect(cleaned.created).toBeUndefined()
		expect(cleaned.updated).toBeUndefined()
		expect(cleaned.stats).toBeUndefined()
		expect(cleaned.version).toBeUndefined()

		// Converts required array to property flags
		expect(cleaned.required).toBeUndefined()
		expect(cleaned.properties.name.required).toBe(true)
		expect(cleaned.properties.email.required).toBe(true)

		// Adds default configuration
		expect(cleaned.configuration).toEqual({ objectNameField: '', objectDescriptionField: '' })
	})
})
