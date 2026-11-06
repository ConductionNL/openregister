/**
 * The date and time of a list cell.
 *
 * @spec exclude Client-side display helper for list views.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

/**
 * A stored date as the list views show it, in the locale of the browser.
 *
 * @param {string|null|undefined} value The stored date
 * @return {string} The date and time, or a dash without a date
 */
export function listDateTime(value) {
	if (!value) return '-'
	const date = new Date(value)
	return `${date.toLocaleDateString()}, ${date.toLocaleTimeString()}`
}
