import { createPinia, setActivePinia } from 'pinia'
import { useApplicationStore } from './application.js'

describe('application store — unreadable application item', () => {
	it('records the error, keeps loading off and does not throw', () => {
		setActivePinia(createPinia())
		const store = useApplicationStore()
		const spy = jest.spyOn(console, 'error').mockImplementation(() => {})
		const unreadable = new Proxy({}, {
			get() {
				throw new Error('item cannot be read')
			},
			ownKeys() {
				throw new Error('item cannot be read')
			},
		})
		expect(() => store.setApplicationItem(unreadable)).not.toThrow()
		expect(store.error).toBe('item cannot be read')
		expect(store.loading).toBe(false)
		expect(store.applicationItem).toBeNull()
		expect(spy).toHaveBeenCalledWith('Error setting application item:', expect.any(Error))
		spy.mockRestore()
	})
})
