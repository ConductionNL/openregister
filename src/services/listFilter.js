/**
 * Header filters for OpenRegister's own list pages.
 *
 * The schemas and registers lists are loaded whole, so their header filters
 * are applied here, on the loaded rows. nextcloud-vue's table emits the same
 * query language it sends to OpenRegister: `key[like]` for contains,
 * `key[gte]` and `key[lte]` for a range, and `key` for an exact value. Before
 * this, the registers list showed header filters that narrowed nothing and the
 * schemas list showed none (cloud check, 8 October 2026).
 *
 * @spec openspec/changes/order-filters-and-notification-links/specs/admin-list-views/spec.md#requirement-openregister-s-schemas-and-registers-lists-must-filter-from-their-column-headers
 */

/**
 * Apply one `filter-change` payload to an active-filter map.
 *
 * @param {object} activeFilters The current map (`{ paramKey: values[] }`).
 * @param {{key: string, values: Array}} payload The change from CnIndexPage.
 * @return {object} A new map; an empty list removes the key.
 *
 * @spec openspec/changes/order-filters-and-notification-links/specs/admin-list-views/spec.md#requirement-openregister-s-schemas-and-registers-lists-must-filter-from-their-column-headers
 */
export function applyFilterChange(activeFilters, payload) {
	const next = { ...(activeFilters || {}) }
	if (!payload || typeof payload.key !== 'string' || payload.key === '') {
		return next
	}
	const values = (
		Array.isArray(payload.values) ? payload.values : [payload.values]
	)
		.filter((value) => value !== undefined && value !== null && value !== '')
		.map((value) => String(value))
	if (values.length === 0) {
		delete next[payload.key]
	} else {
		next[payload.key] = values
	}
	return next
}

/**
 * Split a parameter key into its field and operator.
 *
 * @param {string} paramKey `title`, `title[like]`, `created[gte]`.
 * @return {{field: string, operator: string}} The parts; `eq` without an operator.
 */
function parseKey(paramKey) {
	const match = /^(.+)\[(\w+)\]$/.exec(paramKey)
	return match
		? { field: match[1], operator: match[2] }
		: { field: paramKey, operator: 'eq' }
}

/**
 * Compare a row value with a range bound: as times when both read as dates,
 * else as numbers when both are numeric, else as text.
 *
 * @param {unknown} value The row value.
 * @param {string} bound The bound from the filter.
 * @return {number|null} Negative, zero or positive; null when the row has no value.
 */
function compareToBound(value, bound) {
	if (value === undefined || value === null || value === '') {
		return null
	}
	const number = Number(value)
	const boundNumber = Number(bound)
	if (
		typeof value === 'number'
		|| (!Number.isNaN(number)
			&& !Number.isNaN(boundNumber)
			&& String(value).trim() !== '')
	) {
		return number - boundNumber
	}
	const time = Date.parse(String(value))
	const boundTime = Date.parse(bound)
	if (!Number.isNaN(time) && !Number.isNaN(boundTime)) {
		// A date-only bound covers its whole day on the lower end too: compare
		// the row on the same precision the bound was given in.
		if (/^\d{4}-\d{2}-\d{2}$/.test(bound)) {
			return Date.parse(String(value).slice(0, 10)) - boundTime
		}
		return time - boundTime
	}
	return String(value).localeCompare(bound)
}

/**
 * The text a row value reads as, for contains and exact matches.
 *
 * @param {unknown} value The row value.
 * @return {string} The text.
 */
function asText(value) {
	if (value === undefined || value === null) {
		return ''
	}
	if (typeof value === 'object') {
		return JSON.stringify(value)
	}
	return String(value)
}

/**
 * Whether one row passes one active filter.
 *
 * @param {object} row The row.
 * @param {string} paramKey The parameter key.
 * @param {Array<string>} values The filter values.
 * @param {function(object, string): unknown} valueOf Reads a field from a row.
 * @return {boolean} True when the row passes.
 */
function rowPasses(row, paramKey, values, valueOf) {
	const { field, operator } = parseKey(paramKey)
	const value = valueOf(row, field)
	const term = values[0]
	if (operator === 'like') {
		return asText(value).toLowerCase().includes(String(term).toLowerCase())
	}
	if (operator === 'gte' || operator === 'lte') {
		const comparison = compareToBound(value, String(term))
		if (comparison === null) {
			return false
		}
		return operator === 'gte' ? comparison >= 0 : comparison <= 0
	}
	// Exact: any of the chosen values, compared as text.
	return values.some((candidate) => asText(value) === String(candidate))
}

/**
 * The rows that pass every active header filter.
 *
 * @param {Array<object>} rows The loaded rows.
 * @param {object} activeFilters The active-filter map.
 * @param {function(object, string): unknown} [valueOf] Reads a field from a row; defaults to `row[field]`.
 * @return {Array<object>} The rows that pass, in their original order.
 *
 * @spec openspec/changes/order-filters-and-notification-links/specs/admin-list-views/spec.md#requirement-openregister-s-schemas-and-registers-lists-must-filter-from-their-column-headers
 */
export function filterRows(
	rows,
	activeFilters,
	valueOf = (row, field) => (row ? row[field] : undefined),
) {
	const entries = Object.entries(activeFilters || {})
		.map(([key, values]) => [key, Array.isArray(values) ? values : [values]])
		.filter(([, values]) => values.length > 0)
	if (entries.length === 0) {
		return [...(rows || [])]
	}
	return (rows || []).filter((row) =>
		entries.every(([key, values]) => rowPasses(row, key, values, valueOf)),
	)
}
