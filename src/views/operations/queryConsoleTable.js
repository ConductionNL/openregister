/**
 * The first list of a GraphQL result as a table, the way the export route flattens it.
 *
 * Mirrors AdminQueryExport::tableOf(): the first value of `data` that is a
 * connection (`edges[].node`) or a plain list is the table; each record is
 * flattened to dotted paths, and a list value is written as its JSON.
 *
 * @spec openspec/changes/operate-admin-query-console/specs/admin-query-console/spec.md
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

/**
 * The records of the first list in a result's `data`, or null when there is none.
 *
 * @param {object|null} data The result's `data`
 * @return {Array|null} The records
 */
export function firstList(data) {
	if (data === null || typeof data !== 'object') {
		return null
	}

	for (const value of Object.values(data)) {
		if (value === null || typeof value !== 'object') {
			continue
		}

		if (Array.isArray(value.edges)) {
			return value.edges.map((edge) => (edge !== null && typeof edge === 'object' && 'node' in edge ? edge.node : edge))
		}

		if (Array.isArray(value)) {
			return value
		}
	}

	return null
}

/**
 * A record flattened to `path => scalar`, a list written as its JSON.
 *
 * @param {object|Array|string|number|boolean|null} value The value
 * @param {string} prefix The dotted path so far
 * @return {object} The flattened record
 */
export function flatten(value, prefix = '') {
	if (value === null || typeof value !== 'object') {
		return { [prefix]: value }
	}

	if (Array.isArray(value)) {
		return { [prefix]: JSON.stringify(value) }
	}

	let flat = {}
	for (const [key, nested] of Object.entries(value)) {
		flat = { ...flat, ...flatten(nested, prefix === '' ? key : `${prefix}.${key}`) }
	}

	return flat
}

/**
 * The table of a result: its columns in first-seen order and its flattened rows.
 *
 * @param {object} result The GraphQL result
 * @return {{columns: Array<string>, rows: Array<object>}|null} The table, or null without a list
 */
export function tableOf(result) {
	const records = firstList(result?.data)
	if (records === null) {
		return null
	}

	const columns = []
	const rows = records.map((record) => {
		const flat = flatten(record)
		for (const column of Object.keys(flat)) {
			if (!columns.includes(column)) {
				columns.push(column)
			}
		}
		return flat
	})

	return { columns, rows }
}
