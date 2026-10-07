/**
 * Header sorting for OpenRegister's own list pages.
 *
 * The schemas list is loaded whole and sorted here; the audit trail list is
 * paged on the server and sorted there. Both used to declare sortable
 * headers (or none) that did nothing when clicked (live audit G2, 7 October
 * 2026).
 *
 * @spec openspec/changes/live-audit-round-one/specs/audit-trail-immutable/spec.md
 */

/**
 * Sortable audit trail column key => the column the server sorts by.
 * `size` is computed per row and has no column, so it stays unsortable.
 *
 * @type {Object<string, string>}
 */
export const AUDIT_SORT_FIELDS = {
	action: 'action',
	created: 'created',
	object: 'object',
	register: 'register',
	userName: 'user_name',
	schema: 'schema',
}

/**
 * The header state that matches the audit trail sort the store holds.
 *
 * @param {object} sort `{ field: 'ASC' | 'DESC' }`; empty means newest first.
 * @return {{sortKey: string, sortOrder: string}} The header state.
 *
 * @spec openspec/changes/live-audit-round-one/specs/audit-trail-immutable/spec.md
 */
export function headerSortOf(sort) {
	const [field, direction] = Object.entries(sort || {})[0] || ['created', 'DESC']
	const key =
		Object.keys(AUDIT_SORT_FIELDS).find((k) => AUDIT_SORT_FIELDS[k] === field)
		|| 'created'
	return {
		sortKey: key,
		sortOrder: String(direction).toUpperCase() === 'ASC' ? 'asc' : 'desc',
	}
}

/**
 * The value a schema row is compared by for one header.
 *
 * @param {object} schema The schema row.
 * @param {string} key The column key.
 * @return {number|string} The comparable value.
 */
function schemaValue(schema, key) {
	const value = schema ? schema[key] : null
	if (key === 'created' || key === 'updated') {
		const time = value ? Date.parse(value) : NaN
		return Number.isNaN(time) ? 0 : time
	}
	if (key === 'properties') {
		return value && typeof value === 'object' ? Object.keys(value).length : 0
	}
	return value === null || value === undefined ? '' : String(value)
}

/**
 * The schema list in the order a header asks for, newest first without one.
 *
 * Highest id first is the default because a just-created schema must land on
 * page 1 (the list is paged client-side). Ties keep that order.
 *
 * @param {Array<object>} schemas The schemas.
 * @param {string|null} sortKey The column key, or null for the default.
 * @param {string} sortOrder `asc` or `desc`.
 * @return {Array<object>} A sorted copy.
 *
 * @spec openspec/changes/live-audit-round-one/specs/audit-trail-immutable/spec.md
 */
export function sortSchemas(schemas, sortKey, sortOrder = 'asc') {
	const newestFirst = [...(schemas || [])].sort(
		(a, b) => Number(b?.id ?? 0) - Number(a?.id ?? 0),
	)
	if (!sortKey) {
		return newestFirst
	}
	const direction = sortOrder === 'desc' ? -1 : 1
	return newestFirst
		.map((schema, index) => ({ schema, index }))
		.sort((a, b) => {
			const left = schemaValue(a.schema, sortKey)
			const right = schemaValue(b.schema, sortKey)
			const result =
				typeof left === 'number' && typeof right === 'number'
					? left - right
					: String(left).localeCompare(String(right), undefined, {
							sensitivity: 'base',
							numeric: true,
						})
			return result !== 0 ? result * direction : a.index - b.index
		})
		.map(({ schema }) => schema)
}
