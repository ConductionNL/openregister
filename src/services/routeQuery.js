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

/**
 * Whether two shallow queries have the same keys and the same values as
 * strings.
 *
 * @param {object} a A query
 * @param {object|undefined} b The other query
 * @return {boolean} Whether they match
 *
 * @spec exclude Sidebar filter-state to route-query plumbing; no backend contract.
 */
export function queriesEqual(a, b) {
	const keys = Object.keys(a || {}).sort()
	const others = Object.keys(b || {}).sort()
	return (
		keys.length === others.length
		&& keys.every(
			(key, i) => key === others[i] && String(a[key]) === String(b[key]),
		)
	)
}

/**
 * A date from the route, or null when it is missing or not a valid date.
 *
 * @param {string|undefined} value The ISO string
 * @return {Date|null} The date
 *
 * @spec exclude Sidebar route-query to filter-state plumbing; no backend contract.
 */
export function routeDate(value) {
	return value && new Date(value).getDate() ? new Date(value) : null
}

/**
 * The values of a comma-separated route parameter, trimmed, empty ones left out.
 *
 * @param {string} text The parameter
 * @return {Array<string>} The values
 *
 * @spec exclude Sidebar route-query to filter-state plumbing; no backend contract.
 */
export function csvValues(text) {
	return text
		.split(',')
		.map((value) => value.trim())
		.filter(Boolean)
}
