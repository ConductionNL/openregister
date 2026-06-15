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
			entities: false,
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
		/**
		 * @param dialog
		 * @spec exclude Pure client UI-state setter — toggles the single active dialog. No backend contract.
		 */
		setDialog(dialog) {
			this.dialog = dialog
		},
		/**
		 * @param data
		 * @spec exclude Pure client UI-state setter — stashes cross-component transfer payload. No backend contract.
		 */
		setTransferData(data) {
			this.transferData = data
		},
		/**
		 * @spec exclude Pure client UI-state getter — returns the stashed transfer payload. No backend contract.
		 */
		getTransferData() {
			return this.transferData
		},
		/**
		 * @spec exclude Pure client UI-state mutator — clears the stashed transfer payload. No backend contract.
		 */
		clearTransferData() {
			this.transferData = null
		},
	},
})
