/* eslint-disable no-console */
import { setActivePinia, createPinia } from 'pinia'

import { useSourceStore } from './source.js'
import { Source, mockSource } from '../../entities/index.js'

describe('Source Store', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('wraps the active source in a Source entity', () => {
		const store = useSourceStore()
		const item = mockSource()[0]

		store.setSourceItem(item)

		expect(store.sourceItem).toBeInstanceOf(Source)
		expect(store.sourceItem.id).toBe(item.id)
		expect(store.sourceItem.title).toBe(item.title)
		expect(store.sourceItem.validate().success).toBe(true)
	})

	it('clears the active source when given null', () => {
		const store = useSourceStore()
		store.setSourceItem(mockSource()[0])
		store.setSourceItem(null)
		expect(store.sourceItem).toBeFalsy()
	})

	it('wraps every entry of the list in a Source entity', () => {
		const store = useSourceStore()
		const items = mockSource()

		store.setSourceList(items)

		expect(store.sourceList).toHaveLength(items.length)
		store.sourceList.forEach((item, index) => {
			expect(item).toBeInstanceOf(Source)
			expect(item.id).toBe(items[index].id)
			expect(item.validate().success).toBe(true)
		})
	})
})
