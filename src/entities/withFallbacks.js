/**
 * Copy the fields named by a fallback table off an input, taking the
 * fallback wherever the input's value is falsy. The result has the table's
 * keys in the table's order.
 *
 * @template {object} T
 * @param {object|null|undefined} source The input, or nothing.
 * @param {T} fallbacks One fallback per field. Build it per call when it holds
 * objects or arrays, so no two results share them.
 * @return {T} The copied fields.
 *
 * @spec exclude Entity model field-copy helper: no standalone behavioural contract.
 */
export function withFallbacks(source, fallbacks) {
	const input = source || {}
	const result = {}
	Object.keys(fallbacks).forEach((key) => {
		result[key] = input[key] || fallbacks[key]
	})
	return /** @type {T} */ (result)
}
