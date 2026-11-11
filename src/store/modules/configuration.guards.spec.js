import { createPinia, setActivePinia } from 'pinia'
import { useConfigurationStore } from './configuration.js'

describe('configuration store — input guards', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		global.fetch = jest.fn()
		store = useConfigurationStore()
	})

	afterEach(() => {
		delete global.fetch
	})

	it('deleteConfiguration refuses an item without an id before any request', async () => {
		await expect(store.deleteConfiguration({ title: 'no id' })).rejects.toThrow('No configuration item to delete')
		expect(global.fetch).not.toHaveBeenCalled()
	})

	it('importConfiguration refuses a missing file before any request', async () => {
		await expect(store.importConfiguration(null)).rejects.toThrow('No file to import')
		expect(global.fetch).not.toHaveBeenCalled()
	})
})
