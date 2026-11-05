/**
 * Building blocks for the filters a trail sidebar hands to its store.
 * Each one adds its keys in a fixed order and leaves out the filters that
 * are not set.
 *
 * @spec exclude Sidebar filter-state to store-filter plumbing; no backend contract.
 */

/**
 * Add the selected register and schema ids, as strings.
 *
 * @param {object} filters The filters being built.
 * @param {{register: object|null, schema: object|null}} scope The selected register and schema.
 *
 * @spec exclude Sidebar filter-state to store-filter plumbing; no backend contract.
 */
export function addScopeIds(filters, scope) {
	if (scope.register) {
		filters.register = scope.register.id.toString()
	}
	if (scope.schema) {
		filters.schema = scope.schema.id.toString()
	}
}

/**
 * Add the values of picked options as one comma-separated string.
 *
 * @param {object} filters The filters being built.
 * @param {[string, Array<{value: string}>]} pair The filter key and the picked options.
 *
 * @spec exclude Sidebar filter-state to store-filter plumbing; no backend contract.
 */
export function addJoinedValues(filters, [key, picked]) {
	if (Array.isArray(picked) && picked.length > 0) {
		filters[key] = picked.map((option) => option.value).join(',')
	}
}

/**
 * Add each value that is set, as it is.
 *
 * @param {object} filters The filters being built.
 * @param {Array<[string, string|number|boolean|null]>} pairs Filter keys and their values.
 *
 * @spec exclude Sidebar filter-state to store-filter plumbing; no backend contract.
 */
export function addPresent(filters, pairs) {
	for (const [key, value] of pairs) {
		if (value) {
			filters[key] = value
		}
	}
}

/**
 * The distinct users of a trail list, as picker options.
 *
 * @param {Array<object>|undefined} trails The trail entries
 * @return {Array<{label: string, value: string}>} The options
 *
 * @spec exclude Sidebar filter-state to store-filter plumbing; no backend contract.
 */
export function trailUserOptions(trails) {
	if (!trails || !trails.length) {
		return []
	}
	const users = new Set(
		trails.map((trail) => trail.userName || trail.user).filter(Boolean),
	)
	return [...users].map((user) => ({ label: user, value: user }))
}
