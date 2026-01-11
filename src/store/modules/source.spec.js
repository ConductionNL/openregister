/* eslint-disable no-console */
import { setActivePinia, createPinia } from 'pinia'

import { useSourceStore } from './source.js'
import { Source, mockSource } from '../../entities/index.js'

describe('Source Store', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('sets source item correctly', () => {
		const store = useSourceStore()

		store.setSourceItem(mockSource()[0])

		expect(store.sourceItem).toBeInstanceOf(Source)
		expect(store.sourceItem).toEqual(mockSource()[0])

		expect(store.sourceItem.validate().success).toBe(true)
	})

	it('sets source list correctly', () => {
		const store = useSourceStore()

		store.setSourceList(mockSource())

		expect(store.sourceList).toHaveLength(mockSource().length)

		store.sourceList.forEach((item, index) => {
			expect(item).toBeInstanceOf(Source)
			expect(item).toEqual(mockSource()[index])
			expect(item.validate().success).toBe(true)
		})
	})

	// ... other tests ...
})
