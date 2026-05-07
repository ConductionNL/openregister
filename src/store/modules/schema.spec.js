/* eslint-disable no-console */
import { setActivePinia, createPinia } from 'pinia'

import { useSchemaStore } from './schema.js'
import { Schema, mockSchema } from '../../entities/index.js'

describe('Schema Store', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('wraps the active schema in a Schema entity', () => {
		const store = useSchemaStore()
		const item = mockSchema()[0]

		store.setSchemaItem(item)

		expect(store.schemaItem).toBeInstanceOf(Schema)
		// Field-by-field, not deep equal: the mock generates new Date()
		// timestamps so two invocations differ in ms.
		expect(store.schemaItem.id).toBe(item.id)
		expect(store.schemaItem.title).toBe(item.title)
		expect(store.schemaItem.slug).toBe(item.slug)
		expect(store.schemaItem.validate().success).toBe(true)
	})

	it('clears the active schema when given null', () => {
		const store = useSchemaStore()
		store.setSchemaItem(mockSchema()[0])
		store.setSchemaItem(null)
		expect(store.schemaItem).toBeFalsy()
	})

	it('stores the schema list with stable length', () => {
		const store = useSchemaStore()
		const items = mockSchema()

		store.setSchemaList(items)

		expect(store.schemaList).toHaveLength(items.length)
		// setSchemaList intentionally spreads the schema rather than
		// wrapping it (it adds a `showProperties` UI flag), so don't
		// assert constructor identity here.
		store.schemaList.forEach((item, index) => {
			expect(item.id).toBe(items[index].id)
			expect(item.showProperties).toBe(false)
		})
	})
})
