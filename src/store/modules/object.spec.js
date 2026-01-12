/* eslint-disable no-console */
import { setActivePinia, createPinia } from 'pinia'

import { useObjectStore } from './object.js'
import { ObjectEntity, mockObject } from '../../entities/index.js'

describe('Object Store', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('sets object item correctly', () => {
		const store = useObjectStore()

		store.setObjectItem(mockObject()[0])

		expect(store.objectItem).toBeInstanceOf(ObjectEntity)
		expect(store.objectItem).toEqual(mockObject()[0])

		expect(store.objectItem.validate().success).toBe(true)
	})

	it('sets object list correctly', () => {
		const store = useObjectStore()

		store.setObjectList(mockObject())

		expect(store.objectList).toHaveLength(mockObject().length)

		store.objectList.forEach((item, index) => {
			expect(item).toBeInstanceOf(ObjectEntity)
			expect(item).toEqual(mockObject()[index])
			expect(item.validate().success).toBe(true)
		})
	})
})
