/**
 * Paged list reads for the audit trail, search trail and deleted-objects
 * stores. Each store keeps its own search, filters and sort; these helpers
 * turn them into the query the list endpoint reads, fetch the page, and turn
 * the answer back into store pagination.
 *
 * @spec openspec/specs/audit-trail-immutable/spec.md
 */

/**
 * Append each `{ field: direction }` pair as a `sort` and an `order` parameter.
 *
 * @param {URLSearchParams} params The query being built.
 * @param {object|undefined} sort The sort map, or nothing.
 */
function appendSort(params, sort) {
	if (!sort) {
		return
	}
	Object.entries(sort).forEach(([field, direction]) => {
		params.append('sort', field)
		params.append('order', direction)
	})
}

/**
 * The query string of one list page.
 *
 * Paging (`limit`, `offset`, `page`) comes from the options only. Search and
 * sort come from the options, else from the store. Filters are the store's,
 * overlaid with the options' ones; null, undefined and empty values are left out.
 *
 * @param {object} options The caller's `{ limit, offset, page, search, filters, sort }`.
 * @param {object} current The store's `{ search, filters, sort }`.
 * @return {string} The encoded query, without a leading `?`.
 *
 * @spec openspec/specs/audit-trail-immutable/spec.md
 */
export function listPageQuery(options, current) {
	const params = new URLSearchParams()
	;['limit', 'offset', 'page'].forEach((key) => {
		if (options[key]) params.append(key, options[key])
	})
	const search = options.search || current.search
	if (search) {
		params.append('search', search)
	}
	const filters = { ...current.filters, ...options.filters }
	Object.entries(filters).forEach(([key, value]) => {
		if (value !== null && value !== undefined && value !== '') {
			params.append(key, value)
		}
	})
	appendSort(params, options.sort || current.sort)
	return params.toString()
}

/**
 * GET a list endpoint and return its JSON body.
 *
 * @param {string} url The endpoint with its query.
 * @param {string} failure The error message when the body names none.
 * @return {Promise<object>} The response body.
 * @throws {Error} When the response is not ok.
 *
 * @spec openspec/specs/audit-trail-immutable/spec.md
 */
export async function getListPage(url, failure) {
	const response = await fetch(url, {
		method: 'GET',
		headers: {
			'Content-Type': 'application/json',
			requesttoken: OC.requestToken,
		},
	})
	const data = await response.json()
	if (!response.ok) {
		throw new Error(data.error || failure)
	}
	return data
}

/**
 * The store pagination of one list answer.
 *
 * @param {object} data The response body.
 * @param {number} defaultLimit The page size when the body names none.
 * @return {{total: number, page: number, pages: number, limit: number, offset: number}} The pagination.
 *
 * @spec openspec/specs/audit-trail-immutable/spec.md
 */
export function listPagination(data, defaultLimit) {
	const fallback = { total: 0, page: 1, pages: 1, limit: defaultLimit, offset: 0 }
	return Object.fromEntries(
		Object.entries(fallback).map(([key, value]) => [key, data[key] || value]),
	)
}
