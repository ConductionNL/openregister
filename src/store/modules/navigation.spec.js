/* eslint-disable no-console */
import { setActivePinia, createPinia } from 'pinia'

import { useNavigationStore } from './navigation.js'

describe('Navigation Store', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('has correct initial state', () => {
		const store = useNavigationStore()

		expect(store.selected).toBe('dashboard')
		expect(store.modal).toBe(false)
		expect(store.dialog).toBe(false)
		expect(store.transferData).toBeNull()
	})

	it('sets current selected view correctly', () => {
		const store = useNavigationStore()

		store.setSelected('publication')
		expect(store.selected).toBe('publication')

		store.setSelected('catalogi')
		expect(store.selected).toBe('catalogi')

		store.setSelected('metadata')
		expect(store.selected).toBe('metadata')
	})

	it('sets modal correctly', () => {
		const store = useNavigationStore()

		store.setModal('editPublication')
		expect(store.modal).toBe('editPublication')

		store.setModal('editCatalogi')
		expect(store.modal).toBe('editCatalogi')

		store.setModal('editMetadata')
		expect(store.modal).toBe('editMetadata')
	})

	it('sets dialog correctly', () => {
		const store = useNavigationStore()

		store.setDialog('deletePublication')
		expect(store.dialog).toBe('deletePublication')

		store.setDialog('deleteCatalogi')
		expect(store.dialog).toBe('deleteCatalogi')

		store.setDialog('deleteMetadata')
		expect(store.dialog).toBe('deleteMetadata')
	})

	it('sets and clears transfer data', () => {
		const store = useNavigationStore()
		const data = { id: 1, name: 'Test' }

		store.setTransferData(data)
		expect(store.transferData).toEqual(data)

		expect(store.getTransferData()).toEqual(data)

		store.clearTransferData()
		expect(store.transferData).toBeNull()
	})

	it('sets sidebar state', () => {
		const store = useNavigationStore()

		expect(store.sidebarState.registers).toBe(true)

		store.setSidebarState('registers', false)
		expect(store.sidebarState.registers).toBe(false)

		store.setSidebarState('registers', true)
		expect(store.sidebarState.registers).toBe(true)
	})

	it('has all sidebar sections in initial state', () => {
		const store = useNavigationStore()

		const expectedSections = [
			'registers', 'register', 'organisations', 'search',
			'deleted', 'logs', 'searchTrail', 'auditTrail', 'chat',
		]

		expectedSections.forEach((section) => {
			expect(store.sidebarState[section]).toBe(true)
		})
	})
})
