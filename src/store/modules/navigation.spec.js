/* eslint-disable no-console */
import { setActivePinia, createPinia } from 'pinia'

import { useNavigationStore } from './navigation.js'

describe('Navigation Store', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('set current selected view correctly', () => {
		const store = useNavigationStore()

		store.setSelected('publication')
		expect(store.selected).toBe('publication')

		store.setSelected('catalogi')
		expect(store.selected).toBe('catalogi')

		store.setSelected('metadata')
		expect(store.selected).toBe('metadata')
	})

	it('set current selected publication catalogi correctly', () => {
		const store = useNavigationStore()

		store.setSelectedCatalogus('7a048bfd-210f-4e93-a1e8-5aa9261740b7')
		expect(store.selectedCatalogus).toBe('7a048bfd-210f-4e93-a1e8-5aa9261740b7')

		store.setSelectedCatalogus('dd133c51-89bc-4b06-bdbb-41f4dc07c4f1')
		expect(store.selectedCatalogus).toBe('dd133c51-89bc-4b06-bdbb-41f4dc07c4f1')

		store.setSelectedCatalogus('3b1cbee2-756e-4904-a157-29fb0cbe01d3')
		expect(store.selectedCatalogus).toBe('3b1cbee2-756e-4904-a157-29fb0cbe01d3')
	})

	it('set modal correctly', () => {
		const store = useNavigationStore()

		store.setModal('editPublication')
		expect(store.modal).toBe('editPublication')

		store.setModal('editCatalogi')
		expect(store.modal).toBe('editCatalogi')

		store.setModal('editMetadata')
		expect(store.modal).toBe('editMetadata')
	})

	it('set modal correctly', () => {
		const store = useNavigationStore()

		store.setDialog('deletePublication')
		expect(store.dialog).toBe('deletePublication')

		store.setDialog('deleteCatalogi')
		expect(store.dialog).toBe('deleteCatalogi')

		store.setDialog('deleteMetadata')
		expect(store.dialog).toBe('deleteMetadata')
	})
})
