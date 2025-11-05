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
		organisations: true,
		search: true,
		deleted: true,
		logs: true,
		searchTrail: true,
		auditTrail: true,
		chat: true,
	},
	}),
	actions: {
		setSidebarState(sidebar, state) {
			this.sidebarState[sidebar] = state
		},
		setSelected(selected) {
			this.selected = selected
		},
		setSelectedCatalogus(selectedCatalogus) {
			this.selectedCatalogus = selectedCatalogus
		},
		setModal(modal) {
			this.modal = modal
		},
		setDialog(dialog) {
			console.log('NavigationStore - setDialog() called with:', dialog)
			this.dialog = dialog
		},
		setTransferData(data) {
			console.log('NavigationStore - setTransferData() called with:', data)
			this.transferData = data
			console.log('NavigationStore - transferData set to:', this.transferData)
		},
		getTransferData() {
			console.log('NavigationStore - getTransferData() called, returning:', this.transferData)
			return this.transferData
		},
		clearTransferData() {
			console.log('NavigationStore - clearTransferData() called')
			this.transferData = null
		},
	},
})
