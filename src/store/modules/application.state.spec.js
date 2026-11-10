import { createPinia, setActivePinia } from 'pinia'
import { Application } from '../../entities/index.js'
import { useApplicationStore } from './application.js'

describe('application store — client state', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useApplicationStore()
	})

	it('getters reflect the state', () => {
		store.$patch({ loading: true, error: 'e', viewMode: 'table' })
		expect(store.isLoading).toBe(true)
		expect(store.getError).toBe('e')
		expect(store.getViewMode).toBe('table')
		expect(store.getApplicationItem).toBeNull()
	})

	it('setViewMode switches the view mode', () => {
		expect(store.viewMode).toBe('cards')
		store.setViewMode('table')
		expect(store.viewMode).toBe('table')
	})

	it('setApplicationItem wraps the item in an Application and clears error and loading', () => {
		store.error = 'old'
		store.setApplicationItem({ id: 3, uuid: 'u-3', name: 'Zaken' })
		expect(store.applicationItem).toBeInstanceOf(Application)
		expect(store.applicationItem.name).toBe('Zaken')
		expect(store.getApplicationItem.id).toBe(3)
		expect(store.error).toBeNull()
		expect(store.loading).toBe(false)
	})

	it('setApplicationItem with no item clears the active application', () => {
		store.setApplicationItem({ id: 3, name: 'Zaken' })
		store.setApplicationItem(null)
		expect(store.applicationItem).toBeNull()
	})
})
