/**
 * Paging over a list its store fetches page by page.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec exclude list-view pagination handlers shared by the store-paged lists.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

/**
 * A mixin with the two paginator handlers of a store-paged list: each asks
 * the store for the page, then clears the selection.
 *
 * @param {object} paging How the list pages
 * @param {function(object): Promise<void>} paging.fetch Fetches `{ page, limit }`
 * @param {function(): number} paging.limit The store's current page size
 * @param {string} paging.selection The data field holding the selection
 * @param {boolean} [paging.logPageError] Log a failed page change (default true)
 * @return {object} The mixin
 */
export function storePaging({ fetch, limit, selection, logPageError = true }) {
	return {
		methods: {
			/**
			 * Handle page change from pagination component
			 *
			 * @spec exclude list-view pagination page-change handler
			 * @param {number} page - The page number to change to
			 * @return {Promise<void>}
			 */
			async onPageChanged(page) {
				try {
					await fetch({ page, limit: limit() })
					// Clear selection when page changes
					this[selection] = []
				} catch (error) {
					if (logPageError) {
						// eslint-disable-next-line no-console
						console.error('Error loading page:', error)
					}
				}
			},

			/**
			 * Handle page size change from pagination component
			 *
			 * @spec exclude list-view pagination page-size-change handler
			 * @param {number} pageSize - The new page size
			 * @return {Promise<void>}
			 */
			async onPageSizeChanged(pageSize) {
				try {
					await fetch({ page: 1, limit: pageSize })
					// Clear selection when page size changes
					this[selection] = []
				} catch (error) {
					// eslint-disable-next-line no-console
					console.error('Error changing page size:', error)
				}
			},
		},
	}
}
