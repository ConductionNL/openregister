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
 * @spec openspec/specs/admin-list-views/spec.md#requirement-openregister-s-schemas-and-registers-lists-must-filter-from-their-column-headers
 */

/**
 * Apply one `filter-change` payload to an active-filter map.
 *
 * @param {object} activeFilters The current map (`{ paramKey: values[] }`).
 * @param {{key: string, values: Array}} payload The change from CnIndexPage.
 * @return {object} A new map; an empty list removes the key.
 *
 * @spec openspec/specs/admin-list-views/spec.md#requirement-openregister-s-schemas-and-registers-lists-must-filter-from-their-column-headers
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
 * @spec openspec/specs/admin-list-views/spec.md#requirement-openregister-s-schemas-and-registers-lists-must-filter-from-their-column-headers
 */
function compareToBound(value, bound) {
	if (isBlank(value)) {
		return null
	}
	if (isNumericPair(value, bound)) {
		return Number(value) - Number(bound)
	}
	return compareAsTimes(value, bound) ?? String(value).localeCompare(bound)
}

/**
 * Whether a row value is missing or empty text.
 *
 * @param {unknown} value The row value.
 * @return {boolean}
 * @spec openspec/specs/admin-list-views/spec.md#requirement-openregister-s-schemas-and-registers-lists-must-filter-from-their-column-headers
 */
function isBlank(value) {
	return value === undefined || value === null || value === ''
}

/**
 * Whether a row value and a bound compare as numbers: the value is a number,
 * or both read as numbers and the value is not blank text.
 *
 * @param {unknown} value The row value.
 * @param {string} bound The bound from the filter.
 * @return {boolean}
 * @spec openspec/specs/admin-list-views/spec.md#requirement-openregister-s-schemas-and-registers-lists-must-filter-from-their-column-headers
 */
function isNumericPair(value, bound) {
	return (
		typeof value === 'number'
		|| (!Number.isNaN(Number(value))
			&& !Number.isNaN(Number(bound))
			&& String(value).trim() !== '')
	)
}

/**
 * Compare a row value with a bound as times.
 *
 * @param {unknown} value The row value.
 * @param {string} bound The bound from the filter.
 * @return {number|null} Negative, zero or positive; null when either is no date.
 * @spec openspec/specs/admin-list-views/spec.md#requirement-openregister-s-schemas-and-registers-lists-must-filter-from-their-column-headers
 */
function compareAsTimes(value, bound) {
	const time = Date.parse(String(value))
	const boundTime = Date.parse(bound)
	if (Number.isNaN(time) || Number.isNaN(boundTime)) {
		return null
	}
	// A date-only bound covers its whole day on the lower end too: compare
	// the row on the same precision the bound was given in.
	if (/^\d{4}-\d{2}-\d{2}$/.test(bound)) {
		return Date.parse(String(value).slice(0, 10)) - boundTime
	}
	return time - boundTime
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

// The test each operator applies to a row value and the filter values;
// an operator not listed matches exactly.
const OPERATOR_TESTS = new Map([
	[
		'like',
		(value, values) =>
			asText(value).toLowerCase().includes(String(values[0]).toLowerCase()),
	],
	[
		'gte',
		(value, values) =>
			withinBound(value, {
				bound: String(values[0]),
				keeps: (comparison) => comparison >= 0,
			}),
	],
	[
		'lte',
		(value, values) =>
			withinBound(value, {
				bound: String(values[0]),
				keeps: (comparison) => comparison <= 0,
			}),
	],
])

/**
 * Whether one row passes one active filter.
 *
 * @param {object} row The row.
 * @param {string} paramKey The parameter key.
 * @param {Array<string>} values The filter values.
 * @param {function(object, string): unknown} valueOf Reads a field from a row.
 * @return {boolean} True when the row passes.
 * @spec openspec/specs/admin-list-views/spec.md#requirement-openregister-s-schemas-and-registers-lists-must-filter-from-their-column-headers
 */
function rowPasses(row, paramKey, values, valueOf) {
	const { field, operator } = parseKey(paramKey)
	const passes = OPERATOR_TESTS.get(operator) || matchesAny
	return passes(valueOf(row, field), values)
}

/**
 * Whether a row value is any of the chosen values, compared as text.
 *
 * @param {unknown} value The row value.
 * @param {Array<string>} values The filter values.
 * @return {boolean}
 * @spec openspec/specs/admin-list-views/spec.md#requirement-openregister-s-schemas-and-registers-lists-must-filter-from-their-column-headers
 */
function matchesAny(value, values) {
	return values.some((candidate) => asText(value) === String(candidate))
}

/**
 * Whether a row value lies on the kept side of a range bound.
 *
 * @param {unknown} value The row value.
 * @param {{bound: string, keeps: function(number): boolean}} range The bound and the test on the comparison.
 * @return {boolean} False when the row has no value.
 * @spec openspec/specs/admin-list-views/spec.md#requirement-openregister-s-schemas-and-registers-lists-must-filter-from-their-column-headers
 */
function withinBound(value, range) {
	const comparison = compareToBound(value, range.bound)
	return comparison !== null && range.keeps(comparison)
}

/**
 * The rows that pass every active header filter.
 *
 * @param {Array<object>} rows The loaded rows.
 * @param {object} activeFilters The active-filter map.
 * @param {function(object, string): unknown} [valueOf] Reads a field from a row; defaults to `row[field]`.
 * @return {Array<object>} The rows that pass, in their original order.
 *
 * @spec openspec/specs/admin-list-views/spec.md#requirement-openregister-s-schemas-and-registers-lists-must-filter-from-their-column-headers
 */
export function filterRows(
	rows,
	activeFilters,
	valueOf = (row, field) => (row ? row[field] : undefined),
) {
	const list = rows || []
	const entries = activeEntries(activeFilters)
	if (entries.length === 0) {
		return [...list]
	}
	return list.filter((row) =>
		entries.every(([key, values]) => rowPasses(row, key, values, valueOf)),
	)
}

/**
 * The filters that hold at least one value, each value list as a list.
 *
 * @param {object} activeFilters The active-filter map.
 * @return {Array<[string, Array]>} The parameter keys and their values.
 * @spec openspec/specs/admin-list-views/spec.md#requirement-openregister-s-schemas-and-registers-lists-must-filter-from-their-column-headers
 */
function activeEntries(activeFilters) {
	return Object.entries(activeFilters || {})
		.map(([key, values]) => [key, Array.isArray(values) ? values : [values]])
		.filter(([, values]) => values.length > 0)
}
