/**
 * The JSON text field of the upload dialogs: whether the text parses.
 * A plain function: list it in a component's `methods` as `validateJson: isValidJson`.
 *
 * @spec exclude UI validation helper — reports whether a string parses as JSON.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

/**
 * Whether a string parses as JSON.
 *
 * @param {string} json The text
 * @spec exclude UI validation helper — reports whether a string parses as JSON.
 * @return {boolean} True when it parses
 */
export function isValidJson(json) {
	try {
		JSON.parse(json)
		return true
	} catch {
		return false
	}
}
