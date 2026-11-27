/**
 * The Tables page's column sort as the object list query reads it.
 *
 * A header click used to be stored as `sortKey` / `sortOrder` in the search
 * params, and the search store sends every param it holds to
 * `GET /api/objects/{register}/{schema}`. The API does not know those two
 * names, reads them as property filters, and answers an EMPTY list: clicking a
 * column header emptied the table. The API sorts on `_order` (sent as
 * `_order={"title":"asc"}`), so the sort is stored as that, and the header
 * state is read back from it.
 *
 * @spec openspec/changes/case-insensitive-ordering/specs/case-insensitive-ordering/spec.md
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

/**
 * The search params for a header sort: `_order` with one column, or none.
 *
 * @param {string|null} key The column key, or null for the default order.
 * @param {string} order `asc` or `desc`; anything else sorts ascending.
 * @return {{_order: object|null}} The params to merge into the search.
 * @spec openspec/changes/case-insensitive-ordering/specs/case-insensitive-ordering/spec.md
 */
export function orderParams(key, order) {
	if (!key) {
		return { _order: null }
	}

	return { _order: { [key]: order === 'desc' ? 'desc' : 'asc' } }
}

/**
 * The header state that matches the `_order` the search holds.
 *
 * @param {object} params The search params.
 * @return {{sortKey: string|null, sortOrder: string}} The header state.
 * @spec openspec/changes/case-insensitive-ordering/specs/case-insensitive-ordering/spec.md
 */
export function headerSortOf(params) {
	let order = params?._order
	if (typeof order === 'string') {
		try {
			order = JSON.parse(order)
		} catch {
			order = null
		}
	}

	if (!order || typeof order !== 'object' || Array.isArray(order)) {
		return { sortKey: null, sortOrder: 'asc' }
	}

	const [key, direction] = Object.entries(order)[0] || [null, 'asc']
	return {
		sortKey: key,
		sortOrder: String(direction).toLowerCase() === 'desc' ? 'desc' : 'asc',
	}
}
