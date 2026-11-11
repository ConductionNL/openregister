/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * The list watcher shared by the log index views: once the shown page of
 * trails changes, re-emit the counts to the sidebar on the next tick.
 *
 * @spec exclude list-view watcher; re-emits counts when the trail list changes
 */
export const recountOnChange = {
	/**
	 * Re-emit the counts after the DOM has caught up with the new list.
	 *
	 * @spec exclude list-view watcher; re-emits counts when the trail list changes
	 * @return {void}
	 */
	handler() {
		this.$nextTick(() => {
			this.updateCounts()
		})
	},

	deep: false,
}
