/* eslint-disable no-console */
import { defineStore } from 'pinia'

export const useNavigationStore = defineStore('ui', {
	state: () => ({
		// The currently active menu item, defaults to '' which triggers the dashboard
		selected: 'dashboard',
		// The currently active modal, managed trough the state to ensure that only one modal can be active at the same time
		modal: false,
		// The currently active dialog
		dialog: false,
		// Any data needed in various models, dialogs, views which cannot be transferred through normal means or without writing crappy/excessive code
		transferData: null,

		sidebarState: {
			registers: true,
			register: true,
			search: true,
		},
	}),
	actions: {
		setSidebarState(sidebar, state) {
			this.sidebarState[sidebar] = state
		},
		setSelected(selected) {
			this.selected = selected
			console.log('Active menu item set to ' + selected)
		},
		setSelectedCatalogus(selectedCatalogus) {
			this.selectedCatalogus = selectedCatalogus
			console.log('Active catalogus menu set to ' + selectedCatalogus)
		},
		setModal(modal) {
			this.modal = modal
			console.log('Active modal set to ' + modal)
		},
		setDialog(dialog) {
			this.dialog = dialog
			console.log('Active dialog set to ' + dialog)
		},
		setTransferData(data) {
			this.transferData = data
		},
		getTransferData() {
			const tempData = this.transferData
			this.transferData = null
			return tempData
		},
	},
})
