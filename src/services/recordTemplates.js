/**
 * Record templates on the create dialog: the templates a user may start a
 * record from, and a template's values as they still fit the schema.
 *
 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** Keys a template never carries into a new record: they belong to one record. */
const RECORD_KEYS = ['@self', 'id', 'uuid']

/**
 * The templates the current user may start a record from in a register and schema.
 * The server applies the reach (owner, group share, public) and the create right.
 *
 * @param {object} scope The register and schema.
 * @param {string|number} scope.register The register id.
 * @param {string|number} scope.schema The schema id.
 * @return {Promise<Array<object>>} The templates; none when the list cannot be read.
 */
export async function fetchRecordTemplates({ register, schema }) {
	if (!register || !schema) return []
	try {
		const response = await axios.get(generateUrl('/apps/openregister/api/record-templates'), {
			params: { register, schema },
		})
		const results = response?.data?.results
		return Array.isArray(results) ? results : []
	} catch {
		return []
	}
}

/**
 * The JSON-schema type a value has.
 *
 * @param {*} value The value.
 * @return {string}
 */
export function valueType(value) {
	if (value === null) return 'null'
	if (Array.isArray(value)) return 'array'
	if (typeof value === 'number') return Number.isInteger(value) ? 'integer' : 'number'
	return typeof value
}

/**
 * Whether a value still fits a property's declared type. A property without a
 * type takes anything; null fits any property; an integer fits a number; a
 * file property takes what the file input stores (an id, an object or a list).
 *
 * @param {*} value The template value.
 * @param {object} property The schema property.
 * @return {boolean}
 */
export function fitsProperty(value, property) {
	const declared = property?.type
	if (!declared || value === null) return true
	const types = Array.isArray(declared) ? declared : [declared]
	const actual = valueType(value)
	return types.some((type) => {
		if (type === actual) return true
		if (type === 'number' && actual === 'integer') return true
		if (type === 'file') return ['integer', 'string', 'object', 'array'].includes(actual)
		return false
	})
}

/**
 * A template's values as they apply to a schema today: values whose property
 * no longer exists, or whose type no longer matches, are dropped and named.
 * The record is still saved through the normal save, so validation and RBAC
 * apply to what is kept.
 *
 * @param {object} template The template (`values` keyed by property name).
 * @param {object} schema The schema the record is created in.
 * @return {{values: object, dropped: Array<{field: string, reason: string}>}}
 */
export function applyTemplate(template, schema) {
	const properties = schema?.properties || {}
	const values = {}
	const dropped = []
	for (const [field, value] of Object.entries(template?.values || {})) {
		if (RECORD_KEYS.includes(field)) continue
		if (!Object.hasOwn(properties, field)) {
			dropped.push({ field, reason: 'missing' })
			continue
		}
		if (!fitsProperty(value, properties[field])) {
			dropped.push({ field, reason: 'type' })
			continue
		}
		values[field] = value
	}
	return { values, dropped }
}
