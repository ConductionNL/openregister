/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */
import { qualityStore } from '../../store/store.js'

/**
 * A mixin for the data quality pages that read one register and schema: the
 * selection and loading flags, a first load when a selection exists, and a
 * reload whenever the selection changes. The page supplies `loadData()`; it
 * can override `selectionChanged()` to reset its own state first.
 *
 * @spec exclude UI plumbing shared by the quality pages; the store actions carry the @spec tags
 */
export const qualitySelection = {
	computed: {
		/**
		 * @spec exclude UI plumbing — proxies the store's hasSelection getter, no backend contract of its own
		 */
		hasSelection() {
			return qualityStore.hasSelection
		},

		/**
		 * @spec exclude UI plumbing — proxies the store's loading flag, no backend contract of its own
		 */
		loading() {
			return qualityStore.isLoading
		},
	},

	/**
	 * @spec openspec/specs/mdm-frontend/spec.md
	 */
	mounted() {
		if (this.hasSelection) {
			this.loadData()
		}
		this.$watch(
			() => [qualityStore.selectedRegister, qualityStore.selectedSchema],
			() => this.selectionChanged(),
		)
	},

	methods: {
		/**
		 * Reload for the new register and schema.
		 *
		 * @spec exclude UI plumbing — delegates to the page's loadData
		 */
		selectionChanged() {
			this.loadData()
		},
	},
}
