/**
 * The list-state actions the admin list stores share: the page and page size,
 * and the header filters.
 *
 * @spec exclude Pure client UI-state setters — list pagination cursor and filter criteria. No backend contract.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

/**
 * The `setPagination` and `setFilters` actions of a list store.
 *
 * @param {number} defaultLimit The page size when `setPagination` gets none.
 * @return {{setPagination: function(number, number=): void, setFilters: function(object): void}} The actions, to spread into the store's `actions`.
 * @spec exclude Pure client UI-state setters — list pagination cursor and filter criteria. No backend contract.
 */
export function listStateActions(defaultLimit) {
	return {
		/**
		 * Set pagination details.
		 *
		 * @param {number} page The current page number.
		 * @param {number} limit The number of items per page.
		 */
		setPagination(page, limit = defaultLimit) {
			this.pagination = { page, limit }
		},
		/**
		 * Merge filter criteria into the list's filters.
		 *
		 * @param {object} filters The filter criteria to apply.
		 */
		setFilters(filters) {
			this.filters = { ...this.filters, ...filters }
		},
	}
}
