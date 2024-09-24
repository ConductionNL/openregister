/* eslint-disable no-console */
import { setActivePinia, createPinia } from 'pinia'

import { useSchemaStore } from './schema.js'
import { Schema, mockSchema } from '../../entities/index.js'

describe('Schema Store', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('sets schema item correctly', () => {
		const store = useSchemaStore()

		store.setSchemaItem(mockSchema()[0])

		expect(store.schemaItem).toBeInstanceOf(Schema)
		expect(store.schemaItem).toEqual(mockSchema()[0])

		expect(store.schemaItem.validate().success).toBe(true)
	})

	it('sets schema list correctly', () => {
		const store = useSchemaStore()

		store.setSchemaList(mockSchema())

		expect(store.schemaList).toHaveLength(mockSchema().length)

		store.schemaList.forEach((item, index) => {
			expect(item).toBeInstanceOf(Schema)
			expect(item).toEqual(mockSchema()[index])
			expect(item.validate().success).toBe(true)
		})
	})

	// ... other tests ...
})
