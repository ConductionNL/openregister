/**
 * Paging over a list held in full on the client.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec exclude Client-side paging shared by the list pages.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

/**
 * A mixin holding `pagination: { page, limit }` and the two paginator
 * handlers. The page slices its own list from it.
 */
export const localPagination = {
	data() {
		return {
			pagination: {
				page: 1,
				limit: 20,
			},
		}
	},

	methods: {
		/**
		 * Handle a page change from the paginator.
		 *
		 * @spec exclude UI plumbing — pagination state mutation; admin list contract owned by admin-list-views.
		 * @param {number} page - new page number
		 * @return {void}
		 */
		onPageChanged(page) {
			this.pagination.page = page
		},

		/**
		 * Handle a page-size change from the paginator.
		 *
		 * @spec exclude UI plumbing — pagination state mutation; admin list contract owned by admin-list-views.
		 * @param {number} pageSize - new page size
		 * @return {void}
		 */
		onPageSizeChanged(pageSize) {
			this.pagination.page = 1
			this.pagination.limit = pageSize
		},
	},
}
