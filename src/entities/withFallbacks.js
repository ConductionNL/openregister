/**
 * Marks a field that is copied as it is, with no fallback.
 */
export const COPY = Symbol('copy')

/**
 * The quota fallback of an entity: no limit on anything.
 */
export const NO_QUOTA = {
	storage: null,
	bandwidth: null,
	requests: null,
	users: null,
	groups: null,
}

/**
 * The usage fallback of an entity: nothing used yet.
 */
export const NO_USAGE = {
	storage: 0,
	bandwidth: 0,
	requests: 0,
	users: 0,
	groups: 0,
}

/**
 * A fallback as a new value, so no two results share an object or array.
 *
 * @param {*} value The fallback from the table.
 * @return {*} The value, or a deep copy of it.
 */
function fresh(value) {
	return value !== null && typeof value === 'object'
		? JSON.parse(JSON.stringify(value))
		: value
}

/**
 * Copy the fields named by a fallback table off an input, taking the
 * fallback wherever the input's value is falsy, and the input's value as it
 * is for a field marked COPY. The result has the table's keys in the table's
 * order.
 *
 * @template {object} T
 * @param {object|null|undefined} source The input, or nothing.
 * @param {T} fallbacks One fallback (or COPY) per field.
 * @return {T} The copied fields.
 *
 * @spec exclude Entity model field-copy helper: no standalone behavioural contract.
 */
export function withFallbacks(source, fallbacks) {
	const input = source || {}
	const result = {}
	Object.keys(fallbacks).forEach((key) => {
		result[key] =
			fallbacks[key] === COPY
				? input[key]
				: input[key] || fresh(fallbacks[key])
	})
	return /** @type {T} */ (result)
}
