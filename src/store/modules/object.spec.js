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
		const item = mockObject()[0]

		store.setObjectItem(item)

		expect(store.objectItem).toBeInstanceOf(ObjectEntity)
		// Compare structural fields rather than deep-equal: the mock's
		// `updated`/`created` use new Date().toISOString() which drifts
		// in ms between calls, and ObjectEntity wraps to its prototype.
		expect(store.objectItem['@self'].uuid).toBe(item['@self'].uuid)
		expect(store.objectItem['@self'].id).toBe(item['@self'].id)
	})

	it('clears object item when given null', () => {
		const store = useObjectStore()
		store.setObjectItem(mockObject()[0])
		store.setObjectItem(null)
		expect(store.objectItem).toBe(false)
	})
})
