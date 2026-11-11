/**
 * The date and time of a list or detail cell, as one locale string.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec exclude Client-side display helper for list and detail views.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

/**
 * A stored date in the locale of the browser, or a dash without a date.
 *
 * @param {string|number|Date|null|undefined} date The stored date
 * @return {string} The formatted date and time, or `-`
 */
export function displayDateTime(date) {
	if (!date) return '-'
	return new Date(date).toLocaleString()
}
