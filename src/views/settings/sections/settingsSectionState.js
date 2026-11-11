/**
 * What the settings sections read from the settings store alike.
 *
 * @spec exclude Client-side section state; the settings contract lives in the settings store.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { mapStores } from 'pinia'
import { useSettingsStore } from '../../../store/settings.js'

/**
 * A mixin with the store, its busy states and the rebase dialog.
 */
export const settingsSectionState = {
	computed: {
		...mapStores(useSettingsStore),

		/**
		 * @spec exclude busy state of the store, UI plumbing
		 */
		loading() {
			return this.settingsStore.loading
		},

		/**
		 * @spec exclude busy state of the store, UI plumbing
		 */
		saving() {
			return this.settingsStore.saving
		},

		/**
		 * @spec exclude busy state of the store, UI plumbing
		 */
		rebasing() {
			return this.settingsStore.rebasing
		},

		/**
		 * Props and listeners for SectionSaveActions: the busy states, the
		 * rebase dialog and the section's own saveSettings.
		 *
		 * @spec exclude UI plumbing — binding object for the shared section actions
		 * @return {object}
		 */
		saveActions() {
			return {
				loading: this.loading,
				saving: this.saving,
				rebasing: this.rebasing,
				onRebase: this.showRebaseDialog,
				onSave: this.saveSettings,
			}
		},
	},

	methods: {
		/**
		 * @spec exclude store passthrough opening the rebase dialog, UI plumbing
		 */
		showRebaseDialog() {
			this.settingsStore.showRebaseDialog()
		},
	},
}
