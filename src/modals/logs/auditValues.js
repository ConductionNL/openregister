/**
 * How the audit trail modals print a logged value.
 *
 * @spec exclude Client-side display formatting of audit trail values.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

/**
 * A logged value as text: objects as indented JSON (or their string form
 * when they cannot be serialised), anything else as its string form.
 *
 * @param {*} value The value
 * @return {string} The text
 */
export function printableValue(value) {
	if (typeof value === 'object') {
		try {
			return JSON.stringify(value, null, 2)
		} catch {
			return String(value)
		}
	}
	return String(value)
}
