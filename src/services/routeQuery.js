/**
 * Building blocks for the route query a list sidebar keeps its filters in.
 * Each one adds its keys to the query in a fixed order and leaves out the
 * filters that are not set.
 *
 * @spec exclude Sidebar filter-state to route-query plumbing; no backend contract.
 */

/**
 * A date as an ISO string, or null when it is not a valid date.
 *
 * @param {string|Date} value The date.
 * @return {string|null} The ISO string, or null.
 */
function isoDateOrNull(value) {
	return new Date(value).getDate() ? new Date(value).toISOString() : null
}

/**
 * Add the selected register and schema, by id, when they have one.
 *
 * @param {object} query The query being built.
 * @param {{register: object|null, schema: object|null}} scope The selected register and schema.
 *
 * @spec exclude Sidebar filter-state to route-query plumbing; no backend contract.
 */
export function addScope(query, scope) {
	if (scope.register && scope.register.id) {
		query.register = String(scope.register.id)
	}
	if (scope.schema && scope.schema.id) {
		query.schema = String(scope.schema.id)
	}
}

/**
 * Add the date range: each date that is set, as an ISO string or null.
 *
 * @param {object} query The query being built.
 * @param {{dateFrom: *, dateTo: *}} range The range.
 *
 * @spec exclude Sidebar filter-state to route-query plumbing; no backend contract.
 */
export function addDateRange(query, range) {
	if (range.dateFrom) query.dateFrom = isoDateOrNull(range.dateFrom)
	if (range.dateTo) query.dateTo = isoDateOrNull(range.dateTo)
}

/**
 * Add each value that is set, as a string.
 *
 * @param {object} query The query being built.
 * @param {Array<[string, *]>} pairs Key and value pairs, in query order.
 *
 * @spec exclude Sidebar filter-state to route-query plumbing; no backend contract.
 */
export function addStrings(query, pairs) {
	for (const [key, value] of pairs) {
		if (value) query[key] = String(value)
	}
}
