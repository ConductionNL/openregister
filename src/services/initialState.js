/**
 * A component's initial state, kept as a plain literal and copied per instance.
 *
 * @spec exclude client-side helper for component initial state
 */

/**
 * A deep copy of plain data: arrays and plain objects are copied, every other
 * value (strings, numbers, booleans, null, undefined) is kept as it is.
 *
 * @param {*} value The plain data to copy
 * @return {*} The copy
 * @spec exclude client-side helper for component initial state
 */
export function freshState(value) {
	if (Array.isArray(value)) {
		return value.map(freshState)
	}
	if (value === null || typeof value !== 'object') {
		return value
	}
	return Object.fromEntries(
		Object.entries(value).map(([key, item]) => [key, freshState(item)]),
	)
}
