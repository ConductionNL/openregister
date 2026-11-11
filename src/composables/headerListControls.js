/**
 * The header sort and filter state of a list view that loads its whole list
 * and sorts, filters and pages it client-side (the register and schema
 * lists). Spread `headerListData()` into `data` and
 * `headerListMethods(() => store)` into `methods`.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { applyFilterChange } from '../services/listFilter.js'
import { BUILTIN_ACTIONS_OFF } from '../views/indexPageOptions.js'

/**
 * The view's sort and filter state.
 *
 * @return {object} The data fields
 */
export function headerListData() {
	return {
		builtinActionsOff: BUILTIN_ACTIONS_OFF,
		isRefreshing: false,
		// The header the list is sorted by; null keeps the list's own order.
		sortKey: null,
		sortOrder: 'asc',
		// The header filters, as `{ paramKey: values[] }` (`title[like]`).
		activeFilters: {},
	}
}

/**
 * The sort and filter handlers, which send paging back to page 1 of the
 * list's store.
 *
 * @param {function(): object} storeOf Returns the list's store, with `pagination` and `setPagination`
 * @return {{onSort: function(object): void, onFilterChange: function(object): void}} The two methods
 */
export function headerListMethods(storeOf) {
	/**
	 * Back to page 1, keeping the page size.
	 *
	 * @return {void}
	 */
	function firstPage() {
		const store = storeOf()
		store.setPagination(1, store.pagination.limit || 20)
	}
	return {
		/**
		 * Sort by a clicked header. The list is loaded whole, so it is sorted
		 * here, and paging goes back to page 1 so the top of the new order shows.
		 *
		 * @param {{key: string, order: string}} payload The header and direction.
		 * @return {void}
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md
		 * @spec openspec/specs/admin-list-views/spec.md#requirement-openregister-s-schemas-and-registers-lists-must-filter-from-their-column-headers
		 */
		onSort(payload) {
			this.sortKey = payload && payload.key ? payload.key : null
			this.sortOrder = payload && payload.order === 'desc' ? 'desc' : 'asc'
			firstPage()
		},

		/**
		 * Apply a header filter on the loaded list and go back to page 1.
		 *
		 * @param {{key: string, values: Array}} payload The filter change.
		 * @return {void}
		 *
		 * @spec openspec/specs/admin-list-views/spec.md#requirement-openregister-s-schemas-and-registers-lists-must-filter-from-their-column-headers
		 */
		onFilterChange(payload) {
			this.activeFilters = applyFilterChange(this.activeFilters, payload)
			firstPage()
		},
	}
}
